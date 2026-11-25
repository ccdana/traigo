<?php

namespace App\Models;

use App\Support\FileLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto, enlace o pedido que respalda un indicador. */
#[Fillable([
    'kpi_weekly_result_id', 'kind', 'url', 'public_id', 'order_id', 'note', 'status',
    'submitted_by', 'submitted_at', 'client_uuid',
])]
class KpiEvidence extends Model
{
    use HasUuids;

    protected $table = 'kpi_evidence';

    protected $primaryKey = 'id_kpi_evidence';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id_order');
    }

    /** @return array<string, ?string> */
    public function toApi(): array
    {
        return [
            'id' => $this->getKey(),
            'kind' => $this->kind,
            'status' => $this->status,
            'url' => FileLinks::temporary($this->url),
            'order_id' => $this->order_id,
            'order_number' => $this->order?->order_number,
            'note' => $this->note,
            'submitted_at' => $this->submitted_at->timezone(config('app.timezone'))->toIso8601String(),
            'review_note' => $this->review_note,
        ];
    }
}
