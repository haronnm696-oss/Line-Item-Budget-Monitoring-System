<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make the production database utf8mb4 and repair text that was inserted
     * while the connection/table charset still treated the peso sign and
     * em dash as unsupported characters (they became literal "???").
     *
     * This is intentionally a no-op outside MySQL because SQLite (tests)
     * has no per-table charset settings.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();

        DB::statement(
            "ALTER DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        // Converting a column that participates in a foreign key can fail
        // while MySQL checks the other side, so pause FK checks for this
        // controlled schema-wide conversion.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $tables = DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE']
            );

            foreach ($tables as $table) {
                $name = str_replace('`', '``', $table->TABLE_NAME);
                DB::statement(
                    "ALTER TABLE `{$name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // The realignment amount carries a peso sign in its title.
        DB::statement(
            "UPDATE approval_requests "
            . "SET title = REPLACE(title, 'Realign ???', 'Realign ₱') "
            . "WHERE title LIKE 'Realign ???%'"
        );

        // Everywhere else the corrupted glyph was an em dash.
        $textColumns = [
            'project_tranches' => ['name'],
            'project_reports' => ['title'],
            'liquidations' => ['title'],
            'funding_agencies' => ['name'],
            'projects' => ['title', 'description'],
            'lib_categories' => ['line_item_name'],
            'expenses' => ['description'],
            'approval_requests' => ['title', 'description', 'review_notes'],
        ];

        foreach ($textColumns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(
                    "UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, '???', '—') "
                    . "WHERE `{$column}` LIKE '%???%'"
                );
            }
        }
    }

    public function down(): void
    {
        // Charset conversion is intentionally irreversible: reverting would
        // re-introduce the data-loss condition this migration repairs.
    }
};
