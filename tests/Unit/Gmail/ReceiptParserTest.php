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

    public function test_vnd_amount_with_symbol_after_number(): void
    {
        $google = config('providers.google');

        $result = ReceiptParser::parse(
            $google,
            'Your Google Play Order Receipt',
            "Item Price\nGoogle AI Plus (400 GB) (Google One) (by Google LLC) 66.000 ₫/month\nAuto-renewing subscription\nTax: 7.333 ₫\nTotal: 73.333 ₫/month",
            '2026-07-01',
        );

        $this->assertSame(73333.0, $result['amount']);
        $this->assertSame('VND', $result['currency']);
    }

    public function test_prefers_total_line_over_item_price_line(): void
    {
        $google = config('providers.google');

        $result = ReceiptParser::parse(
            $google,
            'Your Google Play Order Receipt',
            "Google AI Plus (400 GB) (Google One) 66.000 ₫/month\nTotal: 73.333 ₫/month",
            '2026-07-01',
        );

        $this->assertSame(73333.0, $result['amount']);
        $this->assertSame('VND', $result['currency']);
    }

    public function test_usd_amount_still_works(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            'Amount charged: $12.99',
            '2026-07-01',
        );

        $this->assertSame(12.99, $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function test_vnd_symbol_before_number(): void
    {
        $google = config('providers.google');

        $result = ReceiptParser::parse(
            $google,
            'Receipt',
            'Total: ₫66.000',
            '2026-07-01',
        );

        $this->assertSame(66000.0, $result['amount']);
        $this->assertSame('VND', $result['currency']);
    }

    public function test_vnd_suffix_variants(): void
    {
        $google = config('providers.google');

        $withVnd = ReceiptParser::parse($google, 'Receipt', 'Total: 66.000 VND', '2026-07-01');
        $this->assertSame(66000.0, $withVnd['amount']);
        $this->assertSame('VND', $withVnd['currency']);

        $withDong = ReceiptParser::parse($google, 'Receipt', 'Total: 73.333 đồng', '2026-07-01');
        $this->assertSame(73333.0, $withDong['amount']);
        $this->assertSame('VND', $withDong['currency']);

        $withDNoMark = ReceiptParser::parse($google, 'Receipt', 'Total: 73.333 đ', '2026-07-01');
        $this->assertSame(73333.0, $withDNoMark['amount']);
        $this->assertSame('VND', $withDNoMark['currency']);
    }

    public function test_marketing_email_does_not_look_like_a_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Premium has music you love in high quality audio',
            'REJOIN PREMIUM Headphones on us when you join Premium.',
            '2026-07-01',
        );

        $this->assertFalse($result['is_receipt']);
    }

    public function test_real_receipt_with_amount_looks_like_a_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            'Amount charged: $12.99',
            '2026-07-01',
        );

        $this->assertTrue($result['is_receipt']);
    }

    public function test_receipt_keyword_without_amount_looks_like_a_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Google Play order receipt',
            'Thanks for your order, no amount included here.',
            '2026-07-01',
        );

        $this->assertTrue($result['is_receipt']);
    }

    public function test_cancellation_without_amount_still_looks_like_a_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix membership has been cancelled',
            "Your membership ended. We're sorry to see you go.",
            '2026-07-05',
        );

        $this->assertTrue($result['is_receipt']);
    }

    public function test_total_line_wins_but_subtotal_does_not_masquerade_as_it(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            "Netflix Premium\nSubtotal $20.00\nTax $2.00\nTotal $22.00",
            '2026-07-01',
        );

        $this->assertSame(22.00, $result['amount']);
    }

    public function test_a_stray_mention_of_years_does_not_make_the_cycle_yearly(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            "Thanks for being with us over the years.\nTotal $12.99",
            '2026-07-01',
        );

        $this->assertSame('monthly', $result['billing_cycle']);
        $this->assertSame('2026-08-01', $result['next_renewal_date']);
    }

    public function test_a_real_yearly_plan_is_still_detected(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            "Annual plan\nTotal $129.99",
            '2026-07-01',
        );

        $this->assertSame('yearly', $result['billing_cycle']);
    }

    public function test_boilerplate_about_how_to_cancel_does_not_turn_a_receipt_into_a_cancellation(): void
    {
        $result = ReceiptParser::parse(
            config('providers.google'),
            'Your Google Play Order Receipt',
            "Google One\nTotal ₫146.667\nBy subscribing, you authorize us to charge you the "
                ."subscription cost (as described above) automatically, charged to the payment "
                ."method provided until canceled. Learn how to cancel. Keep this for your records.",
            '2026-08-01',
        );

        $this->assertSame('payment', $result['intent']);
        $this->assertSame(146667.0, $result['amount']);
    }

    public function test_an_actual_cancellation_notice_is_still_detected(): void
    {
        $result = ReceiptParser::parse(
            config('providers.google'),
            'Your Google One membership has been canceled',
            'Your membership ended. You can resubscribe at any time.',
            '2026-08-01',
        );

        $this->assertSame('cancellation', $result['intent']);
    }
}
