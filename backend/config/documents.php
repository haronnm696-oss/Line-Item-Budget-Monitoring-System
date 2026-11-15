<?php

return [
    /*
    | Storage disk for uploaded documents (receipts, invoices, quotations...).
    | "local" (storage/app, private) for development; "documents_s3" for an
    | S3-compatible bucket in production (see config/filesystems.php).
    */
    'disk' => env('DOCUMENTS_DISK', 'local'),

    /* Accepted file types and the size limit (kilobytes). */
    'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    'max_kb' => 10240,
];
