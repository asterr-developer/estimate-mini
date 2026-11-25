<?php

namespace App\Http\Controllers;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProposalImportController extends Controller
{
    public function __invoke(Request $request): array
    {
        $file = $request->validate(['file' => 'required|file|max:128'])['file'];
        $source = $file->getContent();
        if (str_contains($source, "\0")) {
            throw ValidationException::withMessages(['file' => 'Сохраните файл как CSV в UTF-8 или Windows-1251.']);
        }
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        if (!mb_check_encoding($source, 'UTF-8')) {
            $source = mb_convert_encoding($source, 'UTF-8', 'Windows-1251');
        }

        $columns = ['наименование' => 'name', 'название' => 'name', 'единица' => 'unit', 'ед.' => 'unit',
            'количество' => 'quantity', 'кол-во' => 'quantity', 'цена' => 'price', 'цена, ₽' => 'price'];
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $source);
        try {
            $header = null;
            foreach ([';', ',', "\t"] as $separator) {
                rewind($stream);
                $cells = fgetcsv($stream, separator: $separator, escape: '');
                $fields = array_map(fn ($cell) => $columns[mb_strtolower(trim($cell ?? ''))] ?? null, $cells ?: []);
                if (count($fields) === 4 && count(array_unique(array_filter($fields))) === 4) {
                    $header = $fields;
                    break;
                }
            }
            if ($header === null) {
                throw ValidationException::withMessages(['file' => 'В первой строке укажите: Наименование;Единица;Количество;Цена']);
            }

            $lines = $errors = [];
            $lineNumber = 2;
            $read = 0;
            while (($row = fgetcsv($stream, separator: $separator, escape: '')) !== false) {
                $current = $lineNumber;
                $lineNumber += 1 + array_sum(array_map(fn ($cell) => substr_count($cell ?? '', "\n"), $row));
                if ($row === [null]) {
                    continue;
                }
                if (++$read > 30) {
                    $errors[] = 'Строка '.$current.': файл содержит больше 30 позиций.';
                    break;
                }
                if (count($row) !== 4) {
                    $errors[] = "Строка $current: ожидается 4 столбца.";
                    continue;
                }

                $data = array_combine($header, array_map(fn ($value) => trim($value ?? ''), $row));
                if ($data['name'] === '' || mb_strlen($data['name']) > 120 || mb_strlen($data['unit']) > 16
                    || !preg_match('/^[1-9][0-9]{0,2}$/D', $data['quantity']) || Money::cents($data['price']) === null) {
                    $errors[] = "Строка $current: проверьте название, количество, единицу и цену.";
                    continue;
                }
                $data['quantity'] = (int) $data['quantity'];
                $data['unit'] = $data['unit'] ?: 'ед.';
                $lines[] = $data;
            }

            return ['lines' => $lines, 'errors' => $errors];
        } finally {
            fclose($stream);
        }
    }
}
