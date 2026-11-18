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
        // ₱300 of ₱1,000,000 = 0.03%: above zero, so not flattened to 0.0.
        $value = Percent::of(300, 1000000);
        $this->assertGreaterThan(0, $value);
        $this->assertSame('<0.1%', Percent::label($value));
        $this->assertSame('<0.1', Percent::label($value, ''));
    }

    public function test_a_share_just_under_the_whole_is_not_shown_as_100(): void
    {
        $value = Percent::of(999700, 1000000); // 99.97% remaining
        $this->assertLessThan(100, $value);
        $this->assertSame('99.9%+', Percent::label($value));
    }

    public function test_float_noise_under_half_a_centavo_counts_as_zero(): void
    {
        $this->assertSame(0.0, Percent::of(0.001, 1000000));
        $this->assertSame(100.0, Percent::of(999999.999, 1000000));
    }

    public function test_labels(): void
    {
        $this->assertSame('0.0%', Percent::label(0));
        $this->assertSame('100.0%', Percent::label(100));
        $this->assertSame('42.8%', Percent::label(42.84));
        $this->assertSame('112.5%', Percent::label(112.5));
        $this->assertSame('', Percent::label(null));
    }
}
