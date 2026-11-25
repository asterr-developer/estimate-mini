<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cents_vat_and_payments_match_between_review_and_issue(): void
    {
        $data = [
            'title' => 'Сайт', 'sender' => 'Студия', 'client' => 'Компания', 'discount' => 0,
            'vat_mode' => 'added', 'vat_rate' => 22,
            'lines' => [['name' => 'Дизайн', 'price' => '18 000,44', 'quantity' => 1]],
            'payments' => [['label' => 'Аванс', 'percent' => 30], ['label' => 'Сдача', 'percent' => 40], ['label' => 'Запуск', 'percent' => 30]],
        ];
        $review = $this->postJson('/api/review', $data)->assertOk()
            ->assertJsonPath('snapshot.lines.0.price_cents', 1800044)->assertJsonPath('snapshot.vat_amount', 396010)
            ->assertJsonPath('snapshot.total', 2196054)->json('snapshot');
        $this->assertSame(2196054, array_sum(array_column($review['payments'], 'amount')));
        $issued = $this->postJson('/api/proposals', $data)->assertCreated()->json();
        $this->assertSame($review, $issued['snapshot']);
        $this->get('/api/proposals/'.$issued['token'].'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/review', array_replace($data, ['vat_mode' => 'included']))->assertOk()
            ->assertJsonPath('snapshot.total', 1800044)->assertJsonPath('snapshot.vat_amount', 324598);
        $this->postJson('/api/review', array_replace($data, ['vat_rate' => 0]))->assertOk()
            ->assertJsonPath('snapshot.vat_rate', 0)->assertJsonPath('snapshot.vat_amount', 0);
        $this->postJson('/api/review', array_replace($data, ['vat_mode' => 'none']))->assertOk()
            ->assertJsonPath('snapshot.vat_rate', null);
        $this->postJson('/api/review', array_replace($data, ['payments' => [['label' => 'Аванс', 'percent' => 99]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->postJson('/api/review', array_replace($data, ['vat_rate' => null]))->assertUnprocessable()
            ->assertJsonValidationErrors('vat_rate');
    }

    public function test_import_reports_bad_rows_and_accepts_excel_format(): void
    {
        $csv = "\xEF\xBB\xBFНаименование;Единица;Количество;Цена\r\n\"Дизайн; макеты\";час;2;18000,44\r\nТест;час;-1;100\r\n";
        $this->post('/api/import-positions', ['file' => UploadedFile::fake()->createWithContent('items.csv', $csv)], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('lines.0.name', 'Дизайн; макеты')->assertJsonPath('lines.0.price', '18000,44')
            ->assertJsonPath('errors.0', 'Строка 3: проверьте название, количество, единицу и цену.');
        $this->actingAs(User::factory()->create())->postJson('/api/workspace/services', [
            'name' => 'Дизайн', 'unit' => 'час', 'price' => '18000,44',
        ])->assertOk()->assertJsonPath('data.price', '18000,44');
    }
}
