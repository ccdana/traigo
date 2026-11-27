<?php

namespace Tests\Feature\Web;

use App\Mail\CustomerPasswordResetMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class CustomerAccountTest extends WebTestCase
{
    /** La que pone la fábrica de usuarios en las pruebas. */
    private const PASSWORD = 'clave-de-prueba';

    private function customer(array $data = []): User
    {
        return User::factory()->customer()->create(['email' => 'ana@correo.test', ...$data]);
    }

    /** @param  array<string, mixed>  $data */
    private function register(array $data = []): TestResponse
    {
        return $this->from('/registro')->post('/registro', [
            'name' => 'Ana',
            'lastname' => 'Rojas',
            'phone' => '+591 712-00000',
            'email' => 'Ana@Correo.test',
            'password' => 'una-clave-larga',
            'password_confirmation' => 'una-clave-larga',
            ...$data,
        ]);
    }

    private function signIn(string $email, string $password = self::PASSWORD, array $data = []): TestResponse
    {
        return $this->from('/ingresar')->post('/ingresar', ['email' => $email, 'password' => $password, ...$data]);
    }

    private function fillBag(): void
    {
        $this->stock($this->product(), 10);
        $this->post('/bolsa', ['product_id' => $this->product()->getKey(), 'quantity' => 1]);
    }

    #[Test]
    public function mirar_la_tienda_y_armar_la_bolsa_no_pide_cuenta(): void
    {
        $this->fillBag();

        foreach (['/', '/categoria/macetas', '/producto/'.$this->product()->slug, '/por-mayor', '/bolsa'] as $page) {
            $this->get($page)->assertOk()->assertSee('Ingresar');
        }

        $this->get('/bolsa')->assertViewHas('units', 1);
    }

    #[Test]
    public function comprar_ver_pedidos_y_la_cuenta_piden_ingresar(): void
    {
        $this->fillBag();

        foreach (['/pago', '/mis-pedidos', '/mi-cuenta'] as $page) {
            $this->get($page)->assertRedirect('/ingresar');
        }

        $this->post('/pago', [])->assertRedirect('/ingresar');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->level($this->product())->reserved);
    }

    #[Test]
    public function al_crear_la_cuenta_vuelve_al_pago_con_su_bolsa(): void
    {
        DB::table('settings')->where('key', 'payment_qr_url')->update(['value' => json_encode('https://example.test/qr.png')]);
        $this->fillBag();
        $this->get('/pago')->assertRedirect('/ingresar');

        $this->register()->assertSessionHasNoErrors()->assertRedirect('/pago');

        $customer = User::query()->where('email', 'ana@correo.test')->firstOrFail();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $this->assertSame('customer', $customer->role->value);
        $this->assertNull($customer->code);
        $this->assertSame('71200000', $customer->phone);
        $this->assertTrue(Hash::check('una-clave-larga', $customer->password));
        $this->get('/pago')->assertOk()->assertSee('Ana Rojas')->assertViewHas('units', 1);
    }

    #[Test]
    public function el_registro_no_permite_elegir_rol_ni_codigo(): void
    {
        $this->register(['role' => 'management', 'code' => 'DIR-99', 'pin_hash' => Hash::make('1111'), 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'ana@correo.test', 'role' => 'customer', 'code' => null, 'pin_hash' => null, 'is_active' => true,
        ]);
    }

    #[Test]
    public function el_registro_pide_los_datos_basicos_bien_escritos(): void
    {
        $this->register(['lastname' => ''])->assertSessionHasErrors('lastname');
        $this->register(['phone' => 'llámame'])->assertSessionHasErrors('phone');
        $this->register(['email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->register(['password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors('password');
        $this->register(['password_confirmation' => 'otra-distinta'])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->where('role', 'customer')->count());
    }

    #[Test]
    public function un_correo_no_se_usa_dos_veces_ni_el_de_alguien_del_equipo(): void
    {
        $this->customer();

        $this->register(['email' => 'ANA@correo.test'])->assertSessionHasErrors('email');
        $this->register(['email' => $this->member('JIM-01')->email])->assertSessionHasErrors('email');
    }

    #[Test]
    public function ingresa_con_su_correo_y_el_error_no_revela_si_la_cuenta_existe(): void
    {
        $customer = $this->customer();

        $message = ['email' => 'Correo o contraseña incorrectos.'];
        $this->signIn('ana@correo.test', 'equivocada')->assertSessionHasErrors($message);
        $this->signIn('nadie@correo.test')->assertSessionHasErrors($message);
        $this->assertGuest('customer');

        $this->signIn(' ANA@correo.test ')->assertRedirect('/');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    #[Test]
    public function el_equipo_y_las_cuentas_desactivadas_no_entran_como_clientes(): void
    {
        $jime = $this->member('JIM-01');
        $jime->update(['password' => self::PASSWORD]);
        $this->customer(['is_active' => false]);

        $this->signIn($jime->email)->assertSessionHasErrors('email');
        $this->signIn('ana@correo.test')->assertSessionHasErrors('email');

        $this->assertGuest('customer');
        $this->assertGuest('web');
    }

    #[Test]
    public function desactivar_la_cuenta_cierra_la_sesion_abierta(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer');
        $customer->update(['is_active' => false]);

        $this->get('/mis-pedidos')->assertRedirect('/ingresar');
        $this->assertGuest('customer');
    }

    #[Test]
    public function la_sesion_de_cliente_no_abre_el_panel_ni_la_api(): void
    {
        $this->actingAs($this->customer(), 'customer');

        $this->get('/equipo/inventario')->assertRedirect('/equipo/ingresar');
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
    }

    #[Test]
    public function la_sesion_del_panel_no_sirve_para_comprar(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');

        $this->get('/pago')->assertRedirect('/ingresar');
        $this->get('/mis-pedidos')->assertRedirect('/ingresar');
    }

    #[Test]
    public function al_salir_se_borra_tambien_la_bolsa(): void
    {
        $this->customer();
        $this->signIn('ana@correo.test');
        $this->fillBag();

        $this->post('/salir')->assertRedirect('/');

        $this->assertGuest('customer');
        $this->get('/bolsa')->assertSee('Tu bolsa está vacía.');
    }

    #[Test]
    public function edita_sus_datos_y_cambiar_el_correo_pide_la_contrasena(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer');
        $data = ['name' => 'Ana María', 'lastname' => 'Rojas', 'phone' => '72222222', 'email' => 'ana@correo.test'];

        $this->get('/mi-cuenta')->assertOk()->assertSee($customer->phone);
        $this->from('/mi-cuenta')->patch('/mi-cuenta', $data)->assertSessionHasNoErrors();
        $this->assertSame('72222222', $customer->refresh()->phone);

        $this->patch('/mi-cuenta', [...$data, 'email' => 'nuevo@correo.test'])->assertSessionHasErrors('current_password');
        $this->patch('/mi-cuenta', [...$data, 'email' => 'nuevo@correo.test', 'current_password' => 'equivocada'])
            ->assertSessionHasErrors('current_password');
        $this->patch('/mi-cuenta', [...$data, 'email' => 'nuevo@correo.test', 'current_password' => self::PASSWORD, 'role' => 'management'])
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('nuevo@correo.test', $customer->email);
        $this->assertSame('customer', $customer->role->value);
    }

    #[Test]
    public function cambia_su_contrasena_solo_con_la_actual(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer')->from('/mi-cuenta');
        $new = ['password' => 'otra-clave-larga', 'password_confirmation' => 'otra-clave-larga'];

        $this->put('/mi-cuenta/clave', [...$new, 'current_password' => 'equivocada'])->assertSessionHasErrors('current_password');
        $this->put('/mi-cuenta/clave', [...$new, 'current_password' => self::PASSWORD])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('otra-clave-larga', $customer->refresh()->password));
        // La sesión desde donde la cambió sigue abierta.
        $this->get('/mi-cuenta')->assertOk();
    }

    #[Test]
    public function una_sesion_con_la_contrasena_antigua_se_cierra(): void
    {
        $customer = $this->customer();
        $this->signIn('ana@correo.test');
        $this->get('/mi-cuenta')->assertOk();

        // Cambiada desde otro equipo.
        DB::table('users')->where('id_user', $customer->getKey())->update(['password' => Hash::make('cambiada-afuera')]);
        $this->app['auth']->forgetGuards();

        $this->get('/mi-cuenta')->assertRedirect('/ingresar');
    }

    #[Test]
    public function recuperar_la_contrasena_envia_el_enlace_solo_a_clientes_sin_revelar_nada(): void
    {
        Mail::fake();
        $this->customer();
        $answers = [];

        foreach (['ana@correo.test', 'nadie@correo.test', $this->member('JIM-01')->email] as $email) {
            $answers[] = $this->from('/olvide-mi-clave')->post('/olvide-mi-clave', ['email' => $email])
                ->getSession()->get('status');
        }

        $this->assertCount(1, array_unique($answers));
        Mail::assertSent(CustomerPasswordResetMail::class, 1);
        Mail::assertSent(CustomerPasswordResetMail::class, fn ($mail) => $mail->hasTo('ana@correo.test')
            && str_contains($mail->resetUrl, '/restablecer-clave/'));
    }

    #[Test]
    public function con_el_enlace_pone_una_contrasena_nueva_una_sola_vez(): void
    {
        Mail::fake();
        $customer = $this->customer();
        $this->post('/olvide-mi-clave', ['email' => 'ana@correo.test']);
        $url = Mail::sent(CustomerPasswordResetMail::class)->first()->resetUrl;
        $token = Str::before(Str::after($url, '/restablecer-clave/'), '?');
        $data = ['token' => $token, 'email' => 'ana@correo.test', 'password' => 'clave-recuperada', 'password_confirmation' => 'clave-recuperada'];

        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/restablecer-clave', [...$data, 'token' => 'inventado'])->assertSessionHasErrors('email');
        $this->post('/restablecer-clave', $data)->assertRedirect('/ingresar');
        $this->post('/restablecer-clave', $data)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('clave-recuperada', $customer->refresh()->password));
    }

    #[Test]
    public function ni_con_un_enlace_valido_se_toca_una_cuenta_del_equipo(): void
    {
        $jime = $this->member('JIM-01');
        $token = Password::createToken($jime);

        $this->post('/restablecer-clave', [
            'token' => $token, 'email' => $jime->email,
            'password' => 'intento-de-robo', 'password_confirmation' => 'intento-de-robo',
        ])->assertSessionHasErrors('email');

        $this->assertNull($jime->refresh()->password);
    }

    #[Test]
    public function otra_cuenta_puede_ingresar_en_la_misma_sesion_del_navegador(): void
    {
        $this->customer();
        $second = User::factory()->customer()->create(['email' => 'beto@correo.test', 'password' => 'clave-de-beto']);
        $this->signIn('ana@correo.test');
        $this->get('/mi-cuenta')->assertOk();

        // La primera sale sin cerrar la sesión del navegador (venció su ingreso).
        $this->app['auth']->guard('customer')->logoutCurrentDevice();
        $this->app['auth']->forgetGuards();
        $this->signIn('beto@correo.test', 'clave-de-beto');
        $this->app['auth']->forgetGuards();

        $this->get('/mi-cuenta')->assertOk()->assertSee($second->phone);
    }

    #[Test]
    public function muchos_intentos_de_ingreso_se_frenan(): void
    {
        $this->customer();

        foreach (range(1, 5) as $attempt) {
            $this->signIn('ana@correo.test', 'equivocada');
        }

        $this->signIn('ana@correo.test')->assertTooManyRequests()->assertSee('Demasiados intentos');
    }
}
