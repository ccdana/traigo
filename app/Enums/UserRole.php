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

    /** Quienes entran al panel web de inventario; el resto trabaja con la app. */
    public function canUseInventoryPanel(): bool
    {
        return in_array($this, [self::Warehouse, self::Control, self::Management], true);
    }

    /** Solo almacén registra entradas y ajustes, igual que en la app. */
    public function canMoveStock(): bool
    {
        return $this === self::Warehouse;
    }

    /** Jime y Dirección administran productos, ofertas y datos de la tienda. */
    public function canManageCatalog(): bool
    {
        return $this === self::Control || $this === self::Management;
    }

    /** Jime propone precios y crea ofertas. */
    public function canProposePrices(): bool
    {
        return $this === self::Control;
    }

    public function canCreatePromotions(): bool
    {
        return $this === self::Control;
    }

    /** Dirección autoriza, publica productos y cambia el QR de pago. */
    public function canAuthorize(): bool
    {
        return $this === self::Management;
    }

    public function canSell(): bool
    {
        return $this->isStaff();
    }
}
