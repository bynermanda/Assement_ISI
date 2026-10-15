<?php

namespace App\Http\Controllers;

use App\Models\CriteriaDescription;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * HRD uploads the employee lists as they come (.xls / .xlsx / .csv, several files at once).
 * New employees are added; existing ones (matched by NIK) can optionally be updated.
 */
class EmployeeImportController extends Controller
{
    /**
     * employees column => accepted header names (lower case, letters and digits only).
     * NOTE: the HRD file's "Grade" (Grade 1..9) is stored in `position`,
     *       and its "Jabatan" (Operator, Staff, ...) in `grade`, like the existing data.
     */
    private const COLUMNS = [
        'id_employee' => ['nik', 'idemployee', 'id', 'nip'],
        'name'        => ['nama', 'name', 'namakaryawan'],
        'department'  => ['department', 'departemen', 'dept', 'bagian'],
        'section'     => ['section', 'seksi', 'seksie'],
        'position'    => ['grade', 'golongan', 'position'],
        'grade'       => ['jabatan'],
    ];

    public function create(): View
    {
        return view('employees.import', [
            'excelSupported'    => class_exists(IOFactory::class),
            'missingGuidelines' => $this->missingGuidelines(),
            'activeEmployees'   => Employee::where('status', 'Active')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'files'           => ['required', 'array', 'min:1'],
            'files.*'         => ['file', 'max:10240', 'extensions:xls,xlsx,csv,txt'],
            'update_existing' => ['nullable', 'boolean'],
        ], [
            'files.*.extensions' => 'Please upload .xls, .xlsx or .csv files.',
        ]);

        set_time_limit(300);

        $update   = $request->boolean('update_existing');
        $existing = Employee::all()->keyBy(fn ($employee) => $this->normalizeId($employee->id_employee));
        $now      = now();
        $seen     = [];
        $writes   = [];   // rows to insert or update, written in a few big queries at the end
        $report   = ['files' => [], 'problems' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $fields   = ['name', 'department', 'section', 'position', 'grade'];

        /** @var UploadedFile $file */
        foreach ($request->file('files') as $file) {
            $stat = ['name' => $file->getClientOriginalName(), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'error' => null];

            try {
                $records = $this->records($this->matrices($file));
            } catch (\RuntimeException $e) {
                $stat['error'] = $e->getMessage();
                $report['files'][] = $stat;
                continue;
            }

            if ($records === []) {
                $stat['error'] = 'No rows found. The file needs a header row with NIK, Nama and Department.';
                $report['files'][] = $stat;
                continue;
            }

            foreach ($records as $record) {
                $nik        = $record['id_employee'] ?? '';
                $name       = $record['name'] ?? '';
                $department = $record['department'] ?? '';

                if ($nik === '' || $name === '' || $department === '') {
                    $this->fail($report, $stat, $file, $record['line'], $nik, 'NIK, name or department is empty.');
                    continue;
                }

                $key = $this->normalizeId($nik);

                if (isset($seen[$key])) {
                    $this->fail($report, $stat, $file, $record['line'], $nik, 'Duplicate NIK in the uploaded files.');
                    continue;
                }
                $seen[$key] = true;

                // Only non-empty values are used, so a blank cell never wipes existing data
                $data = array_filter([
                    'name'       => $name,
                    'department' => $department,
                    'section'    => $record['section'] ?? '',
                    'position'   => $record['position'] ?? '',
                    'grade'      => $record['grade'] ?? '',
                ], fn ($value) => $value !== '');

                $employee = $existing->get($key);

                if ($employee) {
                    if (! $update) {
                        $stat['unchanged']++; $report['unchanged']++;
                        continue;
                    }

                    $current = $employee->only([...$fields, 'status']);
                    $merged  = array_merge($current, $data, ['status' => 'Active']);

                    if ($merged == $current) {
                        $stat['unchanged']++; $report['unchanged']++;
                        continue;
                    }

                    $writes[] = ['id_employee' => $employee->id_employee] + $merged + ['created_at' => $now, 'updated_at' => $now];
                    $stat['updated']++; $report['updated']++;
                    continue;
                }

                $writes[] = ['id_employee' => $nik]
                    + array_merge(array_fill_keys($fields, null), $data)
                    + ['status' => 'Active', 'created_at' => $now, 'updated_at' => $now];
                $stat['created']++; $report['created']++;
            }

            $report['files'][] = $stat;
        }

        try {
            DB::transaction(function () use ($writes) {
                // One query per 150 rows instead of one query per row
                foreach (array_chunk($writes, 150) as $chunk) {
                    DB::table('employees')->upsert(
                        $chunk,
                        ['id_employee'],
                        ['name', 'department', 'section', 'position', 'grade', 'status', 'updated_at']
                    );
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed, nothing was saved: ' . $e->getMessage());
        }

        \App\Support\RefCache::flush('employees', 'routes');   // the upsert bypasses the model events

        return redirect()->route('employees.import')->with('employee_import', $report);
    }

    /* ------------------------------------------------------------------ */

    /** Department / section pairs of Assessment 1 employees that have no guideline row yet (Assessment 2 people do not need one). */
    private function missingGuidelines(): Collection
    {
        $pairs = \App\Support\ReviewRules::assessedPairs();

        $rows = CriteriaDescription::all(['department', 'section']);

        return $pairs->filter(function ($pair) use ($rows) {
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) $row->department)) !== mb_strtolower(trim((string) $pair->department))) {
                    continue;
                }

                $rowSection = trim((string) $row->section);

                if ($rowSection === '' || mb_strtolower($rowSection) === mb_strtolower(trim((string) $pair->section))) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function fail(array &$report, array &$stat, UploadedFile $file, int $line, string $nik, string $message): void
    {
        $stat['failed']++;
        $report['failed']++;
        $report['problems'][] = ['file' => $file->getClientOriginalName(), 'line' => $line, 'nik' => $nik, 'message' => $message];
    }

    /**
     * NIK in the database lost its leading zero and dots in Excel ("1.151.002"),
     * while the HRD file has "01.151002". Both become "1151002".
     */
    private function normalizeId(?string $id): string
    {
        return strtoupper(ltrim(preg_replace('/[^A-Za-z0-9]/', '', (string) $id), '0'));
    }

    /** @return array<int, array<int, array<int, mixed>>> one matrix (rows of cells) per sheet */
    private function matrices(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xls', 'xlsx'], true)) {
            if (! class_exists(IOFactory::class)) {
                throw new \RuntimeException('Reading Excel files needs the PhpSpreadsheet package: run "composer require phpoffice/phpspreadsheet", or save the file as CSV.');
            }

            try {
                $reader = IOFactory::createReaderForFile($file->getRealPath());
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($file->getRealPath());
            } catch (\Throwable $e) {
                throw new \RuntimeException('The Excel file could not be read: ' . $e->getMessage());
            }

            $matrices = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $matrices[] = $sheet->toArray(null, false, false, false);   // raw values: NIK stays text
            }

            return $matrices;
        }

        return [$this->csvMatrix($file->getRealPath())];
    }

    private function csvMatrix(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $content), fn ($l) => trim($l) !== ''));

        if ($lines && stripos(trim($lines[0]), 'sep=') === 0) {
            array_shift($lines);
        }

        if ($lines === []) {
            return [];
        }

        $counts = [';' => substr_count($lines[0], ';'), ',' => substr_count($lines[0], ','), "\t" => substr_count($lines[0], "\t")];
        arsort($counts);
        $delimiter = array_key_first($counts);

        return array_map(fn ($line) => str_getcsv($line, $delimiter), $lines);
    }

    /** Finds the header row (within the first 15 rows) of each sheet and turns the rows below it into records. */
    private function records(array $matrices): array
    {
        $records = [];

        foreach ($matrices as $matrix) {
            $headerRow = null;
            $map       = [];

            foreach ($matrix as $index => $row) {
                if ($index > 15) {
                    break;
                }

                $candidate = $this->mapHeader($row);

                if (isset($candidate['id_employee'], $candidate['name'], $candidate['department'])) {
                    $headerRow = $index;
                    $map       = $candidate;
                    break;
                }
            }

            if ($headerRow === null) {
                continue;
            }

            foreach ($matrix as $index => $row) {
                if ($index <= $headerRow) {
                    continue;
                }

                $record = [];
                foreach ($map as $field => $position) {
                    $record[$field] = $this->cell($row[$position] ?? null);
                }

                if (implode('', $record) === '') {
                    continue;   // empty row
                }

                $records[] = ['line' => $index + 1] + $record;
            }
        }

        return $records;
    }

    /** @return array<string,int> employees column => position in the row */
    private function mapHeader(array $row): array
    {
        $map = [];

        foreach ($row as $position => $cell) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $cell));

            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = $position;
                }
            }
        }

        return $map;
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
