<?php

namespace App\Enums;

/** Igual que el tipo `payment_method` de la base. */
enum PaymentMethod: string
{
    case Qr = 'qr';
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';

    /** Se confirma con la foto del comprobante. */
    public function needsProof(): bool
    {
        return $this === self::Qr || $this === self::Transfer;
    }
}
