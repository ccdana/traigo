<?php

namespace App\Services\Control;

use App\Support\Dates;
use App\Support\Money;
use App\Support\Numbers;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * El historial que ven Jime y Dirección: una línea clara por cada hecho
 * importante (pagos, cajas, KPI, entregas, cierres, comisiones, precios,
 * pedidos anulados, stock y accesos). Sale del registro que escribe la propia
 * base, que no se edita ni se borra; los pasos automáticos quedan allí sin
 * mostrarse.
 *
 * «El mismo paso» son las filas de una misma transacción: igual hora y mismo
 * `xmin` (el `xmin` solo no sirve tras restaurar un respaldo).
 */
final class AuditHistory
{
    public const PER_PAGE = 30;

    /** Qué filas del registro cuentan como un hecho. */
    private const IMPORTANT = <<<'SQL'
        -- Un comprobante confirmado, o rechazado por Jime (no al anular el pedido).
        (a.entity_type = 'payments' and a.action = 'update'
            and a.new_values->>'status' is distinct from a.old_values->>'status'
            and a.new_values->>'method' <> 'cash'
            and (a.new_values->>'status' = 'confirmed'
                or (a.new_values->>'status' = 'rejected' and a.new_values->>'reviewed_by' is not null
                    and not exists (select 1 from audit_logs o
                        where o.created_at = a.created_at and o.xmin = a.xmin and o.entity_type = 'orders'
                            and o.entity_id = a.new_values->>'order_id'
                            and o.new_values->>'status' in ('cancelled', 'returned')))))
        or (a.entity_type = 'orders' and a.action = 'update'
            and a.new_values->>'status' is distinct from a.old_values->>'status'
            and a.new_values->>'status' in ('cancelled', 'returned'))
        or (a.entity_type = 'cash_closings' and a.new_values->>'status' in ('sent', 'validated', 'observed')
            and (a.action = 'insert' or a.new_values->>'status' is distinct from a.old_values->>'status'))
        or (a.entity_type = 'kpi_weekly_results' and a.action = 'update'
            and a.new_values->>'actual' is distinct from a.old_values->>'actual')
        or (a.entity_type = 'kpi_evidence' and a.action = 'update'
            and a.new_values->>'status' = 'observed' and a.old_values->>'status' <> 'observed')
        or (a.entity_type = 'system_deliverables' and a.action = 'update'
            and a.new_values->>'jime_validated' is not null
            and a.new_values->>'jime_validated' is distinct from a.old_values->>'jime_validated')
        or (a.entity_type in ('weekly_closings', 'commission_settlements') and a.action in ('insert', 'update')
            and a.new_values->>'status' <> 'open'
            and a.new_values->>'status' is distinct from a.old_values->>'status')
        or (a.entity_type = 'weekly_member_results' and (
            (a.action = 'insert' and a.new_values->>'closed_by' is not null)
            or (a.action = 'update' and a.old_values->>'paid_at' is null and a.new_values->>'paid_at' is not null)))
        or (a.entity_type = 'price_change_requests'
            and (a.action = 'insert' or a.new_values->>'status' is distinct from a.old_values->>'status'))
        -- Un precio cambiado por fuera de una aprobación.
        or (a.entity_type = 'products' and not exists (select 1 from audit_logs r
            where r.created_at = a.created_at and r.xmin = a.xmin and r.entity_type = 'price_change_requests'
                and r.new_values->>'status' = 'approved'))
        or (a.entity_type = 'settings' and a.entity_id not in ('last_backup_at', 'restore_tested_at'))
        or a.entity_type in ('users', 'promotions', 'bonus_weeks', 'bonus_tiers', 'team_profiles', 'kpi_indicators')
        -- Borrados que no hace la app.
        or (a.action = 'delete' and a.entity_type in ('orders', 'payments', 'cash_closings', 'commissions',
            'commission_settlements', 'price_change_requests', 'kpi_evidence', 'system_deliverables',
            'kpi_weekly_results', 'weekly_closings', 'inventory'))
        SQL;

