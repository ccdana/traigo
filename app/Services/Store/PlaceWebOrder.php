<?php

namespace App\Services\Store;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Sales\Stock;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra un pedido de la tienda en una sola transacción: precios del
 * servidor, stock apartado hasta que venza la reserva, copia de cada línea y
 * el cobro por QR pendiente. No hay vendedor ni comisión.
 */
final class PlaceWebOrder
{
    public function __construct(
        private readonly StoreCatalog $catalog,
        private readonly StoreSettings $settings,
    ) {}

    /**
     * @param  User  $customer  la cuenta que compra
     * @param  array<string, int>  $items  cantidad por producto (el carrito)
     * @param  array{delivery_type: 'pickup'|'delivery', address: ?string, latitude?: ?string, longitude?: ?string, reference?: ?string}  $delivery  ya validado
     * @param  int  $expectedCents  el total que la persona vio al confirmar
     */
    public function place(User $customer, array $items, array $delivery, int $expectedCents, string $clientUuid): Order
    {
        return DB::transaction(function () use ($customer, $items, $delivery, $expectedCents, $clientUuid): Order {
            // El mismo formulario enviado dos veces no crea dos pedidos.
            $previous = Order::query()->where('client_uuid', $clientUuid)->lockForUpdate()->first();

            if ($previous) {
                if ($previous->origin !== 'web' || $previous->customer_id !== $customer->getKey()) {
                    throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otro envío.');
                }

                return $previous;
            }

            // Después del reintento: al reenviar, el carrito ya está vacío.
            if ($items === []) {
                throw ApiException::invalid('Tu carrito está vacío.', 'empty_bag');
            }

            $this->ensureNotTooManyOpen($customer);

            $lines = $this->lines($items);
            $total = (int) $lines->sum('total_cents');

            if ($total !== $expectedCents) {
                throw ApiException::conflict('price_changed', 'Los precios cambiaron. Revisa el total y confirma otra vez.');
            }

            return $this->store($customer, $delivery, $lines, $total, $clientUuid);
        });
    }

    /**
     * Sin cobrar, cada pedido aparta stock: se limita cuántos puede tener a la
     * vez una cuenta o un mismo WhatsApp (así no basta con crear otra cuenta).
     * Los que están en revisión también cuentan: no vencen y bastaría subir
     * cualquier imagen para saltarse el límite.
     */
    private function ensureNotTooManyOpen(User $customer): void
    {
        // Dos envíos a la vez de la misma persona esperan su turno.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["web-order:{$customer->phone}"]);

        $open = Order::query()
            ->where('origin', 'web')
            ->whereIn('status', [OrderStatus::PendingPayment->value, OrderStatus::PaymentReview->value])
            ->where(fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->orWhere('customer_phone', $customer->phone))
            ->count();

        if ($open >= config('traigo.web.max_open_orders')) {
            throw ApiException::conflict(
                'too_many_open_orders',
                'Ya tienes varios pedidos sin confirmar. Espera a que se confirmen o venzan antes de hacer otro.',
            );
        }
    }

    /**
     * Las líneas con el precio de hoy, con el inventario ya bloqueado y el
     * stock comprobado.
     *
     * @param  array<string, int>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(array $items): Collection
    {
        $inventory = Stock::lock(array_keys($items));
        $products = $this->catalog->productsById(array_keys($items));
        $promotions = StorePricing::activePromotions(array_keys($items));

        return collect($items)->map(function (int $quantity, string $productId) use ($inventory, $products, $promotions): array {
            $product = $products->get($productId)
                ?? throw ApiException::conflict('product_unavailable', 'Un producto de tu carrito ya no está a la venta.');

            $row = $inventory->get($productId);
            $available = $row ? $row->stock - $row->reserved : 0;

            if ($quantity < 1 || $quantity > $available) {
                throw ApiException::conflict(
                    'insufficient_stock',
                    $available <= 0 ? "{$product->name} se agotó." : "Solo quedan {$available} de {$product->name}.",
                );
            }

            $price = StorePricing::price($product, $quantity, $promotions->get($productId, collect()));

            return [
                'product' => $product,
                'quantity' => $quantity,
                'price' => $price,
                'total_cents' => $price['cents'] * $quantity,
            ];
        })->values();
    }

    /**
     * @param  array{delivery_type: string, address: ?string, latitude?: ?string, longitude?: ?string, reference?: ?string}  $delivery
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function store(User $customer, array $delivery, Collection $lines, int $total, string $clientUuid): Order
    {
        $order = Order::create([
            'origin' => 'web',
            'status' => OrderStatus::PendingPayment,
            'customer_id' => $customer->getKey(),
            // Copia del contacto: el pedido no cambia si luego edita su cuenta.
            'customer_name' => Str::squish("{$customer->name} {$customer->lastname}"),
            'customer_phone' => $customer->phone,
            'delivery_type' => $delivery['delivery_type'],
            'shipping_address' => DeliveryLocations::addressForApp($delivery),
            // El costo del envío se coordina aparte: queda «por definir».
            'subtotal_bs' => Money::format($total),
            'total_bs' => Money::format($total),
            'expires_at' => now()->addHours($this->settings->reservationHours()),
            'client_uuid' => $clientUuid,
        ])->refresh();

        app(DeliveryLocations::class)->save($order, $delivery);

        foreach ($lines as $line) {
            $product = $line['product'];

            OrderItem::create([
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                'promotion_id' => $line['price']['promotion_id'],
                'item_code' => $product->item_code,
                'product_name' => $product->name,
                'price_type' => $line['price']['type'],
                'list_price_bs' => $line['price']['list_cents'] === null ? null : Money::format($line['price']['list_cents']),
                'unit_price_bs' => Money::format($line['price']['cents']),
                'unit_cost_bs' => $product->cost_bs,
                'quantity' => $line['quantity'],
            ]);

            Stock::reserve($product->getKey(), $line['quantity']);
        }

        $order->recordStatus(null, $customer);

        Payment::create([
            'order_id' => $order->getKey(),
            'method' => PaymentMethod::Qr,
            'status' => PaymentStatus::Pending,
            'amount_bs' => Money::format($total),
        ]);

        return $order;
    }
}
