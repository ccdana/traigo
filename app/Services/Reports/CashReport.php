<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Services\Cash\CashDay;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** La caja de un día por forma de pago, con sus diferencias y sus cobros. */
final class CashReport
{
    private const STATUS = [
        'open' => 'Sin enviar', 'sent' => 'Enviado a Jime', 'validated' => 'Validado', 'observed' => 'Observado',
    ];

    private const PAYMENT_STATUS = [
        'pending' => 'Sin comprobante', 'submitted' => 'Por confirmar', 'confirmed' => 'Confirmado',
        'rejected' => 'Rechazado', 'reversed' => 'Devuelto',
    ];

    public static function of(string $date): Workbook
    {
        $day = CashDay::show($date);
        $closing = $day['closing_id'] === null ? null : DB::table('cash_closings as c')
            ->leftJoin('users as v', 'v.id_user', '=', 'c.validated_by')
            ->where('c.id_cash_closing', $day['closing_id'])
            ->first(['c.validated_at', 'v.name as reviewer']);

        return (new Workbook)
            ->sheet('Caja', [
                ['Forma de pago', 16], ['Pedidos', 9], ['Según pedidos Bs', 17, 'money'],
                ['Recibido Bs', 13, 'money'], ['Diferencia Bs', 13, 'money'], ['Nota', 40],
            ], self::lines($day))
            ->sheet('Cierre', [['Concepto', 24], ['Detalle', 60]], [
                ['Fecha', Workbook::date($date)],
                ['Estado', self::STATUS[$day['status']]],
                ['Enviado por', $day['closed_by_name']],
                ['Enviado el', Workbook::dateTime($day['sent_at'])],
                ['Explicación de almacén', $day['note']],
                ['Revisado por', $closing?->reviewer],
                ['Revisado el', Workbook::dateTime($closing?->validated_at)],
                ['Observación de Jime', $day['review_note']],
            ])
            ->sheet('Cobros', [
                ['Pedido', 10], ['Cliente', 28], ['Vendedor', 14], ['Forma', 13], ['Monto Bs', 13, 'money'],
                ['Estado', 14], ['Llegó el', 17],
            ], self::payments($date, $day))
            ->sheet('Por cobrar', [
                ['Pedido', 10], ['Cliente', 28], ['Vendedor', 14], ['Forma', 13], ['Total Bs', 13, 'money'], ['Detalle', 22],
            ], array_map(fn (array $order) => [
                $order['number'],
                $order['customer_name'],
                $order['seller_name'] ?? 'Tienda web',
                PaymentMethod::from($order['payment_method'])->label(),
                Workbook::money($order['total']),
                $order['detail'],
            ], $day['pending']));
    }

    private static function lines(array $day): array
    {
        $rows = [];
        $totals = [0, 0, null];

        foreach ($day['lines'] as $line) {
            $expected = Money::toCents($line['expected']);
            $counted = $line['counted'] === null ? null : Money::toCents($line['counted']);
            $rows[] = [
                PaymentMethod::from($line['method'])->label(),
                $line['orders_count'],
                Workbook::money($expected),
                Workbook::money($counted),
                $counted === null ? null : Workbook::money($counted - $expected),
                $line['note'],
            ];
            $totals[0] += $line['orders_count'];
            $totals[1] += $expected;
            if ($counted !== null) {
                $totals[2] = ($totals[2] ?? 0) + $counted;
            }
        }

        $rows[] = [
            'Total',
            $totals[0],
            Workbook::money($totals[1]),
            Workbook::money($totals[2]),
            $totals[2] === null ? null : Workbook::money($totals[2] - $totals[1]),
            null,
        ];

        return $rows;
    }

    /** Los del cierre enviado; si está abierto, los que entrarían. */
    private static function payments(string $date, array $day): iterable
    {
        $query = in_array($day['status'], CashDay::SENT, true)
            ? DB::table('payments as p')
                ->join('orders as o', 'o.id_order', '=', 'p.order_id')
                ->where('p.cash_closing_id', $day['closing_id'])
            : CashDay::collections($date, $day['closing_id']);

        return $query
            ->leftJoin('users as s', 's.id_user', '=', 'o.seller_id')
            ->orderByRaw('coalesce(p.received_at, p.submitted_at)')
            ->get([
                'o.order_number', 'o.customer_name', 's.name as seller', 'p.method', 'p.amount_bs', 'p.status',
                'p.received_at', 'p.submitted_at',
            ])
            ->map(fn (object $row) => [
                $row->order_number,
                $row->customer_name,
                $row->seller ?? 'Tienda web',
                PaymentMethod::from($row->method)->label(),
                Workbook::money($row->amount_bs),
                self::PAYMENT_STATUS[$row->status],
                Workbook::dateTime($row->received_at ?? $row->submitted_at),
            ]);
    }
}
