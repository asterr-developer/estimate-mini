<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function session(Request $request)
    {
        return response()->json(['user' => $request->user()?->only('id', 'name', 'email'), 'token' => csrf_token()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'max:128', Password::min(8)],
        ]);
        Auth::login(User::create($data));

        return $this->enter($request);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:128']);
        if (!Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'Проверьте почту и пароль.']);
        }

        return $this->enter($request);
    }

    private function enter(Request $request)
    {
        $request->session()->regenerate();
        $oldOwner = $request->session()->get('proposal_owner');
        if ($oldOwner !== null) {
            DB::table('proposals')->where('owner', $oldOwner)->update(['owner' => 'user:'.$request->user()->id]);
            $request->session()->forget('proposal_owner');
        }

        return $this->session($request);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->session($request);
    }

    public function forgot(Request $request): array
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        PasswordBroker::sendResetLink($data);

        return ['message' => 'Если кабинет с такой почтой существует, мы отправим ссылку для сброса пароля.'];
    }

    public function reset(Request $request): array
    {
        $data = $request->validate([
            'token' => 'required|string|max:255', 'email' => 'required|email|max:255',
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(8)],
        ]);
        $status = PasswordBroker::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
            }
            event(new PasswordReset($user));
        });
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Ссылка недействительна или просрочена. Запросите новую.']);
        }
        if ($request->user() && strcasecmp($request->user()->email, $data['email']) === 0) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ['message' => 'Пароль обновлён. Теперь можно войти в кабинет.', 'token' => csrf_token()];
    }

    public function records(Request $request, string $kind)
    {
        return response()->json($this->recordsQuery($request, $kind)->latest('updated_at')->orderByDesc('id')->get()
            ->map($this->record(...)))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $kind, ?int $id = null)
    {
        $rules = match ($kind) {
            'clients' => ['name' => 'required|string|max:100', 'details' => 'nullable|string|max:240'],
            'contractors' => ['name' => 'required|string|max:100', 'details' => 'nullable|string|max:240',
                'contact' => 'nullable|string|max:180'],
            'services' => ['name' => 'required|string|max:120', 'unit' => 'required|string|max:16', 'price' => ['required', Money::priceRule()],
                'mini_category' => 'nullable|string|max:60', 'mini_description' => 'nullable|string|max:240',
                'mini_visible' => 'nullable|boolean'],
            'drafts', 'templates' => [
                'title' => 'nullable|string|max:120', 'sender' => 'nullable|string|max:100', 'client' => 'nullable|string|max:100',
                'sender_details' => 'nullable|string|max:240', 'client_details' => 'nullable|string|max:240',
                'sender_contact' => 'nullable|string|max:180',
                'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d',
                'valid_until' => 'nullable|date_format:Y-m-d', 'discount' => 'nullable|integer|between:0,100',
                'notes' => 'nullable|string|max:1500', 'basis' => 'nullable|string|max:180',
                'price_type' => 'nullable|in:fixed,approximate', 'revision_of' => 'nullable|integer|min:1',
                'template_name' => $kind === 'templates' ? 'required|string|max:100' : 'prohibited',
                'client_record_id' => 'nullable|integer|min:1',
                'advance_percent' => 'nullable|integer|between:0,100',
                'vat_mode' => 'nullable|in:none,added,included',
                'vat_rate' => 'nullable|integer|in:0,5,7,10,20,22',
                'payments' => 'nullable|array|list|max:12',
                'payments.*' => 'array:label,percent,due_on',
                'payments.*.due_on' => 'nullable|date_format:Y-m-d',
                'payments.*.label' => 'nullable|string|max:120',
                'payments.*.percent' => 'nullable|integer|between:0,100',
                'stages' => 'nullable|array|list|max:10', 'stages.*' => 'array:key,name,due_on',
                'stages.*.key' => 'required|alpha_dash:ascii|max:40|distinct',
                'stages.*.name' => 'nullable|string|max:100', 'stages.*.due_on' => 'nullable|date_format:Y-m-d',
                'lines' => 'required|array|list|min:1|max:30', 'lines.*' => 'array:name,unit,price,quantity,stage',
                'lines.*.stage' => 'nullable|alpha_dash:ascii|max:40',
                'lines.*.name' => 'nullable|string|max:120', 'lines.*.unit' => 'nullable|string|max:16',
                'lines.*.price' => ['nullable', Money::priceRule()], 'lines.*.quantity' => 'nullable|integer|between:0,999',
            ],
        };
        $data = $request->validate($rules);
        if ($kind === 'templates') {
            unset($data['client'], $data['client_details'], $data['client_record_id'], $data['revision_of'], $data['valid_until']);
        }
        $query = $this->recordsQuery($request, $kind);
        $entry = ['payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        if ($id !== null) {
            $version = $request->validate(['version' => 'required|integer|min:1'])['version'];
            $existing = (clone $query)->where('id', $id);
            abort_unless($existing->exists(), 404);
            $changed = $existing->where('version', $version)->update($entry + ['version' => DB::raw('version + 1')]);
            abort_unless($changed, 409, 'Эта запись уже изменена. Откройте кабинет заново, чтобы загрузить актуальную версию.');
        } else {
            $id = DB::table('workspace_records')->insertGetId($entry + [
                'user_id' => $request->user()->id, 'kind' => $kind,
            ]);
        }
        $row = $query->find($id);
        abort_unless($row, 404);

        return $this->record($row);
    }

    public function remove(Request $request, string $kind, int $id)
    {
        abort_unless($this->recordsQuery($request, $kind)->where('id', $id)->delete(), 404);

        return response()->noContent();
    }

    private function recordsQuery(Request $request, string $kind)
    {
        return DB::table('workspace_records')->where('user_id', $request->user()->id)->where('kind', $kind);
    }

    private function record(object $row): array
    {
        return ['id' => $row->id, 'version' => $row->version, 'updated_at' => $row->updated_at,
            'data' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)];
    }
}
