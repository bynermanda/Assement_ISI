<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\A2Unit;
use App\Models\Assessment;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Multi-period recap (rekap rata-rata) of APPROVED assessments, Assessment 1 or 2.
 *
 *  - average = sum of the final scores ÷ number of months that HAVE an approved score (empty months are skipped:
 *    new employee, long leave, not approved yet). E.g. 70 + 87 + 90 → ÷ 3.
 *  - the average's letter uses the scale of the LAST period of the range (Assessment::finalGrades($to)).
 *  - department / section (A1) and unit (A2) are the employee's CURRENT ones; every month counts wherever it was.
 */
final class AverageExport
{
    /** Longest range per format, checked in AverageExportRequest (24 months in a PDF = already ~25 narrow columns). */
    public const MAX_PDF_MONTHS  = 24;
    public const MAX_XLSX_MONTHS = 60;
    /** Safety stop for the month loop only: the request needs the true length to report it. */
    private const LOOP_GUARD = 600;

    /** @param array{type:string, from:string, to:string, department:string, section:string, unit:?int} $filters */
    private function __construct(public readonly array $filters, public readonly array $periods) {}

    public static function fromRequest(Request $request): self
    {
        $type = $request->query('type') === 'a2' ? 'a2' : 'a1';
        $to   = (string) $request->query('to', now()->format('Y-m'));
        $to   = Assessment::isPeriod($to) ? $to : now()->format('Y-m');

        if ($request->boolean('all')) {
            $first = ($type === 'a2' ? A2Assessment::query() : Assessment::query())
                ->where('status', Assessment::STATUS_APPROVED)->pluck('period')->filter(fn ($p) => Assessment::isPeriod($p))->min();
            $from = $first && strcmp($first, $to) <= 0 ? $first : $to;
        } else {
            $from = (string) $request->query('from', '');
            $from = Assessment::isPeriod($from) ? $from : Carbon::createFromFormat('!Y-m', $to)->subMonthsNoOverflow(11)->format('Y-m');
        }
        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }

        $periods = [];
        for ($d = Carbon::createFromFormat('!Y-m', $from); $d->format('Y-m') <= $to && count($periods) < self::LOOP_GUARD; $d->addMonthNoOverflow()) {
            $periods[] = $d->format('Y-m');
        }

