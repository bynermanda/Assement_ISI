<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use App\Support\ReviewRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * HRD attendance import, hybrid: the browser reads the HRD attendance export (CSV / XLS / XLSX), computes the two
 * attendance grades and shows an editable preview; this controller re-validates and stores them in one go.
 *
 *  - Point 1 Tingkat Kehadiran (score_attendance): max(Ijin Pribadi days, Sakit days) -> 0 = A, <=1 = B, <=3 = C, >3 = D
 *  - Point 2 Berada di area kerja (score_tardiness): Terlambat (times)          -> 0 = A,  1 = B, 2-3 = C, >3 = D
 *
 * Rows are saved as Draft with hrd_imported_at set: the two attendance scores are then LOCKED for the Penilai and the
 * approver (Assessment::hrdLocked(), enforced by every save path); the Penilai completes the other four criteria.
 * HRD can correct them by importing again. Assessments already sent or approved for the period are never touched.
 */
class AssessmentImportController extends Controller
{
    /** Statuses the import may write into (a new row, a draft, or one sent back for revision). */
    private const WRITABLE = [Assessment::STATUS_DRAFT, Assessment::STATUS_REJECTED];

    public function create(Request $request): View
    {
        $period = $this->period((string) $request->query('period', now()->format('Y-m')));

        $existing = Assessment::where('period', $period)->pluck('status', 'employee_id');

        // what the browser needs to match the file: normalised NIK -> employee, Assessment 1 or not, current status
        $employees = ReviewRules::activeEmployees()->mapWithKeys(fn (Employee $e) => [ReviewRules::normalizeNik($e->id_employee) => [
            'id'     => $e->id_employee,
            'name'   => $e->name,
            'unit'   => ReviewRules::unitOf($e),
            'a1'     => ReviewRules::isAssessed($e),
            'status' => $existing[$e->id_employee] ?? null,
        ]]);

        return view('admin.assessments.import', [
            'period'      => $period,
            'periodLabel' => \App\Support\ApprovedExport::labelFor($period),
            'employees'   => $employees,
            'writable'    => self::WRITABLE,
        ]);
    }

    /**
     * Batch save from the preview (JSON). Everything is validated first; then one upsert per 200 rows inside one
     * transaction. Response: counts plus the rows that were skipped and why.
     */
    public function store(Request $request): JsonResponse
    {
        $grades = array_values(Assessment::GRADES);
        $data   = $request->validate([
            'period'                  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'rows'                    => ['required', 'array', 'min:1', 'max:3000'],
            'rows.*.id_employee'      => ['required', 'string', 'max:30'],
            'rows.*.score_attendance' => ['required', 'integer', Rule::in($grades)],
            'rows.*.score_tardiness'  => ['required', 'integer', Rule::in($grades)],
            'rows.*.ijin'             => ['nullable', 'numeric', 'min:0', 'max:366'],
            'rows.*.sakit'            => ['nullable', 'numeric', 'min:0', 'max:366'],
            'rows.*.late'             => ['nullable', 'numeric', 'min:0', 'max:366'],
        ], [
            'period.regex' => 'Periode harus berformat TTTT-BB.',
            'rows.required' => 'Tidak ada baris yang dikirim.',
            'rows.*.score_attendance.in' => 'Nilai Tingkat Kehadiran harus A, B, C atau D.',
            'rows.*.score_tardiness.in'  => 'Nilai Berada di area kerja harus A, B, C atau D.',
        ]);

        $period  = $data['period'];
        $user    = Auth::user();
        $byNik   = ReviewRules::activeEmployees()->keyBy(fn (Employee $e) => ReviewRules::normalizeNik($e->id_employee));
        $skipped = [];
        $rows    = [];

        // re-check every row on the server: known, active, in Assessment 1; one row per employee (last one wins)
        foreach ($data['rows'] as $i => $row) {
            $e = $byNik->get(ReviewRules::normalizeNik($row['id_employee']));
            if (! $e) {
                $skipped[] = ['id' => $row['id_employee'], 'reason' => 'NIK tidak ada di data karyawan aktif'];
                continue;
            }
            if (! ReviewRules::isAssessed($e)) {
                $skipped[] = ['id' => $row['id_employee'], 'name' => $e->name, 'reason' => 'bukan Assessment 1 (pimpinan / Assessment 2)'];
                continue;
            }
            $rows[$e->id_employee] = $row + ['employee' => $e];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $period, $user, &$skipped, &$created, &$updated) {
            if ($rows === []) {
                return;
            }
            $existing = Assessment::where('period', $period)->whereIn('employee_id', array_keys($rows))->lockForUpdate()->get()->keyBy('employee_id');
            $now      = Carbon::now();
            $upsert   = [];

            foreach ($rows as $id => $row) {
                $current = $existing->get($id);
                if ($current && ! in_array($current->status, self::WRITABLE, true)) {
                    $skipped[] = ['id' => $id, 'name' => $row['employee']->name, 'reason' => 'sudah dikirim / disetujui (' . $current->status . ')'];
                    continue;
                }

                $scores = [];
                foreach (array_keys(Assessment::CRITERIA) as $column) {
                    $scores[$column] = $current?->{$column};   // other criteria: keep what the Penilai entered, else empty
                }
                $scores['score_attendance'] = (int) $row['score_attendance'];
                $scores['score_tardiness']  = (int) $row['score_tardiness'];

                $notes = array_merge($current?->notes ?? [], array_filter([
                    'score_attendance' => isset($row['ijin']) || isset($row['sakit'])
                        ? sprintf('Impor HRD: ijin pribadi %s hari, sakit %s hari', $this->num($row['ijin'] ?? 0), $this->num($row['sakit'] ?? 0)) : null,
                    'score_tardiness'  => isset($row['late']) ? sprintf('Impor HRD: terlambat %s kali', $this->num($row['late'])) : null,
                ]));

                $upsert[] = ['employee_id' => $id, 'period' => $period] + $scores + [
                    'final_score' => Assessment::calculateFinalScore($scores),
                    'status'      => $current?->status ?? Assessment::STATUS_DRAFT,
                    'notes'       => json_encode($notes, JSON_UNESCAPED_UNICODE),   // upsert skips casts
                    'created_by'  => $current?->created_by ?? $user->username,
                    'created_at'  => $current?->created_at ?? $now,
                    'updated_at'  => $now,
                    'hrd_imported_at' => $now,   // locks score_attendance + score_tardiness, status stays Draft
                ];
                $current ? $updated++ : $created++;
            }

            // existing rows: only the two attendance scores, the total, the notes and the HRD lock change
            foreach (array_chunk($upsert, 200) as $chunk) {
                Assessment::upsert($chunk, ['employee_id', 'period'], ['score_attendance', 'score_tardiness', 'final_score', 'notes', 'updated_at', 'hrd_imported_at']);
            }
        });

        return response()->json([
            'message' => "{$created} draf baru, {$updated} draf diperbarui (nilai kehadiran terkunci)" . ($skipped ? ', ' . count($skipped) . ' dilewati' : '') . '.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    private function period(string $period): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : now()->format('Y-m');
    }

    private function num(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, ',', ''), '0'), ',');
    }
}
