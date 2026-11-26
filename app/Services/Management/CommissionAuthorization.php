<?php

namespace App\Services\Management;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Control\Commissions;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** La liquidación de comisiones de un mes que envió Jime. */
class CommissionAuthorization implements AuthorizationKind
{
    public function pending(): array
    {
        return $this->sent()
            ->orderBy('s.period')
            ->get()
            ->map(fn (object $row) => $this->item($row))
            ->all();
    }

    public function detail(string $id): array
    {
        $row = $this->sent()->where('s.period', $this->period($id))->first() ?? throw Authorizations::gone();
        $month = Commissions::month($id);
        $sales = array_sum(array_column($month['members'], 'paid_sales'));
        $adjustments = array_sum(array_map(
            fn (array $member) => Money::toCents($member['adjustments']),
            $month['members'],
        ));

        return [
            ...$this->item($row),
            'pays' => collect($month['members'])
                ->filter(fn (array $member) => $member['amount'] !== null && Money::toCents($member['amount']) !== 0)
                ->map(fn (array $member) => ['name' => $member['name'], 'amount' => $member['amount']])
                ->values()->all(),
            'unpaid' => [],
            'checks' => array_values(array_filter([
                $sales === 1 ? '1 venta cobrada' : "{$sales} ventas cobradas",
                $adjustments === 0 ? null : 'Devoluciones descontadas: '.Money::display($adjustments),
            ])),
            'changes' => [],
            'can_approve' => true,
            'blocked_reason' => null,
        ];
    }

    public function approve(User $direction, string $id): void
    {
        DB::transaction(function () use ($direction, $id) {
            $settlement = $this->lockSent($id);

            if ($settlement->sent_by === $direction->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie autoriza lo que envió.');
            }

            $this->update($settlement, [
                'status' => 'authorized',
                'authorized_by' => $direction->getKey(),
                'authorized_at' => now(),
            ]);
        });
    }

    public function sendBack(User $direction, string $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $this->update($this->lockSent($id), [
                'status' => 'returned',
                'return_reason' => $reason,
                'authorized_by' => null,
                'authorized_at' => null,
            ]);
        });
    }

    private function update(object $settlement, array $values): void
    {
        DB::table('commission_settlements')
            ->where('id_commission_settlement', $settlement->id_commission_settlement)
            ->update([...$values, 'updated_at' => now()]);
    }

    private function item(object $row): array
    {
        $total = DB::table('commissions')
            ->where('settlement_id', $row->id_commission_settlement)
            ->pluck('amount_bs')
            ->sum(fn (?string $amount) => Money::toCents($amount ?? '0'));

        return Authorizations::item(
            'commissions',
            substr($row->period, 0, 7),
            'Comisiones de '.Dates::monthName($row->period),
            $row->sender,
            $row->sent_at,
            Money::format($total),
        );
    }

    private function sent()
    {
        return DB::table('commission_settlements as s')
            ->join('users as u', 'u.id_user', '=', 's.sent_by')
            ->where('s.status', 'sent')
            ->select(['s.*', 'u.name as sender']);
    }

    private function lockSent(string $id): object
    {
        $settlement = DB::table('commission_settlements')->where('period', $this->period($id))->lockForUpdate()->first();

        if ($settlement?->status !== 'sent') {
            throw Authorizations::gone();
        }

        return $settlement;
    }

    private function period(string $id): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $id)) {
            throw Authorizations::gone();
        }

        return "{$id}-01";
    }
}
