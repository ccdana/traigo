<?php

namespace App\Services\Management;

use App\Models\User;
use App\Services\Control\PriceChanges;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Un cambio de precio, mínimo, comisión o costo que propuso Jime. */
class PriceAuthorization implements AuthorizationKind
{
    private const LABELS = [
        'price_unit_bs' => 'Minorista',
        'price_wholesale_bs' => 'Mayorista',
        'wholesale_min_qty' => 'Mínimo mayorista',
        'commission_pct' => 'Comisión',
        'cost_bs' => 'Costo',
    ];

    public function __construct(private readonly PriceChanges $prices) {}

    public function pending(): array
    {
        return $this->requests()
            ->orderBy('r.requested_at')
            ->get()
            ->map(fn (object $row) => $this->item($row))
            ->all();
    }

    public function detail(string $id): array
    {
        $row = $this->requests()->where('r.id_price_change_request', $this->uuid($id))->first()
            ?? throw Authorizations::gone();
        $product = DB::table('products')->where('id_product', $row->product_id)->first();
        $changes = PriceChanges::requested($row);
        $problem = PriceChanges::problem($product, $changes);
        $checks = ['Las ventas ya registradas conservan su precio.'];

        if (isset($changes['commission_pct'])) {
            $waiting = PriceChanges::withoutRate($product->id_product)->distinct()->count('order_id');
            array_unshift($checks, 'Las ventas sin % de este producto que no se liquidaron toman esta comisión'
                .($waiting === 0 ? '.' : ($waiting === 1 ? ' (hoy, 1).' : " (hoy, {$waiting}).")));
        }

        if (filled($row->reason)) {
            $checks[] = "Motivo de Jime: {$row->reason}";
        }

        return [
            ...$this->item($row),
            'pays' => [],
            'unpaid' => [],
            'checks' => $checks,
            'changes' => array_values(array_map(fn (string $column) => [
                'label' => self::LABELS[$column],
                'before' => $this->value($column, $product->{$column}),
                'after' => $this->value($column, $changes[$column]),
            ], array_keys($changes))),
            'can_approve' => $problem === null,
            'blocked_reason' => $problem === null ? null : PriceChanges::outdated($problem),
        ];
    }

    public function approve(User $direction, string $id): void
    {
        $this->prices->approve($direction, $this->uuid($id));
    }

    public function sendBack(User $direction, string $id, string $reason): void
    {
        $this->prices->reject($direction, $this->uuid($id), $reason);
    }

    private function value(string $column, string|int|null $value): string
    {
        if ($value === null) {
            return match ($column) {
                'commission_pct' => 'Por definir',
                default => 'Sin definir',
            };
        }

        return match ($column) {
            'wholesale_min_qty' => "{$value} u.",
            'commission_pct' => PriceChanges::percent($value),
            default => Money::display(Money::toCents((string) $value)),
        };
    }

    private function item(object $row): array
    {
        return Authorizations::item(
            'price_change',
            $row->id_price_change_request,
            "Precio de {$row->product}",
            $row->requester,
            $row->requested_at,
        );
    }

    private function requests()
    {
        return DB::table('price_change_requests as r')
            ->join('products as p', 'p.id_product', '=', 'r.product_id')
            ->join('users as u', 'u.id_user', '=', 'r.requested_by')
            ->where('r.status', 'pending')
            ->select(['r.*', 'p.name as product', 'u.name as requester']);
    }

    private function uuid(string $id): string
    {
        return Str::isUuid($id) ? $id : throw Authorizations::gone();
    }
}
