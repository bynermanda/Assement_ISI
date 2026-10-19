<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use App\Models\HrdSanction;
use App\Models\User;
use App\Support\ApprovedExport;
use App\Support\ReviewRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * HRD sanction import -> Point 6 (score_safety, 安全紀律 Kepatuhan Keselamatan), hybrid like the attendance import:
 * the browser reads the HRD "Data List Sanksi Karyawan" file (CSV / XLS / XLSX), keeps the letters of the chosen month
 * and shows a preview; this controller re-validates, stores the letters and recomputes Point 6 from the database.
 *
 *  - Grade per employee and month (Point 6 guideline): 1 sanction -> C, 2 or more -> D (HrdSanction::gradeFor).
 *    Counted from hrd_sanctions, so importing the same file twice never counts a letter twice.
 *  - Note of Point 6: "[No. Surat] - [Kasus]", one line per letter (HrdSanction::noteFor).
 *  - The assessment gets sanction_locked_at: Point 6 and its note are LOCKED for the Penilai and the approver
 *    (Assessment::lockedColumns(), enforced by every save path). Status stays Draft; no sanction = Point 6 stays manual.
 *  - Assessments already sent or approved are not changed (reported); the letter itself is still stored.
 */
class SanctionImportController extends Controller
{
    private const WRITABLE = [Assessment::STATUS_DRAFT, Assessment::STATUS_REJECTED];

    public function create(Request $request): View
    {
        $period   = $this->period((string) $request->query('period', now()->format('Y-m')));
        $existing = Assessment::where('period', $period)->get(['employee_id', 'status', 'sanction_locked_at'])->keyBy('employee_id');

        $employees = ReviewRules::activeEmployees()->mapWithKeys(fn (Employee $e) => [ReviewRules::normalizeNik($e->id_employee) => [
            'id'     => $e->id_employee,
            'name'   => $e->name,
            'unit'   => ReviewRules::unitOf($e),
            'a1'     => ReviewRules::isAssessed($e),
            'status' => $existing->get($e->id_employee)?->status,
        ]]);

        $stored = HrdSanction::with('employee:id_employee,name,department,section')
            ->where('period', $period)->orderBy('sanction_date')->orderBy('id')->get();

        return view('admin.assessments.sanctions', [
            'period'      => $period,
            'periodLabel' => ApprovedExport::labelFor($period),
            'employees'   => $employees,
            'writable'    => self::WRITABLE,
            'stored'      => $stored,
            'existing'    => $existing,
            'storedKeys'  => $stored->pluck('source_key')->all(),
        ]);
    }

