<?php

return [
    /*
    | Where uploaded document files (receipts, invoices, quotations...) are
    | kept. "database" (default): the bytes go into the document_contents
    | table, so they survive redeploys on hosts with a temporary disk
    | (Render's free instance). Any Laravel disk name also works: "local"
    | (storage/app, private) or "documents_s3" (config/filesystems.php).
    | Documents already stored on a disk keep being read from that disk.
    */
    'disk' => env('DOCUMENTS_DISK', 'database'),

    /* Accepted file types and the size limit (kilobytes): 5 MB. */
    'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    'max_kb' => 5120,
];
