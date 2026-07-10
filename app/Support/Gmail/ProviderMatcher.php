<?php

namespace App\Support\Gmail;

class ProviderMatcher
{
    /**
     * Return the provider key whose sender domain matches the From header's
     * host (exact host or a subdomain of it), or null if none match.
     */
    public static function match(string $from, string $subject): ?string
    {
        $host = self::extractHost($from);
        if ($host === null) {
            return null;
        }

        foreach (config('providers') as $key => $provider) {
            foreach ($provider['sender_domains'] as $domain) {
                $domain = strtolower($domain);
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $key;
                }
            }
        }

        return null;
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
