<?php

namespace Tests\Unit;

use App\Support\ReleaseWording;
use PHPUnit\Framework\TestCase;

class ReleaseWordingTest extends TestCase
{
    public function test_stored_text_reads_release_instead_of_tranche(): void
    {
        $this->assertSame('Release 3', ReleaseWording::apply('Tranche 3'));
        $this->assertSame('Liquidation report, release 1', ReleaseWording::apply('Liquidation report, tranche 1'));
        $this->assertSame('Releases are due', ReleaseWording::apply('Tranches are due'));
        $this->assertSame('Release 3 — Field Validation', ReleaseWording::apply('Release Tranche 3 — Field Validation'));
        $this->assertNull(ReleaseWording::apply(null));
    }

    public function test_the_milestone_is_what_a_name_adds_beyond_its_number(): void
    {
        $this->assertSame('Mobilization', ReleaseWording::milestone('Tranche 1 — Mobilization'));
        $this->assertSame('Mid-year Milestone', ReleaseWording::milestone('Tranche 2 – Mid-year Milestone'));
        $this->assertSame('Final Report & Closeout', ReleaseWording::milestone('Tranche 4 ??? Final Report & Closeout'));
        $this->assertNull(ReleaseWording::milestone('Second tranche'));
        $this->assertNull(ReleaseWording::milestone('Tranche 7'));
        $this->assertNull(ReleaseWording::milestone('Release 2'));
        $this->assertSame('Equipment purchase', ReleaseWording::milestone('Equipment purchase'));
    }
}
