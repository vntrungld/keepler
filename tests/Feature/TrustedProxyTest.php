<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Production terminates TLS at a load balancer and forwards plain HTTP to the
 * container, so the app only learns the original scheme from X-Forwarded-Proto.
 * Ignoring that header makes every generated URL http, and a browser then
 * blocks the stylesheet and module scripts on an https page as mixed content,
 * leaving a blank page.
 */
class TrustedProxyTest extends TestCase
{
    public function test_generates_https_urls_when_the_proxy_reports_an_https_request(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/')
            ->assertRedirect('https://localhost/login');
    }

    public function test_keeps_http_when_the_proxy_reports_a_plain_request(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'http')
            ->get('/')
            ->assertRedirect('http://localhost/login');
    }
}
