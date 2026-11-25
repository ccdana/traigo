<?php

namespace App\Models;

use App\Support\FileLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una de las entregas obligatorias de Sistemas. `result` lo calcula la base. */
#[Fillable([
    'delivered_on', 'evidence_url', 'evidence_public_id', 'jime_validated', 'validated_by',
    'validated_at', 'review_note',
])]
class SystemDeliverable extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_system_deliverable';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'delivered_on' => 'date',
            'jime_validated' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id_user');
    }

    /** Validada a tiempo o fuera de plazo: ya no se vuelve a enviar. */
    public function isValidated(): bool
    {
        return $this->result === 'on_time' || $this->result === 'late';
    }

    /**
     * Para la app, lo enviado que Jime no revisó todavía está «en revisión»
     * (en la base sigue `pending` hasta que lo valide).
     */
    public function apiStatus(): string
    {
        if ($this->result !== 'pending') {
            return $this->result;
        }

        return $this->delivered_on !== null && $this->evidence_url !== null && $this->jime_validated === null
            ? 'in_review'
            : 'pending';
    }

    /** @return array<string, ?string> */
    public function toApi(): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'acceptance_test' => $this->acceptance_test,
            'due_on' => $this->due_on->toDateString(),
            'status' => $this->apiStatus(),
            'member_id' => $this->user_id,
            'member_name' => $this->member->name,
            'delivered_on' => $this->delivered_on?->toDateString(),
            'evidence_url' => FileLinks::temporary($this->evidence_url),
            'review_note' => $this->review_note,
        ];
    }
}
