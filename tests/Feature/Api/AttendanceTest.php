<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

final class AttendanceTest extends ApiTestCase
{
    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-02 00:05:00');
        $this->artisan('traigo:asistencia-sede', [
            'codigo' => 'LOCAL',
            'nombre' => 'Sede de pruebas',
            'latitud' => '-17',
            'longitud' => '-63',
            'radio' => '100',
            'responsable' => 'JIM-01',
        ])->assertSuccessful();
        $this->artisan('traigo:asistencia-turno', [
            'persona' => 'ALI-04',
            'sede' => 'LOCAL',
            'inicio' => '2026-12-01 23:00',
            'fin' => '2026-12-02 06:00',
            'responsable' => 'JIM-01',
        ])->assertSuccessful();
        $this->shift = (string) DB::table('attendance_assignments')->value('id_assignment');
    }

    #[Test]
    public function registra_entrada_y_salida_del_turno_sin_duplicar(): void
    {
        $this->as($this->tokenFor('ALI-04'));
        $this->getJson('/api/v1/me/attendance')->assertOk()
            ->assertJsonPath('data.0.id', $this->shift)->assertJsonPath('data.0.active', true);
        $this->mark(['kind' => 'exit'])->assertOk()->assertJsonPath('data.reason', 'entry_required');
        $key = (string) Str::uuid();
        $first = $this->mark(key: $key)->assertOk()->assertJsonPath('data.accepted', true)->json('data.marked_at');
        $this->travel(1)->minutes();
        $this->mark(key: $key)->assertOk()->assertJsonPath('data.marked_at', $first);
        $this->mark()->assertOk()->assertJsonPath('data.marked_at', $first);
        $this->mark(['kind' => 'exit'], key: $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->mark(['kind' => 'exit'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->getJson('/api/v1/me/attendance')->assertOk()
            ->assertJsonPath('data.0.entry_at', $first)->assertJsonPath('data.0.exit_at', now()->toIso8601String());
        $this->assertDatabaseCount('attendance_marks', 2);
    }

    #[Test]
    public function rechaza_lecturas_fuera_del_rango_imprecisas_o_antiguas(): void
    {
        $this->as($this->tokenFor('ALI-04'));
        $key = (string) Str::uuid();
        $this->mark(['latitude' => -17.00086], key: $key)->assertOk()
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
    public function el_turno_solo_es_visible_y_marcable_por_su_titular(): void
    {
        $this->getJson('/api/v1/me/attendance')->assertUnauthorized();
        $this->as($this->tokenFor('ISA-03'));
        $this->getJson('/api/v1/me/attendance')->assertOk()->assertJsonCount(0, 'data');
        $this->mark()->assertNotFound();
        $this->as($this->tokenFor('ALI-04'));
        $this->travelTo('2026-12-02 06:01:00');
        $this->mark()->assertOk()->assertJsonPath('data.reason', 'outside_shift');
        $this->assertDatabaseCount('attendance_marks', 0);
    }

    private function mark(array $data = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/me/attendance', [
            'assignment_id' => $this->shift,
            'kind' => 'entry',
            'latitude' => -17,
            'longitude' => -63,
            'accuracy_m' => 10,
            'captured_at' => now()->subSecond()->toIso8601String(),
            ...$data,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
