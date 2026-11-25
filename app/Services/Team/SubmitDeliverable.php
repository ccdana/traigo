<?php

namespace App\Services\Team;

use App\Exceptions\ApiException;
use App\Models\SystemDeliverable;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\WebLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Una entrega de Sistemas con su evidencia (foto o enlace). La fecha real la
 * pone quien entrega y Jime la comprueba. Mientras no esté validada, enviar
 * otra vez reemplaza lo anterior.
 */
class SubmitDeliverable
{
    public function submit(User $member, string $id, string $deliveredOn, ?UploadedFile $file, ?string $url): SystemDeliverable
    {
        $stored = null;

        try {
            return DB::transaction(function () use ($member, $id, $deliveredOn, $file, $url, &$stored): SystemDeliverable {
                $deliverable = SystemDeliverable::query()
                    ->where('user_id', $member->getKey())
                    ->lockForUpdate()
                    ->find($id)
                    ?? throw ApiException::notFound('No encontramos esa entrega entre las tuyas.');

                if ($deliverable->isValidated()) {
                    throw ApiException::conflict('deliverable_validated', 'Jime ya validó esta entrega.');
                }

                if (! MemberKpi::acceptsEvidence($member->getKey(), $deliverable->week_id)) {
                    throw ApiException::conflict('week_closed', 'Esta semana ya se cerró.');
                }

                // Una foto o, si no hay, un enlace.
                $evidence = $file
                    ? ($stored = PrivateFiles::put($file, "entregas/{$id}", 'entrega'))
                    : WebLinks::require((string) $url);

                // Queda sin revisar: «en revisión» para la app.
                $deliverable->update([
                    'delivered_on' => $deliveredOn,
                    'evidence_url' => $evidence,
                    'evidence_public_id' => $stored,
                    'jime_validated' => null,
                    'validated_by' => null,
                    'validated_at' => null,
                    'review_note' => null,
                ]);

                // `result` lo recalcula la base.
                return $deliverable->refresh();
            });
        } catch (Throwable $e) {
            PrivateFiles::forget($stored);

            throw $e;
        }
    }
}
