<?php

namespace App\Support;

use Illuminate\Http\Request;

final class MiniAppIdentity
{
    public function get(Request $request): array
    {
        $raw = $request->header('X-Telegram-Init-Data', '');
        if ($raw === '' && app()->isLocal() && config('miniapp.local_demo') && !config('miniapp.token')) {
            if (!$request->session()->has('mini_demo')) {
                $request->session()->put('mini_demo', bin2hex(random_bytes(16)));
            }
            return ['key' => 'local:'.$request->session()->get('mini_demo'), 'name' => 'Тестовый клиент'];
        }

        $token = config('miniapp.token');
        abort_unless($token && $raw !== '' && strlen($raw) <= 16000, 401, 'Откройте мини-приложение в Telegram.');
        parse_str($raw, $fields);
        $hash = $fields['hash'] ?? null;
        unset($fields['hash']);
        abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash), 401);
        foreach ($fields as $value) {
            abort_unless(is_string($value), 401);
        }
        ksort($fields, SORT_STRING);
        $check = implode("\n", array_map(fn ($key, $value) => "$key=$value", array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        abort_unless(hash_equals(hash_hmac('sha256', $check, $secret), $hash), 401);
        $date = (int) ($fields['auth_date'] ?? 0);
        abort_unless($date >= now()->timestamp - 3600 && $date <= now()->timestamp + 60, 401, 'Сессия устарела. Откройте приложение заново.');
        $user = json_decode($fields['user'] ?? '', true);
        abort_unless(is_int($user['id'] ?? null) && $user['id'] > 0, 401);
        $name = mb_substr(trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: 'Клиент Telegram', 0, 100);

        return ['key' => 'tg:'.$user['id'], 'name' => $name];
    }
}
