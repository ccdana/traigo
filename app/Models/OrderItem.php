<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La copia de lo vendido, con los precios y la comisión de ese momento. */
#[Fillable([
    'order_id', 'product_id', 'item_code', 'product_name', 'price_type', 'unit_price_bs',
    'unit_cost_bs', 'commission_rate_pct', 'quantity', 'promotion_id', 'list_price_bs',
])]
class OrderItem extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_order_item';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id_product');
    }
}
