<?php

namespace Tests\Feature\Gmail;

use App\Jobs\ScanGmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanGmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'a', 'gmail_refresh_token' => 'r',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        return $user->fresh();
    }

    public function test_job_completes_a_scan_with_candidates_and_full_progress(): void
    {
        $netflixBody = rtrim(strtr(base64_encode('Charged $12.99 monthly.'), '+/', '-_'), '=');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@netflix.com'],
                        ['name' => 'Subject', 'value' => 'Your Netflix receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $netflixBody],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [['id' => 'm1']],
            ]),
        ]);

        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'pending']);

        (new ScanGmailJob($scan))->handle();

        $fresh = $scan->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertSame(1, $fresh->total);
        $this->assertSame(1, $fresh->processed);
        $this->assertCount(1, $fresh->candidates);
        $this->assertSame('netflix', $fresh->candidates[0]['provider_key']);
    }

    public function test_job_marks_scan_failed_without_leaking_details_on_api_error(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'boom'], 401)]);

        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'pending']);

        (new ScanGmailJob($scan))->handle();

        $fresh = $scan->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->error);
        $this->assertStringNotContainsString('boom', $fresh->error);
        $this->assertStringNotContainsString('401', $fresh->error);
    }
}
