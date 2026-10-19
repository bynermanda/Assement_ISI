<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assessment extends Model
{
    public const STATUS_DRAFT    = 'Draft';
    public const STATUS_PENDING  = 'Pending';    // submitted by Penilai 1, waiting for Penilai 2
    public const STATUS_VERIFIED = 'Verified';   // legacy (old 3-step flow); treated like Pending
    public const STATUS_APPROVED = 'Approved';   // final
    public const STATUS_REJECTED = 'Rejected';   // sent back to Penilai 1

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /** Statuses in which the inputter may still edit the assessment. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    /** Criteria that HRD provides (imported); the department cannot change them afterwards. */
    public const HRD_COLUMNS = ['score_attendance', 'score_tardiness'];

    /** Criterion filled in (and locked) by an HRD sanction import (HrdSanction). */
    public const SANCTION_COLUMNS = ['score_safety'];

    /** Grade letter => raw score. */
    public const GRADES = ['A' => 100, 'B' => 80, 'C' => 60, 'D' => 0];

    /**
     * column => [label, short label, sub label, weight in %, column in criteria_descriptions]
     * Total weight must equal 100.
     */
    public const CRITERIA = [
        'score_attendance'   => ['label' => 'Attendance Performance', 'short' => 'Attendance', 'sub' => 'Tingkat Kehadiran · 出勤率',         'zh' => '出勤率',   'id' => 'Tingkat Kehadiran',     'group' => 'attendance', 'weight' => 5,  'description' => 'desc1'],
        'score_tardiness'    => ['label' => 'Tardiness Performance',  'short' => 'Tardiness',  'sub' => 'Berada di area kerja · 上崗作業', 'zh' => '上崗作業', 'id' => 'Berada di area kerja',  'group' => 'attendance', 'weight' => 5,  'description' => 'desc2'],
        'score_work_quality' => ['label' => 'Work Quality',           'short' => 'Quality',    'sub' => 'Kualitas Kerja · 工作品質',       'zh' => '工作品質', 'id' => 'Kualitas Kerja',        'group' => null,         'weight' => 30, 'description' => 'desc3'],
        'score_efficiency'   => ['label' => 'Work Efficiency',        'short' => 'Efficiency', 'sub' => 'Efisiensi Kerja · 工作效率',      'zh' => '工作效率', 'id' => 'Efisiensi Kerja',       'group' => null,         'weight' => 20, 'description' => 'desc4'],
        'score_attitude'     => ['label' => 'Work Attitude',          'short' => 'Attitude',   'sub' => 'Sikap Kerja · 工作態度',          'zh' => '工作態度', 'id' => 'Sikap Kerja',           'group' => null,         'weight' => 20, 'description' => 'desc5'],
        'score_safety'       => ['label' => 'Safety Compliance',      'short' => 'Safety',     'sub' => 'Kepatuhan Keselamatan',           'zh' => '',         'id' => 'Kepatuhan Keselamatan', 'group' => null,         'weight' => 20, 'description' => 'desc6'],
    ];

    /** Criteria shown under one parent item in the tables ("1 and 2" = one attendance item). */
    public const CRITERIA_GROUPS = [
        'attendance' => ['zh' => '出勤表現', 'id' => 'Performa Kehadiran'],
    ];

    /** Rowspan / colspan helper: column => [group key|null, is first of its group, group size]. */
    public static function criteriaLayout(): array
    {
        $sizes  = array_count_values(array_filter(array_column(self::CRITERIA, 'group')));
        $seen   = [];
        $layout = [];

        foreach (self::CRITERIA as $column => $meta) {
            $group = $meta['group'];
            $layout[$column] = [$group, $group !== null && ! isset($seen[$group]), $group !== null ? $sizes[$group] : 1];
            if ($group !== null) {
                $seen[$group] = true;
            }
        }

        return $layout;
    }

    /** "Tingkat Kehadiran (出勤率)": plain text for aria labels, tooltips and summaries. */
    public static function itemText(string $column): string
    {
        $meta = self::CRITERIA[$column] ?? null;

        return $meta ? trim($meta['id'] . ($meta['zh'] !== '' ? " ({$meta['zh']})" : '')) : $column;
    }

    /** Table headings used by the manual form, the approval page and the bulk grid: [Chinese, Indonesian]. */
    public const HEADINGS = [
        'criteria' => ['考核項目', 'Item Evaluasi'],
        'weight'   => ['權重比例', 'Bobot Persentase'],
        'target'   => ['目標', 'Target'],
        'points'   => ['得分', 'Nilai'],
        'notes'    => ['備註&評價特別說明', 'Catatan & Evaluasi Catatan Khusus'],
        'scale'    => ['等計分數', 'Perhitungan Nilai'],
    ];

    /**
     * 等計分數 Perhitungan Nilai: final score -> final grade. Letter => lowest score of that grade, highest first.
     * The scale used BEFORE the first saved version (A 91~100, B 80~90, C 60~79, D 0~59). The admin adds versions in
     * Panduan › Skala Nilai Akhir, each valid from a period on, so always read finalGrades($period).
     */
    public const FINAL_GRADES = ['A' => 91, 'B' => 80, 'C' => 60, 'D' => 0];

    /** Setting key: list of scale versions [['from' => 'YYYY-MM', 'A' => .., 'B' => .., 'C' => .., 'by' => .., 'at' => ..], ...]. */
    public const SCALE_SETTING = 'final_grade_scales';

    private static ?array $scaleVersions = null;

    /** Saved scale versions, valid ones only, oldest first. */
    public static function scaleVersions(): array
    {
        if (self::$scaleVersions !== null) {
            return self::$scaleVersions;
        }
        $versions = array_values(array_filter((array) AppSetting::get(self::SCALE_SETTING, []), fn ($v) => is_array($v)
            && self::isPeriod($v['from'] ?? null) && isset($v['A'], $v['B'], $v['C'])
            && 0 < (int) $v['C'] && (int) $v['C'] < (int) $v['B'] && (int) $v['B'] < (int) $v['A'] && (int) $v['A'] <= 100));
        usort($versions, fn ($x, $y) => strcmp($x['from'], $y['from']));

        return self::$scaleVersions = $versions;
    }

    /**
     * The scale of a period ('YYYY-MM'; null = the current period): the latest version valid from that period or
     * earlier, else FINAL_GRADES. A period keeps its scale forever: a later version never changes earlier periods.
     */
    public static function finalGrades(?string $period = null): array
    {
        $period ??= now()->format('Y-m');
        $scale    = self::FINAL_GRADES;
        if (! self::isPeriod($period)) {
            return $scale;   // malformed old periods: the original scale
        }
        foreach (self::scaleVersions() as $v) {
            if (strcmp($v['from'], $period) > 0) {
                break;
            }
            $scale = ['A' => (int) $v['A'], 'B' => (int) $v['B'], 'C' => (int) $v['C'], 'D' => 0];
        }

        return $scale;
    }

    /**
     * Legend(s) for an export: one scale for a single period; for every period ('all') each scale with the periods it
     * covers. [['label' => null|string, 'ranges' => finalGradeRanges()], ...]
     */
    public static function scaleLegends(string $period): array
    {
        if (self::isPeriod($period) || self::scaleVersions() === []) {
            return [['label' => null, 'ranges' => self::finalGradeRanges(self::isPeriod($period) ? $period : null)]];
        }
        $versions = self::scaleVersions();
        $legends  = [['label' => 'Sebelum ' . \App\Support\ApprovedExport::labelFor($versions[0]['from']), 'ranges' => self::rangesOf(self::FINAL_GRADES)]];
        foreach ($versions as $v) {
            $legends[] = ['label' => 'Mulai ' . \App\Support\ApprovedExport::labelFor($v['from']), 'ranges' => self::finalGradeRanges($v['from'])];
        }

        return $legends;
    }

    /** Forget the per-request copy (after the admin saved a scale version). */
    public static function forgetFinalGrades(): void
    {
        self::$scaleVersions = null;
    }

    public static function isPeriod(?string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period);
    }

    /** Key in `notes` for the note about the employee as a whole (one per row in the bulk grid). */
    public const NOTE_GENERAL = 'general';

    public const NOTE_MAX = 500;

    protected $fillable = [
        'employee_id',
        'period',
        'score_attendance',
        'score_tardiness',
        'score_work_quality',
        'score_efficiency',
        'score_attitude',
        'score_safety',
        'final_score',
        'status',
        'rejection_note',
        'created_by',
        'verified_by',
        'approved_by',
        'hrd_imported_at',
        'hrd_imported_by',
        'sanction_locked_at',
        'sanction_locked_by',
        'edited_by',
        'edited_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'notes'           => 'array',
            'final_score'     => 'float',
            'hrd_imported_at' => 'datetime',
            'sanction_locked_at' => 'datetime',
            'edited_at'       => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id_employee');
    }

    /** Inputter (created_by stores the username). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'username');
    }

    /** Penilai 2 who changed the scores of Penilai 1 (edited_by stores the username). */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by', 'username');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'username');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'username');
    }

    /** Leave days -> score. 0 = A, 1 = B, 2-3 = C, 4 or more = D. */
    public static function gradeForLeaveDays(float $days): int
    {
        return match (true) {
            $days <= 0 => 100,
            $days <= 1 => 80,
            $days <= 3 => 60,
            default    => 0,
        };
    }

    /** Times late -> score. 0 = A, 1 = B, 2 = C, 3 or more = D. */
    public static function gradeForLateCount(float $times): int
    {
        return match (true) {
            $times <= 0 => 100,
            $times <= 1 => 80,
            $times <= 2 => 60,
            default     => 0,
        };
    }

    /** True when HRD loaded attendance and tardiness for this assessment. */
    public function hrdLocked(): bool
    {
        return $this->hrd_imported_at !== null;
    }

    /** True when Point 6 comes from an HRD sanction (HrdSanction). */
    public function sanctionLocked(): bool
    {
        return $this->sanction_locked_at !== null;
    }

    /** Criteria the Penilai / approver may not change: Point 1 & 2 (attendance import), Point 6 (sanction). */
    public function lockedColumns(): array
    {
        return [...($this->hrdLocked() ? self::HRD_COLUMNS : []), ...($this->sanctionLocked() ? self::SANCTION_COLUMNS : [])];
    }

    /** Notes posted by a form, with the notes of the locked criteria put back as HRD wrote them. */
    public static function keepLockedNotes(?array $notes, ?self $current): ?array
    {
        if (! $current) {
            return $notes;
        }
        $notes ??= [];
        foreach ($current->lockedColumns() as $column) {
            if (isset($current->notes[$column]) && $current->notes[$column] !== '') {
                $notes[$column] = $current->notes[$column];
            } else {
                unset($notes[$column]);
            }
        }

        return $notes === [] ? null : $notes;
    }

    /** True while at least one criterion has not been scored yet (e.g. an HRD draft). */
    public function isIncomplete(): bool
    {
        foreach (array_keys(self::CRITERIA) as $column) {
            if ($this->{$column} === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guideline row for a department and (optionally) a section; not case-sensitive.
     * Order: the row of that exact section, then the department-wide row (empty section), then any row of the department.
     */
    public static function guidelinesFor(?string $department, ?string $section = null): ?CriteriaDescription
    {
        if ($department === null || trim($department) === '') {
            return null;
        }

        // all guideline rows are few (one per department / section) and read on every form: cached
        $rows = \App\Support\RefCache::remember('guidelines', 'all', fn () => CriteriaDescription::all())
            ->filter(fn ($row) => mb_strtolower(trim((string) $row->department)) === mb_strtolower(trim($department)))
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $section = mb_strtolower(trim((string) $section));

        if ($section !== '') {
            $match = $rows->first(fn ($row) => mb_strtolower(trim((string) $row->section)) === $section);

            if ($match) {
                return $match;
            }
        }

        return $rows->first(fn ($row) => trim((string) $row->section) === '') ?? $rows->first();
    }

    /**
     * Raw guideline text -> safe HTML.
     * - literal "\n" saved as text in the database becomes a real line break
     * - inline numbered lists ("1. ... 2. ... 3. ...") are put on separate lines
     */
    public static function formatGuideline(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $text = str_replace(['\r\n', '\n'], "\n", $text);
        $text = preg_replace('/[ \t]+(?=[2-9]\.(?!\d))/u', "\n", $text);

        return nl2br(e(trim($text)));
    }

    /** Allowed keys in `notes`: every criterion column plus "general". */
    public static function noteKeys(): array
    {
        return [...array_keys(self::CRITERIA), self::NOTE_GENERAL];
    }

    /** Validation rules for a posted notes array (form field "notes[...]"). */
    public static function noteRules(string $prefix = 'notes'): array
    {
        $rules = [$prefix => ['nullable', 'array']];

        foreach (self::noteKeys() as $key) {
            $rules["{$prefix}.{$key}"] = ['nullable', 'string', 'max:' . self::NOTE_MAX];
        }

        return $rules;
    }

    /** Trimmed, empty ones dropped, unknown keys ignored; null when nothing is left. */
    public static function cleanNotes(?array $notes): ?array
    {
        $clean = [];

        foreach (self::noteKeys() as $key) {
            $text = trim((string) ($notes[$key] ?? ''));

            if ($text !== '') {
                $clean[$key] = $text;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /** Weighted total of all criteria (0 - 100). */
    public static function calculateFinalScore(array $scores): float
    {
        $total = 0.0;

        foreach (self::CRITERIA as $column => $meta) {
            $total += ((int) ($scores[$column] ?? 0)) * $meta['weight'] / 100;
        }

        return round($total, 2);
    }

    /**
     * Final grade (A/B/C/D) of a final score with the scale of its period (null = current period);
     * "-" when there is no score. Pass the assessment's period so earlier months keep their own scale.
     */
    public static function finalGradeFor(int|float|string|null $score, ?string $period = null): string
    {
        if ($score === null || $score === '') {
            return '-';
        }

        foreach (self::finalGrades($period) as $letter => $minimum) {
            if ((float) $score >= $minimum) {
                return $letter;
            }
        }

        return 'D';
    }

    /** "A 91~100", "B 80~90", ...: the range of each final grade of a period's scale, highest first. */
    public static function finalGradeRanges(?string $period = null): array
    {
        return self::rangesOf(self::finalGrades($period));
    }

    private static function rangesOf(array $scale): array
    {
        $ranges = [];
        $upper  = 100;

        foreach ($scale as $letter => $minimum) {
            $ranges[$letter] = [$minimum, $upper];
            $upper           = $minimum - 1;
        }

        return $ranges;
    }

    public static function finalGradeClass(string $letter): string
    {
        return match ($letter) {
            'A'     => 'bg-emerald-600 text-white ring-emerald-700',
            'B'     => 'bg-sky-600 text-white ring-sky-700',
            'C'     => 'bg-amber-400 text-amber-950 ring-amber-500',
            'D'     => 'bg-rose-600 text-white ring-rose-700',
            default => 'bg-slate-100 text-slate-500 ring-slate-300',
        };
    }

    public static function letterFor(int|string|null $score): string
    {
        if ($score === null || $score === '') {
            return '-';
        }

        return array_search((int) $score, self::GRADES, true) ?: '-';
    }

    public static function badgeClass(?string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT    => 'bg-slate-100 text-slate-700 ring-slate-300',
            self::STATUS_PENDING  => 'bg-amber-50 text-amber-800 ring-amber-300',
            self::STATUS_VERIFIED => 'bg-sky-50 text-sky-800 ring-sky-300',
            self::STATUS_APPROVED => 'bg-emerald-600 text-white ring-emerald-700',
            self::STATUS_REJECTED => 'bg-rose-50 text-rose-800 ring-rose-300',
            default               => 'bg-slate-50 text-slate-500 ring-slate-200',
        };
    }
}
