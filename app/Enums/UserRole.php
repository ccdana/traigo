<?php

namespace App\Enums;

/** Igual que el tipo `user_role` de la base y que `role` en la API. */
enum UserRole: string
{
    case Customer = 'customer';
    case Content = 'content';
    case Warehouse = 'warehouse';
    case Wholesale = 'wholesale';
    case Seller = 'seller';
    case Systems = 'systems';
    case Control = 'control';
    case Management = 'management';

    /** Quienes usan la app (todos menos los clientes de la tienda). */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }

    /** Jime y Dirección entran con un segundo paso. */
    public function requiresSecondStep(): bool
    {
        return $this === self::Control || $this === self::Management;
    }

    public function canSell(): bool
    {
        return $this === self::Seller || $this === self::Wholesale;
    }
}
