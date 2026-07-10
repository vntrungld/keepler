<?php

namespace Tests\Unit\Gmail;

use App\Support\Gmail\ReceiptParser;
use Tests\TestCase;

class ReceiptParserTest extends TestCase
{
    private array $netflix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->netflix = config('providers.netflix');
    }

    public function test_normalize_amount_handles_common_formats(): void
    {
        $this->assertSame(12.99, ReceiptParser::normalizeAmount('12.99'));
        $this->assertSame(1234.56, ReceiptParser::normalizeAmount('1,234.56'));
        $this->assertSame(260000.0, ReceiptParser::normalizeAmount('260.000'));
        $this->assertSame(9.99, ReceiptParser::normalizeAmount('9,99'));
    }

    public function test_parses_a_payment_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            "Thanks for your payment.\nAmount charged: $12.99\nYour plan renews monthly.",
            '2026-07-01',
        );

        $this->assertSame('payment', $result['intent']);
        $this->assertSame(12.99, $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('monthly', $result['billing_cycle']);
        // monthly renewal inferred one month past the email date
        $this->assertSame('2026-08-01', $result['next_renewal_date']);
        $this->assertTrue($result['confidence']['amount']);
    }

    public function test_detects_a_cancellation_email(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix membership has been cancelled',
            "Your membership ended. We're sorry to see you go.",
            '2026-07-05',
        );

        $this->assertSame('cancellation', $result['intent']);
    }

    public function test_infers_yearly_cycle_from_keywords(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your annual plan receipt',
            "You were charged $129.99 for your yearly subscription.",
            '2026-07-01',
        );

        $this->assertSame('yearly', $result['billing_cycle']);
        $this->assertSame('2027-07-01', $result['next_renewal_date']);
    }

    public function test_amount_is_null_and_flagged_low_confidence_when_absent(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix update',
            'Here is some news about your account with no price at all.',
            '2026-07-01',
        );

        $this->assertNull($result['amount']);
        $this->assertFalse($result['confidence']['amount']);
    }
}
