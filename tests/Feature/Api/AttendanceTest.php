<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

final class AttendanceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-02 00:05:00');
        $this->as($this->tokenFor('JIM-01'))->putJson('/api/v1/attendance/site', [
            'latitude' => -17, 'longitude' => -63,
        ])->assertOk();
    }

    #[Test]
    public function registra_entrada_y_salida_diarias_sin_turnos_ni_duplicados(): void
    {
        $this->as($this->tokenFor('ALI-04'));
        $this->getJson('/api/v1/me/attendance')->assertOk()
            ->assertJsonPath('data.date', '2026-12-02')->assertJsonPath('data.radius_m', 50);
        $this->mark(['kind' => 'exit'])->assertOk()->assertJsonPath('data.reason', 'entry_required');
        $key = (string) Str::uuid();
        $first = $this->mark(['latitude' => -17.00044, 'accuracy_m' => 30], key: $key)
            ->assertOk()->assertJsonPath('data.accepted', true)->json('data.marked_at');
        $this->travel(1)->minutes();
        $this->mark(key: $key)->assertOk()->assertJsonPath('data.marked_at', $first);
        $this->mark()->assertOk()->assertJsonPath('data.marked_at', $first);
        $this->mark(['kind' => 'exit'], key: $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->mark(['kind' => 'exit'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->getJson('/api/v1/me/attendance')->assertOk()
            ->assertJsonPath('data.entry_at', $first)->assertJsonPath('data.exit_at', now()->toIso8601String());
        $this->assertDatabaseCount('attendance_marks', 2);
        $this->assertDatabaseCount('attendance_assignments', 0);
    }

    #[Test]
    public function rechaza_lecturas_fuera_del_rango_imprecisas_o_antiguas(): void
    {
        $this->as($this->tokenFor('ALI-04'));
        $key = (string) Str::uuid();
        $this->mark(['latitude' => -17.00050], key: $key)->assertOk()
            ->assertJsonPath('data.reason', 'outside_site');
        $this->mark(key: $key)->assertOk()->assertJsonPath('data.reason', 'outside_site');
        $this->mark(['accuracy_m' => 40])->assertOk()->assertJsonPath('data.reason', 'poor_accuracy');
        $this->mark(['captured_at' => now()->subMinutes(3)->toIso8601String()])->assertOk()
            ->assertJsonPath('data.reason', 'stale_location');
        $this->mark(['latitude' => 91])->assertUnprocessable();
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertDatabaseCount('attendance_attempts', 3);
        $this->assertSame(0, DB::table('attendance_attempts')->whereNotNull('fix_captured_at')->count());
    }

    #[Test]
    public function las_marcas_son_personales_y_solo_jimena_y_miguel_configuran(): void
    {
        $this->as(null)->getJson('/api/v1/me/attendance')->assertUnauthorized();
        $this->as($this->tokenFor('ALI-04'));
        $this->mark()->assertOk()->assertJsonPath('data.accepted', true);
        $this->as($this->tokenFor('ISA-03'));
        $this->getJson('/api/v1/me/attendance')->assertOk()->assertJsonPath('data.entry_at', null)
            ->assertJsonPath('data.can_configure', false)->assertJsonPath('data.center', null);
        $this->putJson('/api/v1/attendance/site', ['latitude' => -18, 'longitude' => -64])->assertForbidden();
        $this->travel(1)->seconds();
        $this->as($this->tokenFor('DIR-08'))
            ->putJson('/api/v1/attendance/site', ['latitude' => -18, 'longitude' => -64])
            ->assertOk()->assertJsonPath('data.can_configure', true)->assertJsonPath('data.center.latitude', -18);
        $this->mark()->assertOk()->assertJsonPath('data.reason', 'outside_site');
        $this->mark(['latitude' => -18, 'longitude' => -64])->assertOk()->assertJsonPath('data.accepted', true);
        $this->assertDatabaseCount('attendance_geofences', 2);
        $this->assertDatabaseCount('attendance_marks', 2);
    }

    private function mark(array $data = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/me/attendance', [
            'kind' => 'entry',
            'latitude' => -17,
            'longitude' => -63,
            'accuracy_m' => 10,
            'captured_at' => now()->subSecond()->toIso8601String(),
            ...$data,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
