<?php

namespace App\Services\Reports;

use App\Services\Control\Commissions;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Las comisiones de un mes por persona y por producto. */
final class CommissionReport
{
    private const STATUS = [
        'open' => 'Sin enviar', 'sent' => 'Enviada a Dirección', 'returned' => 'Devuelta a Jime',
        'authorized' => 'Autorizada', 'paid' => 'Pagada',
    ];

    public static function of(string $month): Workbook
    {
        $summary = Commissions::month($month);
        $lines = Commissions::lines($month);
        $settlement = DB::table('commission_settlements')->where('period', "{$month}-01")->first();

        return (new Workbook)
            ->sheet('Por persona', [
                ['Persona', 18], ['Ventas cobradas', 16], ['Ajustes Bs', 13, 'money'], ['Comisión Bs', 14, 'money'],
            ], [
                ...array_map(fn (array $member) => [
                    $member['name'],
                    $member['paid_sales'],
                    Workbook::money($member['adjustments']),
                    $member['amount'] === null ? 'Por definir' : Workbook::money($member['amount']),
                ], $summary['members']),
                ['Total', null, null, $summary['total'] === null ? 'Por definir' : Workbook::money($summary['total'])],
            ])
            ->sheet('Por producto', [
                ['Persona', 18], ['Código', 10], ['Producto', 40], ['Unidades', 10], ['Base Bs', 13, 'money'],
                ['Comisión Bs', 14, 'money'],
            ], self::byProduct($lines))
            ->sheet('Detalle', [
                ['Se ganó el', 17], ['Pedido', 10], ['Persona', 16], ['Código', 10], ['Producto', 36],
                ['Tipo', 9], ['Unidades', 10], ['Base Bs', 13, 'money'], ['%', 7], ['Comisión Bs', 14, 'money'],
            ], $lines->map(fn (object $line) => [
                Workbook::dateTime($line->earned_at),
                $line->order_number,
                $line->seller,
                $line->item_code,
                $line->product_name,
                $line->kind === 'sale' ? 'Venta' : 'Ajuste',
                self::units($line),
                Workbook::money($line->base_bs),
                $line->rate_pct === null ? 'Por definir' : (float) $line->rate_pct,
                Workbook::money($line->amount_bs),
            ]))
            ->sheet('Liquidación', [['Concepto', 22], ['Detalle', 40]], [
                ['Mes', Dates::monthName("{$month}-01").' '.substr($month, 0, 4)],
                ['Estado', self::STATUS[$summary['status']]],
                ['Enviada el', Workbook::dateTime($settlement?->sent_at)],
                ['Autorizada el', Workbook::dateTime($settlement?->authorized_at)],
                ['Pagada el', Workbook::dateTime($settlement?->paid_at)],
                ['Motivo de devolución', $summary['return_reason']],
            ]);
    }

    private static function byProduct(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (object $line) => "{$line->seller}|{$line->item_code}")
            ->map(fn (Collection $group) => [
                $group->first()->seller,
                $group->first()->item_code,
                $group->first()->product_name,
                $group->sum(fn (object $line) => self::units($line)),
                Workbook::money($group->sum(fn (object $line) => Money::toCents($line->base_bs))),
                $group->contains(fn (object $line) => $line->amount_bs === null)
                    ? 'Por definir'
                    : Workbook::money($group->sum(fn (object $line) => Money::toCents($line->amount_bs))),
            ])
            ->values();
    }

    /** En un ajuste por devolución, las unidades restan. */
    private static function units(object $line): int
    {
        return $line->kind === 'sale' ? (int) $line->quantity : -(int) $line->quantity;
    }
}
