<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_record_partial_payment_then_archive_without_breaking_the_share_link(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $item = $this->postJson('/api/proposals', $this->proposal())->assertCreated()->json();
        $path = '/api/proposals/'.$item['token'];
        $receipt = [
            'payment_index' => 0, 'amount' => 120000, 'paid_on' => '2026-08-05',
            'reference' => 'Платёжное поручение № 42', 'request_id' => Str::uuid()->toString(),
        ];

        $record = $this->postJson($path.'/receipts', $receipt)->assertOk()
            ->assertJsonPath('summary.paid', 120000)->assertJsonPath('summary.schedule.0.remaining', 30000)
            ->assertJsonPath('summary.schedule.0.overdue', true)->json('rows.0');
        $this->postJson($path.'/receipts', $receipt)->assertOk()->assertJsonCount(1, 'rows');
        $this->postJson($path.'/receipts', array_replace($receipt, ['request_id' => Str::uuid()->toString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $other = User::factory()->create();
        $this->actingAs($other)->getJson($path.'/receipts')->assertNotFound();
        $this->postJson($path.'/archive', ['archived' => true])->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonMissingPath('payment_summary')->assertJsonMissingPath('archived_at');

        $this->actingAs($owner)->postJson($path.'/archive', ['archived' => true])->assertOk()
            ->assertJsonStructure([['archived_at']]);
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson($path.'/archive', ['archived' => false])->assertOk()->assertJsonPath('0.archived_at', null);
        $this->postJson($path.'/receipts/'.$record['id'].'/void')->assertOk()->assertJsonPath('summary.paid', 0);
    }

    public function test_line_feedback_is_fixed_to_issued_lines_and_workbook_uses_literal_text(): void
    {
        $this->actingAs(User::factory()->create());
        $profile = $this->postJson('/api/workspace/contractors', [
            'name' => 'Студия', 'details' => 'Тула', 'contact' => 'desk@example.test',
        ])->assertOk()->json('data');
        $proposal = $this->postJson('/api/proposals', $this->proposal() + ['sender_contact' => $profile['contact']])
            ->assertCreated()->json();
        $path = '/api/proposals/'.$proposal['token'];
        $this->postJson($path.'/changes', ['positions' => [['index' => 1, 'comment' => 'Нет строки']]])
            ->assertUnprocessable()->assertJsonValidationErrors('positions');
        $this->postJson($path.'/changes', ['positions' => [['index' => 0, 'comment' => 'Уточните объём']]])
            ->assertOk()->assertJsonPath('status', 'changes')->assertJsonPath('line_feedback.0.index', 0);

        $response = $this->get($path.'/xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = tempnam(sys_get_temp_dir(), 'estimate-xlsx-');
        try {
            file_put_contents($file, $response->streamedContent());
            $book = (new Xlsx())->load($file);
            $this->assertSame(['Смета', 'Позиции', 'График платежей'], $book->getSheetNames());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheet(1)->getCell('B2')->getDataType());
            $this->assertSame('=2+2', $book->getSheet(1)->getCell('B2')->getValue());
            $this->assertEquals(5000, $book->getSheet(0)->getCell('B18')->getValue());
            $this->assertEquals(1500, $book->getSheet(2)->getCell('D2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    private function proposal(): array
    {
        return [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'discount' => 0,
            'lines' => [['name' => '=2+2', 'price' => '5000,00', 'quantity' => 1]],
            'payments' => [['label' => 'Аванс', 'percent' => 30, 'due_on' => '2026-08-05'],
                ['label' => 'Сдача', 'percent' => 70]],
        ];
    }
}
