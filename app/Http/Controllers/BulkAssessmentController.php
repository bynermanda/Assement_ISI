<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use App\Support\ReviewRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Assessment 1, bulk: Penilai 1 scores a whole section in one grid and saves it in ONE request.
 * Same rules as the single form (AssessmentController::store): only own employees, only Draft / Rejected
 * (or new) rows can be written, HRD columns stay as loaded, final score computed on the server.
 */
class BulkAssessmentController extends Controller
{
    private const MAX_ROWS = 500;

    public function create(Request $request): View|RedirectResponse
    {
        $user   = Auth::user();
        $period = $this->currentPeriod();

        abort_unless(in_array(ReviewRules::STAGE_ASSESS, ReviewRules::dutyStages($user), true), 403, t('Akun Anda bukan akun Penilai 1.'));

        if (! ReviewRules::usesBulk($user)) {
            return redirect()->route('assessments.create')->with('success', 'Your department has ' . config('assessment.bulk_min_employees', 25) . ' employees or fewer: please use the Assessment Form.');
        }

        $byUnit = ReviewRules::employeesFor($user, ReviewRules::STAGE_ASSESS)
            ->groupBy(fn (Employee $e) => ReviewRules::unitOf($e))
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);

        $unit      = $byUnit->has((string) $request->query('unit')) ? (string) $request->query('unit') : $byUnit->keys()->first();
        $employees = $unit ? $byUnit->get($unit)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values() : collect();

        $existing = Assessment::where('period', $period)
            ->whereIn('employee_id', $employees->pluck('id_employee'))
            ->get()
            ->keyBy('employee_id');

        $columns = array_keys(Assessment::CRITERIA);

        $rows = $employees->map(function (Employee $e) use ($existing, $columns) {
            $a = $existing->get($e->id_employee);

            return [
                'id'       => $e->id_employee,
                'name'     => $e->name,
                'position' => trim($e->position . ' · ' . $e->grade, ' ·'),
                'status'   => $a?->status,
                'editable' => ! $a || in_array($a->status, Assessment::EDITABLE_STATUSES, true),
                'revision' => $a?->status === Assessment::STATUS_REJECTED ? $a->rejection_note : null,
                'locked'   => $a?->hrdLocked() ? Assessment::HRD_COLUMNS : [],
                'scores'   => collect($columns)->mapWithKeys(fn ($c) => [$c => $a?->{$c} === null ? '' : (string) $a->{$c}])->all(),
                'note'     => (string) ($a?->notes[Assessment::NOTE_GENERAL] ?? ''),
                // per-criterion notes written in the manual form: shown as a hint, kept on save
                'criterionNotes' => collect($a?->notes ?? [])->except(Assessment::NOTE_GENERAL)
                    ->mapWithKeys(fn ($text, $c) => [Assessment::itemText($c) => $text])->all(),
            ];
        })->values();

        $first      = $employees->first();
        $guidelines = $first ? Assessment::guidelinesFor($first->department, $first->section) : null;