    /**
     * Batch save from the preview (JSON): validate everything, upsert the letters (by source_key), then recompute
     * Point 6 of every employee touched, all in one transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period'               => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'rows'                 => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.nik'           => ['required', 'string', 'max:30'],
            'rows.*.id_employee'   => ['required', 'string', 'max:30'],
            'rows.*.name'          => ['nullable', 'string', 'max:150'],
            'rows.*.section'       => ['nullable', 'string', 'max:60'],
            'rows.*.sanction_date' => ['required', 'date_format:Y-m-d'],
            'rows.*.letter_no'     => ['nullable', 'string', 'max:80'],
            'rows.*.type'          => ['required', Rule::in(HrdSanction::TYPES)],
            'rows.*.case_text'     => ['nullable', 'string', 'max:10000'],
            'rows.*.case_summary'  => ['required', 'string', 'max:450'],
            'rows.*.article'       => ['nullable', 'string', 'max:150'],
        ], [
            'period.regex'                   => 'Periode harus berformat TTTT-BB.',
            'rows.required'                  => 'Tidak ada sanksi yang dikirim.',
            'rows.*.sanction_date.date_format' => 'Tanggal sanksi tidak terbaca.',
            'rows.*.type.in'                 => 'Jenis sanksi harus salah satu dari: ' . implode(', ', HrdSanction::TYPES) . '.',
            'rows.*.case_summary.required'   => 'Kasus (untuk catatan Poin 6) wajib diisi.',
            'rows.*.case_summary.max'        => 'Kasus untuk catatan Poin 6 maksimal 450 karakter.',
        ]);

        $period  = $data['period'];
        $user    = Auth::user();
        $active  = ReviewRules::activeEmployees();
        $byNik   = $active->keyBy(fn (Employee $e) => ReviewRules::normalizeNik($e->id_employee));
        $byId    = $active->keyBy('id_employee');
        $now     = Carbon::now();
        $skipped = [];
        $letters = [];

        // re-check every row on the server: date inside the period, NIK of an active employee (or the employee the
        // preview matched by name, only if that name really matches)
        foreach ($data['rows'] as $row) {
            if (substr($row['sanction_date'], 0, 7) !== $period) {
                $skipped[] = ['id' => $row['nik'], 'name' => $row['name'] ?? null, 'reason' => "tanggal {$row['sanction_date']} di luar periode {$period}"];
                continue;
            }
            $e = $byNik->get(ReviewRules::normalizeNik($row['nik']));
            if (! $e && ($candidate = $byId->get($row['id_employee'])) && $this->sameName($candidate->name, $row['name'] ?? '')) {
                $e = $candidate;
            }
            if (! $e) {
                $skipped[] = ['id' => $row['nik'], 'name' => $row['name'] ?? null, 'reason' => 'NIK tidak ada di data karyawan aktif'];
                continue;
            }
            $letter = $this->clean($row['letter_no'] ?? null, 80);
            $key    = HrdSanction::keyFor($e->id_employee, $row['sanction_date'], $letter, $row['type']);
            $letters[$key] = [
                'source_key'      => $key,
                'employee_id'     => $e->id_employee,
                'period'          => $period,
                'sanction_date'   => $row['sanction_date'],
                'letter_no'       => $letter,
                'sanction_type'   => $row['type'],
                'case_text'       => $this->clean($row['case_text'] ?? null, 10000),
                'case_summary'    => $this->clean($row['case_summary'], 450),
                'article'         => $this->clean($row['article'] ?? null, 150),
                'name_in_file'    => $this->clean($row['name'] ?? null, 150),
                'section_in_file' => $this->clean($row['section'] ?? null, 60),
                'imported_by'     => $user->username,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        $result = ['created' => 0, 'updated' => 0, 'cleared' => 0];
        DB::transaction(function () use ($letters, $period, $user, &$skipped, &$result) {
            foreach (array_chunk(array_values($letters), 200) as $chunk) {
                HrdSanction::upsert($chunk, ['source_key'], [
                    'sanction_date', 'letter_no', 'sanction_type', 'case_text', 'case_summary', 'article',
                    'name_in_file', 'section_in_file', 'imported_by', 'updated_at',
                ]);
            }
            $ids    = array_values(array_unique(array_column($letters, 'employee_id')));
            $result = $this->applyPoint6($ids, $period, $user, $skipped);
        });

        $n = count($letters);
        return response()->json([
            'message' => "{$n} surat sanksi tersimpan · Poin 6: {$result['created']} draf baru, {$result['updated']} penilaian diperbarui (terkunci)"
                . ($skipped ? ' · ' . count($skipped) . ' dilewati' : '') . '.',
            'stored'  => $n,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $skipped,
        ]);
    }

    /** Removes one letter (wrong import) and recomputes Point 6; no letter left = Point 6 unlocked and empty again. */
    public function destroy(HrdSanction $sanction): RedirectResponse
    {
        $skipped = [];
        DB::transaction(function () use ($sanction, &$skipped) {
            $sanction->delete();
            $this->applyPoint6([$sanction->employee_id], $sanction->period, Auth::user(), $skipped);
        });

        return redirect()->route('admin.assessments.sanctions', ['period' => $sanction->period])
            ->with($skipped ? 'error' : 'success', $skipped
                ? 'Surat sanksi dihapus, tetapi Poin 6 tidak diubah: ' . $skipped[0]['reason'] . '.'
                : 'Surat sanksi ' . ($sanction->letter_no ?: $sanction->sanction_type) . ' dihapus; Poin 6 dihitung ulang.');
    }

