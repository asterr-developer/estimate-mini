<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NotifyMiniClient implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [20, 60];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = DB::table('mini_messages as m')->join('mini_requests as r', 'r.id', '=', 'm.mini_request_id')
            ->where('m.id', $this->messageId)->where('m.from_operator', true)->first(['r.visitor', 'm.body']);
        $token = config('miniapp.token');
        if (!$message || !$token || !str_starts_with($message->visitor, 'tg:')) {
            return;
        }
        try {
            $response = Http::connectTimeout(4)->timeout(10)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => substr($message->visitor, 3), 'text' => $message->body,
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Telegram недоступен.');
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException('Telegram временно не принял уведомление.');
        }
        if (!$response->successful() || !$response->json('ok')) {
            $this->fail(new RuntimeException('Telegram не доставил уведомление. Ответ доступен в мини-приложении.'));
        }
    }
}
