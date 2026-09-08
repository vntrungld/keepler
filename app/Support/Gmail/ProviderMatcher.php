<?php

namespace App\Support\Gmail;

class ProviderMatcher
{
    /**
     * Return the provider key whose sender domain matches the From header's
     * host (exact host or a subdomain of it), or null if none match.
     *
     * A provider marked `requires_product_match` sells more than one thing, so
     * its sender domain alone proves nothing: every OpenAI email arrives from
     * openai.com whether it bills a ChatGPT Plus subscription or pay-as-you-go
     * API usage. Those providers must also name their product in the email.
     *
     * When the sender is a known payment aggregator (e.g. Google Play or
     * Stripe, which forward/process receipts for many unrelated merchants),
     * the real provider is resolved ONLY by scanning the email body for a
     * catalog provider's `match_keywords` — an aggregator receipt naming no
     * catalog product resolves to null rather than falling back to
     * sender-domain matching (that fallback is what mis-assigns unrelated
     * merchants, e.g. a Stripe-processed Runpod receipt, to whichever
     * catalog provider happens to share the aggregator's domain).
     */
    public static function match(string $from, string $subject, string $body = ''): ?string
    {
        if (self::isAggregator($from, $subject)) {
            return self::matchByBody($body);
        }

        return self::matchByDomain($from, $subject."\n".$body);
    }

    private static function isAggregator(string $from, string $subject): bool
    {
        if (str_contains(strtolower($subject), 'google play order receipt')) {
            return true;
        }

        $host = self::extractHost($from);
        $email = self::extractEmail($from);

        foreach (config('providers._aggregators', []) as $aggregator) {
            $aggregator = strtolower($aggregator);

            if (str_contains($aggregator, '@')) {
                if ($email !== null && strtolower($email) === $aggregator) {
                    return true;
                }

                continue;
            }

            if ($host !== null && ($host === $aggregator || str_ends_with($host, '.'.$aggregator))) {
                return true;
            }
        }

        return false;
    }

    private static function matchByBody(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        return self::best(fn (array $provider): ?int => self::productMatchLength($provider, $body));
    }

    private static function matchByDomain(string $from, string $text = ''): ?string
    {
        $host = self::extractHost($from);
        if ($host === null) {
            return null;
        }

        return self::best(function (array $provider) use ($host, $text): ?int {
            if (! self::sendsFrom($provider, $host)) {
                return null;
            }

            if (! ($provider['requires_product_match'] ?? false)) {
                // A single-product vendor's domain is proof enough, but it is
                // the least specific kind of evidence there is: any sibling
                // that actually names its product outranks it.
                return 0;
            }

            return self::productMatchLength($provider, $text);
        });
    }

    /**
     * Pick the provider the scorer rates highest, or null if it rates none.
     *
     * Scores are keyword lengths, so the most specific product wins: an Apple
     * receipt naming "apple music" (11) beats one merely naming "apple" (5).
     * Without this, the catalog's own order decided which of five apple.com
     * products a receipt belonged to. Ties keep catalog order.
     *
     * @param  \Closure(array<string,mixed>): ?int  $score
     */
    private static function best(\Closure $score): ?string
    {
        $bestKey = null;
        $bestScore = -1;

        foreach (config('providers') as $key => $provider) {
            if (! is_array($provider) || $key === '_aggregators') {
                continue;
            }

            $value = $score($provider);

            if ($value !== null && $value > $bestScore) {
                $bestKey = $key;
                $bestScore = $value;
            }
        }

        return $bestKey;
    }

    /** Whether $host is this provider's sender domain or a subdomain of it. */
    private static function sendsFrom(array $provider, string $host): bool
    {
        foreach ($provider['sender_domains'] ?? [] as $domain) {
            $domain = strtolower($domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Length of the longest `match_keywords` entry the text names, or null if
     * it names none — i.e. how specifically the text identifies this product.
     */
    private static function productMatchLength(array $provider, string $text): ?int
    {
        $textLower = strtolower($text);
        $longest = null;

        foreach ($provider['match_keywords'] ?? [] as $keyword) {
            if ($keyword === '' || ! str_contains($textLower, strtolower($keyword))) {
                continue;
            }

            $longest = max($longest ?? 0, strlen($keyword));
        }

        return $longest;
    }

    /**
     * Extract the raw "addr@domain" portion from a From header in either
     * "Name <addr@domain>" or bare "addr@domain" form; null if absent.
     */
    private static function extractEmail(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        $from = trim($from);

        return str_contains($from, '@') ? $from : null;
    }

    /**
     * Extract the lowercase host from a From header in either
     * "Name <addr@domain>" or bare "addr@domain" form; null if absent.
     */
    private static function extractHost(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        if (! str_contains($from, '@')) {
            return null;
        }

        $host = strtolower(trim(substr(strrchr($from, '@'), 1)));

        return $host === '' ? null : $host;
    }
}
