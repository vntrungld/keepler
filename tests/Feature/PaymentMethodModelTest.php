<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->for($user)->create(['label' => 'Visa •••• 1234']);

        $this->assertSame($user->id, $method->user_id);
        $this->assertTrue($user->paymentMethods->contains($method));
    }
}
