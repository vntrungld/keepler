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
}
