<?php

namespace App\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record that documents can be attached to. documentRules() returns:
 *   folder            storage folder, e.g. "expenses"
 *   types             allowed document_type values
 *   uploader_roles    project roles that may upload (or)
 *   uploader_ids      user ids that may upload (takes precedence)
 *   uploader_message  403 message for anyone else
 *   open              whether uploads are allowed in the current stage
 *   closed_message    422 message when not open
 *   locked            whether documents can no longer be deleted
 *   locked_message    422 message when locked
 */
interface HasDocuments
{
    public function documents(): MorphMany;

    public function documentRules(): array;
}
