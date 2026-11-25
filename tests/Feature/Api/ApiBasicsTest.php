<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Lo que comparten todas las rutas: errores, historial y envíos repetidos. */
class ApiBasicsTest extends ApiTestCase
{
    private function defineRoute(string $uri, callable $action): void
    {
        Route::post("api/v1/_prueba/{$uri}", $action)
            ->middleware(['auth:sanctum', 'staff', 'actor', 'idempotent']);
    }

    #[Test]
    public function las_respuestas_de_la_api_no_se_anotan_como_errores(): void
    {
        Exceptions::fake();

        $this->login('ALI-04', '0000')->assertUnauthorized();

        Exceptions::assertNothingReported();
    }

    #[Test]
    public function lo_que_no_existe_responde_en_el_formato_del_contrato(): void
    {
        $this->getJson('/api/v1/no-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => 'No encontramos lo que pediste.']);

        $this->deleteJson('/api/v1/auth/login')
            ->assertMethodNotAllowed()
            ->assertExactJson(['message' => 'Esa operación no existe.']);
    }

    #[Test]
    public function una_falla_del_servidor_no_muestra_detalles(): void
    {
        config(['app.debug' => false]);
        $this->defineRoute('falla', fn () => throw new RuntimeException('clave secreta'));

        $this->as($this->tokenFor('ALI-04'))->postJson('/api/v1/_prueba/falla')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Algo falló en el servidor. Intenta de nuevo.']);
    }

    #[Test]
    public function las_reglas_de_la_base_llegan_como_conflicto_en_espanol(): void
    {
        // Como en la API, cada cambio va en su transacción (aquí, un punto de
        // guardado dentro de la de la prueba).
        DB::table('audit_logs')->insert(['action' => 'insert', 'entity_type' => 'x', 'entity_id' => '1']);
        // Un disparador de la base con su propio mensaje: el historial no se edita.
        $this->defineRoute('historial', fn () => DB::transaction(
            fn () => DB::table('audit_logs')->update(['action' => 'otro']),
        ));
        // Una restricción de columna: su mensaje en inglés no se muestra.
        $this->defineRoute('restriccion', fn () => DB::transaction(
            fn () => DB::table('users')->where('code', 'ALI-04')->update(['failed_login_attempts' => -1]),
        ));
        $token = $this->tokenFor('ALI-04');

        $this->as($token)->postJson('/api/v1/_prueba/historial')
            ->assertConflict()
            ->assertExactJson(['message' => 'La tabla audit_logs solo admite agregar filas.']);

        $this->as($token)->postJson('/api/v1/_prueba/restriccion')
            ->assertConflict()
            ->assertExactJson(['message' => 'No se puede guardar: choca con una regla de los datos.']);
    }

    #[Test]
    public function el_historial_guarda_quien_hizo_el_cambio(): void
    {
        $this->defineRoute('desactivar', function () {
            DB::table('users')->where('code', 'KIM-05')->update(['is_active' => false]);

            return ['data' => true];
        });

        $this->as($this->tokenFor('JIM-01'))->postJson('/api/v1/_prueba/desactivar')->assertOk();

        $entry = DB::table('audit_logs')->where('entity_type', 'users')->latest('id_audit_log')->first();
        $this->assertSame($this->member('JIM-01')->getKey(), $entry->actor_id);
        $this->assertStringNotContainsString('pin_hash', (string) $entry->new_values);
        // Al terminar la petición ya no queda nadie como autor.
        $this->assertSame('', DB::scalar("select coalesce(current_setting('traigo.user_id', true), '')"));
    }

    #[Test]
    public function un_envio_repetido_con_la_misma_clave_se_hace_una_vez(): void
    {
        $calls = 0;
        $this->defineRoute('venta', function () use (&$calls) {
            $calls++;

            return response()->json(['data' => ['numero' => $calls]], 201);
        });
        $token = $this->tokenFor('ALI-04');
        $key = [EnsureIdempotency::HEADER => 'clave-de-prueba-1'];

        $this->as($token)->postJson('/api/v1/_prueba/venta', ['total' => '87.00'], $key)->assertCreated();
        $this->as($token)->postJson('/api/v1/_prueba/venta', ['total' => '87.00'], $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson(['data' => ['numero' => 1]]);
        $this->assertSame(1, $calls);

        $this->as($token)->postJson('/api/v1/_prueba/venta', ['total' => '90.00'], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');

        // La clave es de cada persona.
        $this->as($this->tokenFor('KIM-05'))
            ->postJson('/api/v1/_prueba/venta', ['total' => '90.00'], $key)
            ->assertCreated();
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function un_envio_con_error_se_puede_reintentar_con_la_misma_clave(): void
    {
        $calls = 0;
        $this->defineRoute('intento', function () use (&$calls) {
            $calls++;

            return response()->json(['data' => $calls], $calls === 1 ? 409 : 201);
        });
        $token = $this->tokenFor('ALI-04');
        $key = [EnsureIdempotency::HEADER => 'clave-de-prueba-2'];

        $this->as($token)->postJson('/api/v1/_prueba/intento', [], $key)->assertConflict();
        $this->as($token)->postJson('/api/v1/_prueba/intento', [], $key)->assertCreated();

        $this->as($token)->postJson('/api/v1/_prueba/intento', [], [EnsureIdempotency::HEADER => 'x'])
            ->assertUnprocessable();
    }
}
