<?php

namespace App\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Support\Money;
use App\Support\ProposalWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposalController extends Controller
{
    public function catalog(): array
    {
        return [
            ['name' => 'Аудит и прототип', 'price' => 18000],
            ['name' => 'Дизайн интерфейса', 'price' => 25500],
            ['name' => 'Адаптивная вёрстка', 'price' => 16000],
            ['name' => 'Настройка аналитики', 'price' => 6500],
        ];
    }

    public function index(Request $request)
    {
        $owner = $this->owner($request);
        $receipts = DB::table('proposal_receipts as r')->join('proposals as p', 'p.id', '=', 'r.proposal_id')
            ->where('p.owner', $owner)->whereNull('r.voided_at')->select('r.proposal_id', 'r.payment_index')
            ->selectRaw('sum(r.amount) as paid')->groupBy('r.proposal_id', 'r.payment_index')->get()->groupBy('proposal_id');
        $rows = DB::table('proposals')->where('owner', $owner)->orderByDesc('id')->get()->map(function ($row) use ($receipts) {
            $data = $this->serialize($row);
            return $data + ['archived_at' => $row->archived_at, 'payment_summary' => $this->paymentSummary(
                $data['snapshot'], $receipts->get($row->id, collect())->pluck('paid', 'payment_index')->all()
            )];
        });

        return response()->json($rows)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        [$data, $owner, $revisionOf, $clientRecordId] = $this->validatedProposal($request);
        $id = DB::table('proposals')->insertGetId([
            'owner' => $owner,
            'revision_of' => $revisionOf,
            'client_record_id' => $clientRecordId,
            'share_token' => bin2hex(random_bytes(32)),
            'snapshot' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        return response()->json($this->serialize(DB::table('proposals')->find($id)), 201);
    }

    public function review(Request $request)
    {
        [$data] = $this->validatedProposal($request);
        $warnings = [];
        foreach (['sender_details' => 'подрядчика', 'client_details' => 'заказчика'] as $key => $party) {
            if (empty($data[$key])) {
                $warnings[] = 'Не заполнены реквизиты '.$party.'.';
            }
        }

        return response()->json(['snapshot' => $data, 'warnings' => $warnings])->header('Cache-Control', 'private, no-store');
    }

    private function validatedProposal(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'sender' => 'required|string|max:100',
            'client' => 'required|string|max:100',
            'sender_details' => 'nullable|string|max:240',
            'sender_contact' => 'nullable|string|max:180',
            'client_details' => 'nullable|string|max:240',
            'starts_on' => 'nullable|required_with:ends_on|date_format:Y-m-d',
            'ends_on' => 'nullable|required_with:starts_on|date_format:Y-m-d|after_or_equal:starts_on',
            'notes' => 'nullable|string|max:1500',
            'basis' => 'nullable|string|max:180',
            'price_type' => 'nullable|in:fixed,approximate',
            'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:'.today('Europe/Moscow')->toDateString(),
            'discount' => 'required|integer|between:0,100',
            'revision_of' => 'nullable|integer|min:1',
            'client_record_id' => 'nullable|integer|min:1',
            'advance_percent' => 'nullable|integer|between:0,100',
            'vat_mode' => 'nullable|in:none,added,included',
            'vat_rate' => 'nullable|required_if:vat_mode,added,included|integer|in:0,5,7,10,20,22',
            'payments' => 'nullable|array|list|max:12',
            'payments.*' => 'required|array:label,percent,due_on',
            'payments.*.due_on' => 'nullable|date_format:Y-m-d',
            'payments.*.label' => 'required|string|max:120',
            'payments.*.percent' => 'required|integer|between:1,100',
            'stages' => 'nullable|array|list|max:10',
            'stages.*' => 'required|array:key,name,due_on',
            'stages.*.key' => 'required|alpha_dash:ascii|max:40|distinct',
            'stages.*.name' => 'required|string|max:100',
            'stages.*.due_on' => 'nullable|date_format:Y-m-d',
            'lines' => 'required|array|list|min:1|max:30',
            'lines.*' => 'required|array:name,quantity,price,unit,stage',
            'lines.*.name' => 'required|string|max:120',
            'lines.*.stage' => 'nullable|alpha_dash:ascii|max:40',
            'lines.*.unit' => 'nullable|string|max:16',
            'lines.*.quantity' => 'required|integer|between:1,999',
            'lines.*.price' => ['required', Money::priceRule()],
        ]);
        $owner = $this->owner($request);
        $revisionOf = $data['revision_of'] ?? null;
        $clientRecordId = $data['client_record_id'] ?? null;
        unset($data['revision_of'], $data['client_record_id']);
        if ($clientRecordId !== null && (!$request->user() || !DB::table('workspace_records')
            ->where('user_id', $request->user()->id)->where('kind', 'clients')->where('id', $clientRecordId)->exists())) {
            throw ValidationException::withMessages(['client_record_id' => 'Выберите заказчика из вашего справочника.']);
        }
        if ($revisionOf !== null && !DB::table('proposals')->where('owner', $owner)->where('id', $revisionOf)->exists()) {
            throw ValidationException::withMessages(['revision_of' => 'Исходная смета отсутствует в вашей истории.']);
        }
        $data['price_type'] ??= 'fixed';
        $data['stages'] ??= [];
        $stageTotals = [];
        foreach ($data['stages'] as $index => $stage) {
            $due = $stage['due_on'] ?? null;
            if ($due !== null && ((!empty($data['starts_on']) && $due < $data['starts_on'])
                || (!empty($data['ends_on']) && $due > $data['ends_on']))) {
                throw ValidationException::withMessages(["stages.$index.due_on" => 'Срок этапа должен быть в пределах срока работ.']);
            }
            $stageTotals[$stage['key']] = 0;
        }

        $total = 0;
        foreach ($data['lines'] as $index => &$line) {
            $line['quantity'] = (int) $line['quantity'];
            $line['price_cents'] = Money::cents($line['price']);
            $line['price'] = $line['price_cents'] % 100 === 0
                ? intdiv($line['price_cents'], 100)
                : number_format($line['price_cents'] / 100, 2, '.', '');
            $line['amount'] = $line['quantity'] * $line['price_cents'];
            $total += $line['amount'];
            $stage = $line['stage'] ?? null;
            if ($stage !== null) {
                if (!array_key_exists($stage, $stageTotals)) {
                    throw ValidationException::withMessages(["lines.$index.stage" => 'Выберите существующий этап.']);
                }
                $stageTotals[$stage] += $line['amount'];
            }
        }
        unset($line);
        foreach ($data['stages'] as &$stage) {
            $stage['subtotal'] = $stageTotals[$stage['key']];
        }
        unset($stage);

        $data['discount'] = (int) $data['discount'];
        $data['subtotal'] = $total;
        $data['discount_amount'] = Money::ratio($total, $data['discount']);
        $discounted = $total - $data['discount_amount'];
        $data['vat_mode'] ??= 'none';
        $data['vat_rate'] = $data['vat_mode'] === 'none' ? null : (int) $data['vat_rate'];
        $rate = $data['vat_rate'] ?? 0;
        $data['vat_amount'] = Money::ratio($discounted, $rate, $data['vat_mode'] === 'included' ? 100 + $rate : 100);
        $data['vat_base'] = $data['vat_mode'] === 'included' ? $discounted - $data['vat_amount'] : $discounted;
        $data['total'] = $data['vat_base'] + $data['vat_amount'];
        $data['advance_percent'] = (int) ($data['advance_percent'] ?? 0);
        $data['payments'] ??= [];
        if ($data['payments'] !== [] && array_sum(array_column($data['payments'], 'percent')) !== 100) {
            throw ValidationException::withMessages(['payments' => 'Доли платежей должны в сумме составлять 100%.']);
        }
        if ($data['payments'] !== [] && $data['advance_percent'] !== 0) {
            throw ValidationException::withMessages(['payments' => 'Укажите аванс первой строкой графика или используйте отдельное поле аванса.']);
        }
        $share = $paid = 0;
        foreach ($data['payments'] as &$payment) {
            $payment['percent'] = (int) $payment['percent'];
            $share += $payment['percent'];
            $cumulative = Money::ratio($data['total'], $share);
            $payment['amount'] = $cumulative - $paid;
            $paid = $cumulative;
        }
        unset($payment);
        $data['advance'] = Money::ratio($data['total'], $data['advance_percent']);
        $data['balance'] = $data['total'] - $data['advance'];

        return [$data, $owner, $revisionOf, $clientRecordId];
    }

    public function show(string $token)
    {
        $proposal = $this->serialize($this->find($token));
        unset($proposal['client_record_id']);

        return response()->json($proposal)->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, string $token)
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        $proposal = $this->serialize($this->find($token));
        if ($proposal['status'] === 'withdrawn') {
            throw ValidationException::withMessages(['name' => 'Предложение отозвано.']);
        }
        if ($proposal['accepted_at'] === null && $proposal['expired']) {
            throw ValidationException::withMessages(['name' => 'Срок действия предложения истёк.']);
        }

        DB::table('proposals')->where('share_token', $token)->whereNull('accepted_at')->whereNull('withdrawn_at')
            ->update(['accepted_by' => $data['name'], 'accepted_at' => now()]);

        return $this->show($token);
    }

    public function withdraw(Request $request, string $token)
    {
        $query = DB::table('proposals')->where('owner', $this->owner($request))->where('share_token', $token);
        abort_unless((clone $query)->exists(), 404, 'Предложение не найдено.');
        (clone $query)->whereNull('accepted_at')->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
        $proposal = $this->serialize($query->first());
        if ($proposal['status'] === 'accepted') {
            throw ValidationException::withMessages(['proposal' => 'Клиент уже ответил. Отозвать это предложение нельзя.']);
        }

        return response()->json($proposal)->header('Cache-Control', 'private, no-store');
    }

    public function requestChanges(Request $request, string $token)
    {
        $data = $request->validate([
            'comment' => 'nullable|required_without:positions|string|max:1500',
            'positions' => 'nullable|required_without:comment|array|list|max:30',
            'positions.*' => 'required|array:index,comment',
            'positions.*.index' => 'required|integer|min:0|distinct',
            'positions.*.comment' => 'required|string|max:500',
        ]);
        $proposal = $this->serialize($this->find($token));
        foreach ($data['positions'] ?? [] as $position) {
            if (!isset($proposal['snapshot']['lines'][$position['index']])) {
                throw ValidationException::withMessages(['positions' => 'Позиция отсутствует в этой версии сметы.']);
            }
        }
        if (!in_array($proposal['status'], ['pending', 'changes'], true)) {
            throw ValidationException::withMessages(['comment' => 'Запрос правок для этого предложения недоступен.']);
        }
        DB::table('proposals')->where('share_token', $token)->whereNull('accepted_at')->whereNull('withdrawn_at')
            ->whereNull('revision_requested_at')->update([
                'revision_comment' => $data['comment'] ?? null, 'revision_requested_at' => now(),
                'line_feedback' => json_encode($data['positions'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

        return $this->show($token);
    }

    public function archive(Request $request, string $token)
    {
        $data = $request->validate(['archived' => 'required|boolean']);
        $row = $this->owned($request, $token);
        DB::table('proposals')->where('id', $row->id)->update(['archived_at' => $data['archived'] ? ($row->archived_at ?? now()) : null]);

        return $this->index($request);
    }

    public function receipts(Request $request, string $token)
    {
        $proposal = $this->owned($request, $token);
        $rows = DB::table('proposal_receipts')->where('proposal_id', $proposal->id)->orderByDesc('id')
            ->get(['id', 'payment_index', 'amount', 'paid_on', 'reference', 'voided_at']);
        $paid = $rows->whereNull('voided_at')->groupBy('payment_index')->map(fn ($group) => $group->sum('amount'))->all();

        return response()->json([
            'rows' => $rows, 'summary' => $this->paymentSummary($this->serialize($proposal)['snapshot'], $paid),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function storeReceipt(Request $request, string $token)
    {
        $data = $request->validate([
            'payment_index' => 'required|integer|between:0,11',
            'paid_on' => 'required|date_format:Y-m-d|before_or_equal:'.today('Europe/Moscow')->toDateString(),
            'reference' => 'nullable|string|max:120',
            'amount' => 'required|integer|min:1|max:40000000000000',
            'request_id' => 'required|uuid',
        ]);
        DB::transaction(function () use ($request, $token, $data) {
            $row = $this->owned($request, $token, true);
            $recorded = DB::table('proposal_receipts')->where('request_id', $data['request_id'])->first();
            if ($recorded && (int) $recorded->proposal_id === (int) $row->id) {
                return;
            }
            if ($recorded) {
                throw ValidationException::withMessages(['amount' => 'Этот идентификатор уже использован.']);
            }
            $index = (int) $data['payment_index'];
            $schedule = $this->paymentSummary($this->serialize($row)['snapshot'], []);
            $payment = $schedule['schedule'][$index] ?? null;
            $paid = DB::table('proposal_receipts')->where('proposal_id', $row->id)->where('payment_index', $index)
                ->whereNull('voided_at')->sum('amount');
            if (!$payment || $data['amount'] > $payment['amount'] - $paid) {
                throw ValidationException::withMessages(['amount' => 'Сумма превышает остаток выбранного платежа.']);
            }
            DB::table('proposal_receipts')->insert($data + ['proposal_id' => $row->id, 'created_at' => now()]);
        }, 3);

        return $this->receipts($request, $token);
    }

    public function voidReceipt(Request $request, string $token, int $receipt)
    {
        DB::transaction(function () use ($request, $token, $receipt) {
            $row = $this->owned($request, $token, true);
            $entry = DB::table('proposal_receipts')->where('proposal_id', $row->id)->where('id', $receipt);
            abort_unless((clone $entry)->exists(), 404);
            $entry->whereNull('voided_at')->update(['voided_at' => now()]);
        }, 3);

        return $this->receipts($request, $token);
    }

    private function paymentSummary(array $snapshot, array $paid): array
    {
        $schedule = $snapshot['payments'] ?? [];
        if ($schedule === []) {
            $advance = $snapshot['advance'] ?? 0;
            $schedule = $advance > 0 ? [['label' => 'Аванс', 'amount' => $advance]] : [];
            if ($snapshot['total'] > $advance || $schedule === []) {
                $schedule[] = ['label' => $advance > 0 ? 'Остаток' : 'Оплата по смете', 'amount' => $snapshot['total'] - $advance];
            }
        }
        $today = today('Europe/Moscow')->toDateString();
        foreach ($schedule as $index => &$payment) {
            $payment['paid'] = (int) ($paid[$index] ?? 0);
            $payment['remaining'] = max(0, $payment['amount'] - $payment['paid']);
            $payment['overdue'] = $payment['remaining'] > 0 && !empty($payment['due_on']) && $payment['due_on'] < $today;
        }
        unset($payment);
        return ['paid' => array_sum(array_column($schedule, 'paid')), 'remaining' => array_sum(array_column($schedule, 'remaining')),
            'schedule' => $schedule];
    }

    private function owned(Request $request, string $token, bool $lock = false): object
    {
        $query = DB::table('proposals')->where('share_token', $token)->where('owner', $this->owner($request));
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row, 404, 'Предложение не найдено.');
        return $row;
    }

    public function download(string $token)
    {
        $proposal = $this->serialize($this->find($token));
        $number = str_pad((string) $proposal['id'], 4, '0', STR_PAD_LEFT);
        $pdf = new Dompdf(new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]));
        $pdf->loadHtml(view('estimate-pdf', compact('proposal', 'number'))->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(49, $canvas->get_height() - 35, "№ $number   •   {PAGE_NUM} / {PAGE_COUNT}",
            $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.28, 0.38, 0.34]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="estimate-'.$number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function spreadsheet(string $token, ProposalWorkbook $workbook)
    {
        $proposal = $this->serialize($this->find($token));
        $number = str_pad((string) $proposal['id'], 4, '0', STR_PAD_LEFT);

        return response()->streamDownload(fn () => $workbook->stream($proposal), "estimate-$number.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(string $token): object
    {
        $proposal = DB::table('proposals')->where('share_token', $token)->first();
        abort_if($proposal === null, 404, 'Предложение не найдено.');

        return $proposal;
    }

    private function owner(Request $request): string
    {
        if ($request->user()) {
            return 'user:'.$request->user()->id;
        }
        if (!$request->session()->has('proposal_owner')) {
            $request->session()->put('proposal_owner', bin2hex(random_bytes(32)));
        }

        return $request->session()->get('proposal_owner');
    }

    private function serialize(object $row): array
    {
        $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $validUntil = $snapshot['valid_until'] ?? null;
        $expired = $validUntil !== null && $validUntil < today('Europe/Moscow')->toDateString();

        return [
            'id' => $row->id,
            'token' => $row->share_token,
            'created_at' => $row->created_at,
            'accepted_at' => $row->accepted_at,
            'accepted_by' => $row->accepted_by,
            'withdrawn_at' => $row->withdrawn_at,
            'revision_of' => $row->revision_of,
            'client_record_id' => $row->client_record_id,
            'revision_comment' => $row->revision_comment,
            'line_feedback' => json_decode($row->line_feedback ?? '[]', true, flags: JSON_THROW_ON_ERROR),
            'revision_requested_at' => $row->revision_requested_at,
            'expired' => $expired,
            'status' => match (true) {
                $row->accepted_at !== null => 'accepted',
                $row->withdrawn_at !== null => 'withdrawn',
                $expired => 'expired',
                $row->revision_requested_at !== null => 'changes',
                default => 'pending',
            },
            'snapshot' => $snapshot,
        ];
    }
}
