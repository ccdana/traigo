<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra una venta en una sola transacción: precios del servidor (comparados
 * con lo que vio la persona), stock, copia de cada línea, comisiones y cobro.
 */
class CreateSale
{
    /** Margen para «se lo lleva ahora», igual que en la app. */
    public const PAST_TOLERANCE_MINUTES = 30;

    /** @param  array<string, mixed>  $sale  ya validada */
    public function create(User $seller, array $sale, string $clientUuid): Order
    {
        $promisedAt = Carbon::parse($sale['delivery']['promised_at'])->timezone(config('app.timezone'));

        return DB::transaction(function () use ($seller, $sale, $clientUuid, $promisedAt): Order {
            // Segunda barrera contra duplicados, por si se perdió la respuesta
            // guardada del primer envío.
            $previous = Order::query()->where('client_uuid', $clientUuid)->lockForUpdate()->first();

            if ($previous) {
                if ($previous->seller_id !== $seller->getKey()) {
                    throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otro envío.');
                }

                return $previous;
            }

            if ($promisedAt->lt(now()->subMinutes(self::PAST_TOLERANCE_MINUTES))) {
                throw ApiException::invalid('La hora de entrega ya pasó.', 'promised_in_past');
            }

            $lines = $this->priceLines($sale['lines']);
            $total = $lines->sum('total_cents');

            if ($total !== Money::toCents($sale['expected_total'])) {
                throw ApiException::conflict(
                    'price_changed',
                    'Los precios cambiaron. Revisa el total antes de registrar.',
                    ['total' => Money::format($total)],
                );
            }

            $this->ensureAvailable($lines);

            return $this->store($seller, $sale, $clientUuid, $promisedAt, $lines, $total);
        });
    }

    /**
     * @param  list<array{product_id: string, quantity: int|string}>  $requested
     * @return Collection<int, array{product: Product, quantity: int, price_type: string, unit_cents: int, total_cents: int}>
     */
    private function priceLines(array $requested): Collection
    {
        $products = Product::query()
            ->whereIn('id_product', array_column($requested, 'product_id'))
            ->get()
            ->keyBy('id_product');

        return collect($requested)->map(function (array $line) use ($products): array {
            $product = $products->get($line['product_id']);

            if (! $product || ! $product->is_active) {
                throw ApiException::conflict(
                    'product_unavailable',
                    $product ? "{$product->name} ya no está a la venta." : 'Un producto ya no está a la venta.',
                    ['product_id' => $line['product_id']],
                );
            }

            $quantity = (int) $line['quantity'];
            $price = Pricing::unitPrice($product, $quantity);

            return [
                'product' => $product,
                'quantity' => $quantity,
                'price_type' => $price['type'],
                'unit_cents' => $price['cents'],
                'total_cents' => $price['cents'] * $quantity,
            ];
        });
    }

    private function ensureAvailable(Collection $lines): void
    {
        $inventory = Stock::lock($lines->map(fn ($line) => $line['product']->getKey())->all());

        foreach ($lines as $line) {
            $product = $line['product'];
            $row = $inventory->get($product->getKey());
            $available = $row ? $row->stock - $row->reserved : 0;

            if ($line['quantity'] > $available) {
                throw ApiException::conflict(
                    'insufficient_stock',
                    $available === 0
                        ? "{$product->name} se agotó."
                        : "Solo quedan {$available} de {$product->name}.",
                    ['product_id' => $product->getKey(), 'available' => $available],
                );
            }
        }
    }

    private function store(
        User $seller,
        array $sale,
        string $clientUuid,
        Carbon $promisedAt,
        Collection $lines,
        int $total,
    ): Order {
        $method = PaymentMethod::from($sale['payment_method']);
        // El efectivo ya se recibió: nace «Pagado» para que almacén lo
        // entregue. Jime lo confirma al validar la caja del día.
        $cash = $method === PaymentMethod::Cash;
        $delivery = $sale['delivery'];

        $order = Order::create([
            'origin' => $sale['origin'],
            'status' => $cash ? OrderStatus::Paid : OrderStatus::PendingPayment,
            'seller_id' => $seller->getKey(),
            'created_by' => $seller->getKey(),
            'customer_name' => Str::squish($sale['customer']['name']),
            'customer_phone' => $sale['customer']['phone'] ?? null,
            'delivery_type' => $delivery['type'],
            'shipping_address' => $delivery['type'] === 'delivery' ? trim($delivery['address']) : null,
            'subtotal_bs' => Money::format($total),
            'total_bs' => Money::format($total),
            'promised_dispatch_at' => $promisedAt,
            'paid_at' => $cash ? now() : null,
            'client_uuid' => $clientUuid,
        ])->refresh();

        foreach ($lines as $line) {
            $this->storeLine($order, $seller, $line);
        }

        $order->recordStatus(null, $seller);

        Payment::create([
            'order_id' => $order->getKey(),
            'method' => $method,
            'status' => $cash ? PaymentStatus::Submitted : PaymentStatus::Pending,
            'amount_bs' => Money::format($total),
            'submitted_by' => $cash ? $seller->getKey() : null,
            'submitted_at' => $cash ? now() : null,
        ]);

        return $order;
    }

    private function storeLine(Order $order, User $seller, array $line): void
    {
        $product = $line['product'];

        $item = OrderItem::create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'item_code' => $product->item_code,
            'product_name' => $product->name,
            'price_type' => $line['price_type'],
            'unit_price_bs' => Money::format($line['unit_cents']),
            'unit_cost_bs' => $product->cost_bs,
            'commission_rate_pct' => $product->commission_pct,
            'quantity' => $line['quantity'],
        ]);

        Stock::reserve($product->getKey(), $line['quantity']);

        // La base exige el mismo redondeo: por eso lo calcula ella.
        $base = Money::format($line['total_cents']);
        DB::insert(
            'insert into commissions (order_item_id, base_bs, rate_pct, amount_bs, created_by)
             values (?, ?::numeric, ?::numeric, round(?::numeric * ?::numeric / 100, 2), ?)',
            [
                $item->getKey(), $base,
                $product->commission_pct, $base, $product->commission_pct, $seller->getKey(),
            ],
        );
    }
}
