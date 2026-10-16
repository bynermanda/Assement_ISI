<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Criterion;
use App\Models\A2Unit;
use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Data of the Assessment 2 export (Excel, PDF). ONLY approved assessments. Criteria differ per unit, so the
 * export is grouped per unit; columns come from the criteria saved with the assessments (criteria_snapshot).
 */
final class A2Export
{
    /** @param array{period:string, unit:?int} $filters */
    private function __construct(public readonly array $filters) {}

    public static function fromRequest(Request $request): self
    {
        $period = (string) $request->query('period', now()->format('Y-m'));
        if ($period !== ApprovedExport::ALL_PERIODS && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = now()->format('Y-m');
        }

        return new self(['period' => $period, 'unit' => $request->filled('unit') ? (int) $request->query('unit') : null]);
    }

    /** Approved assessments grouped per unit: [ ['unit' => A2Unit, 'criteria' => Collection, 'rows' => [...]], ... ] */
    public function groups(): Collection
    {
        $assessments = A2Assessment::with(['employee', 'unit', 'creator', 'approver'])
            ->where('status', Assessment::STATUS_APPROVED)
            ->when($this->filters['period'] !== ApprovedExport::ALL_PERIODS, fn ($q) => $q->where('period', $this->filters['period']))
            ->when($this->filters['unit'], fn ($q) => $q->where('unit_id', $this->filters['unit']))
            ->get()
            ->sortBy(fn ($a) => sprintf('%05d|%s|%s', $a->unit?->sort, $a->period, $a->employee?->name));

        return $assessments->groupBy('unit_id')->map(function (Collection $list) {
            $criteria = $this->criteriaOf($list);
            $rows     = $list->values()->map(fn (A2Assessment $a, int $i) => [
                'no'          => $i + 1,
                'period'      => $a->period,
                'nik'         => $a->employee_id,
                'name'        => $a->employee?->name ?? '-',
                'position'    => trim(($a->employee?->position ?? '') . ' · ' . ($a->employee?->grade ?? ''), ' ·'),
                'grades'      => $criteria->mapWithKeys(fn ($c) => [$c->id => Assessment::letterFor($a->scores[$c->id] ?? null)])->all(),
                'final_score' => (float) $a->final_score,
                'final_grade' => Assessment::finalGradeFor($a->final_score),
                'penilai'     => $a->creator?->name ?? $a->created_by ?? '-',
                'approved_by' => $a->approver?->name ?? $a->approved_by ?? '-',
                'approved_at' => $a->updated_at?->format('d M Y'),
                'notes'       => $this->notesText($a, $criteria),
            ]);

            return ['unit' => $list->first()->unit, 'criteria' => $criteria, 'rows' => $rows];
        })->values();
    }

    /** Criteria columns of a unit: the snapshot of its latest assessment, plus any other criterion found in older ones. */
    private function criteriaOf(Collection $list): Collection
    {
        $criteria = collect();
        foreach ($list->sortByDesc('updated_at') as $a) {
            foreach ($a->snapshotCriteria() as $c) {
                if (! $criteria->has($c->id)) {
                    $criteria->put($c->id, $c);
                }
            }
        }

        return $criteria->values();
    }

    private function notesText(A2Assessment $a, Collection $criteria): string
    {
        $byId = $criteria->keyBy('id');

        return collect($a->notes ?? [])->map(function ($text, $key) use ($byId) {
            if ($key === Assessment::NOTE_GENERAL) {
                return "Overall: {$text}";
            }
            $c = $byId->get((int) substr((string) $key, 1));

            return ($c ? trim(preg_replace('/\s+/', ' ', $c->name_id)) : $key) . ": {$text}";
        })->implode("\n");
    }

    public static function finalCounts(Collection $groups): array
    {
        $counts = array_fill_keys(array_keys(Assessment::finalGrades()), 0);
        foreach ($groups as $g) {
            foreach ($g['rows'] as $row) {
                if (isset($counts[$row['final_grade']])) { $counts[$row['final_grade']]++; }
            }
        }

        return $counts;
    }

    public function description(): string
    {
        $unit = $this->filters['unit'] ? A2Unit::find($this->filters['unit'])?->name : null;

        return 'Period: ' . ($this->filters['period'] === ApprovedExport::ALL_PERIODS ? 'All periods' : ApprovedExport::labelFor($this->filters['period']))
            . ($unit ? " · Unit: {$unit}" : '');
    }

    /** Indonesian version for the PDF header (the Excel keeps description()). */
    public function pdfDescription(): string
    {
        $unit = $this->filters['unit'] ? A2Unit::find($this->filters['unit'])?->name : null;

        return 'Periode: ' . ($this->filters['period'] === ApprovedExport::ALL_PERIODS ? 'Semua periode' : ApprovedExport::labelFor($this->filters['period']))
            . ($unit ? " · Unit: {$unit}" : '');
    }

    public function filename(string $extension): string
    {
        $unit = $this->filters['unit'] ? A2Unit::find($this->filters['unit'])?->name : null;

        return \Illuminate\Support\Str::slug('assessment2-approved ' . $this->filters['period'] . ' ' . $unit, '-') . '.' . $extension;
    }

    /** Group runs of the criteria (for the merged group headings), as in the assessment table. */
    public static function layout(Collection $criteria): array
    {
        return A2Criterion::layout($criteria);
    }
}
