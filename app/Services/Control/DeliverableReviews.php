<?php

namespace App\Services\Control;

use App\Exceptions\ApiException;
use App\Models\SystemDeliverable;
use App\Models\User;
use App\Services\Team\WeekLock;
use Illuminate\Support\Facades\DB;

/**
 * Las entregas de Sistemas. Jime comprueba la fecha real: de ella sale «a
 * tiempo» o «fuera de plazo» (lo calcula la base).
 */
class DeliverableReviews
{
    /** @return list<array<string, ?string>> las 8, en el orden del Excel */
    public static function all(): array
    {
        return SystemDeliverable::query()
            ->with('member')
            ->orderBy('sort_order')
            ->get()
            ->map->toApi()
            ->values()
            ->all();
    }

    public function approve(User $jime, string $id, string $deliveredOn): SystemDeliverable
    {
        return $this->review($jime, $id, [
            'delivered_on' => $deliveredOn,
            'jime_validated' => true,
            'review_note' => null,
        ]);
    }

    public function reject(User $jime, string $id, string $note): SystemDeliverable
    {
        return $this->review($jime, $id, ['jime_validated' => false, 'review_note' => $note]);
    }

    /** @param  array<string, mixed>  $values */
    private function review(User $jime, string $id, array $values): SystemDeliverable
    {
        return DB::transaction(function () use ($jime, $id, $values) {
            $deliverable = SystemDeliverable::query()->lockForUpdate()->find($id)
                ?? throw ApiException::notFound('No encontramos esa entrega.');

            if ($deliverable->apiStatus() !== 'in_review') {
                throw ApiException::conflict('already_reviewed', 'Esa entrega no está esperando revisión.');
            }

            if ($deliverable->user_id === $jime->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie valida su propia entrega.');
            }

            WeekLock::ensureOpen($deliverable->week_id);

            $deliverable->update([
                ...$values,
                'validated_by' => $jime->getKey(),
                'validated_at' => now(),
            ]);

            // `result` lo recalcula la base.
            return $deliverable->refresh()->load('member');
        });
    }
}
