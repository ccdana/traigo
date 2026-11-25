<?php

namespace App\Models;

use App\Exceptions\ApiException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Una semana de la campaña. */
class BonusWeek extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_bonus_week';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'requires_system_deliverables' => 'boolean',
        ];
    }

    /**
     * La semana en curso (días de Bolivia): la que contiene hoy; antes de la
     * campaña, la primera, y después, la última.
     */
    public static function current(): self
    {
        return self::query()->where('starts_on', '<=', today()->toDateString())->orderByDesc('starts_on')->first()
            ?? self::query()->orderBy('starts_on')->first()
            ?? throw ApiException::notFound('Todavía no hay semanas de campaña.');
    }

    /** La semana pedida; una que todavía no empieza no existe para el equipo. */
    public static function started(int $number): self
    {
        $current = self::current();

        if ($number > $current->number) {
            throw ApiException::notFound('Esa semana todavía no empieza.');
        }

        return $number === $current->number
            ? $current
            : self::query()->where('number', $number)->first()
                ?? throw ApiException::notFound('No encontramos esa semana.');
    }

    /** Lo cobrado y confirmado del equipo en esta semana. */
    public function collected(): string
    {
        return Money::fromDatabase(
            DB::table('v_week_collected')->where('week_id', $this->getKey())->value('collected_bs') ?? '0',
        );
    }

    /** @return array<string, string> */
    public function toApi(): array
    {
        return [
            'label' => $this->label,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'target' => Money::fromDatabase($this->sales_target_bs),
            'base_prize' => Money::fromDatabase($this->base_prize_bs),
        ];
    }
}
