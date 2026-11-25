<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Una por línea vendida; sin % definido, el monto queda nulo. */
class Commission extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_commission';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['earned_at' => 'datetime'];
    }
}