    private const TABLES = [
        'orders' => 'un pedido', 'payments' => 'un cobro', 'cash_closings' => 'un cierre de caja',
        'commissions' => 'una comisión', 'commission_settlements' => 'una liquidación',
        'price_change_requests' => 'un pedido de cambio de precio', 'kpi_evidence' => 'una evidencia',
        'system_deliverables' => 'una entrega', 'kpi_weekly_results' => 'un resultado del KPI',
        'weekly_closings' => 'un cierre semanal', 'inventory' => 'un registro de stock', 'products' => 'un producto',
        'bonus_weeks' => 'las semanas', 'bonus_tiers' => 'los escalones del bono',
        'team_profiles' => 'el reparto del bono', 'kpi_indicators' => 'los indicadores',
    ];

    /** @var array<string, array<string, mixed>> lo ya buscado en esta página */
    private array $seen = [];

    /** Lo más nuevo primero. */
    public static function page(int $page): LengthAwarePaginator
    {
        // `tx` desempata lo que quedó con la misma hora.
        $union = '
            select \'a\' as source, a.id_audit_log as seq, a.id_audit_log::text as ref, a.created_at as at,
                   a.xmin::text::bigint as tx, a.actor_id
            from audit_logs a where ('.self::IMPORTANT.')
            union all
            select \'m\', m.id_stock_movement, m.id_stock_movement::text, m.created_at, m.xmin::text::bigint, m.created_by
            from stock_movements m
            where m.type in (\'initial\', \'purchase\', \'adjustment_in\', \'adjustment_out\') and m.source <> \'system\'
            union all
            select \'c\', 0, c.id_inventory_count::text, c.created_at, c.xmin::text::bigint, c.counted_by
            from inventory_counts c';

        $total = (int) DB::selectOne("select count(*) as total from ({$union}) h")->total;
        $rows = DB::select(
            "select h.*, u.name as actor from ({$union}) h left join users u on u.id_user = h.actor_id
             order by h.at desc, h.tx desc, h.seq desc, h.ref limit ? offset ?",
            [self::PER_PAGE, ($page - 1) * self::PER_PAGE],
        );

        return new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page);
    }

    /** @return array{id: string, at: string, actor_name: string, description: string} */
    public function entry(object $row): array
    {
        return [
            // Los números de cada origen se repiten: la letra los distingue.
            'id' => $row->source === 'a' ? $row->ref : "{$row->source}-{$row->ref}",
            'at' => Dates::toApi($row->at),
            'actor_name' => $row->actor ?? 'Sistema',
            'description' => match ($row->source) {
                'a' => $this->describe(DB::table('audit_logs')->where('id_audit_log', $row->seq)->first()),
                'm' => $this->movement($row->seq),
                'c' => $this->count($row->ref),
            },
        ];
    }

    private function describe(object $log): string
    {
        $new = json_decode($log->new_values ?? 'null', true) ?? [];
        $old = json_decode($log->old_values ?? 'null', true) ?? [];

        if ($log->action === 'delete') {
            return 'Se borró '.(self::TABLES[$log->entity_type] ?? $log->entity_type).' ('.substr($log->entity_id, 0, 8).').';
        }

        return match ($log->entity_type) {
            'payments' => $this->payment($new),
            'orders' => $this->order($new),
            'cash_closings' => $this->cash($new, $old),
            'kpi_weekly_results' => 'Cargó el valor real de '.$this->indicator($new['id_kpi_weekly_result']).': '
                .$this->number($old['actual'] ?? null).' → '.$this->number($new['actual'] ?? null),
            'kpi_evidence' => 'Observó la evidencia de '.$this->indicator($new['kpi_weekly_result_id']).': '
                .$new['review_note'],
            'system_deliverables' => $this->deliverable($new),
            'weekly_closings' => $this->week($new),
            'weekly_member_results' => $this->memberResult($log->action, $new),
            'commission_settlements' => $this->settlement($new),
            'price_change_requests' => $this->priceChange($log, $new),
            'products' => $this->product($new, $old),
            'users' => $this->account($log, $new),
            'promotions' => $log->action === 'promotion_approved'
                ? "Autorizó la promoción «{$new['name']}»"
                : "No aprobó la promoción «{$new['name']}»: {$new['note']}",
            'settings' => "Cambió la configuración «{$log->entity_id}»",
            default => 'Cambió '.(self::TABLES[$log->entity_type] ?? $log->entity_type),
        };
    }

    private function payment(array $new): string
    {
        $number = $this->orderNumber($new['order_id']);

        return $new['status'] === 'confirmed'
            ? "Confirmó el pago {$number} (".Money::display(Money::toCents($this->decimal($new['amount_bs']))).')'
            : "Rechazó el comprobante de {$number}: {$new['reject_reason']}";
    }

