<?php

namespace App\Enums;

/** Igual que el tipo `payment_method` de la base. */
enum PaymentMethod: string
{
    case Qr = 'qr';
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';

    /** Como se muestra en la app. */
    public function label(): string
    {
        return match ($this) {
            self::Qr => 'QR',
            self::Transfer => 'Transferencia',
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
        };
    }

    /** Se confirma con la foto del comprobante. */
    public function needsProof(): bool
    {
        return $this === self::Qr || $this === self::Transfer;
    }
}
