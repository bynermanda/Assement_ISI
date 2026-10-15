<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Support\RefCache;
use App\Support\ReviewRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public const DEPARTMENTS = [
        'HRD', 'PRODUKSI', 'MAINTENANCE', 'QC', 'QA', 'PURCHASING', 'DIES', 'PRESS',
        'EXIM', 'IT (INFORMATION TECHNOLOGY)', 'MARKETING', 'FIN/ACC', 'R&D', 'GA', 'PCC/WH2',
    ];

    public const GRADES = [
        'Advisor', 'Asst. Kepala Line', 'Kepala Line', 'Asst. Kepala Regu', 'Kepala Regu',
        'Asst. Kepala Seksie', 'Kepala Seksie', 'Asst. Senior Staff', 'Senior Staff',
        'Asst. Yunior Staff', 'DANRU', 'Staff', 'Operator', 'Teknisi', 'Teknisi 1',
        'Teknisi 2', 'Teknisi 3', 'Teknisi 4', 'Yunior Staff',
    ];

    public const STATUSES = ['Active', 'Resigned'];

    public function index(Request $request): View
    {
        $term = '%' . addcslashes((string) $request->query('q', ''), '%_\\') . '%';

        $employees = Employee::query()
            ->when($request->filled('q'), fn ($query) => $query->where(
                fn ($w) => $w->where('name', 'ilike', $term)->orWhere('id_employee', 'ilike', $term)->orWhere('section', 'ilike', $term)
            ))
            ->when($request->filled('department'), fn ($query) => $query->where('department', $request->query('department')))
            ->when(in_array($request->query('assessment'), ['1', '2'], true), fn ($query) => $this->whereLevel($query, (int) $request->query('assessment')))
            ->when($request->query('assessment') === 'manual', fn ($query) => $query->whereNotNull('assessment_level'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('employees.index', [
            'employees'   => $employees,
            'departments' => $this->departments(),
            'sections'    => $this->sections(),
            'positions'   => $this->positions(),
            'grades'      => $this->grades(),
            'statuses'    => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_employee' => ['required', 'string', 'max:30', Rule::unique('employees', 'id_employee')],
            ...$this->rules(),
        ]);

        Employee::create($data);

        return redirect()->route('employees.index')->with('success', 'Karyawan berhasil ditambahkan.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validate($this->rules()));

        return redirect()->route('employees.index')->with('success', 'Data karyawan berhasil diperbarui.');
    }

    /**
     * After editing the tables directly in Supabase (outside the app), the cached reference data is up to
     * 10 minutes old: this reloads it at once.
     */
    public function refreshCache(): RedirectResponse
    {
        RefCache::flush('employees', 'guidelines', 'a2', 'routes');

        return back()->with('success', 'Data dimuat ulang dari database (karyawan, seksi, panduan, Assessment 2).');
    }

    /** Quick move from the list: Assessment 1, Assessment 2, or back to automatic (by grade). */
    public function setAssessmentLevel(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate(['assessment_level' => ['required', Rule::in(['auto', '1', '2'])]]);

        $employee->update(['assessment_level' => $data['assessment_level'] === 'auto' ? null : (int) $data['assessment_level']]);

        $label = ReviewRules::levelOf($employee) ? 'Assessment ' . ReviewRules::levelOf($employee) : 'tanpa penilaian (tidak ada grade)';

        return back()->with('success', "{$employee->name} sekarang masuk {$label}" . ($employee->assessment_level === null ? ' (sesuai grade).' : '.'));
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        try {
            $employee->delete();
        } catch (QueryException) {
            return redirect()->route('employees.index')
                ->with('error', 'Karyawan ini sudah punya penilaian sehingga tidak bisa dihapus. Ubah statusnya menjadi "Resigned".');
        }

        return redirect()->route('employees.index')->with('success', 'Karyawan dihapus.');
    }

    /**
     * Option lists = built-in defaults + every value already used in the database,
     * so existing data (e.g. ASSY 1/2/3) always appears in the dropdowns.
     */
    // Option lists are cached (RefCache group "employees", flushed whenever an employee is saved or imported)
    private function departments(): array
    {
        return RefCache::remember('employees', 'options:departments', function () {
            $existing = array_merge(
                Employee::query()->whereNotNull('department')->distinct()->pluck('department')->all(),
                User::query()->whereNotNull('department')->distinct()->pluck('department')->all(),
            );

            return $this->mergeOptions(self::DEPARTMENTS, $existing, sort: true);
        });
    }

    private function sections(): array
    {
        return RefCache::remember('employees', 'options:sections', fn () => $this->mergeOptions([],
            Employee::query()->whereNotNull('section')->distinct()->pluck('section')->all(), sort: true));
    }

    private function positions(): array
    {
        return RefCache::remember('employees', 'options:positions', fn () => $this->mergeOptions(array_map(fn ($i) => "Grade $i", range(1, 10)),
            Employee::query()->whereNotNull('position')->distinct()->pluck('position')->all()));
    }

    private function grades(): array
    {
        return RefCache::remember('employees', 'options:grades', fn () => $this->mergeOptions(self::GRADES,
            Employee::query()->whereNotNull('grade')->distinct()->pluck('grade')->all()));
    }

    private function mergeOptions(array $defaults, array $existing, bool $sort = false): array
    {
        $all = array_unique(array_filter(
            array_map('trim', array_merge($defaults, $existing)),
            fn ($value) => $value !== ''
        ));

        if ($sort) {
            natcasesort($all);
        }

        return array_values($all);
    }

    /** Same rule as ReviewRules::levelOf(), in SQL: the admin's choice, otherwise the number in "Grade N". */
    private function whereLevel($query, int $level)
    {
        $max   = (int) config('assessment.assessed_max_grade', 5);
        $grade = "CAST(NULLIF(substring(position from '[0-9]+'), '') AS integer)";

        return $query->where(fn ($w) => $w->where('assessment_level', $level)->orWhere(
            fn ($auto) => $auto->whereNull('assessment_level')
                ->whereRaw($level === 1 ? "{$grade} BETWEEN 1 AND ?" : "{$grade} > ?", [$max])
        ));
    }

    private function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:150'],
            'department'       => ['required', Rule::in($this->departments())],
            'section'          => ['nullable', 'string', 'max:100'],
            'position'         => ['required', Rule::in($this->positions())],
            'grade'            => ['required', Rule::in($this->grades())],
            'status'           => ['required', Rule::in(self::STATUSES)],
            'assessment_level' => ['nullable', 'integer', Rule::in([1, 2])],   // empty = by grade
        ];
    }
}
