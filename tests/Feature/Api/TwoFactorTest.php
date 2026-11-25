<?php

namespace Tests\Feature\Api;

use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TwoFactorTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
    }

    /** El código del último correo enviado. */
    private function sentCode(): string
    {
        $mail = Mail::sent(TwoFactorCodeMail::class)->last();

        $this->assertNotNull($mail, 'No se envió el código.');

        return $mail->code;
    }

    private function verify(string $challenge, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/two-factor', [
            'challenge_id' => $challenge,
            'code' => $code,
            'device_name' => 'Pruebas',
        ]);
    }

    private function resend(string $challenge): TestResponse
    {
        return $this->postJson('/api/v1/auth/two-factor/resend', ['challenge_id' => $challenge]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }

    #[Test]
    public function jime_recibe_un_codigo_por_correo_y_todavia_no_la_sesion(): void
    {
        $response = $this->login('JIM-01');

        $response->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertJsonPath('data.two_factor.code_length', 6)
            ->assertJsonPath('data.two_factor.expires_at', now()->addMinutes(5)->toIso8601String())
            ->assertJsonPath('data.two_factor.resend_at', now()->addSeconds(45)->toIso8601String());
        $this->assertStringEndsWith('-04:00', $response->json('data.two_factor.expires_at'));

        Mail::assertSent(TwoFactorCodeMail::class, fn ($mail) => $mail->hasTo('jim-01@traigo.test'));
        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, $response->getContent());

        $stored = TwoFactorChallenge::query()->sole();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertTrue(Hash::check($code, $stored->code_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function con_el_codigo_entra_y_el_codigo_sirve_una_sola_vez(): void
    {
        $challenge = $this->login('DIR-08')->json('data.two_factor.challenge_id');
        $code = $this->sentCode();

        $this->verify($challenge, $code)
            ->assertOk()
            ->assertJsonPath('data.member.role', 'management')
            ->assertJsonStructure(['data' => ['token']]);

        $this->verify($challenge, $code)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'two_factor_expired');
    }

    #[Test]
    public function un_codigo_malo_cuenta_intentos_y_al_quinto_ya_no_sirve(): void
    {
        $challenge = $this->login('JIM-01')->json('data.two_factor.challenge_id');
        $code = $this->sentCode();

        for ($i = 0; $i < 5; $i++) {
            $this->verify($challenge, $this->wrong($code))
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'El código no es correcto.', 'code' => 'two_factor_invalid']);
        }

        $this->verify($challenge, $code)->assertJsonPath('code', 'two_factor_expired');
    }

    #[Test]
    public function el_codigo_vence_a_los_cinco_minutos(): void
    {
        $challenge = $this->login('JIM-01')->json('data.two_factor.challenge_id');
        $code = $this->sentCode();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->verify($challenge, $code)->assertJsonPath('code', 'two_factor_expired');
    }

    #[Test]
    public function se_puede_pedir_otro_despues_de_45_segundos_y_anula_el_anterior(): void
    {
        $first = $this->login('JIM-01')->json('data.two_factor.challenge_id');
        $firstCode = $this->sentCode();

        $this->resend($first)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '45');

        $this->travel(46)->seconds();
        $second = $this->resend($first)->assertOk()->json('data.challenge_id');
        $this->assertNotSame($first, $second);
        $secondCode = $this->sentCode();

        $this->verify($first, $firstCode)->assertJsonPath('code', 'two_factor_expired');
        $this->resend($first)->assertJsonPath('code', 'two_factor_expired');
        $this->verify($second, $secondCode)->assertOk();
    }

    #[Test]
    public function un_codigo_vencido_se_puede_volver_a_pedir_por_un_rato(): void
    {
        $challenge = $this->login('JIM-01')->json('data.two_factor.challenge_id');

        $this->travel(10)->minutes();
        $this->resend($challenge)->assertOk();

        $old = $this->login('JIM-01')->json('data.two_factor.challenge_id');
        $this->travel(16)->minutes();
        $this->resend($old)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Vuelve a ingresar tu código y tu PIN.');
    }

    #[Test]
    public function una_verificacion_inventada_no_falla_el_servidor(): void
    {
        foreach (['no-es-un-uuid', (string) Str::uuid()] as $challenge) {
            $this->verify($challenge, '123456')->assertJsonPath('code', 'two_factor_expired');
            $this->resend($challenge)->assertJsonPath('code', 'two_factor_expired');
        }
    }

    #[Test]
    public function no_se_pueden_pedir_codigos_sin_limite(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('JIM-01')->assertOk();
            $this->travel(1)->minutes();
        }

        $this->login('JIM-01')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Pediste demasiados códigos. Espera unos minutos.');
        $this->assertCount(5, Mail::sent(TwoFactorCodeMail::class));
    }

    #[Test]
    public function sin_correo_no_hay_como_enviar_el_codigo(): void
    {
        $this->member('JIM-01')->update(['email' => null]);

        $this->login('JIM-01')
            ->assertConflict()
            ->assertJsonPath('code', 'two_factor_unavailable');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    #[Test]
    public function si_el_correo_falla_no_queda_un_codigo_suelto(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

        $this->login('JIM-01')
            ->assertStatus(503)
            ->assertJsonPath('message', 'No pudimos enviarte el código. Intenta de nuevo en un momento.');

        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    #[Test]
    public function en_produccion_los_codigos_nunca_van_al_log(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->login('JIM-01')->assertStatus(503);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    #[Test]
    public function el_codigo_no_va_en_el_asunto_pero_si_en_el_texto(): void
    {
        $mail = new TwoFactorCodeMail('Ailén & Jime', '482913', 5);

        $this->assertSame('Tu código de acceso a Traigo', $mail->envelope()->subject);
        $text = view('mail.two-factor-code', ['memberName' => $mail->memberName, 'code' => $mail->code, 'minutes' => 5])->render();
        $this->assertStringContainsString('482913', $text);
        $this->assertStringContainsString('Ailén & Jime', $text);
    }
}
