<?php

namespace Tests\Feature\Concerns;

/**
 * Employee ids for generated people: MSU-90001, MSU-90002… counted per test,
 * so two people in one test never get the same id (random ids could collide
 * on the unique users.employee_id). Fixed ids in tests that use this trait
 * stay outside MSU-90001 to MSU-99997.
 */
trait GeneratesEmployeeIds
{
    private int $employeeSequence = 0;

    protected function nextEmployeeId(): string
    {
        return sprintf('MSU-9%04d', ++$this->employeeSequence);
    }
}
