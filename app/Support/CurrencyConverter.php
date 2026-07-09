<?php

namespace App\Support;

use InvalidArgumentException;

class CurrencyConverter
{
    /**
     * Quy đổi số tiền từ một loại tiền sang VND (làm tròn số nguyên đồng).
     */
    public static function toVnd(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $rates = config('currency.rates', []);

        if (! array_key_exists($currency, $rates)) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}");
        }

        return (int) round($amount * $rates[$currency]);
    }

    /**
     * Danh sách mã tiền được hỗ trợ (dùng cho validation).
     *
     * @return array<int, string>
     */
    public static function supportedCurrencies(): array
    {
        return array_keys(config('currency.rates', []));
    }
}
