<?php

namespace App\Support\Gmail;

use Illuminate\Support\Carbon;

class ReceiptParser
{
    /**
     * Best-effort extraction of a subscription candidate's fields from one email.
     *
     * @param  array  $provider  one catalog entry from config('providers')
     * @return array{intent:string,amount:?float,currency:?string,billing_cycle:string,next_renewal_date:?string,confidence:array<string,bool>}
     */
    /** Receipt keywords (English + Vietnamese) that signal a real payment email. */
    private const RECEIPT_KEYWORDS = [
        'receipt', 'order', 'invoice', 'charged', 'payment',
        'hóa đơn', 'biên nhận', 'đã thanh toán', 'thanh toán',
    ];

    public static function parse(array $provider, string $subject, string $body, string $emailDate): array
    {
        $text = strtolower($subject."\n".$body);

        $intent = self::matchesAny($text, $provider['cancellation_keywords'] ?? [])
            ? 'cancellation'
            : 'payment';

        [$amount, $currency] = self::extractAmount($subject."\n".$body, $provider);

        $isYearly = self::matchesAny($text, ['year', 'yearly', 'annual', 'annually', 'năm']);
        $cycle = $isYearly ? 'yearly' : ($provider['default_cycle'] ?? 'monthly');

        $renewal = null;
        if ($intent === 'payment') {
            $base = Carbon::parse($emailDate);
            $renewal = ($cycle === 'yearly' ? $base->copy()->addYear() : $base->copy()->addMonth())
                ->toDateString();
        }

        $isReceipt = $intent === 'cancellation'
            || $amount !== null
            || self::matchesAny($text, self::RECEIPT_KEYWORDS);

        return [
            'intent' => $intent,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'next_renewal_date' => $renewal,
            'is_receipt' => $isReceipt,
            'confidence' => [
                'amount' => $amount !== null,
                'renewal' => $renewal !== null,
            ],
        ];
    }

    /**
     * Extract an amount + currency from free-form subject/body text.
     *
     * Supports USD ($12.99, US$12.99) and VND (66.000 ₫, ₫66.000, 73.333 đ,
     * 66.000 VND, 66.000 đồng — symbol/suffix before or after the number).
     * If a line contains "total"/"tổng" and has its own amount match, that
     * amount wins (it reflects tax-inclusive total charged); otherwise the
     * first amount found in the text is used.
     *
     * Currency is derived from the matched symbol. A bare number with no
     * recognized currency symbol/suffix falls back to the provider's
     * `default_currency`.
     *
     * @return array{0:?float,1:?string}
     */
    private static function extractAmount(string $text, array $provider): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];

        $totalMatch = null;
        $firstMatch = null;

        foreach ($lines as $line) {
            $match = self::matchAmountInLine($line);
            if ($match === null) {
                continue;
            }

            if ($firstMatch === null) {
                $firstMatch = $match;
            }

            if ($totalMatch === null && preg_match('/total|tổng/i', $line)) {
                $totalMatch = $match;
            }
        }

        $best = $totalMatch ?? $firstMatch;
        if ($best === null) {
            return [null, null];
        }

        [$rawAmount, $symbol] = $best;
        $amount = self::normalizeAmount($rawAmount);
        if ($amount === null) {
            return [null, null];
        }

        $currency = match ($symbol) {
            'USD' => 'USD',
            'VND' => 'VND',
            default => $provider['default_currency'] ?? null,
        };

        return [$amount, $currency];
    }

    /**
     * Find the first currency amount in a single line of text.
     *
     * @return ?array{0:string,1:?string} [raw number string, currency symbol or null]
     */
    private static function matchAmountInLine(string $line): ?array
    {
        $patterns = [
            'USD' => '/(?:US)?\$\s?([0-9][0-9.,]*)/i',
            'VND' => '/(?:₫|đ(?:ồng)?|VND)\s?([0-9][0-9.,]*)|([0-9][0-9.,]*)\s?(?:₫|đ(?:ồng)?\b|VND\b)/iu',
        ];

        foreach ($patterns as $currency => $pattern) {
            if (preg_match($pattern, $line, $m)) {
                $raw = '';
                for ($i = 1; $i < count($m); $i++) {
                    if ($m[$i] !== '') {
                        $raw = $m[$i];
                        break;
                    }
                }
                if ($raw !== '') {
                    return [$raw, $currency];
                }
            }
        }

        return null;
    }

    /**
     * Parse a human-formatted money string into a float, or null if unparseable.
     * Handles "12.99", "1,234.56", "260.000" (thousands), "9,99" (comma decimal).
     */
    public static function normalizeAmount(string $raw): ?float
    {
        $s = preg_replace('/[^0-9.,]/', '', $raw);
        if ($s === '' || $s === null) {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // The separator that appears last is the decimal separator.
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace($thousands, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($hasComma) {
            // Comma decimal only if exactly two digits follow the last comma.
            $s = preg_match('/,\d{2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($hasDot) {
            // Dot is thousands if 3 digits follow and none are a 2-digit cents tail.
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private static function matchesAny(string $haystackLower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystackLower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
