<?php

namespace Tests\Unit;

use App\Support\CurrencyConverter;
use Tests\TestCase;

class CurrencyConverterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
    }

    public function test_converts_usd_to_vnd_using_configured_rate(): void
    {
        $result = CurrencyConverter::toVnd(9.99, 'USD');
        $this->assertSame(259740, $result);
    }

    public function test_passes_vnd_through_unchanged(): void
    {
        $result = CurrencyConverter::toVnd(260000, 'VND');
        $this->assertSame(260000, $result);
    }

    public function test_is_case_insensitive_on_currency_code(): void
    {
        $result = CurrencyConverter::toVnd(1, 'usd');
        $this->assertSame(26000, $result);
    }

    public function test_throws_for_unsupported_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CurrencyConverter::toVnd(10, 'EUR');
    }
}
