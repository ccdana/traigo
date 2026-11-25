<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;

/** Cada quien ve solo sus ventas: lo de otra persona no existe (404). */
final class OwnOrders
{
    public static function find(User $seller, string $orderId): Order
    {
        return Order::query()
            ->where('seller_id', $seller->getKey())
            ->find($orderId)
            ?? throw ApiException::notFound('No encontramos ese pedido.');
    }

    /** Bloqueado hasta que termine la transacción. */
    public static function lock(User $seller, string $orderId): Order
    {
        return Order::query()
            ->where('seller_id', $seller->getKey())
            ->lockForUpdate()
            ->find($orderId)
            ?? throw ApiException::notFound('No encontramos ese pedido.');
    }
}
