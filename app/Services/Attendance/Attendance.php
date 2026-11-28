<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class Attendance
{
    public function shifts(User $user): array
    {
        $now = now();
        $shifts = DB::table('attendance_assignments as a')
            ->join('attendance_sites as s', 's.id_site', '=', 'a.site_id')
            ->where('a.user_id', $user->getKey())
            ->where('a.ends_at', '>=', $now->copy()->subDays(14))
            ->where('a.starts_at', '<=', $now->copy()->addDay())
            ->orderByRaw('CASE WHEN a.starts_at <= ? AND a.ends_at >= ? AND a.cancelled_at IS NULL THEN 0 ELSE 1 END', [$now, $now])
            ->orderByDesc('a.starts_at')
            ->limit(100)
            ->get(['a.*', 's.name as site']);
        $marks = DB::table('attendance_marks')->whereIn('assignment_id', $shifts->pluck('id_assignment'))
            ->get()->groupBy('assignment_id');

        return $shifts->map(function (object $shift) use ($now, $marks): array {
            $recorded = $marks->get($shift->id_assignment, collect())->keyBy('kind');

            return [
                'id' => (string) $shift->id_assignment,
                'site' => $shift->site,
                'starts_at' => Dates::toApi($shift->starts_at),
                'ends_at' => Dates::toApi($shift->ends_at),
                'active' => self::active($shift, $now),
                'cancelled' => $shift->cancelled_at !== null && Carbon::parse($shift->cancelled_at)->lte($now),
                'entry_at' => Dates::toApi($recorded->get('entry')?->marked_at),
                'exit_at' => Dates::toApi($recorded->get('exit')?->marked_at),
            ];
        })->all();
    }

    public function mark(User $user, string $clientKey, array $input): array
    {
        $received = now();
        $actor = $user->getKey();

        return DB::transaction(function () use ($actor, $clientKey, $input, $received): array {
            // La clave persiste aunque se reinicie la API o se venza la caché.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["attendance:{$actor}"]);
            $shift = DB::table('attendance_assignments')->where('user_id', $actor)
                ->where('id_assignment', $input['assignment_id'])->sharedLock()->first()
                ?? throw ApiException::notFound('No encontramos ese turno entre tus asignaciones.');
            $kind = $input['kind'];
            $attempt = DB::table('attendance_attempts')->where('actor_id', $actor)->where('client_uuid', $clientKey)->first();

            if ($attempt !== null) {
                if ((string) $attempt->assignment_id !== (string) $shift->id_assignment || $attempt->kind !== $kind) {
                    throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otra marcación.');
                }

                return self::result($attempt->outcome, $attempt->received_at, $attempt->rejection_reason);
            }

            $marks = DB::table('attendance_marks')->where('assignment_id', $shift->id_assignment)->pluck('marked_at', 'kind');
            if ($marks->has($kind)) {
                return self::result('accepted', $marks->get($kind), null);
            }

            $fix = Carbon::parse($input['captured_at']);
            $fence = DB::table('attendance_geofences as g')->join('attendance_policies as p', 'p.id_policy', '=', 'g.policy_id')
                ->where('g.site_id', $shift->site_id)->where('g.valid_from', '<=', self::timestamp($received))
                ->orderByDesc('g.valid_from')->first([
                    'g.id_geofence', 'g.radius_m', 'g.center_latitude', 'g.center_longitude',
                    'p.edge_rule', 'p.max_accuracy_m', 'p.max_fix_age_seconds',
                ]);
            $accuracy = max(0.001, round((float) $input['accuracy_m'], 3));
            $distance = $fence === null ? null : round(self::distance(
                (float) $input['latitude'], (float) $input['longitude'],
                (float) $fence->center_latitude, (float) $fence->center_longitude,
            ), 3);
            $testedDistance = $distance + ($fence?->edge_rule === 'accuracy_within' ? $accuracy : 0);
            $reason = match (true) {
                ! self::active($shift, $received) => 'outside_shift',
                $kind === 'exit' && (! $marks->has('entry') || Carbon::parse($marks->get('entry'))->gt($received)) => 'entry_required',
                $fence === null => 'site_unconfigured',
                $fix->gt($received) || $fix->lt($received->copy()->subSeconds((int) $fence->max_fix_age_seconds)) => 'stale_location',
                $accuracy > (float) $fence->max_accuracy_m => 'poor_accuracy',
                round($testedDistance, 3) > (float) $fence->radius_m => 'outside_site',
                default => null,
            };
            $outcome = $reason === null ? 'accepted' : 'rejected';

            // Las coordenadas del dispositivo solo se usan para calcular la distancia.
            DB::table('attendance_attempts')->insert([
                'actor_id' => $actor,
                'client_uuid' => $clientKey,
                'assignment_id' => $shift->id_assignment,
                'geofence_id' => $fence?->id_geofence,
                'kind' => $kind,
                'received_at' => self::timestamp($received),
                'fix_captured_at' => $reason === null ? self::timestamp($fix) : null,
                'distance_m' => $reason === null ? $distance : null,
                'accuracy_m' => $reason === null ? $accuracy : null,
                'outcome' => $outcome,
                'rejection_reason' => $reason,
            ]);

            return self::result($outcome, $received, $reason);
        });
    }

    private static function active(object $shift, Carbon $now): bool
    {
        return $now->betweenIncluded(Carbon::parse($shift->starts_at), Carbon::parse($shift->ends_at))
            && ($shift->cancelled_at === null || $now->lt(Carbon::parse($shift->cancelled_at)));
    }

    private static function timestamp(Carbon $date): string
    {
        return $date->format('Y-m-d H:i:s.uP');
    }

    private static function distance(float $latitude, float $longitude, float $siteLatitude, float $siteLongitude): float
    {
        $arc = sin(deg2rad($siteLatitude - $latitude) / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($siteLatitude))
            * sin(deg2rad($siteLongitude - $longitude) / 2) ** 2;

        return 12_742_017.6 * asin(sqrt(min(1.0, max(0.0, $arc))));
    }

    private static function result(string $outcome, Carbon|string $received, ?string $reason): array
    {
        return [
            'accepted' => $outcome === 'accepted',
            'marked_at' => $outcome === 'accepted' ? Dates::toApi($received) : null,
            'reason' => $reason,
            'message' => match ($reason) {
                'outside_shift' => 'Tu turno no está abierto para marcar asistencia.',
                'entry_required' => 'Primero marca la entrada de este turno.',
                'site_unconfigured' => 'La sede aún no tiene un rango de asistencia configurado.',
                'stale_location' => 'La ubicación ya no está vigente. Vuelve a marcar y revisa la hora del teléfono.',
                'poor_accuracy' => 'La ubicación es demasiado imprecisa. Activa la ubicación precisa y vuelve a intentar.',
                'outside_site' => 'La ubicación no se pudo verificar dentro del rango de la sede.',
                default => 'Asistencia registrada.',
            },
        ];
    }
}
