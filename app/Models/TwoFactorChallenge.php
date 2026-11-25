<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** El segundo paso del ingreso. El código solo se guarda hasheado. */
#[Fillable(['user_id', 'code_hash', 'channel', 'attempts', 'expires_at', 'resend_at', 'consumed_at'])]
#[Hidden(['code_hash'])]
class TwoFactorChallenge extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_two_factor_challenge';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'resend_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id_user');
    }
}