        return view('assessments.bulk', [
            'targets'     => collect(Assessment::CRITERIA)->map(fn ($m) => $guidelines?->{$m['description']}),
            'periodLabel' => $this->periodLabel($period),
            'period'      => $period,
            'unit'        => $unit,
            'units'       => $byUnit->map->count(),
            'rows'        => $rows,
            'criteria'    => collect(Assessment::CRITERIA)->map(fn ($m, $c) => ['zh' => $m['zh'], 'id' => $m['id'], 'text' => Assessment::itemText($c), 'weight' => $m['weight']]),
            'layout'      => Assessment::criteriaLayout(),
            'grades'      => Assessment::GRADES,
        ]);
    }

    /**
     * Body (JSON): { action: "draft"|"submit", rows: [ { id_employee, score_attendance, ..., score_safety }, ... ] }
     * All rows are checked first; if one is wrong nothing is saved. Then one upsert per 200 rows.
     */
    public function store(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $period  = $this->currentPeriod();
        $columns = array_keys(Assessment::CRITERIA);
        $points  = array_values(Assessment::GRADES);

        abort_unless(ReviewRules::usesBulk($user), 403, 'Assessment Massal is only for departments with more than ' . config('assessment.bulk_min_employees', 25) . ' employees.');

        $allowed =ReviewRules::employeesFor($user, ReviewRules::STAGE_ASSESS)->keyBy('id_employee');

        $rules = [
            'action'             => ['required', Rule::in(['draft', 'submit'])],
            'rows'               => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS],
            'rows.*.id_employee' => ['required', 'string', 'distinct', Rule::in($allowed->keys()->all())],
            'rows.*.note'        => ['nullable', 'string', 'max:' . Assessment::NOTE_MAX],
        ];
        foreach ($columns as $column) {
            $rules["rows.*.{$column}"] = ['nullable', 'integer', Rule::in($points)];   // required unless HRD-locked, checked below
        }

        $data = $request->validate($rules, [
            'rows.*.id_employee.in'       => t('Salah satu karyawan bukan karyawan yang Anda nilai.'),
            'rows.*.id_employee.distinct' => t('Ada karyawan yang muncul dua kali.'),
        ]);

        $existing = Assessment::where('period', $period)
            ->whereIn('employee_id', array_column($data['rows'], 'id_employee'))
            ->get()
            ->keyBy('employee_id');

        $errors = [];
        $upsert = [];
        $now    = now();
        $status = $data['action'] === 'submit' ? Assessment::STATUS_PENDING : Assessment::STATUS_DRAFT;

        foreach ($data['rows'] as $i => $row) {
            $id      = $row['id_employee'];
            $name    = $allowed->get($id)->name;
            $current = $existing->get($id);

            if ($current && ! in_array($current->status, Assessment::EDITABLE_STATUSES, true)) {
                $errors["rows.{$i}"] = "{$name}: already {$current->status}, it cannot be changed.";
                continue;
            }

            $locked = $current?->hrdLocked() ? Assessment::HRD_COLUMNS : [];
            $scores = [];

            foreach ($columns as $column) {
                $scores[$column] = in_array($column, $locked, true) ? $current->{$column} : ($row[$column] ?? null);
            }

            if (in_array(null, $scores, true)) {
                $errors["rows.{$i}"] = "{$name}: not every criterion has a grade.";
                continue;
            }

            // The grid edits the general note only; per-criterion notes from the manual form are kept
            $notes = Assessment::cleanNotes(array_merge($current?->notes ?? [], [Assessment::NOTE_GENERAL => $row['note'] ?? '']));

            $scores   = array_map('intval', $scores);
            $upsert[] = ['employee_id' => $id, 'period' => $period] + $scores + [
                'notes'          => $notes === null ? null : json_encode($notes, JSON_UNESCAPED_UNICODE),   // upsert skips casts
                'final_score'    => Assessment::calculateFinalScore($scores),
                'status'         => $status,
                'created_by'     => $user->username,
                'rejection_note' => null,
                'verified_by'    => null,
                'approved_by'    => null,
                'edited_by'      => null,
                'edited_at'      => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if ($errors !== []) {
            return response()->json(['message' => t('Tidak ada yang disimpan. Perbaiki baris di bawah.'), 'errors' => $errors], 422);
        }

        $update = array_values(array_diff(array_keys($upsert[0]), ['employee_id', 'period', 'created_at']));

        DB::transaction(function () use ($upsert, $update) {
            foreach (array_chunk($upsert, 200) as $chunk) {
                // INSERT ... ON CONFLICT (employee_id, period) DO UPDATE: one query for the whole chunk
                Assessment::upsert($chunk, ['employee_id', 'period'], $update);
            }
        });

        $count = count($upsert);

        return response()->json([
            'message' => $status === Assessment::STATUS_PENDING
                ? t(':n penilaian dikirim ke Penilai 2.', ['n' => $count])
                : t(':n penilaian disimpan sebagai draf.', ['n' => $count]),
            'saved'   => $count,
        ]);
    }

    private function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    private function periodLabel(string $period): string
    {
        return \App\Support\ApprovedExport::labelFor($period);
    }
}
