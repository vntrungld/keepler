<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_payment_method_for_themselves(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/payment-methods', [
            'label' => 'Visa •••• 1234',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_methods', [
            'user_id' => $user->id,
            'label' => 'Visa •••• 1234',
        ]);
    }

    public function test_label_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/payment-methods', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    public function test_guest_cannot_create_a_payment_method(): void
    {
        $this->postJson('/payment-methods', ['label' => 'Visa'])->assertUnauthorized();
    }
}
