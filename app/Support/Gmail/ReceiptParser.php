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
    public static function parse(array $provider, string $subject, string $body, string $emailDate): array
    {
        $text = strtolower($subject."\n".$body);

        $intent = self::matchesAny($text, $provider['cancellation_keywords'] ?? [])
            ? 'cancellation'
            : 'payment';

        $amount = null;
        if (preg_match($provider['amount_regex'], $subject."\n".$body, $m)) {
            $amount = self::normalizeAmount($m[1]);
        }

        $currency = $amount !== null ? ($provider['default_currency'] ?? null) : null;

        $isYearly = self::matchesAny($text, ['year', 'yearly', 'annual', 'annually', 'năm']);
        $cycle = $isYearly ? 'yearly' : ($provider['default_cycle'] ?? 'monthly');

        $renewal = null;
        if ($intent === 'payment') {
            $base = Carbon::parse($emailDate);
            $renewal = ($cycle === 'yearly' ? $base->copy()->addYear() : $base->copy()->addMonth())
                ->toDateString();
        }

        return [
            'intent' => $intent,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'next_renewal_date' => $renewal,
            'confidence' => [
                'amount' => $amount !== null,
                'renewal' => $renewal !== null,
            ],
        ];
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
