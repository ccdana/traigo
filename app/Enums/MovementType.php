<?php

namespace App\Enums;

/** Igual que el tipo `movement_type` de la base. */
enum MovementType: string
{
    case Initial = 'initial';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Inventario inicial',
            self::Purchase => 'Entrada de mercadería',
            self::Sale => 'Venta',
            self::SaleReturn => 'Devolución',
            self::AdjustmentIn => 'Ajuste: sobra',
            self::AdjustmentOut => 'Ajuste: falta o merma',
        };
    }

    /** Saca unidades de la bodega. */
    public function isOutgoing(): bool
    {
        return $this === self::Sale || $this === self::AdjustmentOut;
    }

    /**
     * Los que se registran a mano; las ventas y devoluciones las registra el
     * sistema.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Initial, self::Purchase, self::AdjustmentIn, self::AdjustmentOut];
    }
}
