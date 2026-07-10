<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailScanModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_is_created_through_the_user_relation(): void
    {
        $user = User::factory()->create();

        $scan = $user->gmailScans()->create(['status' => 'pending']);

        $this->assertSame('pending', $scan->status);
        $this->assertSame($user->id, $scan->user_id);
        $this->assertSame(0, $scan->total);
        $this->assertSame(0, $scan->processed);
    }

    public function test_candidates_are_cast_to_array(): void
    {
        $user = User::factory()->create();
        $scan = $user->gmailScans()->create([
            'status' => 'done',
            'candidates' => [['provider_key' => 'netflix', 'name' => 'Netflix']],
        ]);

        $this->assertIsArray($scan->fresh()->candidates);
        $this->assertSame('netflix', $scan->fresh()->candidates[0]['provider_key']);
    }

    public function test_progress_percent_computes_and_handles_zero_total(): void
    {
        $user = User::factory()->create();

        $zero = $user->gmailScans()->create(['status' => 'pending']);
        $this->assertSame(0, $zero->progressPercent());

        $half = $user->gmailScans()->create(['status' => 'running', 'total' => 50, 'processed' => 12]);
        $this->assertSame(24, $half->progressPercent());
    }
}
