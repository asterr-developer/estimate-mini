<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyMiniClient;
use App\Models\User;
use App\Support\MiniAppIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MiniAppController extends Controller
{
    public function home(Request $request, MiniAppIdentity $identity)
    {
        $visitor = $identity->get($request);
        $owner = $this->owner();
        return response()->json([
            'name' => $visitor['name'], 'catalog' => $this->catalog($owner),
            'requests' => $this->threads($owner, $visitor['key']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, MiniAppIdentity $identity)
    {
        $visitor = $identity->get($request);
        $owner = $this->owner();
        $data = $request->validate([
            'items' => 'nullable|array|list|max:30', 'items.*' => 'required|array:id,quantity',
            'items.*.id' => 'required|string|max:40|distinct', 'items.*.quantity' => 'required|integer|between:1,999',
            'comment' => 'nullable|string|max:1500',
        ]);
        $catalog = collect($this->catalog($owner))->keyBy('id');
        $lines = [];
        foreach ($data['items'] ?? [] as $index => $item) {
            $service = $catalog->get($item['id']);
            if (!$service) {
                throw ValidationException::withMessages(["items.$index" => 'Каталог обновился. Проверьте корзину.']);
            }
            $lines[] = ['name' => $service['name'], 'unit' => $service['unit'], 'price' => $service['price'], 'quantity' => (int) $item['quantity']];
        }
        $comment = $data['comment'] ?? null;
        if (!$lines && !$comment) {
            throw ValidationException::withMessages(['comment' => 'Выберите услуги или напишите вопрос.']);
        }

        $id = DB::transaction(function () use ($owner, $visitor, $lines, $comment) {
            $subject = $lines ? 'Подбор услуг' : 'Вопрос в поддержку';
            $draft = null;
            if ($lines) {
                $profile = DB::table('workspace_records')->where('user_id', $owner)->where('kind', 'contractors')
                    ->latest('updated_at')->orderByDesc('id')->value('payload');
                $profile = json_decode($profile ?? '{}', true);
                $draft = DB::table('workspace_records')->insertGetId([
                    'user_id' => $owner, 'kind' => 'drafts', 'updated_at' => now(),
                    'payload' => $this->json([
                        'title' => $subject.' · '.$visitor['name'], 'sender' => $profile['name'] ?? '',
                        'sender_details' => $profile['details'] ?? '', 'sender_contact' => $profile['contact'] ?? '',
                        'client' => $visitor['name'], 'discount' => 0, 'lines' => $lines, 'notes' => '',
                    ]),
                ]);
            }
            $id = DB::table('mini_requests')->insertGetId([
                'user_id' => $owner, 'visitor' => $visitor['key'], 'customer' => $visitor['name'],
                'draft_id' => $draft, 'subject' => $subject, 'items' => $this->json($lines),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($comment) {
                $this->message($id, $comment, false);
            }

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function followUp(Request $request, MiniAppIdentity $identity, int $thread): array
    {
        $visitor = $identity->get($request);
        $row = DB::table('mini_requests')->where('user_id', $this->owner())->where('visitor', $visitor['key'])->find($thread);
        abort_unless($row, 404);
        $body = $request->validate(['comment' => 'required|string|max:1500'])['comment'];
        DB::transaction(fn () => $this->message($thread, $body, false));

        return ['ok' => true];
    }

    public function inbox(Request $request)
    {
        return response()->json($this->threads($request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $thread): array
    {
        $row = DB::table('mini_requests')->where('user_id', $request->user()->id)->find($thread);
        abort_unless($row, 404);
        $data = $request->validate(['comment' => 'nullable|required_without:proposal_id|string|max:1500',
            'proposal_id' => 'nullable|integer|min:1']);
        $base = rtrim(config('app.url'), '/');
        $telegram = str_starts_with($row->visitor, 'tg:');
        DB::transaction(function () use ($request, $row, $data, $base, $telegram) {
            $body = $data['comment'] ?? '';
            if (!empty($data['proposal_id'])) {
                $proposal = DB::table('proposals')->where('owner', 'user:'.$request->user()->id)
                    ->whereNull('withdrawn_at')->whereNull('archived_at')->lockForUpdate()->find($data['proposal_id']);
                abort_unless($proposal, 404);
                $alreadyShared = DB::table('mini_requests')->where('proposal_id', $proposal->id)
                    ->where('visitor', '!=', $row->visitor)->exists();
                if ($alreadyShared || ($telegram && !str_starts_with($base, 'https://'))) {
                    throw ValidationException::withMessages(['proposal_id' => $alreadyShared
                        ? 'Эту смету уже отправили другому клиенту.' : 'Для ссылки в Telegram укажите публичный HTTPS-адрес приложения.']);
                }
                DB::table('mini_requests')->where('id', $row->id)->update(['proposal_id' => $proposal->id]);
                $body = trim($body."\n\nГотовая смета: ".$base.'/p/'.$proposal->share_token);
            }
            $id = $this->message($row->id, $body, true);
            if ($telegram && config('miniapp.token')) {
                NotifyMiniClient::dispatch($id)->afterCommit();
            }
        });

        return ['ok' => true];
    }

    private function threads(int $owner, ?string $visitor = null): array
    {
        $rows = DB::table('mini_requests as r')->leftJoin('proposals as p', 'p.id', '=', 'r.proposal_id')
            ->where('r.user_id', $owner)->when($visitor, fn ($query) => $query->where('r.visitor', $visitor))
            ->select('r.id', 'r.customer', 'r.draft_id', 'r.subject', 'r.items', 'r.created_at', 'r.updated_at', 'p.share_token')
            ->latest('r.updated_at')->orderByDesc('r.id')->limit(40)->get();
        $messages = DB::table('mini_messages')->whereIn('mini_request_id', $rows->pluck('id'))->orderBy('id')->get()
            ->groupBy('mini_request_id');

        return $rows->map(function ($row) use ($messages) {
            $result = (array) $row;
            $result['items'] = json_decode($row->items, true, flags: JSON_THROW_ON_ERROR);
            $result['messages'] = $messages->get($row->id, collect())->values();
            return $result;
        })->all();
    }

    private function owner(): int
    {
        $id = config('miniapp.owner_id');
        if (!$id && app()->isLocal() && config('miniapp.local_demo')) {
            $id = User::where('name', 'test')->oldest('id')->value('id');
        }
        abort_unless($id && User::whereKey($id)->exists(), 503, 'Кабинет подрядчика пока не подключён.');
        return (int) $id;
    }

    private function catalog(int $owner): array
    {
        $items = DB::table('workspace_records')->where('user_id', $owner)->where('kind', 'services')
            ->orderBy('id')->limit(60)->get();
        if ($items->isNotEmpty()) {
            return $items->map(function ($item) {
                $data = json_decode($item->payload, true, flags: JSON_THROW_ON_ERROR);
                return [
                    'id' => 'service-'.$item->id, 'category' => $data['mini_category'] ?? 'Услуги подрядчика',
                    'description' => $data['mini_description'] ?? 'Стоимость уточняется после обсуждения.',
                    'name' => $data['name'], 'unit' => $data['unit'], 'price' => $data['price'],
                    'visible' => (bool) ($data['mini_visible'] ?? true),
                ];
            })->filter(fn ($item) => $item['visible'])->values()->all();
        }

        return [
            ['id' => 'audit', 'category' => 'Планирование', 'name' => 'Аудит и прототип', 'description' => 'Соберём требования и подготовим структуру сайта.', 'unit' => 'услуга', 'price' => 18000],
            ['id' => 'design', 'category' => 'Дизайн', 'name' => 'Дизайн интерфейса', 'description' => 'Подготовим макет одного экрана.', 'unit' => 'экран', 'price' => 7500],
            ['id' => 'layout', 'category' => 'Разработка', 'name' => 'Адаптивная вёрстка', 'description' => 'Сверстаем страницу для компьютера и телефона.', 'unit' => 'страница', 'price' => 16000],
            ['id' => 'analytics', 'category' => 'Разработка', 'name' => 'Настройка аналитики', 'description' => 'Подключим счётчик и цели для форм.', 'unit' => 'услуга', 'price' => 6500],
        ];
    }

    private function message(int $id, string $body, bool $operator): int
    {
        DB::table('mini_requests')->where('id', $id)->update(['updated_at' => now()]);
        return DB::table('mini_messages')->insertGetId([
            'mini_request_id' => $id, 'from_operator' => $operator, 'body' => $body, 'created_at' => now(),
        ]);
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
