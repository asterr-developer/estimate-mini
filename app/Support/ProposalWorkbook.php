<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ProposalWorkbook
{
    public function stream(array $proposal): void
    {
        $data = $proposal['snapshot'];
        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('Смета');
        $this->rows($summary, [
            ['Реквизит', 'Значение'],
            ['Номер', 'КП-'.str_pad((string) $proposal['id'], 4, '0', STR_PAD_LEFT)],
            ['Проект', $data['title']],
            ['Подрядчик', $data['sender']],
            ['Реквизиты подрядчика', $data['sender_details'] ?? ''],
            ['Контакты подрядчика', $data['sender_contact'] ?? ''],
            ['Заказчик', $data['client']],
            ['Реквизиты заказчика', $data['client_details'] ?? ''],
            ['Основание', $data['basis'] ?? ''],
            ['Начало работ', $data['starts_on'] ?? ''],
            ['Окончание работ', $data['ends_on'] ?? ''],
            ['Подытог, руб.', $data['subtotal'] / 100],
            ['Скидка, руб.', $data['discount_amount'] / 100],
            ['Режим НДС', match ($data['vat_mode'] ?? null) {
                'added' => 'НДС сверху', 'included' => 'НДС включён', 'none' => 'Без НДС', default => 'Не указан',
            }],
            ['Ставка НДС', isset($data['vat_rate']) ? $data['vat_rate'].'%' : ''],
            ['База без НДС, руб.', isset($data['vat_base']) ? $data['vat_base'] / 100 : ''],
            ['НДС, руб.', isset($data['vat_amount']) ? $data['vat_amount'] / 100 : ''],
            ['Итого, руб.', $data['total'] / 100],
            ['Условия', $data['notes'] ?? ''],
        ], [29, 76]);
        foreach (['B12:B13', 'B16:B18'] as $range) {
            $summary->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $summary->getStyle('A18:B18')->getFont()->setBold(true);

        $positions = $book->createSheet()->setTitle('Позиции');
        $stageNames = array_column($data['stages'] ?? [], 'name', 'key');
        $positionRows = [['№', 'Работа', 'Этап', 'Единица', 'Количество', 'Цена, руб.', 'Сумма, руб.']];
        foreach ($data['lines'] as $index => $line) {
            $positionRows[] = [$index + 1, $line['name'], $stageNames[$line['stage'] ?? ''] ?? '',
                $line['unit'] ?? 'ед.', $line['quantity'], ($line['price_cents'] ?? $line['price'] * 100) / 100, $line['amount'] / 100];
        }
        $this->rows($positions, $positionRows, [7, 47, 28, 13, 15, 18, 20]);
        $positions->setAutoFilter('A1:G'.count($positionRows));
        $positions->getStyle('F2:G'.count($positionRows))->getNumberFormat()->setFormatCode('#,##0.00');

        $payments = $book->createSheet()->setTitle('График платежей');
        $paymentRows = [['Условие', 'Дата по графику', 'Доля', 'Сумма, руб.']];
        $schedule = $data['payments'] ?? [];
        if ($schedule === [] && !empty($data['advance_percent'])) {
            $schedule = [
                ['label' => 'Аванс по договору', 'percent' => $data['advance_percent'], 'amount' => $data['advance']],
                ['label' => 'Остаток', 'percent' => 100 - $data['advance_percent'], 'amount' => $data['balance']],
            ];
        }
        foreach ($schedule as $payment) {
            $paymentRows[] = [$payment['label'], $payment['due_on'] ?? '', $payment['percent'].'%', $payment['amount'] / 100];
        }
        $this->rows($payments, $paymentRows, [49, 21, 12, 19]);
        if (count($paymentRows) > 1) {
            $payments->getStyle('D2:D'.count($paymentRows))->getNumberFormat()->setFormatCode('#,##0.00');
        }
        (new Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();
    }

    private function rows(Worksheet $sheet, array $rows, array $widths): void
    {
        foreach ($rows as $index => $cells) {
            foreach ($cells as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 1], $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $sheet->freezePane('A2');
        $end = chr(64 + count($widths));
        $header = $sheet->getStyle('A1:'.$end.'1');
        $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF235E4F');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1:'.$end.count($rows))->getAlignment()->setWrapText(true)->setVertical('top');
    }
}
