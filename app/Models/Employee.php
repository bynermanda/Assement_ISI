<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $table = 'employees';
    protected $primaryKey = 'id_employee';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id_employee',
        'name',
        'department',
        'section',
        'position',
        'grade',
        'status',
        'assessment_level',   // NULL = by grade, 1 = Assessment 1, 2 = Assessment 2 (set by the admin)
    ];
}