<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One HRD sanction letter (from the HRD "Data List Sanksi Karyawan" file). Drives Point 6 (score_safety) of the
 * Assessment 1 of that employee and month, following the Point 6 guideline:
 *   1 sanction in the month (pelanggaran kedisiplinan 1 kali) -> C, 2 or more in the same month -> D.
 */
class HrdSanction extends Model
{
    /** Columns of the HRD file, in this order; the one that is filled in tells the type. */
    public const TYPES = ['SP', 'SP 1', 'SP 2', 'SP 3', 'SP PERTAMA & TERAKHIR', 'PHK', 'DEMOSI'];

    /** Sanctions in one month -> Point 6 grade points (Assessment::GRADES). */
    public const GRADE_ONE  = 60;   // C
    public const GRADE_MANY = 0;    // D

    protected $fillable = [
        'source_key', 'employee_id', 'period', 'sanction_date', 'letter_no', 'sanction_type',
        'case_text', 'case_summary', 'article', 'name_in_file', 'section_in_file', 'imported_by',
    ];

    protected function casts(): array
    {
        return ['sanction_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id_employee');
    }

    /** "NIK|YYYY-MM-DD|NO SURAT|JENIS": the same letter imported again updates the same row. */
    public static function keyFor(string $employeeId, string $date, ?string $letter, string $type): string
    {
        return mb_substr(implode('|', [$employeeId, $date, mb_strtoupper(trim((string) $letter)), $type]), 0, 191);
    }

    public static function gradeFor(int $count): ?int
    {
        return match (true) {
            $count <= 0 => null,
            $count === 1 => self::GRADE_ONE,
            default      => self::GRADE_MANY,
        };
    }

    /** Point 6 note: one line per letter, "[No. Surat] - [Kasus]", cut to Assessment::NOTE_MAX. */
    public static function noteFor(Collection $sanctions): string
    {
        $lines = $sanctions->sortBy('sanction_date')->map(fn (self $s) => sprintf('%s - %s',
            $s->letter_no ?: $s->sanction_type . ' (tanpa no. surat)', $s->case_summary));

        $note = $lines->implode("\n");

        return mb_strlen($note) > Assessment::NOTE_MAX ? mb_substr($note, 0, Assessment::NOTE_MAX - 1) . '…' : $note;
    }
}
