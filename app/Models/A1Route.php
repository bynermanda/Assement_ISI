<?php

namespace App\Models;

use App\Support\ReviewRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assessment 1 special route: who assesses (Penilai) and approves an employee instead of the department accounts.
 * Matches one employee, or a department + job titles. A route for the person wins over a department route.
 */
class A1Route extends Model
{
    protected $table = 'a1_routes';

    protected $fillable = ['employee_id', 'department', 'jabatans', 'assessor_id', 'approver_id', 'note'];

    protected function casts(): array
    {
        return ['jabatans' => 'array'];
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function matches(Employee $e): bool
    {
        if ($this->employee_id) {
            return ReviewRules::normalizeNik($this->employee_id) === ReviewRules::normalizeNik($e->id_employee);
        }

        // a department route without job titles covers everybody of the department (e.g. DIES: all employees)
        return ReviewRules::key($this->department) === ReviewRules::key($e->department)
            && (empty($this->jabatans) || in_array(ReviewRules::key($e->grade), array_map([ReviewRules::class, 'key'], $this->jabatans), true));
    }

    public function describe(): string
    {
        $what = $this->employee_id
            ? 'karyawan ' . $this->employee_id
            : $this->department . ' · ' . (empty($this->jabatans) ? 'semua karyawan' : implode(', ', $this->jabatans));

        return $what . ($this->note ? ' · ' . $this->note : '');
    }
}
