<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Assessment 2 unit: one department sheet of the HRD template (a department, "QA - Audit", "ADVISOR"). */
class A2Unit extends Model
{
    protected $table = 'a2_units';

    protected $fillable = ['name', 'department', 'sort'];

    public function criteria(): HasMany
    {
        return $this->hasMany(A2Criterion::class, 'unit_id')->orderBy('sort');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(A2Participant::class, 'unit_id');
    }

    public function totalWeight(): float
    {
        return round((float) $this->criteria->sum('weight'), 2);
    }
}
