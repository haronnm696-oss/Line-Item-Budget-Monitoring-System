<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current password reset code of one user (see PasswordResetCodeService).
 * Holds hashes only, never the code or the reset token.
 */
class PasswordResetCode extends Model
{
    protected $fillable = [
        'user_id',
        'code_hash',
        'attempts',
        'code_expires_at',
        'reset_token_hash',
        'reset_token_expires_at',
    ];

    protected $hidden = ['code_hash', 'reset_token_hash'];

    protected $casts = [
        'attempts' => 'integer',
        'code_expires_at' => 'datetime',
        'reset_token_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
