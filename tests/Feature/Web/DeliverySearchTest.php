<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class DeliverySearchTest extends WebTestCase
{
    #[Test]
    public function solo_clientes_autenticados_buscan_y_los_resultados_se_cachean(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [-66.16, -17.38]],
            'properties' => ['name' => 'Plaza', 'city' => 'Cochabamba', 'country' => 'Bolivia'],
        ], null, ['geometry' => ['coordinates' => 'invalid']]]])]);
        $this->getJson('/delivery/buscar?q=Plaza')->assertUnauthorized();
        $this->actingAs(User::factory()->customer()->create(), 'customer');
        $this->getJson('/delivery/buscar?q=Plaza')->assertOk()->assertJsonPath('data.0.latitude', -17.38)
            ->assertJsonPath('data.0.longitude', -66.16)->assertJsonPath('data.0.label', 'Plaza, Cochabamba, Bolivia');
        $this->getJson('/delivery/buscar?q=Plaza')->assertOk();
        Http::assertSentCount(1);
    }

    #[Test]
    public function falla_de_busqueda_no_impide_marcar_manualmente_y_se_limita_el_proveedor(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->customer()->create(), 'customer');
        $this->getJson('/delivery/buscar?q=Centro')->assertStatus(503)->assertJsonPath('message', 'La búsqueda no está disponible. Puedes marcar el punto directamente en el mapa.');
        $this->getJson('/delivery/buscar?q=Otra')->assertStatus(429);
        Http::assertSentCount(1);
        Cache::forget('delivery-search:cooldown');
        $this->getJson('/delivery/buscar?q=x')->assertUnprocessable();
    }
}
