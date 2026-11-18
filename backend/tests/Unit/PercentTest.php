<?php

namespace Tests\Unit;

use App\Support\Percent;
use PHPUnit\Framework\TestCase;

class PercentTest extends TestCase
{
    public function test_ordinary_shares_round_to_one_decimal(): void
    {
        $this->assertSame(42.8, Percent::of(428, 1000));
        $this->assertSame(0.0, Percent::of(0, 1000));
        $this->assertSame(100.0, Percent::of(1000, 1000));
        $this->assertSame(0.0, Percent::of(50, 0));
    }

    public function test_a_tiny_share_keeps_its_precision_instead_of_0_0(): void
    {
        // ₱400 of ₱1,000,000 = 0.04%: above zero, so not flattened to 0.0.
        $value = Percent::of(400, 1000000);
        $this->assertEqualsWithDelta(0.04, $value, 1e-9);
        $this->assertSame('0.04', Percent::csv($value));
    }

    public function test_a_share_just_under_the_whole_is_not_shown_as_100(): void
    {
        $value = Percent::of(999700, 1000000); // 99.97%
        $this->assertEqualsWithDelta(99.97, $value, 1e-9);
        $this->assertSame('99.97', Percent::csv($value));
    }

    public function test_float_noise_under_half_a_centavo_counts_as_zero(): void
    {
        $this->assertSame(0.0, Percent::of(0.001, 1000000));
        $this->assertSame(100.0, Percent::of(999999.999, 1000000));
    }

    public function test_csv_cells_are_plain_numbers_never_text_or_exponents(): void
    {
        $this->assertSame('42.8', Percent::csv(42.8));
        $this->assertSame('0', Percent::csv(0));
        $this->assertSame('100', Percent::csv(100.0));
        $this->assertSame('112.5', Percent::csv(112.5));
        $this->assertSame('0.00001', Percent::csv(0.00001));
        $this->assertSame('0.123457', Percent::csv(0.1234567));
        $this->assertNull(Percent::csv(null));
        foreach ([0.04, 99.97, 0.00001, 42.8] as $value) {
            $this->assertIsNumeric(Percent::csv($value));
            $this->assertStringNotContainsString('<', Percent::csv($value));
            $this->assertStringNotContainsString('+', Percent::csv($value));
        }
    }
}
