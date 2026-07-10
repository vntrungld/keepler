<?php

namespace Tests\Feature\Gmail;

use App\Jobs\ScanGmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GmailScanFlowTest extends TestCase
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

    public function test_store_creates_a_scan_and_dispatches_the_job(): void
    {
        Queue::fake();
        $user = $this->connectedUser();

        $response = $this->actingAs($user)->postJson('/gmail/scans');

        $response->assertOk()->assertJsonStructure(['id']);
        $this->assertDatabaseHas('gmail_scans', [
            'id' => $response->json('id'), 'user_id' => $user->id, 'status' => 'pending',
        ]);
        Queue::assertPushed(ScanGmailJob::class);
    }

    public function test_store_asks_to_connect_when_gmail_not_linked(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/gmail/scans')
            ->assertStatus(409)
            ->assertJsonStructure(['connect_url']);

        $this->assertDatabaseCount('gmail_scans', 0);
        Queue::assertNothingPushed();
    }

    public function test_show_returns_progress_and_is_user_scoped(): void
    {
        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'running', 'total' => 50, 'processed' => 12]);

        $this->actingAs($user)->getJson("/gmail/scans/{$scan->id}")
            ->assertOk()
            ->assertJson(['status' => 'running', 'processed' => 12, 'total' => 50, 'percent' => 24]);

        // Another user cannot read it.
        $this->actingAs(User::factory()->create())
            ->getJson("/gmail/scans/{$scan->id}")
            ->assertNotFound();
    }

    public function test_results_renders_candidates_when_done_and_redirects_otherwise(): void
    {
        $user = $this->connectedUser();

        $done = $user->gmailScans()->create([
            'status' => 'done',
            'candidates' => [['provider_key' => 'netflix', 'name' => 'Netflix', 'action' => 'create']],
        ]);
        $this->actingAs($user)->get("/gmail/scans/{$done->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gmail/ScanResults')
                ->has('candidates', 1)
                ->where('candidates.0.provider_key', 'netflix'));

        $running = $user->gmailScans()->create(['status' => 'running']);
        $this->actingAs($user)->get("/gmail/scans/{$running->id}/results")
            ->assertRedirect(route('dashboard'));

        // Cross-user results are not viewable.
        $this->actingAs(User::factory()->create())
            ->get("/gmail/scans/{$done->id}/results")
            ->assertNotFound();
    }
}
