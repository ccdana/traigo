<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/** `order_number` y `public_token` los pone la base. */
#[Fillable([
    'origin', 'status', 'seller_id', 'created_by', 'customer_name', 'customer_phone', 'delivery_type',
    'shipping_address', 'subtotal_bs', 'total_bs', 'promised_dispatch_at', 'paid_at', 'ready_at',
    'dispatched_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'duplicate_of_id', 'client_uuid',
])]
class Order extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_order';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'promised_dispatch_at' => 'datetime',
            'paid_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Quien vendió; las ventas web no tienen. */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id_user');
    }

    /** En el orden en que se agregaron (los ids son consecutivos). */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id_order')->orderBy('id_order_item');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id', 'id_order');
    }

    /** El cobro vigente: el último que se creó. */
    public function currentPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'order_id', 'id_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id_payment');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'order_id', 'id_order');
    }

    /** Guarda en la línea de tiempo el cambio de estado. */
    public function recordStatus(?OrderStatus $from, User $by, ?string $note = null): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $this->getKey(),
            'from_status' => $from?->value,
            'to_status' => $this->status->value,
            'changed_by' => $by->getKey(),
            'note' => $note,
        ]);
    }
}
