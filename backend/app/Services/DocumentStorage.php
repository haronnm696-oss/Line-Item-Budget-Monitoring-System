<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place that reads and writes document file bytes.
 *
 * New uploads go to config('documents.disk') (DOCUMENTS_DISK):
 *   "database"  bytes in document_contents (default; survives redeploys)
 *   any other   a Laravel filesystem disk ("local", "documents_s3", ...)
 * A document is always read from the backend named in its own `disk`
 * column, so older files on a disk keep working after the default changes.
 *
 * The bytes live in a separate table and are only selected here, so normal
 * queries and JSON lists never load them.
 */
class DocumentStorage
{
    public const DATABASE = 'database';

    /** Backend for new uploads. */
    public function defaultDisk(): string
    {
        return (string) (config('documents.disk') ?: self::DATABASE);
    }

    /** Write the bytes of a document (its row exists; `disk` and `file_path` are set). */
    public function put(Document $document, string $contents): void
    {
        if ($document->disk === self::DATABASE) {
            DB::table('document_contents')->updateOrInsert(
                ['document_id' => $document->id],
                ['content' => $contents, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'created_at' => now()]
            );

            return;
        }

        Storage::disk($document->disk)->put($document->file_path, $contents);
    }

    /** The bytes, or null when the file is not available. */
    public function contents(Document $document): ?string
    {
        if ($document->disk === self::DATABASE) {
            $content = DB::table('document_contents')->where('document_id', $document->id)->value('content');
            if ($content === null) {
                return null;
            }

            return is_resource($content) ? stream_get_contents($content) : (string) $content;
        }

        $disk = Storage::disk($document->disk);

        return $disk->exists($document->file_path) ? $disk->get($document->file_path) : null;
    }

    /** A readable stream of the bytes (rewound), or null when the file is not available. */
    public function get(Document $document)
    {
        if ($document->disk !== self::DATABASE) {
            $disk = Storage::disk($document->disk);

            return $disk->exists($document->file_path) ? $disk->readStream($document->file_path) : null;
        }

        $contents = $this->contents($document);
        if ($contents === null) {
            return null;
        }
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function exists(Document $document): bool
    {
        if ($document->disk === self::DATABASE) {
            return DB::table('document_contents')->where('document_id', $document->id)->exists();
        }

        return Storage::disk($document->disk)->exists($document->file_path);
    }

    /** Remove the bytes; a file that is already gone is fine. */
    public function delete(Document $document): void
    {
        if ($document->disk === self::DATABASE) {
            DB::table('document_contents')->where('document_id', $document->id)->delete();

            return;
        }

        Storage::disk($document->disk)->delete($document->file_path);
    }

    /** SHA-256 of the stored bytes (database: the stored hash), or null when not available. */
    public function sha256(Document $document): ?string
    {
        if ($document->disk === self::DATABASE) {
            return DB::table('document_contents')->where('document_id', $document->id)->value('sha256');
        }
        $contents = $this->contents($document);

        return $contents === null ? null : hash('sha256', $contents);
    }

    /**
     * Stream the file: inline (preview) or as an attachment (download), with
     * the stored Content-Type. Null when the file is not available.
     */
    public function response(Document $document, bool $inline): ?StreamedResponse
    {
        $stream = $this->get($document);
        if ($stream === null) {
            return null;
        }

        $name = str_replace(['"', "\r", "\n"], '', $document->file_name);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
