<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_reminders_enabled_with_a_3_day_window(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->renewal_reminders_enabled);
        $this->assertSame(3, $user->reminder_days_before);
    }

    public function test_preferences_are_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['renewal_reminders_enabled' => false, 'reminder_days_before' => 7]);

        $this->assertFalse($user->fresh()->renewal_reminders_enabled);
        $this->assertSame(7, $user->fresh()->reminder_days_before);
    }
}
