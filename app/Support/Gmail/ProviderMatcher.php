<?php

namespace App\Support\Gmail;

class ProviderMatcher
{
    /**
     * Return the provider key whose sender domain matches the From header's
     * host (exact host or a subdomain of it), or null if none match.
     *
     * When the sender is a known payment aggregator (e.g. Google Play, which
     * forwards receipts for many unrelated products), the real provider is
     * resolved by scanning the email body for a catalog provider's
     * `match_keywords`, falling back to sender-domain matching if nothing in
     * the body matches.
     */
    public static function match(string $from, string $subject, string $body = ''): ?string
    {
        if (self::isAggregator($from, $subject)) {
            $byBody = self::matchByBody($body);
            if ($byBody !== null) {
                return $byBody;
            }
        }

        return self::matchByDomain($from);
    }

    private static function isAggregator(string $from, string $subject): bool
    {
        $email = self::extractEmail($from);
        $aggregators = array_map('strtolower', config('providers._aggregators', []));

        if ($email !== null && in_array(strtolower($email), $aggregators, true)) {
            return true;
        }

        return str_contains(strtolower($subject), 'google play order receipt');
    }

    private static function matchByBody(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $bodyLower = strtolower($body);

        foreach (config('providers') as $key => $provider) {
            if (! is_array($provider) || $key === '_aggregators') {
                continue;
            }

            foreach ($provider['match_keywords'] ?? [] as $keyword) {
                if ($keyword !== '' && str_contains($bodyLower, strtolower($keyword))) {
                    return $key;
                }
            }
        }

        return null;
    }

    private static function matchByDomain(string $from): ?string
    {
        $host = self::extractHost($from);
        if ($host === null) {
            return null;
        }

        foreach (config('providers') as $key => $provider) {
            if (! is_array($provider) || $key === '_aggregators') {
                continue;
            }

            foreach ($provider['sender_domains'] ?? [] as $domain) {
                $domain = strtolower($domain);
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $key;
                }
            }
        }

        return null;
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
