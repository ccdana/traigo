<?php

namespace App\Services\Reports;

use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Un Excel de varias hojas. Los textos van siempre como texto: lo que escriba
 * una persona (por ejemplo un nombre que empiece con «=») nunca se vuelve una
 * fórmula.
 */
final class Workbook
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private readonly Writer $writer;

    private readonly string $path;

    private int $sheets = 0;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'traigo');
        $this->writer = new Writer;
        $this->writer->openToFile($this->path);
    }

    /**
     * @param  list<array{0: string, 1: int, 2?: 'money'}>  $columns  título, ancho y, para montos, «money»
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public function sheet(string $name, array $columns, iterable $rows): self
    {
        $sheet = $this->sheets++ === 0
            ? $this->writer->getCurrentSheet()
            : $this->writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);

        foreach ($columns as $index => $column) {
            $sheet->setColumnWidth($column[1], $index + 1);
        }

        $bold = (new Style)->setFontBold();
        $money = (new Style)->setFormat('#,##0.00');
        $this->writer->addRow(new Row(array_map(fn (array $column) => new StringCell($column[0], $bold), $columns)));

        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $index => $value) {
                $cells[] = $this->cell($value, ($columns[$index][2] ?? null) === 'money' ? $money : null);
            }
            $this->writer->addRow(new Row($cells));
        }

        return $this;
    }

    public function download(string $filename): BinaryFileResponse
    {
        $this->writer->close();

        return response()
            ->download($this->path, $filename, ['Content-Type' => self::MIME])
            ->deleteFileAfterSend();
    }

    /** Un monto de la base (o centavos) como número, para que Excel sume. */
    public static function money(string|int|null $amount): ?float
    {
        if ($amount === null) {
            return null;
        }

        return (is_int($amount) ? $amount : Money::toCents($amount)) / 100;
    }

    /** «06/12/2026 18:30», en hora de Bolivia. */
    public static function dateTime(DateTimeInterface|string|null $at): ?string
    {
        return $at === null ? null : Carbon::parse($at)->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    public static function date(DateTimeInterface|string|null $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->format('d/m/Y');
    }

    private function cell(string|int|float|null $value, ?Style $style): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null, $style),
            is_string($value) => new StringCell($value, $style),
            default => new NumericCell($value, $style),
        };
    }
}
