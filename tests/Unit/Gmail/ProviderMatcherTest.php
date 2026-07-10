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
}
