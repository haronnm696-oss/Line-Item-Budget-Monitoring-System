<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Copies the old expenses.attachment_path values into the documents table as
 * receipt documents. Used by the documents migration (existing production
 * rows) and the demo seeders. Safe to rerun: an expense that already has any
 * document is skipped. The attachment_path column itself is left untouched.
 */
class LegacyAttachmentImporter
{
    public const EXPENSE_TYPE = 'App\\Models\\Expense';

    /** Returns the number of documents created. */
    public static function run(): int
    {
        $created = 0;

        DB::table('expenses')
            ->whereNotNull('attachment_path')
            ->where('attachment_path', '!=', '')
            ->orderBy('id')
            ->select(['id', 'attachment_path', 'recorded_by', 'created_at'])
            ->chunkById(500, function ($expenses) use (&$created) {
                $existing = DB::table('documents')
                    ->where('documentable_type', self::EXPENSE_TYPE)
                    ->whereIn('documentable_id', $expenses->pluck('id'))
                    ->pluck('documentable_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $now = now();
                $rows = [];
                foreach ($expenses as $expense) {
                    if (in_array((int) $expense->id, $existing, true)) {
                        continue;
                    }
                    $rows[] = [
                        'documentable_type' => self::EXPENSE_TYPE,
                        'documentable_id' => $expense->id,
                        'uploaded_by' => $expense->recorded_by,
                        'document_type' => 'receipt',
                        'file_name' => basename($expense->attachment_path),
                        'file_path' => $expense->attachment_path,
                        // Old uploads were stored on the public disk.
                        'disk' => 'public',
                        'size' => null,
                        'mime' => null,
                        'uploaded_at' => $expense->created_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table('documents')->insert($rows);
                    $created += count($rows);
                }
            });

        return $created;
    }
}
