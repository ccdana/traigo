<?php

namespace App\Enums;

/** Igual que el tipo `order_status` de la base y que `status` en la API. */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentReview = 'payment_review';
    case Paid = 'paid';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Returned = 'returned';

    /** Todavía no se cobró: se puede cancelar o cambiar el comprobante. */
    public function isAwaitingPayment(): bool
    {
        return $this === self::PendingPayment || $this === self::PaymentReview;
    }

    public function isVoid(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired, self::Returned], true);
    }
}
