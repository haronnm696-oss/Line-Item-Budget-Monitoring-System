<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copy existing document files from their disk into document_contents.
 *
 *   php artisan documents:import-to-database [--dry-run]
 *
 * Idempotent: a document already in the database is skipped. A file that
 * is missing on its disk does not stop the run: the document is marked
 * "file missing" (file_missing_at) and listed; its row is never deleted.
 * A file that turns up again on a later run is imported and the mark is
 * cleared. The old file on the disk is left in place.
 */
class ImportDocumentsToDatabase extends Command
{
    protected $signature = 'documents:import-to-database {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Copy existing document files from their disk into the database (document_contents)';

    public function handle(DocumentStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['imported' => 0, 'already' => 0, 'missing' => 0, 'failed' => 0];
        $missing = [];

        Document::query()->orderBy('id')->chunkById(100, function ($documents) use ($storage, $dryRun, &$counts, &$missing) {
            foreach ($documents as $document) {
                if ($document->disk === DocumentStorage::DATABASE && $storage->exists($document)) {
                    $counts['already']++;
                    continue;
                }

                $contents = $this->read($document);
                if ($contents === null) {
                    $counts['missing']++;
                    $missing[] = [$document->id, $document->disk, $document->file_path, $document->file_name];
                    if (! $dryRun && $document->file_missing_at === null) {
                        $document->forceFill(['file_missing_at' => now()])->saveQuietly();
                    }
                    continue;
                }

                if ($dryRun) {
                    $counts['imported']++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($document, $contents, $storage) {
                        $document->forceFill(['disk' => DocumentStorage::DATABASE, 'size' => strlen($contents), 'file_missing_at' => null])->saveQuietly();
                        $storage->put($document, $contents);
                    });
                    $counts['imported']++;
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $this->error("Document #{$document->id}: could not be stored ({$exception->getMessage()}).");
                }
            }
        });

        $prefix = $dryRun ? '[dry run] ' : '';
        $this->info($prefix . ($dryRun ? 'Would import' : 'Imported') . ": {$counts['imported']}");
        $this->line($prefix . "Already in the database: {$counts['already']}");
        $this->line($prefix . 'File missing' . ($dryRun ? ' (would be marked)' : ' (marked, rows kept)') . ": {$counts['missing']}");
        if ($counts['failed'] > 0) {
            $this->error("Failed: {$counts['failed']}");
        }
        if ($missing !== []) {
            $this->table(['Document', 'Disk', 'Path', 'File name'], $missing);
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The bytes from the document's current disk, or null when not found. */
    private function read(Document $document): ?string
    {
        if ($document->disk === DocumentStorage::DATABASE) {
            return null; // marked "database" but no bytes stored
        }

        try {
            $disk = Storage::disk($document->disk);

            return $disk->exists($document->file_path) ? $disk->get($document->file_path) : null;
        } catch (Throwable $exception) {
            return null; // unknown or unreachable disk: treat as missing
        }
    }
}
