<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_snapshot_and_first_acceptance_remain_fixed(): void
    {
        $payload = [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания',
            'discount' => 5, 'lines' => [['name' => 'Дизайн', 'quantity' => 2, 'price' => 101]],
        ];
        $proposal = $this->postJson('/api/proposals', $payload)->assertCreated()
            ->assertJsonPath('snapshot.subtotal', 20200)->assertJsonPath('snapshot.total', 19190)->json();
        $url = '/api/proposals/'.$proposal['token'];

        $payload['lines'][0]['price'] = 999;
        $this->postJson('/api/proposals', $payload)->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('snapshot.lines.0.price', 101);
        $this->getJson('/api/proposals')->assertJsonCount(2);
        $this->withSession(['proposal_owner' => str_repeat('a', 64)])->getJson('/api/proposals')->assertExactJson([]);
        $this->postJson($url.'/accept', ['name' => 'Анна'])->assertOk()->assertJsonPath('accepted_by', 'Анна');
        $this->postJson($url.'/accept', ['name' => 'Иван'])->assertOk()->assertJsonPath('accepted_by', 'Анна')
            ->assertJsonPath('snapshot.total', 19190);
    }

    public function test_invalid_line_cannot_be_issued(): void
    {
        $this->postJson('/api/proposals', [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'discount' => 101,
            'lines' => [['name' => 'Дизайн', 'quantity' => 0, 'price' => -1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['discount', 'lines.0.quantity', 'lines.0.price']);
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_expired_proposal_remains_downloadable_but_cannot_be_accepted(): void
    {
        $proposal = $this->postJson('/api/proposals', [
            'title' => 'Адаптация интернет-магазина', 'sender' => 'Студия', 'client' => 'Компания',
            'basis' => 'Договор № 14', 'price_type' => 'approximate',
            'valid_until' => today('Europe/Moscow')->toDateString(),
            'discount' => 5, 'lines' => [['name' => 'Вёрстка', 'unit' => 'час', 'quantity' => 8, 'price' => 1500]],
        ])->assertCreated()->json();
        $url = '/api/proposals/'.$proposal['token'];

        $this->travel(1)->days();
        $this->getJson($url)->assertOk()->assertJsonPath('expired', true);
        $this->postJson($url.'/accept', ['name' => 'Анна'])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $pdf = $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="estimate-0001.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get('/api/proposals/'.str_repeat('f', 64).'/pdf')->assertNotFound();
    }

    public function test_only_owner_can_withdraw_and_issue_a_revision(): void
    {
        $payload = [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'discount' => 0,
            'lines' => [['name' => 'Дизайн', 'quantity' => 1, 'price' => 1000]],
        ];
        $owner = str_repeat('a', 64);
        $first = $this->withSession(['proposal_owner' => $owner])->postJson('/api/proposals', $payload)
            ->assertCreated()->assertJsonPath('status', 'pending')->json();
        $url = '/api/proposals/'.$first['token'];
        $revision = $payload + ['revision_of' => $first['id']];
        $this->withSession(['proposal_owner' => str_repeat('b', 64)])->postJson($url.'/withdraw')->assertNotFound();
        $this->postJson('/api/proposals', $revision)->assertUnprocessable()->assertJsonValidationErrors('revision_of');
        $this->withSession(['proposal_owner' => $owner])->postJson($url.'/withdraw')->assertOk()
            ->assertJsonPath('status', 'withdrawn');
        $this->postJson($url.'/accept', ['name' => 'Анна'])->assertUnprocessable();
        $this->get($url.'/pdf')->assertOk();
        $revision['lines'][0]['price'] = 1400;
        $second = $this->postJson('/api/proposals', $revision)->assertCreated()
            ->assertJsonPath('revision_of', $first['id'])->assertJsonPath('snapshot.total', 140000)->json();
        $secondUrl = '/api/proposals/'.$second['token'];
        $this->postJson($secondUrl.'/accept', ['name' => 'Анна'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson($secondUrl.'/withdraw')->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('snapshot.total', 100000);
    }
}
