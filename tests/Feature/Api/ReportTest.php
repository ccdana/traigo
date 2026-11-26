<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Los tres reportes en Excel. */
class ReportTest extends ControlTestCase
{
    /** @var list<string> en las pruebas la descarga no se envía: se borran al final */
    private array $downloads = [];

    protected function tearDown(): void
    {
        File::delete($this->downloads);

        parent::tearDown();
    }

    private function report(string $type, string $period, string $code = 'JIM-01'): TestResponse
    {
        $response = $this->api($code)->get("/api/v1/control/reports/{$type}?period={$period}", ['Accept' => 'application/json']);

        if ($response->baseResponse instanceof BinaryFileResponse) {
            $this->downloads[] = $response->getFile()->getPathname();
        }

        return $response;
    }

    /**
     * Las hojas del archivo, leídas como las leería Excel.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(TestResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    #[Test]
    public function el_reporte_de_la_semana_trae_cierre_kpi_bono_pagos_y_entregas(): void
    {
        $proof = $this->proofSale(reference: 'QR-77');
        $this->confirm($proof['payment']);
        $this->loadActuals('ALI-04', 0.5);

        $response = $this->report('week', '1')
            ->assertOk()
            ->assertDownload('traigo-semana-1.xlsx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheets = $this->sheets($response);

        $this->assertSame(['Cierre', 'KPI', 'Bono', 'Pagos', 'Entregas'], array_keys($sheets));
        $closing = collect($sheets['Cierre'])->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? null]);
        $this->assertSame('1 · 1–6 dic', $closing['Semana']);
        $this->assertSame(174, (int) $closing['Cobrado Bs']);
        $alison = collect($sheets['KPI'])->first(fn (array $row) => $row[0] === 'Alison');
        $this->assertSame(50, (int) $alison[6]);
        $this->assertCount(7, $sheets['Bono']);
        // Vendedor, referencia y si cuenta para el bono.
        $this->assertSame(
            ['Alison', 'QR-77', 'Sí'],
            [$sheets['Pagos'][1][2], $sheets['Pagos'][1][6], $sheets['Pagos'][1][9]],
        );
        $this->assertCount(9, $sheets['Entregas']);
    }

    #[Test]
    public function un_nombre_que_empieza_con_igual_queda_como_texto(): void
    {
        $this->sell($this->sale(with: ['customer' => ['name' => '=HYPERLINK("http://x")'], 'payment_method' => 'cash']))
            ->assertCreated();

        $response = $this->report('cash', '2026-12-03')->assertOk()->assertDownload('traigo-caja-2026-12-03.xlsx');

        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $xml = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_starts_with($zip->getNameIndex($index), 'xl/worksheets/')) {
                $xml .= $zip->getFromIndex($index);
            }
        }
        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml);

        $sheets = $this->sheets($response);
        $this->assertSame('=HYPERLINK("http://x")', $sheets['Cobros'][1][1]);
    }

    #[Test]
    public function el_reporte_de_caja_suma_lo_cobrado_y_las_diferencias(): void
    {
        $this->cashSale();
        $this->api('ISA-03')->postJson('/api/v1/cash/closings', [
            'business_date' => '2026-12-03',
            'lines' => [['method' => 'qr', 'counted' => '0'], ['method' => 'transfer', 'counted' => '0'], ['method' => 'cash', 'counted' => '164']],
            'note' => 'Faltan Bs 10',
        ], ['Idempotency-Key' => '7f1a1c9e-8b0a-4e5f-9c55-2b6f3a1d4e90'])->assertOk();

        $sheets = $this->sheets($this->report('cash', '2026-12-03', 'DIR-08')->assertOk());

        $this->assertSame(['Caja', 'Cierre', 'Cobros', 'Por cobrar'], array_keys($sheets));
        $total = end($sheets['Caja']);
        $this->assertSame(['Total', 1, 174, 164, -10], [$total[0], (int) $total[1], (int) $total[2], (int) $total[3], (int) $total[4]]);
        $closing = collect($sheets['Cierre'])->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? null]);
        $this->assertSame('Faltan Bs 10', $closing['Explicación de almacén']);
    }

    #[Test]
    public function el_reporte_de_comisiones_va_por_persona_y_por_producto(): void
    {
        $this->setRate('8107', 5);
        $sale = $this->proofSale();
        $this->confirm($sale['payment']);

        $sheets = $this->sheets($this->report('commissions', '2026-12')->assertOk()->assertDownload('traigo-comisiones-2026-12.xlsx'));

        $this->assertSame(['Por persona', 'Por producto', 'Detalle', 'Liquidación'], array_keys($sheets));
        $this->assertSame(['Alison', 1, 0, 8.7], [$sheets['Por persona'][1][0], (int) $sheets['Por persona'][1][1], (int) $sheets['Por persona'][1][2], (float) $sheets['Por persona'][1][3]]);
        $this->assertSame(['Alison', '8107', 2], [$sheets['Por producto'][1][0], (string) $sheets['Por producto'][1][1], (int) $sheets['Por producto'][1][3]]);
    }

    #[Test]
    public function revisa_el_periodo_y_el_permiso(): void
    {
        $this->report('week', '2')->assertNotFound();
        $this->report('week', 'x')->assertUnprocessable()->assertJsonPath('message', 'Falta el número de semana.');
        $this->report('cash', '2026-11-30')->assertNotFound();
        $this->report('cash', '2026-13-01')->assertUnprocessable();
        $this->report('commissions', 'dic')->assertUnprocessable();
        $this->report('otro', '1')->assertNotFound();
        $this->report('week', '1', 'ALI-04')->assertForbidden();
    }
}
