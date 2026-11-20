<?php

namespace Tests\Feature;

use App\Support\AuditEntryPresenter;
use Tests\TestCase;

/**
 * Changed fields in an audit entry are listed in a fixed order per record
 * type, whatever order the stored JSON keys come back in (MySQL's JSON type
 * re-sorts them; SQLite keeps the written order).
 */
class AuditFieldOrderTest extends TestCase
{
    public function test_line_item_fields_follow_the_fixed_order_on_any_database(): void
    {
        $expected = [
            'line_item_name', 'category_type', 'cost_type', 'expense_group', 'computation',
            'implementing_agency_amount', 'funder_amount', 'allocated_amount',
        ];
        // As written (SQLite) and as MySQL returns them (shorter keys first).
        $written = ['category_type', 'cost_type', 'expense_group', 'line_item_name', 'computation', 'implementing_agency_amount', 'funder_amount', 'allocated_amount'];
        $mysql = ['cost_type', 'computation', 'category_type', 'expense_group', 'funder_amount', 'line_item_name', 'allocated_amount', 'implementing_agency_amount'];

        $this->assertSame($expected, AuditEntryPresenter::orderedKeys('LibCategory', $written));
        $this->assertSame($expected, AuditEntryPresenter::orderedKeys('LibCategory', $mysql));
        $this->assertSame($expected, AuditEntryPresenter::orderedKeys('LibCategory', array_reverse($mysql)));
    }

    public function test_unlisted_fields_come_after_in_label_order_then_alphabetically(): void
    {
        $this->assertSame(
            ['name', 'email', 'position', 'status', 'zeta_field', 'zzz'],
            AuditEntryPresenter::orderedKeys('User', ['zzz', 'status', 'position', 'zeta_field', 'email', 'name'])
        );
        // A type with no fixed order still gets one order on every database.
        $keys = ['remarks', 'amount', 'status', 'title'];
        $this->assertSame(
            AuditEntryPresenter::orderedKeys('ProjectReport', $keys),
            AuditEntryPresenter::orderedKeys('ProjectReport', array_reverse($keys))
        );
        $this->assertSame(['amount', 'name'], AuditEntryPresenter::orderedKeys('Project', ['name', 'amount', 'name']));
    }
}
