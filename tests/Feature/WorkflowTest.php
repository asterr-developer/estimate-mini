<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_stage_calculation_and_template_are_tied_to_original_document(): void
    {
        $this->actingAs(User::factory()->create());
        $client = $this->postJson('/api/workspace/clients', ['name' => 'Компания', 'details' => 'Тула'])->assertOk()->json();
        $payload = [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'client_record_id' => $client['id'],
            'client_details' => 'Тула', 'discount' => 10, 'advance_percent' => 25,
            'starts_on' => '2026-12-01', 'ends_on' => '2026-12-15',
            'stages' => [['key' => 'design', 'name' => 'Дизайн', 'due_on' => '2026-12-07']],
            'lines' => [['name' => 'Макет', 'unit' => 'час', 'quantity' => 3, 'price' => 1000, 'stage' => 'design']],
        ];
        $this->postJson('/api/workspace/templates', $payload + ['template_name' => 'Веб-сайт'])->assertOk()
            ->assertJsonMissingPath('data.client')->assertJsonMissingPath('data.client_record_id')
            ->assertJsonPath('data.stages.0.due_on', '2026-12-07');
        $invalid = array_replace_recursive($payload, ['stages' => [['due_on' => '2026-12-17']]]);
        $this->postJson('/api/review', $invalid)->assertUnprocessable()->assertJsonValidationErrors('stages.0.due_on');
        $proposal = $this->postJson('/api/proposals', $payload)->assertCreated()
            ->assertJsonPath('client_record_id', $client['id'])->assertJsonPath('snapshot.stages.0.subtotal', 300000)
            ->assertJsonPath('snapshot.advance', 67500)->assertJsonPath('snapshot.balance', 202500)->json();
        $url = '/api/proposals/'.$proposal['token'];
        $this->postJson($url.'/changes', ['comment' => 'Убрать один макет'])->assertOk()->assertJsonPath('status', 'changes');
        $this->postJson($url.'/changes', ['comment' => 'Второй комментарий'])->assertOk()
            ->assertJsonPath('revision_comment', 'Убрать один макет');
        $this->getJson('/api/proposals')->assertJsonPath('0.revision_comment', 'Убрать один макет');
        $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson($url.'/accept', ['name' => 'Анна'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson($url.'/changes', ['comment' => 'После принятия'])->assertUnprocessable();
    }

    public function test_reset_link_is_single_use_and_unknown_email_gets_same_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $message = $this->postJson('/api/session/forgot', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/session/forgot', ['email' => 'missing@example.test'])->assertOk()->assertExactJson($message);
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $notification->toMail($user)->actionUrl);
        $reset = [
            'email' => $user->email, 'token' => $notification->token,
            'password' => 'updated-password', 'password_confirmation' => 'updated-password',
        ];
        $this->postJson('/api/session/reset', $reset)->assertOk();
        $this->assertTrue(Hash::check('updated-password', $user->fresh()->password));
        $this->postJson('/api/session/reset', $reset)->assertUnprocessable();
        $this->postJson('/api/session/login', ['email' => $user->email, 'password' => 'updated-password'])->assertOk();
    }
}
