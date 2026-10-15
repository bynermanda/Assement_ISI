<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CriteriaDescription extends Model
{
    use HasFactory;

    protected $table = 'criteria_descriptions';
    protected $primaryKey = 'id_desc';

    protected $fillable = [
        'department',
        'sub_department',
        'desc1',
        'desc2',
        'desc3',
        'desc4',
        'desc5',
        'desc6',
    ];
}