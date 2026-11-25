<?php

namespace App\Support;

use Closure;

final class Money
{
    public static function cents(int|float|string|null $value): ?int
    {
        $text = preg_replace('/[ \x{00A0}\x{202F}]/u', '', trim((string) $value));
        if (!is_string($text) || !preg_match('/^([0-9]{1,8})(?:[,.]([0-9]{1,2}))?$/D', $text, $parts)) {
            return null;
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        return $cents <= 1000000000 ? $cents : null;
    }

    public static function priceRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || self::cents($value) === null) {
                $fail('Укажите цену до 10 000 000 руб. с точностью до двух знаков после запятой.');
            }
        };
    }

    public static function ratio(int $amount, int $numerator, int $denominator = 100): int
    {
        return intdiv($amount * $numerator + intdiv($denominator, 2), $denominator);
    }
}