        return new self([
            'type'       => $type,
            'from'       => $periods[0],
            'to'         => end($periods),
            'department' => trim((string) $request->query('department', '')),
            'section'    => trim((string) $request->query('section', '')),
            'unit'       => $request->filled('unit') ? (int) $request->query('unit') : null,
        ], $periods);
    }

    /** One row per employee with at least one approved month in the range. */
    public function rows(): Collection
    {
        $a2 = $this->filters['type'] === 'a2';

        // Stream the approved scores as plain rows (no model per assessment: 24 months x 650 people = ~15k rows)
        // and keep only employee => [period => score]; employees and units are then loaded once, columns trimmed.
        $scores = []; $lastUnit = [];
        $query  = ($a2 ? A2Assessment::query() : Assessment::query())->toBase()
            ->where('status', Assessment::STATUS_APPROVED)
            ->whereIn('period', $this->periods)
            ->orderBy('id')
            ->select(array_merge(['employee_id', 'period', 'final_score'], $a2 ? ['unit_id'] : []));
        foreach ($query->cursor() as $a) {
            $scores[$a->employee_id][$a->period] = (float) $a->final_score;
            if ($a2) $lastUnit[$a->employee_id] = $a->unit_id;
        }
        unset($query);

        $ids       = array_map('strval', array_keys($scores));
        $employees = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $employees = $employees->union(Employee::query()->whereIn('id_employee', $chunk)
                ->get(['id_employee', 'name', 'position', 'grade', 'department', 'section'])->keyBy('id_employee'));
        }
        // current unit of each Assessment 2 participant (falls back to the unit of the assessment)
        $units    = $a2 ? A2Participant::with('unit:id,name')->whereIn('employee_id', $ids)->get(['employee_id', 'unit_id'])->keyBy('employee_id') : collect();
        $unitById = $a2 ? A2Unit::whereIn('id', array_unique($lastUnit))->get(['id', 'name'])->keyBy('id') : collect();
        $key      = fn (?string $v) => mb_strtolower(trim((string) $v));

        return collect($scores)
            ->map(function (array $months, $id) use ($a2, $units, $unitById, $lastUnit, $employees) {
                $id     = (string) $id;
                $e      = $employees->get($id);
                $unit   = $a2 ? ($units->get($id)?->unit ?? $unitById->get($lastUnit[$id] ?? 0)) : null;
                $scores = array_replace(array_fill_keys($this->periods, null), $months);
                $filled  = $months;
                $average = round(array_sum($filled) / count($filled), 2);

                return [
                    'nik'        => $e?->id_employee ?? $id,
                    'name'       => $e?->name ?? $id,
                    'position'   => trim(($e?->position ?? '') . ' · ' . ($e?->grade ?? ''), ' ·'),
                    'department' => (string) $e?->department,
                    'section'    => (string) $e?->section,
                    'unit_id'    => $unit?->id,
                    'unit'       => $unit?->name,
                    'scores'     => $scores,
                    'months'     => count($filled),
                    'sum'        => round(array_sum($filled), 2),
                    'average'    => $average,
                    'grade'      => Assessment::finalGradeFor($average, $this->filters['to']),
                ];
            })
            ->filter(function (array $r) use ($key) {
                if ($this->filters['type'] === 'a2') {
                    return $this->filters['unit'] === null || $r['unit_id'] === $this->filters['unit'];
                }
                return ($this->filters['department'] === '' || $key($r['department']) === $key($this->filters['department']))
                    && ($this->filters['section'] === '' || $key($r['section']) === $key($this->filters['section']));
            })
            ->sortBy(fn ($r) => sprintf('%s|%s|%s', $r['unit'] ?? $r['department'], $r['section'], $r['name']), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn ($r, $i) => ['no' => $i + 1] + $r);
    }

    /** Count of rows per average letter. */
    public static function gradeCounts(Collection $rows): array
    {
        $counts = array_fill_keys(array_keys(Assessment::finalGrades()), 0);
        foreach ($rows as $r) {
            if (isset($counts[$r['grade']])) $counts[$r['grade']]++;
        }

        return $counts;
    }

    public function title(): string
    {
        return $this->filters['type'] === 'a2' ? 'Rekap Rata-rata Assessment 2' : 'Rekap Rata-rata Assessment 1';
    }

    /** "Periode: Oktober 2025 – September 2026 (12 bulan) · Seksi: ASSY 3" */
    public function description(): string
    {
        $unit = $this->filters['unit'] ? A2Unit::find($this->filters['unit'])?->name : null;

        return collect([
            'Periode: ' . ApprovedExport::labelFor($this->filters['from'])
                . ($this->filters['from'] !== $this->filters['to'] ? ' – ' . ApprovedExport::labelFor($this->filters['to']) : '')
                . ' (' . count($this->periods) . ' bulan)',
            $this->filters['type'] === 'a1' && $this->filters['department'] !== '' ? 'Departemen: ' . $this->filters['department'] : null,
            $this->filters['type'] === 'a1' && $this->filters['section'] !== '' ? 'Seksi: ' . $this->filters['section'] : null,
            $unit ? 'Unit: ' . $unit : null,
        ])->filter()->implode(' · ');
    }

    /** Short month label for a column: "Okt 26". */
    public static function monthLabel(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->locale('id')->translatedFormat('M y');
    }

    public function filename(string $extension): string
    {
        $unit = $this->filters['unit'] ? A2Unit::find($this->filters['unit'])?->name : null;

        return \Illuminate\Support\Str::slug(implode(' ', array_filter(['rekap-rata-rata', $this->filters['type'], $this->filters['from'], $this->filters['to'],
            $this->filters['department'], $this->filters['section'], $unit])), '-') . '.' . $extension;
    }
}
