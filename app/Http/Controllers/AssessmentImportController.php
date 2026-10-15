<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRD uploads attendance data once; the system turns it into Draft assessments
 * (attendance + tardiness filled in, locked). Departments then complete the remaining criteria.
 */
class AssessmentImportController extends Controller
{
    public function create(): View
    {
        return view('admin.assessments.import', [
            'period'          => now()->format('Y-m'),
            'activeEmployees' => Employee::where('status', 'Active')->count(),
        ]);
    }

    /** CSV with every active employee, pre-filled with 0 leave days and 0 late. HRD only edits the exceptions. */
    public function template(): StreamedResponse
    {
        $employees = Employee::where('status', 'Active')
            ->orderBy('department')->orderBy('name')
            ->get(['id_employee', 'name', 'department', 'section']);

        return response()->streamDownload(function () use ($employees) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM: Excel shows the names correctly
            fwrite($out, "sep=;\n");        // tells Excel (any regional setting) to split on ";"

            fputcsv($out, ['id_employee', 'name', 'department', 'section', 'leave_days', 'late_count'], ';');
            foreach ($employees as $employee) {
                fputcsv($out, [$employee->id_employee, $employee->name, $employee->department, $employee->section, 0, 0], ';');
            }

            fclose($out);
        }, 'hrd-attendance-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'file'   => ['required', 'file', 'max:4096', 'extensions:csv,txt'],
        ], [
            'file.extensions' => 'Please upload a .csv file (in Excel: Save As, CSV UTF-8).',
        ]);

        set_time_limit(300);

        [$header, $rows] = $this->readCsv($request->file('file')->getRealPath());

        if (! in_array('id_employee', $header, true)) {
            return back()->withErrors(['file' => 'Column "id_employee" was not found. Please start from the downloaded template.']);
        }

        $hasAttendance = array_intersect(['leave_days', 'attendance_grade'], $header) !== [];
        $hasTardiness  = array_intersect(['late_count', 'tardiness_grade'], $header) !== [];

        if (! $hasAttendance || ! $hasTardiness) {
            return back()->withErrors(['file' => 'Columns "leave_days" and "late_count" are required. Please start from the downloaded template.']);
        }

        $period = $request->input('period');
        $admin  = Auth::user()->username;
        $now    = now();

        $employees = Employee::where('status', 'Active')->get()
            ->keyBy(fn ($employee) => $this->normalizeId($employee->id_employee));
        $existing = Assessment::where('period', $period)->get()->keyBy('employee_id');

        $result  = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'problems' => []];
        $inserts = [];
        $groups  = [];   // employees that get the same new values are updated with ONE query
        $seen    = [];

        foreach ($rows as $line => $row) {
            $rawId = $row['id_employee'] ?? '';
            if ($rawId === '') {
                continue;
            }

            $employee = $employees->get($this->normalizeId($rawId));
            if (! $employee) {
                $this->problem($result, 'failed', $line, $rawId, 'Employee not found or not active.');
                continue;
            }

            if (isset($seen[$employee->id_employee])) {
                $this->problem($result, 'failed', $line, $rawId, 'Duplicate ID in the file.');
                continue;
            }
            $seen[$employee->id_employee] = true;

            [$attendance, $error] = $this->score(
                $row['attendance_grade'] ?? '', $row['leave_days'] ?? '', fn ($v) => Assessment::gradeForLeaveDays($v)
            );
            if ($error) {
                $this->problem($result, 'failed', $line, $rawId, 'Attendance: ' . $error);
                continue;
            }

            [$tardiness, $error] = $this->score(
                $row['tardiness_grade'] ?? '', $row['late_count'] ?? '', fn ($v) => Assessment::gradeForLateCount($v)
            );
            if ($error) {
                $this->problem($result, 'failed', $line, $rawId, 'Tardiness: ' . $error);
                continue;
            }

            $scores  = ['score_attendance' => $attendance, 'score_tardiness' => $tardiness];
            $current = $existing->get($employee->id_employee);

            // Never touch an assessment that already left the draft stage
            if ($current && $current->status !== Assessment::STATUS_DRAFT) {
                $this->problem($result, 'skipped', $line, $rawId, "Already {$current->status}, not changed.");
                continue;
            }

            if ($current) {
                $final = Assessment::calculateFinalScore(array_merge($current->only(array_keys(Assessment::CRITERIA)), $scores));
                $key   = "{$attendance}|{$tardiness}|{$final}";

                $groups[$key]['values'] ??= $scores + ['final_score' => $final];
                $groups[$key]['ids'][]    = $current->id;
                $result['updated']++;
            } else {
                $inserts[] = [
                    'employee_id'        => $employee->id_employee,
                    'period'             => $period,
                    'status'             => Assessment::STATUS_DRAFT,
                    'created_by'         => $admin,
                    'score_attendance'   => $attendance,
                    'score_tardiness'    => $tardiness,
                    'score_work_quality' => null,
                    'score_efficiency'   => null,
                    'score_attitude'     => null,
                    'score_safety'       => null,
                    'final_score'        => Assessment::calculateFinalScore($scores),
                    'hrd_imported_at'    => $now,
                    'hrd_imported_by'    => $admin,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
                $result['created']++;
            }
        }

        try {
            DB::transaction(function () use ($inserts, $groups, $now, $admin) {
                foreach (array_chunk($inserts, 150) as $chunk) {
                    DB::table('assessments')->insert($chunk);
                }

                foreach ($groups as $group) {
                    foreach (array_chunk($group['ids'], 500) as $ids) {
                        Assessment::whereIn('id', $ids)->update($group['values'] + [
                            'hrd_imported_at' => $now,
                            'hrd_imported_by' => $admin,
                            'updated_at'      => $now,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed, nothing was saved: ' . $e->getMessage());
        }

        return redirect()->route('admin.assessments.import')
            ->with('import_result', $result + ['period' => $period]);
    }

    /* ------------------------------------------------------------------ */

    /** Grade letter wins; otherwise the number is converted with the guideline rules. */
    private function score(string $letter, string $count, callable $fromCount): array
    {
        $letter = strtoupper(trim($letter));

        if ($letter !== '') {
            return isset(Assessment::GRADES[$letter])
                ? [Assessment::GRADES[$letter], null]
                : [null, "invalid grade \"{$letter}\" (use A, B, C or D)."];
        }

        $count = str_replace(',', '.', trim($count));

        if ($count === '') {
            return [null, 'value is empty.'];
        }
        if (! is_numeric($count) || (float) $count < 0) {
            return [null, "invalid number \"{$count}\"."];
        }

        return [$fromCount((float) $count), null];
    }

    /** Excel may turn "5.250.914" into 5250914, so IDs are compared without punctuation. */
    private function normalizeId(?string $id): string
    {
        return strtoupper(ltrim(preg_replace('/[^A-Za-z0-9]/', '', (string) $id), '0'));
    }

    private function problem(array &$result, string $type, int $line, string $id, string $message): void
    {
        $result[$type]++;
        $result['problems'][] = ['line' => $line, 'id' => $id, 'message' => $message, 'type' => $type];
    }

    /** @return array{0: string[], 1: array<int, array<string,string>>} header names and rows keyed by file line number */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);

        if ($lines && stripos(trim($lines[0]), 'sep=') === 0) {
            array_shift($lines);
        }

        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));

        if (count($lines) < 2) {
            return [[], []];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $header = array_map(
            fn ($name) => strtolower(str_replace([' ', '-'], '_', trim((string) $name))),
            str_getcsv($lines[0], $delimiter)
        );

        $rows = [];
        foreach (array_slice($lines, 1) as $index => $line) {
            $cells = str_getcsv($line, $delimiter);
            $row   = [];

            foreach ($header as $position => $name) {
                if ($name !== '') {
                    $row[$name] = trim((string) ($cells[$position] ?? ''));
                }
            }

            $rows[$index + 2] = $row;
        }

        return [$header, $rows];
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return array_key_first($counts);
    }
}
