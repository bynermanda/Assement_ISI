<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Data of the admin export (page, Excel, PDF). ONLY approved assessments: every query starts from
 * Assessment::STATUS_APPROVED, the filters can only narrow it down.
 */
final class ApprovedExport
{
    public const ALL_PERIODS = 'all';

    /** @param array{period:string, department:string, section:string} $filters */
    private function __construct(public readonly array $filters) {}

    public static function fromRequest(Request $request): self
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        if ($period !== self::ALL_PERIODS && ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = now()->format('Y-m');
        }

        return new self([
            'period'     => $period,
            'department' => trim((string) $request->query('department', '')),
            'section'    => trim((string) $request->query('section', '')),
        ]);
    }

    public function query(): Builder
    {
        return Assessment::query()
            ->select('assessments.*')
            ->join('employees', 'employees.id_employee', '=', 'assessments.employee_id')
            ->where('assessments.status', Assessment::STATUS_APPROVED)
            ->when($this->filters['period'] !== self::ALL_PERIODS, fn ($q) => $q->where('assessments.period', $this->filters['period']))
            ->when($this->filters['department'] !== '', fn ($q) => $q->where('employees.department', $this->filters['department']))
            ->when($this->filters['section'] !== '', fn ($q) => $q->whereRaw('LOWER(TRIM(employees.section)) = ?', [mb_strtolower($this->filters['section'])]))
            ->with(['employee', 'creator', 'approver'])
            ->orderBy('assessments.period')
            ->orderBy('employees.department')
            ->orderBy('employees.section')
            ->orderBy('employees.name');
    }

    /** One flat row per approved assessment, the same columns in the page, the Excel file and the PDF. */
    public function rows(): Collection
    {
        return $this->query()->get()->values()->map(fn (Assessment $a, int $i) => self::row($a, $i + 1));
    }

    public static function row(Assessment $a, int $no): array
    {
        $e = $a->employee;

        return [
            'no'          => $no,
            'period'      => $a->period,
            'nik'         => $e?->id_employee ?? $a->employee_id,
            'name'        => $e?->name ?? '-',
            'department'  => $e?->department ?? '-',
            'section'     => $e?->section ?: '-',
            'position'    => trim(($e?->position ?? '') . ' · ' . ($e?->grade ?? ''), ' ·'),
            'grades'      => collect(Assessment::CRITERIA)->mapWithKeys(fn ($m, $c) => [$c => Assessment::letterFor($a->{$c})])->all(),
            'final_score' => (float) $a->final_score,
            'final_grade' => Assessment::finalGradeFor($a->final_score, $a->period),
            'penilai1'    => $a->creator?->name ?? $a->created_by ?? '-',
            'approved_by' => $a->approver?->name ?? $a->approved_by ?? '-',
            'approved_at' => $a->updated_at?->format('d M Y'),
            'notes'       => collect($a->notes ?? [])
                ->map(fn ($text, $key) => ($key === Assessment::NOTE_GENERAL ? 'Overall' : Assessment::itemText($key)) . ': ' . $text)
                ->implode("\n"),
        ];
    }

    /** Count per final grade (A/B/C/D) of the given rows. */
    public static function finalCounts(Collection $rows): array
    {
        $counts = array_fill_keys(array_keys(Assessment::finalGrades()), 0);

        foreach ($rows as $row) {
            if (isset($counts[$row['final_grade']])) {
                $counts[$row['final_grade']]++;
            }
        }

        return $counts;
    }

    public function periodLabel(): string
    {
        return $this->filters['period'] === self::ALL_PERIODS ? 'All periods' : self::labelFor($this->filters['period']);
    }

    /** "2026-10" -> "October 2026"; anything else (old data such as "5") is shown as it is. */
    public static function labelFor(?string $period): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)
            ? Carbon::createFromFormat('!Y-m', $period)->locale('id')->translatedFormat('F Y')   // Oktober 2026
            : (string) $period;
    }

    /** "Period: October 2026 · Department: PRESS" for titles. */
    public function description(): string
    {
        return collect([
            'Period: ' . $this->periodLabel(),
            $this->filters['department'] !== '' ? 'Department: ' . $this->filters['department'] : null,
            $this->filters['section'] !== '' ? 'Section: ' . $this->filters['section'] : null,
        ])->filter()->implode(' · ');
    }

    /** Indonesian version for the PDF header: "Periode: Oktober 2026 · Seksi: ASSY 3" (the Excel keeps description()). */
    public function pdfDescription(): string
    {
        return collect([
            'Periode: ' . ($this->filters['period'] === self::ALL_PERIODS ? 'Semua periode' : self::labelFor($this->filters['period'])),
            $this->filters['department'] !== '' ? 'Departemen: ' . $this->filters['department'] : null,
            $this->filters['section'] !== '' ? 'Seksi: ' . $this->filters['section'] : null,
        ])->filter()->implode(' · ');
    }

    /** "penilaian-approved-2026-10-press" */
    public function filename(string $extension): string
    {
        $parts = ['penilaian-approved', $this->filters['period'], $this->filters['department'], $this->filters['section']];

        return \Illuminate\Support\Str::slug(implode(' ', array_filter($parts)), '-') . '.' . $extension;
    }

    /** Periods (YYYY-MM) that have at least one approved assessment, newest first. Malformed old values are left out. */
    public static function periods(): array
    {
        return Assessment::where('status', Assessment::STATUS_APPROVED)
            ->distinct()->orderByDesc('period')->pluck('period')
            ->filter(fn ($p) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $p))
            ->values()->all();
    }

    public static function departments(): array
    {
        return Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all();
    }

    public static function sections(): array
    {
        return Employee::query()->whereNotNull('section')->where('section', '!=', '')->distinct()->orderBy('section')->pluck('section')->all();
    }
}
