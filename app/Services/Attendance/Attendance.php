<?php

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class Attendance
{
    public const SITE_CODE = 'PRINCIPAL';

    private const RADIUS_M = 50;

    private const MAX_ACCURACY_M = 30;

    public function status(User $user): array
    {
        $now = now();
        $fence = $this->currentFence($now);
        $canConfigure = self::canConfigure($user);
        $marks = DB::table('attendance_attempts')->where('actor_id', $user->getKey())
            ->where('outcome', 'accepted')->where('business_date', $now->toDateString())
            ->orderByDesc('received_at')->get()->groupBy('kind');

        return [
            'date' => $now->toDateString(),
            'site' => $fence?->site_name,
            'can_configure' => $canConfigure,
            'radius_m' => self::RADIUS_M,
            'max_accuracy_m' => self::MAX_ACCURACY_M,
            'center' => $canConfigure && $fence !== null ? [
                'latitude' => (float) $fence->center_latitude,
                'longitude' => (float) $fence->center_longitude,
            ] : null,
            'entry_at' => Dates::toApi($marks->get('entry')?->first()?->received_at),
            'exit_at' => Dates::toApi($marks->get('exit')?->first()?->received_at),
        ];
    }

    public function configure(User $user, float $latitude, float $longitude): array
    {
        if (! self::canConfigure($user)) {
            throw ApiException::forbidden('Solo Jimena y Miguel pueden configurar la sede.');
        }

        return DB::transaction(function () use ($user, $latitude, $longitude): array {
            DB::table('attendance_sites')->insertOrIgnore(['code' => self::SITE_CODE, 'name' => 'Sede principal']);
            $site = DB::table('attendance_sites')->where('code', self::SITE_CODE)->lockForUpdate()->first();
            $now = now();
            $existing = $this->currentFence($now);
            if ($existing !== null && (float) $existing->center_latitude === round($latitude, 6)
                && (float) $existing->center_longitude === round($longitude, 6)
                && (float) $existing->radius_m === (float) self::RADIUS_M
                && (float) $existing->max_accuracy_m === (float) self::MAX_ACCURACY_M
                && $existing->edge_rule === 'center_within') {
                return $this->status($user);
            }
            $policy = DB::table('attendance_policies')->insertGetId([
                'max_accuracy_m' => self::MAX_ACCURACY_M,
                'max_fix_age_seconds' => 90,
                'edge_rule' => 'center_within',
                'created_by' => $user->getKey(),
            ], 'id_policy');
            DB::table('attendance_geofences')->insert([
                'site_id' => $site->id_site,
                'policy_id' => $policy,
                'center_latitude' => $latitude,
                'center_longitude' => $longitude,
                'radius_m' => self::RADIUS_M,
                'valid_from' => self::timestamp($now),
                'created_by' => $user->getKey(),
            ]);

            return $this->status($user);
        });
    }

    private static function canConfigure(User $user): bool
    {
        return in_array($user->role, [UserRole::Control, UserRole::Management], true);
    }

    private function currentFence(Carbon $now): ?object
    {
        return DB::table('attendance_geofences as g')
            ->join('attendance_sites as s', 's.id_site', '=', 'g.site_id')
            ->join('attendance_policies as p', 'p.id_policy', '=', 'g.policy_id')
            ->where('s.code', self::SITE_CODE)->where('g.valid_from', '<=', self::timestamp($now))
            ->orderByDesc('g.valid_from')->first([
                'g.id_geofence', 'g.radius_m', 'g.center_latitude', 'g.center_longitude',
                's.name as site_name', 'p.edge_rule', 'p.max_accuracy_m', 'p.max_fix_age_seconds',
            ]);
    }

    public function mark(User $user, string $clientKey, array $input): array
    {
        $received = now();
        $actor = $user->getKey();

        return DB::transaction(function () use ($actor, $clientKey, $input, $received): array {
            // La clave persiste aunque se reinicie la API o se venza la caché.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["attendance:{$actor}"]);
            $kind = $input['kind'];
            $attempt = DB::table('attendance_attempts')->where('actor_id', $actor)->where('client_uuid', $clientKey)->first();

            if ($attempt !== null) {
                if ($attempt->kind !== $kind) {
                    throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otra marcación.');
                }

                return self::result($attempt->outcome, $attempt->received_at, $attempt->rejection_reason);
            }

            $marks = DB::table('attendance_attempts')->where('actor_id', $actor)
                ->where('outcome', 'accepted')->where('business_date', $received->toDateString())
                ->orderBy('received_at')->pluck('received_at', 'kind');
            if ($marks->has($kind)) {
                return self::result('accepted', $marks->get($kind), null);
            }

            $fix = Carbon::parse($input['captured_at']);
            DB::table('attendance_sites')->where('code', self::SITE_CODE)->sharedLock()->first();
            $fence = $this->currentFence($received);
            $accuracy = max(0.001, round((float) $input['accuracy_m'], 3));
            $distance = $fence === null ? null : round(self::distance(
                (float) $input['latitude'], (float) $input['longitude'],
                (float) $fence->center_latitude, (float) $fence->center_longitude,
            ), 3);
            $testedDistance = $distance + ($fence?->edge_rule === 'accuracy_within' ? $accuracy : 0);
            $reason = match (true) {
                $kind === 'exit' && (! $marks->has('entry') || Carbon::parse($marks->get('entry'))->gt($received)) => 'entry_required',
                $fence === null => 'site_unconfigured',
                $fix->gt($received) || $fix->lt($received->copy()->subSeconds((int) $fence->max_fix_age_seconds)) => 'stale_location',
                $accuracy > min(self::MAX_ACCURACY_M, (float) $fence->max_accuracy_m) => 'poor_accuracy',
                $distance > self::RADIUS_M || round($testedDistance, 3) > (float) $fence->radius_m => 'outside_site',
                default => null,
            };
            $outcome = $reason === null ? 'accepted' : 'rejected';

            // Las coordenadas del dispositivo solo se usan para calcular la distancia.
            DB::table('attendance_attempts')->insert([
                'actor_id' => $actor,
                'client_uuid' => $clientKey,
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
                'entry_required' => 'Primero marca la entrada de hoy.',
                'site_unconfigured' => 'La sede aún no tiene un rango de asistencia configurado.',
                'stale_location' => 'La ubicación ya no está vigente. Vuelve a marcar y revisa la hora del teléfono.',
                'poor_accuracy' => 'La ubicación es demasiado imprecisa. Activa la ubicación precisa y vuelve a intentar.',
                'outside_site' => 'La ubicación no se pudo verificar dentro del rango de la sede.',
                default => 'Asistencia registrada.',
            },
        ];
    }
}
