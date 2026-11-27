<?php

namespace App\Support;

use App\Enums\OrderStatus;

/** El estado de un pedido con las palabras que lee el cliente de la tienda. */
final class CustomerOrderStatus
{
    /** Los pasos de la barra de avance, en orden. */
    public const STEPS = [
        'pending_payment' => 'Por pagar',
        'payment_review' => 'Comprobante en revisión',
        'paid' => 'Pagado',
        'ready' => 'Listo',
        'delivered' => 'Entregado',
    ];

    public static function label(OrderStatus $status): string
    {
        return self::STEPS[$status->value] ?? match ($status) {
            OrderStatus::Cancelled => 'Cancelado',
            OrderStatus::Expired => 'Vencido',
            OrderStatus::Returned => 'Devuelto',
        };
    }

    public static function title(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PendingPayment => 'Pedido recibido',
            OrderStatus::PaymentReview => 'Estamos revisando tu pago',
            OrderStatus::Paid => 'Pago confirmado',
            OrderStatus::Ready => 'Tu pedido está listo',
            OrderStatus::Delivered => 'Pedido entregado',
            OrderStatus::Cancelled => 'Pedido cancelado',
            OrderStatus::Expired => 'Tu reserva venció',
            OrderStatus::Returned => 'Pedido devuelto',
        };
    }
}