    /**
     * Point 6 of these employees in this period, from the letters in the database:
     * letters -> score_safety C / D + note + lock; no letters (after a delete) -> lock, score and note removed.
     * Only Assessment 1 employees, only Draft / Rejected (or no assessment yet: a new Draft).
     *
     * @param  list<string>  $employeeIds
     * @return array{created:int, updated:int, cleared:int}
     */
    private function applyPoint6(array $employeeIds, string $period, User $user, array &$skipped): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'cleared' => 0];
        if ($employeeIds === []) {
            return $counts;
        }

        $employees = Employee::whereIn('id_employee', $employeeIds)->get()->keyBy('id_employee');
        $letters   = HrdSanction::where('period', $period)->whereIn('employee_id', $employeeIds)->get()->groupBy('employee_id');
        $existing  = Assessment::where('period', $period)->whereIn('employee_id', $employeeIds)->lockForUpdate()->get()->keyBy('employee_id');
        $now       = Carbon::now();
        $upsert    = [];
        $empty     = [];

        foreach ($employeeIds as $id) {
            $e       = $employees->get($id);
            $current = $existing->get($id);
            $list    = $letters->get($id, collect());

            if (! $e || ! ReviewRules::isAssessed($e)) {
                $skipped[] = ['id' => $id, 'name' => $e?->name, 'reason' => 'bukan Assessment 1 (pimpinan / Assessment 2): surat disimpan, Poin 6 tidak dipakai'];
                continue;
            }
            if ($list->isEmpty() && ! $current?->sanctionLocked()) {
                continue;   // nothing to apply, nothing to undo
            }
            if ($current && ! in_array($current->status, self::WRITABLE, true)) {
                $skipped[] = ['id' => $id, 'name' => $e->name, 'reason' => "penilaian sudah {$current->status}: Poin 6 tidak diubah (kembalikan / tolak dulu, lalu impor ulang)"];
                continue;
            }

            $scores = [];
            foreach (array_keys(Assessment::CRITERIA) as $column) {
                $scores[$column] = $current?->{$column};   // other criteria: keep what the Penilai / HRD entered
            }
            $notes = $current?->notes ?? [];

            if ($list->isNotEmpty()) {
                $scores['score_safety'] = HrdSanction::gradeFor($list->count());
                $notes['score_safety']  = HrdSanction::noteFor($list);
                $lockedAt = $now;
                $lockedBy = $user->username;
            } else {
                $scores['score_safety'] = null;
                unset($notes['score_safety']);
                $lockedAt = $lockedBy = null;
                if ($current && ! array_filter($scores, fn ($v) => $v !== null) && ! array_diff_key($notes, [Assessment::NOTE_GENERAL => 1])) {
                    $empty[] = $current->id;   // a draft that only held the sanction: remove it again
                    $counts['cleared']++;
                    continue;
                }
                $counts['cleared']++;
            }

            $upsert[] = ['employee_id' => $id, 'period' => $period] + $scores + [
                'final_score'        => Assessment::calculateFinalScore($scores),
                'status'             => $current?->status ?? Assessment::STATUS_DRAFT,
                'notes'              => $notes === [] ? null : json_encode($notes, JSON_UNESCAPED_UNICODE),   // upsert skips casts
                'created_by'         => $current?->created_by ?? $user->username,
                'created_at'         => $current?->created_at ?? $now,
                'updated_at'         => $now,
                'sanction_locked_at' => $lockedAt,
                'sanction_locked_by' => $lockedBy,
            ];
            if ($list->isNotEmpty()) {
                $current ? $counts['updated']++ : $counts['created']++;
            }
        }

        // existing rows: only Point 6, the total, the notes and the lock change
        foreach (array_chunk($upsert, 200) as $chunk) {
            Assessment::upsert($chunk, ['employee_id', 'period'], ['score_safety', 'final_score', 'notes', 'updated_at', 'sanction_locked_at', 'sanction_locked_by']);
        }
        if ($empty) {
            Assessment::whereIn('id', $empty)->delete();
        }

        return $counts;
    }

    private function sameName(string $a, string $b): bool
    {
        $n = fn (string $v) => preg_replace('/[^A-Z]/', '', mb_strtoupper($v));
        return $n($a) !== '' && $n($a) === $n($b);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function period(string $period): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : now()->format('Y-m');
    }
}
