<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_history_moves_to_account_and_record_updates_are_isolated(): void
    {
        $proposal = [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'discount' => 0,
            'lines' => [['name' => 'Дизайн', 'quantity' => 1, 'price' => 1000]],
        ];
        $this->postJson('/api/review', $proposal)->assertOk()
            ->assertJsonPath('snapshot.total', 100000)->assertJsonCount(2, 'warnings');
        $this->assertDatabaseCount('proposals', 0);
        $this->postJson('/api/review', $proposal + ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/proposals', $proposal)->assertCreated();
        $this->getJson('/api/workspace/drafts')->assertUnauthorized();
        $this->postJson('/api/session/register', ['name' => 'Анна', 'email' => 'anna@example.test', 'password' => 'workspace-test'])
            ->assertOk()->assertJsonPath('user.name', 'Анна');
        $this->getJson('/api/proposals')->assertOk()->assertJsonCount(1);

        $draft = $this->postJson('/api/workspace/drafts', ['lines' => [['name' => '']]])->assertOk()->json();
        $this->postJson('/api/workspace/drafts', $proposal)->assertOk();
        $this->getJson('/api/workspace/drafts')->assertOk()->assertJsonCount(2);

        $client = $this->postJson('/api/workspace/clients', ['name' => 'Контур', 'details' => 'г. Тула'])->assertOk()->json();
        $this->postJson('/api/workspace/services', ['name' => 'Аудит', 'unit' => 'час', 'price' => 1700])
            ->assertOk()->assertJsonPath('data.unit', 'час');
        $this->postJson('/api/workspace/clients/'.$client['id'], ['name' => 'Север', 'version' => 1])
            ->assertOk()->assertJsonPath('version', 2);
        $this->postJson('/api/workspace/clients/'.$client['id'], ['name' => 'Потерянная правка', 'version' => 1])->assertConflict();

        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/workspace/drafts')->assertExactJson([]);
        $this->deleteJson('/api/workspace/drafts/'.$draft['id'])->assertNotFound();
        $this->getJson('/api/proposals')->assertExactJson([]);
    }
}
