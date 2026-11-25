<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'order_id', 'method', 'status', 'amount_bs', 'reference', 'proof_public_id', 'proof_url',
    'submitted_by', 'submitted_at', 'reject_reason', 'client_uuid',
])]
class Payment extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_payment';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
