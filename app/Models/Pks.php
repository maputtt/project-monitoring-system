<?php

namespace App\Models;

use App\Enums\PksStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['pks_number', 'name', 'client_name', 'role_mode', 'issued_date', 'start_date', 'end_date', 'description', 'status'])]
class Pks extends Model
{
    protected $table = 'pks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PksStatus::class,
            'issued_date' => 'date:Y-m-d',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
