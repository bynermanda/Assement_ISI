<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\A2Unit;
use App\Models\Assessment;
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
    public const MAX_MONTHS = 60;

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
        for ($d = Carbon::createFromFormat('!Y-m', $from); $d->format('Y-m') <= $to && count($periods) < self::MAX_MONTHS; $d->addMonthNoOverflow()) {
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
        $assessments = ($a2 ? A2Assessment::with(['employee', 'unit']) : Assessment::with('employee'))
            ->where('status', Assessment::STATUS_APPROVED)
            ->whereIn('period', $this->periods)
            ->get(['id', 'employee_id', 'period', 'final_score'] + ($a2 ? [4 => 'unit_id'] : []));

        // current unit of each Assessment 2 participant (falls back to the unit of the assessment)
        $units = $a2 ? A2Participant::with('unit')->whereIn('employee_id', $assessments->pluck('employee_id')->unique())->get()->keyBy('employee_id') : collect();
        $key   = fn (?string $v) => mb_strtolower(trim((string) $v));

        return $assessments->groupBy('employee_id')
            ->map(function (Collection $list, string $id) use ($a2, $units) {
                $e      = $list->first()->employee;
                $unit   = $a2 ? ($units->get($id)?->unit ?? $list->first()->unit) : null;
                $scores = array_fill_keys($this->periods, null);
                foreach ($list as $a) {
                    $scores[$a->period] = (float) $a->final_score;
                }
                $filled  = array_filter($scores, fn ($s) => $s !== null);
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
