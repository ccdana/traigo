<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\Dates;
use App\Support\FileLinks;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Un pedido como lo ve quien lo vendió. */
final class SaleOrder
{
    /** Lo que hay que cargar antes de armar el detalle. */
    public const DETAIL_RELATIONS = ['items', 'currentPayment', 'commissions'];

    /** @return array<string, mixed> */
    public static function summary(Order $order): array
    {
        return [
            'id' => $order->getKey(),
            'number' => $order->order_number,
            'created_at' => Dates::toApi($order->created_at),
            'customer_name' => $order->customer_name,
            'items_label' => self::itemsLabel($order),
            'total' => Money::fromDatabase($order->total_bs),
            'payment_method' => $order->currentPayment?->method->value,
            'status' => $order->status->value,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Order $order): array
    {
        $order->loadMissing(self::DETAIL_RELATIONS);
        $categories = self::categoriesOf($order);
        $images = self::imagesOf($order);
        $payment = $order->currentPayment;
        $awaiting = $order->status->isAwaitingPayment();

        return [
            ...self::summary($order),
            'origin' => $order->origin,
            'customer_phone' => $order->customer_phone,
            'delivery' => [
                'type' => $order->delivery_type,
                'address' => $order->shipping_address,
                'promised_at' => Dates::toApi($order->promised_dispatch_at),
            ],
            'lines' => $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'item_code' => $item->item_code,
                'name' => $item->product_name,
                // La categoría y la foto son las de hoy (solo para mostrar).
                'category' => $categories[$item->product_id] ?? 'Sin categoría',
                'image_url' => $images[$item->product_id] ?? null,
                'quantity' => $item->quantity,
                'price_type' => $item->price_type,
                'unit_price' => Money::fromDatabase($item->unit_price_bs),
                'line_total' => Money::fromDatabase($item->line_total_bs
                    ?? Money::format(Money::toCents($item->unit_price_bs) * $item->quantity)),
            ])->values(),
            'payment' => self::payment($order, $payment),
            'timeline' => [
                ['status' => OrderStatus::PendingPayment->value, 'at' => Dates::toApi($order->created_at)],
                ['status' => OrderStatus::Paid->value, 'at' => Dates::toApi($order->paid_at)],
                ['status' => OrderStatus::Ready->value, 'at' => Dates::toApi($order->ready_at)],
                ['status' => OrderStatus::Delivered->value, 'at' => Dates::toApi($order->delivered_at)],
            ],
            'commission' => self::commission($order),
            'can_cancel' => $awaiting,
            'can_upload_proof' => $awaiting && ($payment?->method->needsProof() ?? false),
        ];
    }

    /** «Tomatodos de Messi × 2 y 1 producto más». */
    public static function itemsLabel(Order $order): string
    {
        $first = $order->items->first();

        if (! $first) {
            return '';
        }

        $label = "{$first->product_name} × {$first->quantity}";
        $others = $order->items->count() - 1;

        return $others === 0 ? $label : "{$label} y {$others} ".($others === 1 ? 'producto' : 'productos').' más';
    }

    /**
     * Sin comprobante todavía no hay cobro que mostrar; en un pedido anulado,
     * tampoco (la app lo mostraría como rechazado por Jime).
     *
     * @return array<string, mixed>|null
     */
    private static function payment(Order $order, ?Payment $payment): ?array
    {
        if (! $payment || $payment->status === PaymentStatus::Pending
            || $order->status === OrderStatus::Cancelled || $order->status === OrderStatus::Expired) {
            return null;
        }

        return [
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'reference' => $payment->reference,
            'proof_url' => FileLinks::temporary($payment->proof_url),
            'submitted_at' => Dates::toApi($payment->submitted_at),
            'reject_reason' => $payment->reject_reason,
        ];
    }

    /**
     * Pendiente hasta que se cobra; el monto es nulo mientras falte el % de
     * algún producto.
     *
     * @return array{status: string, amount: ?string}|null
     */
    private static function commission(Order $order): ?array
    {
        $sales = $order->commissions->where('kind', 'sale');

        if ($sales->isEmpty()) {
            return null;
        }

        $status = match (true) {
            $order->status->isVoid() => 'cancelled',
            $sales->every(fn ($c) => $c->status === 'earned') => 'earned',
            default => 'pending',
        };

        $amount = $sales->contains(fn ($c) => $c->amount_bs === null)
            ? null
            : Money::format($sales->sum(fn ($c) => Money::toCents($c->amount_bs)));

        return ['status' => $status, 'amount' => $amount];
    }

    private static function categoriesOf(Order $order): array
    {
        return DB::table('products')
            ->join('categories', 'categories.id_category', '=', 'products.category_id')
            ->whereIn('products.id_product', $order->items->pluck('product_id'))
            ->pluck('categories.name', 'products.id_product')
            ->all();
    }

    private static function imagesOf(Order $order): array
    {
        return DB::table('product_images')
            ->whereIn('product_id', $order->items->pluck('product_id'))
            ->orderByDesc('position')
            ->orderByDesc('created_at')
            // Con la misma clave gana la última: la de menor posición.
            ->pluck('url', 'product_id')
            ->all();
    }
}
