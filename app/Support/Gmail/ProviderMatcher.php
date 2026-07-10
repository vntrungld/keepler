<?php

namespace App\Support\Gmail;

class ProviderMatcher
{
    /**
     * Return the provider key whose sender domain appears in the From header,
     * or null if none match.
     */
    public static function match(string $from, string $subject): ?string
    {
        $haystack = strtolower($from);

        foreach (config('providers') as $key => $provider) {
            foreach ($provider['sender_domains'] as $domain) {
                if (str_contains($haystack, '@'.strtolower($domain))
                    || str_contains($haystack, '.'.strtolower($domain))
                    || str_contains($haystack, strtolower($domain).'>')
                    || str_ends_with($haystack, strtolower($domain))) {
                    return $key;
                }
            }
        }

        return null;
    }
}
