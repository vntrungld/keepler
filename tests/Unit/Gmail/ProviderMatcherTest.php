<?php

namespace Tests\Unit\Gmail;

use App\Support\Gmail\ProviderMatcher;
use Tests\TestCase;

class ProviderMatcherTest extends TestCase
{
    public function test_matches_by_sender_domain_case_insensitively(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('info@members.netflix.com', 'Your receipt'));
        $this->assertSame('netflix', ProviderMatcher::match('INFO@NETFLIX.COM', 'RECEIPT'));
        $this->assertSame('spotify', ProviderMatcher::match('no-reply@spotify.com', 'Payment'));
    }

    public function test_returns_null_when_no_domain_matches(): void
    {
        $this->assertNull(ProviderMatcher::match('billing@randomservice.io', 'Your invoice'));
    }

    public function test_extracts_domain_from_display_name_form(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('Netflix <info@netflix.com>', 'Receipt'));
    }

    public function test_does_not_match_lookalike_or_unrelated_domains(): void
    {
        $this->assertNull(ProviderMatcher::match('billing@notnetflix.com', 'Receipt'));
        $this->assertNull(ProviderMatcher::match('promo@evilgoogle.com', 'Receipt'));
        $this->assertNull(ProviderMatcher::match('noreply@fake-adobe.com', 'Invoice'));
        $this->assertNull(ProviderMatcher::match('spoof@netflix.com.evil.com', 'Receipt'));
    }

    public function test_matches_legitimate_subdomains(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('info@mailer.members.netflix.com', 'Receipt'));
    }

    public function test_google_dot_com_resolves_to_google_not_youtube(): void
    {
        $this->assertSame('google', ProviderMatcher::match('no-reply@google.com', 'Google One receipt'));
    }

    public function test_aggregator_resolves_provider_from_body(): void
    {
        $this->assertSame('google', ProviderMatcher::match(
            'googleplay-noreply@google.com',
            'Your Google Play Order Receipt from Jun 28, 2026',
            'Google AI Plus (400 GB) (Google One) (by Google LLC) 66.000 ₫/month',
        ));
    }

    public function test_aggregator_resolves_a_different_product_from_body(): void
    {
        $this->assertSame('spotify', ProviderMatcher::match(
            'googleplay-noreply@google.com',
            'Your Google Play Order Receipt from Jun 28, 2026',
            'Spotify Premium (by Spotify AB) 59.000 ₫/month',
        ));
    }

    public function test_aggregator_resolves_to_null_when_body_matches_nothing(): void
    {
        // Previously fell back to sender-domain matching (resolving to
        // 'google' since the aggregator sender is google.com), but that
        // fallback is precisely what mis-assigned unrelated-merchant
        // aggregator receipts (e.g. Stripe/Runpod) to unrelated catalog
        // providers (e.g. chatgpt). Aggregator senders now resolve by body
        // only; an aggregator receipt naming no catalog product is skipped.
        $this->assertNull(ProviderMatcher::match(
            'googleplay-noreply@google.com',
            'Your Google Play Order Receipt from Jun 28, 2026',
            'Some unrecognized product name that matches no catalog entry.',
        ));
    }

    public function test_non_aggregator_matching_is_unchanged(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('info@netflix.com', 'Receipt'));
        $this->assertNull(ProviderMatcher::match('billing@notnetflix.com', 'Receipt'));
    }

    public function test_stripe_receipt_for_an_unknown_merchant_is_skipped(): void
    {
        $this->assertNull(ProviderMatcher::match(
            'receipts+acct_1KLZG6LeeX2jf1uK@stripe.com',
            'Your Runpod receipt [#1857-3148]',
            'Receipt from Runpod. Payment to Runpod $50.00. partners with Stripe to provide invoicing.',
        ));
    }

    public function test_stripe_receipt_for_a_catalog_provider_resolves_by_body(): void
    {
        $this->assertSame('chatgpt', ProviderMatcher::match(
            'receipts+acct_ABC@stripe.com',
            'Your receipt',
            'Payment to OpenAI — ChatGPT Plus subscription $20.00',
        ));
    }

    public function test_openai_domain_sender_still_maps_to_chatgpt(): void
    {
        $this->assertSame('chatgpt', ProviderMatcher::match(
            'noreply@openai.com',
            'Your ChatGPT receipt',
            'chatgpt plus',
        ));
    }

    public function test_an_openai_api_invoice_is_not_treated_as_a_chatgpt_subscription(): void
    {
        $key = ProviderMatcher::match(
            'OpenAI <invoice+statements@stripe.com>',
            'Your invoice from OpenAI',
            "Invoice from OpenAI\nAPI usage - gpt-5\nTotal $0.20",
        );

        $this->assertNull($key);
    }

    public function test_a_chatgpt_plus_receipt_through_stripe_still_matches(): void
    {
        $key = ProviderMatcher::match(
            'OpenAI <invoice+statements@stripe.com>',
            'Your receipt from OpenAI',
            "Receipt from OpenAI\nChatGPT Plus subscription\nTotal $20.00",
        );

        $this->assertSame('chatgpt', $key);
    }

    public function test_an_openai_api_invoice_from_openais_own_domain_is_not_a_chatgpt_subscription(): void
    {
        $key = ProviderMatcher::match(
            'OpenAI <noreply@email.openai.com>',
            'Your OpenAI invoice',
            "Invoice from OpenAI\nAPI usage - gpt-5\nTotal $0.20",
        );

        $this->assertNull($key);
    }

    public function test_a_chatgpt_plus_receipt_from_openais_own_domain_still_matches(): void
    {
        $key = ProviderMatcher::match(
            'OpenAI <noreply@email.openai.com>',
            'Your receipt from OpenAI',
            "Receipt\nChatGPT Plus subscription\nTotal $20.00",
        );

        $this->assertSame('chatgpt', $key);
    }

    public function test_a_single_product_vendor_still_matches_on_sender_domain_alone(): void
    {
        $key = ProviderMatcher::match('Netflix <info@netflix.com>', 'Your receipt', 'Thanks for your payment.');

        $this->assertSame('netflix', $key);
    }

    public function test_apple_music_receipt_does_not_resolve_to_apple_one(): void
    {
        $key = ProviderMatcher::match(
            'Apple <no_reply@email.apple.com>',
            'Your receipt from Apple',
            "Apple Music Individual\nMonthly\n$10.99",
        );

        $this->assertSame('applemusic', $key);
    }

    public function test_apple_tv_and_arcade_resolve_to_their_own_products(): void
    {
        $this->assertSame('appletv', ProviderMatcher::match(
            'Apple <no_reply@email.apple.com>',
            'Your receipt from Apple',
            "Apple TV+\nMonthly\n$12.99",
        ));

        $this->assertSame('applearcade', ProviderMatcher::match(
            'Apple <no_reply@email.apple.com>',
            'Your receipt from Apple',
            "Apple Arcade\nMonthly\n$6.99",
        ));
    }

    public function test_a_bare_apple_receipt_naming_no_product_is_skipped(): void
    {
        // apple.com bills five different catalog products plus one-off App
        // Store purchases, so the domain alone proves nothing.
        $this->assertNull(ProviderMatcher::match(
            'Apple <no_reply@email.apple.com>',
            'Your receipt from Apple',
            "Some Game Coins Pack\n$4.99",
        ));
    }

    public function test_youtube_music_and_tv_do_not_resolve_to_youtube_premium(): void
    {
        $this->assertSame('youtubemusic', ProviderMatcher::match(
            'YouTube <noreply@youtube.com>',
            'Your YouTube Music receipt',
            'YouTube Music Premium $11.99',
        ));

        $this->assertSame('youtubetv', ProviderMatcher::match(
            'YouTube <noreply@youtube.com>',
            'Your YouTube TV receipt',
            'YouTube TV Base Plan $82.99',
        ));
    }

    public function test_google_workspace_does_not_resolve_to_google_one(): void
    {
        $this->assertSame('googleworkspace', ProviderMatcher::match(
            'no-reply@google.com',
            'Your Google Workspace invoice',
            'Google Workspace Business Starter $14.00',
        ));
    }

    public function test_longest_keyword_wins_over_catalog_order_via_an_aggregator(): void
    {
        // Both `youtube` ("youtube") and `youtubemusic` ("youtube music")
        // match this body. The more specific product must win regardless of
        // which entry the catalog happens to list first.
        $this->assertSame('youtubemusic', ProviderMatcher::match(
            'googleplay-noreply@google.com',
            'Your Google Play Order Receipt from Jun 28, 2026',
            'YouTube Music Premium (by Google LLC) 59.000 \u20ab/month',
        ));
    }

    public function test_every_catalog_entry_is_well_formed(): void
    {
        foreach (config('providers') as $key => $provider) {
            if ($key === '_aggregators') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $key, "catalog key `{$key}` must be lowercase alphanumeric");
            $this->assertNotEmpty($provider['name'] ?? null, "`{$key}` is missing a name");
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $provider['color'] ?? '', "`{$key}` needs an uppercase 6-digit hex color");
            $this->assertNotEmpty($provider['domain'] ?? null, "`{$key}` is missing a domain for the favicon fallback");
            $this->assertNotEmpty($provider['sender_domains'] ?? [], "`{$key}` is missing sender_domains");
            $this->assertNotEmpty($provider['match_keywords'] ?? [], "`{$key}` is missing match_keywords");
            $this->assertNotEmpty($provider['payment_keywords'] ?? [], "`{$key}` is missing payment_keywords");
            $this->assertNotEmpty($provider['cancellation_keywords'] ?? [], "`{$key}` is missing cancellation_keywords");
            $this->assertContains($provider['default_cycle'] ?? null, ['monthly', 'yearly'], "`{$key}` has a bad default_cycle");
            $this->assertNotEmpty($provider['cancel_url'] ?? null, "`{$key}` is missing a cancel_url");
        }
    }

    public function test_providers_sharing_a_sender_domain_require_a_product_match(): void
    {
        $byDomain = [];
        foreach (config('providers') as $key => $provider) {
            if ($key === '_aggregators') {
                continue;
            }
            foreach ($provider['sender_domains'] as $domain) {
                $byDomain[$domain][] = $key;
            }
        }

        foreach ($byDomain as $domain => $keys) {
            if (count($keys) < 2) {
                continue;
            }

            foreach ($keys as $key) {
                $this->assertTrue(
                    config("providers.{$key}.requires_product_match") === true,
                    "`{$key}` shares sender domain `{$domain}` with ".(count($keys) - 1).' other entr(y|ies), so it must set requires_product_match',
                );
            }
        }
    }
}
