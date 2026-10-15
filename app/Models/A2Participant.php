<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person assessed in Assessment 2, with his own assessor (Penilai) and approver. */
class A2Participant extends Model
{
    protected $table = 'a2_participants';

    protected $fillable = ['unit_id', 'employee_id', 'assessor_id', 'approver_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(A2Unit::class, 'unit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id_employee');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
