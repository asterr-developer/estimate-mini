<?php

namespace Database\Seeders;

use App\Http\Controllers\ProposalController;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortfolioExamplesSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('name', 'test')->get();
        if ($users->count() !== 1) {
            throw new \RuntimeException('Для загрузки примеров нужен один кабинет с именем test.');
        }
        $user = $users->first();
        DB::transaction(function () use ($user) {
            $owner = 'user:'.$user->id;
            $examples = [
                ['Студия Контур', 'Корпоративный сайт для Северных систем', '2026-08-05', '2026-08-26', 'accepted', '18000,44', 'added', 22],
                ['Бюро Маяк', 'Личный кабинет для Тихой гавани', '2026-08-14', '2026-09-04', 'withdrawn', '9500,50', 'none', null],
                ['Студия Контур', 'Каталог оборудования для Северных систем', '2026-08-21', '2026-09-18', 'expired', '24350,75', 'included', 22],
                ['Бюро Маяк', 'Обновление сайта мастерской Линия', '2026-09-09', null, 'changes', '12400,90', 'none', null],
                ['Студия Контур', 'Портал партнёров для Тихой гавани', '2026-10-02', null, 'pending', '16500,20', 'added', 22],
                ['Бюро Маяк', 'Обновление сайта мастерской Линия, версия 2', '2026-10-16', null, 'accepted', '10900,90', 'none', null],
                ['Студия Контур', 'Сайт для центра Берег', '2026-11-19', '2026-11-26', 'pending', '18700,44', 'added', 22],
                ['Бюро Маяк', 'Прототип формы записи для центра Берег', '2026-11-21', '2026-12-10', 'changes', '7900,35', 'none', null],
            ];
            $clientNames = ['ООО «Северные системы»', 'ООО «Тихая гавань»', 'Мастерская «Линия»', 'Центр «Берег»'];
            $cities = ['Тула', 'Калуга', 'Ярославль', 'Тверь'];
            $refs = [0, 1, 0, 2, 1, 2, 3, 3];
            $clients = [];
            foreach ($clientNames as $index => $name) {
                $clients[] = $this->record($user->id, 'clients', ['name' => $name, 'details' => 'г. '.$cities[$index]], '2026-08-03');
            }
            foreach ([
                ['Аудит интерфейса', 'час', '2850,50'], ['Проектирование экрана', 'экран', '7200,40'],
                ['Адаптивная вёрстка', 'страница', '9850,25'], ['Настройка аналитики', 'усл.', '6400,75'],
                ['Подготовка инструкции', 'комплект', '3150,15'],
            ] as [$name, $unit, $price]) {
                $this->record($user->id, 'services', compact('name', 'unit', 'price'), '2026-08-04');
            }

            $controller = new ProposalController();
            $prior = null;
            foreach ($examples as $index => [$sender, $title, $day, $validUntil, $state, $price, $vatMode, $vatRate]) {
                $existing = DB::table('proposals')->where('owner', $owner)->where('snapshot->title', $title)
                    ->where('created_at', $day.' 10:00:00')->first();
                if ($existing) {
                    if ($index === 3) {
                        $prior = $existing->id;
                    }
                    continue;
                }

                $at = Carbon::parse($day.' 10:00:00', 'UTC');
                $clientIndex = $refs[$index];
                $payload = $this->draft($title, $day, $price) + [
                    'sender' => $sender,
                    'client' => $clientNames[$clientIndex],
                    'sender_details' => 'г. Москва',
                    'client_details' => 'г. '.$cities[$clientIndex],
                    'client_record_id' => $clients[$clientIndex],
                    'valid_until' => $validUntil,
                    'basis' => 'Техническое задание № '.(26 + $index),
                    'vat_mode' => $vatMode,
                    'vat_rate' => $vatRate,
                    'revision_of' => $index === 5 ? $prior : null,
                ];
                $request = Request::create('/api/proposals', 'POST', $payload);
                $request->setUserResolver(fn () => $user);
                $previousClock = Carbon::getTestNow();
                Carbon::setTestNow($at);
                try {
                    $id = $controller->store($request)->getData()->id;
                } finally {
                    Carbon::setTestNow($previousClock);
                }

                $eventAt = $at->copy()->addDays($index === 7 ? 2 : 4);
                $updated = match ($state) {
                    'accepted' => ['accepted_at' => $eventAt, 'accepted_by' => $index === 5 ? 'Марина Орлова' : 'Анна Морозова'],
                    'withdrawn' => ['withdrawn_at' => $eventAt],
                    'changes' => ['revision_requested_at' => $eventAt, 'revision_comment' => $index === 7
                        ? 'Уточните, входит ли мобильная версия формы в стоимость.'
                        : 'Пересчитайте стоимость проектирования интерфейса и пришлите обновлённую смету.'],
                    default => [],
                };
                if ($updated) {
                    DB::table('proposals')->where('id', $id)->update($updated);
                }
                if ($index === 3) {
                    $prior = $id;
                }
            }

            foreach ([
                ['План интеграции для Северных систем', '2026-08-27', '12500,45', 0],
                ['Ревизия формы заказа для Тихой гавани', '2026-10-12', '15900,80', 1],
                ['Расширение формы записи для центра Берег', '2026-11-22', '8450,10', 3],
            ] as [$title, $day, $price, $client]) {
                $this->record($user->id, 'drafts', $this->draft($title, $day, $price) + [
                    'sender' => 'Студия Контур', 'client' => $clientNames[$client],
                    'client_details' => 'г. '.$cities[$client], 'client_record_id' => $clients[$client],
                    'vat_mode' => 'added', 'vat_rate' => 22,
                ], $day);
            }
            foreach ([
                ['Сайт компании', '2026-08-10', '18000,44'],
                ['Аудит и улучшение формы', '2026-09-18', '8500,75'],
            ] as [$name, $day, $price]) {
                $this->record($user->id, 'templates', $this->draft($name, $day, $price) + [
                    'template_name' => $name, 'sender' => 'Студия Контур', 'vat_mode' => 'added', 'vat_rate' => 22,
                ], $day);
            }
        });
    }

    private function draft(string $title, string $day, string $price): array
    {
        $start = Carbon::parse($day)->addDays(3);

        return [
            'title' => $title, 'discount' => 5, 'price_type' => 'fixed',
            'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addDays(21)->toDateString(),
            'stages' => [
                ['key' => 'design', 'name' => 'Проектирование', 'due_on' => $start->copy()->addDays(8)->toDateString()],
                ['key' => 'delivery', 'name' => 'Подготовка к запуску', 'due_on' => $start->copy()->addDays(21)->toDateString()],
            ],
            'lines' => [
                ['name' => 'Проектирование интерфейса', 'unit' => 'экран', 'price' => $price, 'quantity' => 2, 'stage' => 'design'],
                ['name' => 'Адаптивная вёрстка', 'unit' => 'страница', 'price' => '9850,25', 'quantity' => 2, 'stage' => 'delivery'],
                ['name' => 'Подготовка инструкции', 'unit' => 'комплект', 'price' => '3150,15', 'quantity' => 1, 'stage' => 'delivery'],
            ],
            'payments' => [['label' => 'Аванс после согласования', 'percent' => 30],
                ['label' => 'После согласования макетов', 'percent' => 40],
                ['label' => 'После сдачи работ', 'percent' => 30]],
            'notes' => 'В стоимость входят два раунда правок макетов и передача исходных файлов.',
        ];
    }

    private function record(int $user, string $kind, array $payload, string $day): int
    {
        $key = match ($kind) {
            'drafts' => 'title',
            'templates' => 'template_name',
            default => 'name',
        };
        $existing = DB::table('workspace_records')->where('user_id', $user)->where('kind', $kind)
            ->where('payload->'.$key, $payload[$key])->value('id');

        return $existing ?? DB::table('workspace_records')->insertGetId([
            'user_id' => $user, 'kind' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $day.' 10:00:00',
        ]);
    }
}
