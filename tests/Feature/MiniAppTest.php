<?php

namespace Tests\Feature;

use App\Jobs\NotifyMiniClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MiniAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_telegram_request_creates_owner_draft_and_private_thread(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        config(['miniapp.token' => 'test-bot-token', 'miniapp.owner_id' => $owner->id, 'app.url' => 'https://estimate.example.test']);
        $headers = ['X-Telegram-Init-Data' => $this->initData(12345)];
        $this->getJson('/api/mini', ['X-Telegram-Init-Data' => $headers['X-Telegram-Init-Data'].'x'])->assertUnauthorized();
        $this->getJson('/api/mini', $headers)->assertOk()->assertJsonPath('catalog.0.id', 'audit');
        $id = $this->postJson('/api/mini/requests', [
            'items' => [['id' => 'audit', 'quantity' => 2]], 'comment' => 'Нужно к пятнице',
        ], $headers)->assertCreated()->json('id');
        $this->getJson('/api/mini', ['X-Telegram-Init-Data' => $this->initData(67890)])
            ->assertOk()->assertJsonCount(0, 'requests');
        $this->postJson("/api/mini/requests/$id/messages", ['comment' => 'Чужой вопрос'],
            ['X-Telegram-Init-Data' => $this->initData(67890)])->assertNotFound();

        $this->actingAs(User::factory()->create())->getJson('/api/telegram/inbox')->assertExactJson([]);
        $this->postJson("/api/telegram/inbox/$id/reply", ['comment' => 'Чужой ответ'])->assertNotFound();
        $this->actingAs($owner)->getJson('/api/workspace/drafts')->assertJsonPath('0.data.lines.0.quantity', 2);
        $this->getJson('/api/telegram/inbox')->assertJsonPath('0.messages.0.body', 'Нужно к пятнице');
        $proposal = $this->postJson('/api/proposals', [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Анна', 'discount' => 0,
            'lines' => [['name' => 'Подготовка', 'price' => 18000, 'quantity' => 2]],
        ])->assertCreated()->json();
        $this->postJson("/api/telegram/inbox/$id/reply", ['comment' => 'Готово', 'proposal_id' => $proposal['id']])->assertOk();
        Bus::assertDispatched(NotifyMiniClient::class);
        $this->getJson('/api/mini', $headers)->assertJsonPath('requests.0.share_token', $proposal['token'])
            ->assertJsonPath('requests.0.messages.1.body', "Готово\n\nГотовая смета: https://estimate.example.test/p/".$proposal['token']);
    }

    private function initData(int $id): string
    {
        $fields = ['auth_date' => now()->timestamp, 'user' => json_encode(['id' => $id, 'first_name' => 'Анна'])];
        $check = "auth_date=".$fields['auth_date']."\nuser=".$fields['user'];
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', 'test-bot-token', 'WebAppData', true));

        return http_build_query($fields);
    }
}