    private function order(array $new): string
    {
        $number = $new['order_number'];

        if ($new['status'] === 'returned') {
            $reason = DB::table('order_status_history')
                ->where('order_id', $new['id_order'])
                ->where('to_status', 'returned')
                ->orderByDesc('id_order_status_history')
                ->value('note');

            return "Registró la devolución de {$number}".($reason === null ? '' : ": {$reason}");
        }

        if (($new['cancel_reason'] ?? null) === 'duplicate') {
            return "Quitó {$number} por duplicado de ".$this->orderNumber($new['duplicate_of_id']);
        }

        return "Canceló el pedido {$number}".(filled($new['cancel_reason'] ?? null) ? ": {$new['cancel_reason']}" : '');
    }

    private function cash(array $new, array $old): string
    {
        $day = Dates::weekday($new['business_date']);

        return match ($new['status']) {
            'validated' => "Validó el cierre de caja del {$day}",
            'observed' => "Pidió corregir la caja del {$day}: {$new['review_note']}",
            default => ($old['status'] ?? null) === 'observed'
                ? "Reenvió corregido el cierre de caja del {$day}"
                : "Envió el cierre de caja del {$day}",
        };
    }

    private function deliverable(array $new): string
    {
        $title = "«{$new['title']}» de ".$this->userName($new['user_id']);

        return $new['jime_validated']
            ? "Validó la entrega {$title} (".($new['result'] === 'on_time' ? 'a tiempo' : 'fuera de plazo').')'
            : "No validó {$title}: {$new['review_note']}";
    }

    private function week(array $new): string
    {
        $week = $this->weekLabel($new['week_id']);

        return match ($new['status']) {
            'sent' => "Envió el cierre de la semana {$week} a Dirección",
            'returned' => "Devolvió el cierre de la semana {$week} a Jime: {$new['return_reason']}",
            'authorized' => "Autorizó el bono de la semana {$week} (".Money::display(
                DB::table('weekly_member_results')->where('week_id', $new['week_id'])->pluck('amount_bs')
                    ->sum(fn (string $amount) => Money::toCents($amount)),
            ).')',
            'paid' => "Quedó pagado el bono de la semana {$week}",
            default => "Cambió el cierre de la semana {$week}",
        };
    }

    private function memberResult(string $action, array $new): string
    {
        $name = $this->userName($new['user_id']);

        return $action === 'insert'
            ? "Cerró a {$name} como «no cumple»: {$new['close_reason']}"
            : "Marcó pagado el bono de {$name} (".Money::display(Money::toCents($this->decimal($new['amount_bs']))).')';
    }

    private function settlement(array $new): string
    {
        $month = Dates::monthName($new['period']);

        return match ($new['status']) {
            'sent' => "Envió las comisiones de {$month} a Dirección",
            'authorized' => "Autorizó las comisiones de {$month}",
            'returned' => "Devolvió las comisiones de {$month}: {$new['return_reason']}",
            'paid' => "Marcó pagadas las comisiones de {$month}",
            default => "Cambió las comisiones de {$month}",
        };
    }

    private function priceChange(object $log, array $new): string
    {
        $name = $this->productName($new['product_id']);

        if ($log->action === 'insert') {
            return "Propuso un cambio de precio para {$name}";
        }

        if ($new['status'] === 'rejected') {
            return "No aprobó el precio de {$name}: {$new['decision_note']}";
        }

        $previous = $new['previous_values'];
        $previous = is_string($previous) ? json_decode($previous, true) : $previous;
        $price = '';

        if (($new['price_unit_bs'] ?? null) !== null) {
            $price = ': '.Money::display(Money::toCents($previous['price_unit']))
                .' → '.Money::display(Money::toCents($this->decimal($new['price_unit_bs'])));
        }

        // Las ventas sin % que lo tomaron al aprobar (en el mismo paso).
        $filled = (int) DB::selectOne(
            "select count(distinct c.new_values->>'order_id') as total
             from audit_logs a join audit_logs c on c.created_at = a.created_at and c.xmin = a.xmin
             where a.id_audit_log = ? and c.entity_type = 'commissions' and c.action = 'update'
               and c.old_values->>'rate_pct' is null and c.new_values->>'rate_pct' is not null",
            [$log->id_audit_log],
        )->total;

        return "Autorizó el precio de {$name}{$price}".match (true) {
            $filled === 0 => '',
            $filled === 1 => ' · 1 venta sin % lo tomó',
            default => " · {$filled} ventas sin % lo tomaron",
        };
    }

