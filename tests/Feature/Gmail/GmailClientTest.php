<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use App\Support\Gmail\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailClientTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'valid-access',
            'gmail_refresh_token' => 'refresh-token',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        return $user->fresh();
    }

    public function test_list_message_ids_returns_ids(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [['id' => 'a1'], ['id' => 'b2']],
            ]),
        ]);

        $ids = (new GmailClient($this->connectedUser()))->listMessageIds('from:(netflix.com)', 50);

        $this->assertSame(['a1', 'b2'], $ids);
    }

    public function test_get_message_decodes_headers_and_body(): void
    {
        $body = rtrim(strtr(base64_encode("Amount charged: \$12.99"), '+/', '-_'), '=');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/a1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@netflix.com'],
                        ['name' => 'Subject', 'value' => 'Your receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $body],
                ],
            ]),
        ]);

        $msg = (new GmailClient($this->connectedUser()))->getMessage('a1');

        $this->assertSame('info@netflix.com', $msg['from']);
        $this->assertSame('Your receipt', $msg['subject']);
        $this->assertSame('2026-07-01', $msg['date']);
        $this->assertStringContainsString('12.99', $msg['body']);
    }

    public function test_refreshes_expired_access_token_before_calling(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'stale',
            'gmail_refresh_token' => 'refresh-token',
            'gmail_token_expires_at' => now()->subMinute(), // expired
        ])->save();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access', 'expires_in' => 3600,
            ]),
            'gmail.googleapis.com/*' => Http::response(['messages' => []]),
        ]);

        (new GmailClient($user->fresh()))->listMessageIds('from:(netflix.com)');

        $this->assertSame('fresh-access', $user->fresh()->gmail_access_token);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_throws_on_api_error(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'boom'], 401)]);

        $this->expectException(\RuntimeException::class);
        (new GmailClient($this->connectedUser()))->listMessageIds('from:(netflix.com)');
    }

    public function test_valid_token_is_reused_without_a_refresh_call(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'should-not-be-used', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::response(['messages' => []]),
        ]);

        (new GmailClient($this->connectedUser()))->listMessageIds('from:(netflix.com)');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_get_message_prefers_text_plain_from_a_multipart_body(): void
    {
        $plain = rtrim(strtr(base64_encode('Amount charged: $9.99'), '+/', '-_'), '=');
        $html = rtrim(strtr(base64_encode('<p>ignored html</p>'), '+/', '-_'), '=');

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/mp1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@spotify.com'],
                        ['name' => 'Subject', 'value' => 'Receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'multipart/alternative',
                    'parts' => [
                        ['mimeType' => 'text/html', 'body' => ['data' => $html]],
                        ['mimeType' => 'text/plain', 'body' => ['data' => $plain]],
                    ],
                ],
            ]),
        ]);

        $msg = (new GmailClient($this->connectedUser()))->getMessage('mp1');

        $this->assertStringContainsString('9.99', $msg['body']);
        $this->assertStringNotContainsString('ignored', $msg['body']);
    }
}
