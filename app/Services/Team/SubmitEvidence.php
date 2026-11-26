<?php

namespace App\Services\Team;

use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Models\KpiEvidence;
use App\Models\Order;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\WebLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evidencia de un indicador propio: una foto, un enlace o, para quienes
 * venden, un pedido propio. Queda «en revisión» hasta que Jime la valide.
 */
class SubmitEvidence
{
    /** @param  array{kind: string, url?: ?string, order_id?: ?string, note?: ?string}  $data */
    public function submit(User $member, string $resultId, array $data, ?UploadedFile $file, string $clientUuid): KpiEvidence
    {
        $stored = null;

        try {
            return DB::transaction(function () use ($member, $resultId, $data, $file, $clientUuid, &$stored): KpiEvidence {
                $result = $this->lockOwnResult($member, $resultId);

                // El mismo envío repetido (si se perdió la respuesta guardada).
                $previous = KpiEvidence::query()->where('client_uuid', $clientUuid)->first();
                if ($previous) {
                    return $previous->kpi_weekly_result_id === $resultId
                        ? $previous
                        : throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otro envío.');
                }

                $this->ensureOpen($member, $result->week_id, $resultId);

                $content = match ($data['kind']) {
                    'photo' => [
                        'url' => $stored = PrivateFiles::put($file, "evidencias/{$resultId}", 'evidencia'),
                        'public_id' => $stored,
                    ],
                    'link' => ['url' => WebLinks::require($data['url'])],
                    'order' => ['order_id' => $this->ownOrder($member, $data['order_id'])->getKey()],
                };

                return KpiEvidence::create([
                    'kpi_weekly_result_id' => $resultId,
                    'kind' => $data['kind'],
                    'status' => 'in_review',
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'submitted_by' => $member->getKey(),
                    'submitted_at' => now(),
                    'client_uuid' => $clientUuid,
                    ...$content,
                ])->refresh();
            });
        } catch (Throwable $e) {
            PrivateFiles::forget($stored);

            throw $e;
        }
    }

    /** Bloqueado: Jime no puede validarlo mientras se agrega. */
    private function lockOwnResult(User $member, string $resultId): object
    {
        return DB::table('kpi_weekly_results')
            ->where('id_kpi_weekly_result', $resultId)
            ->where('user_id', $member->getKey())
            ->lockForUpdate()
            ->first()
            ?? throw ApiException::notFound('No encontramos ese indicador entre los tuyos.');
    }

    private function ensureOpen(User $member, string $weekId, string $resultId): void
    {
        // Una semana que todavía no empieza no existe para el equipo.
        BonusWeek::started(BonusWeek::query()->findOrFail($weekId)->number);

        if (! MemberKpi::acceptsEvidence($member->getKey(), $weekId, lock: true)) {
            throw ApiException::conflict('week_closed', 'Esta semana ya se cerró.');
        }

        $latest = KpiEvidence::query()
            ->where('kpi_weekly_result_id', $resultId)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id_kpi_evidence')
            ->value('status');

        if ($latest === 'validated') {
            throw ApiException::conflict('evidence_validated', 'Jime ya validó este indicador.');
        }
    }

    private function ownOrder(User $member, string $orderId): Order
    {
        if (! $member->role->canSell()) {
            throw ApiException::invalid('Solo quienes venden adjuntan pedidos.');
        }

        $order = Order::query()->where('seller_id', $member->getKey())->find($orderId)
            ?? throw ApiException::notFound('No encontramos ese pedido.');

        if ($order->status->isVoid()) {
            throw ApiException::invalid('Ese pedido está anulado.');
        }

        return $order;
    }
}