    private function product(array $new, array $old): string
    {
        $name = $new['name'] ?? $old['name'];

        if (($new['is_active'] ?? null) !== ($old['is_active'] ?? null)) {
            return $new['is_active'] ? "Volvió a poner a la venta {$name}" : "Quitó de la venta {$name}";
        }

        if (($new['price_unit_bs'] ?? null) !== ($old['price_unit_bs'] ?? null)) {
            return "Cambió el precio de {$name}: ".Money::display(Money::toCents($this->decimal($old['price_unit_bs'])))
                .' → '.Money::display(Money::toCents($this->decimal($new['price_unit_bs'])));
        }

        if (($new['commission_pct'] ?? null) !== ($old['commission_pct'] ?? null)) {
            $rate = fn ($value) => $value === null ? 'por definir' : PriceChanges::percent($value);

            return "Cambió la comisión de {$name}: ".$rate($old['commission_pct'] ?? null)
                .' → '.$rate($new['commission_pct'] ?? null);
        }

        return "Cambió el precio por mayor o el costo de {$name}";
    }

    private function account(object $log, array $new): string
    {
        $name = $this->userName($log->entity_id);

        return match (true) {
            $log->action === 'pin_reset' => "Puso un PIN nuevo a {$name}",
            $log->action === 'sessions_revoked' => "Cerró las sesiones de {$name}",
            ($new['is_active'] ?? true) === false => "Desactivó la cuenta de {$name}",
            default => "Cambió la cuenta de {$name}",
        };
    }

    private function movement(int $id): string
    {
        $movement = DB::table('stock_movements as m')
            ->join('products as p', 'p.id_product', '=', 'm.product_id')
            ->where('m.id_stock_movement', $id)
            ->first(['m.type', 'm.quantity', 'm.note', 'p.item_code']);
        $adjustment = str_starts_with($movement->type, 'adjustment');
        $quantity = ($movement->quantity > 0 ? '+' : '−').abs($movement->quantity);

        return ($adjustment ? 'Ajustó' : 'Cargó')." el stock de {$movement->item_code}: {$quantity}"
            .($adjustment ? " ({$movement->note})" : '');
    }

    private function count(string $id): string
    {
        $different = DB::table('inventory_count_items')
            ->where('inventory_count_id', $id)
            ->where('difference', '<>', 0)
            ->count();

        return 'Hizo un conteo: '.match ($different) {
            0 => 'todo coincide',
            1 => '1 producto con diferencia',
            default => "{$different} productos con diferencia",
        };
    }

    /** «X» de Alison. */
    private function indicator(string $resultId): string
    {
        return $this->remember("r:{$resultId}", function () use ($resultId) {
            $row = DB::table('kpi_weekly_results as r')
                ->join('kpi_indicators as i', 'i.id_kpi_indicator', '=', 'r.indicator_id')
                ->join('users as u', 'u.id_user', '=', 'r.user_id')
                ->where('r.id_kpi_weekly_result', $resultId)
                ->first(['i.name', 'u.name as member']);

            return $row === null ? 'un indicador' : "«{$row->name}» de {$row->member}";
        });
    }

    private function number(string|float|int|null $value): string
    {
        return $value === null ? 'sin cargar' : str_replace('.', ',', (string) Numbers::plain($value));
    }

    private function orderNumber(?string $id): string
    {
        return $this->remember("o:{$id}", fn () => DB::table('orders')->where('id_order', $id)->value('order_number') ?? 'un pedido');
    }

    private function userName(string $id): string
    {
        return $this->remember("u:{$id}", fn () => DB::table('users')->where('id_user', $id)->value('name') ?? 'alguien');
    }

    private function productName(string $id): string
    {
        return $this->remember("p:{$id}", fn () => DB::table('products')->where('id_product', $id)->value('name') ?? 'un producto');
    }

    private function weekLabel(string $id): string
    {
        return $this->remember("w:{$id}", fn () => DB::table('bonus_weeks')->where('id_bonus_week', $id)->value('label') ?? '');
    }

    private function remember(string $key, callable $find): string
    {
        return $this->seen[$key] ??= $find();
    }

    /** Los montos del registro llegan como número de JSON. */
    private function decimal(string|float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
