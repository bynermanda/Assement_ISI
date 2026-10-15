<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin (HRD) manages assessments directly: input manually, edit any status, delete.
 * Entries saved as "Approved" skip the verifier/approver steps.
 */
class AdminAssessmentController extends Controller
{
    public function index(Request $request): View
    {
        // ?period= (empty) = all periods; no parameter = current period
        $period = $request->has('period') ? (string) $request->query('period') : now()->format('Y-m');

        $assessments = Assessment::with('employee')
            ->when($period !== '', fn ($q) => $q->where('period', $period))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('department'), fn ($q) => $q->whereHas(
                'employee', fn ($e) => $e->where('department', $request->query('department'))
            ))
            ->when($request->filled('section'), fn ($q) => $q->whereHas(
                'employee', fn ($e) => $e->whereRaw('LOWER(TRIM(section)) = ?', [mb_strtolower(trim((string) $request->query('section')))])
            ))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.assessments.index', [
            'assessments' => $assessments,
            'period'      => $period,
            'statuses'    => Assessment::STATUSES,
            'sections'    => Employee::whereNotNull('section')->distinct()->orderBy('section')->pluck('section'),
            'departments' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $period = (string) $request->query('period', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = now()->format('Y-m');
        }

        $employeeId = (string) $request->query('employee', '');
        $selected   = $employeeId !== '' ? Employee::where('status', 'Active')->find($employeeId) : null;

        if ($selected) {
            $existing = Assessment::where('employee_id', $selected->id_employee)->where('period', $period)->first();

            if ($existing) {
                return redirect()->route('admin.assessments.edit', $existing)
                    ->with('error', 'Penilaian untuk karyawan dan periode ini sudah ada. Anda bisa mengeditnya di sini.');
            }
        }

        return view('admin.assessments.form', $this->formData(null, $selected, $period, Assessment::STATUS_APPROVED) + [
            'employees' => Employee::where('status', 'Active')->orderBy('department')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_employee' => ['required', Rule::exists('employees', 'id_employee')->where('status', 'Active')],
            'period'      => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            ...$this->commonRules(),
        ]);

        $exists = Assessment::where('employee_id', $validated['id_employee'])->where('period', $validated['period'])->exists();

        if ($exists) {
            return back()->withInput()->withErrors([
                'period' => 'Penilaian untuk karyawan dan periode ini sudah ada. Edit dari daftar penilaian.',
            ]);
        }

        Assessment::create([
            'employee_id' => $validated['id_employee'],
            'period'      => $validated['period'],
            'created_by'  => Auth::user()->username,
        ] + $this->payload($validated, null));

        return redirect()->route('admin.assessments.index', ['period' => $validated['period']])
            ->with('success', 'Penilaian disimpan.');
    }

    public function edit(Assessment $assessment): View
    {
        $assessment->load('employee');

        return view('admin.assessments.form', $this->formData(
            $assessment, $assessment->employee, $assessment->period, $assessment->status
        ) + ['employees' => collect()]);
    }

    public function update(Request $request, Assessment $assessment): RedirectResponse
    {
        $validated = $request->validate($this->commonRules());

        $assessment->update($this->payload($validated, $assessment));

        return redirect()->route('admin.assessments.index', ['period' => $assessment->period])
            ->with('success', 'Penilaian diperbarui.');
    }

    public function destroy(Assessment $assessment): RedirectResponse
    {
        $assessment->delete();

        return back()->with('success', 'Penilaian dihapus.');
    }

    /* ------------------------------------------------------------------ */

    private function commonRules(): array
    {
        $rules = [
            'status'         => ['required', Rule::in(Assessment::STATUSES)],
            'rejection_note' => ['nullable', 'required_if:status,' . Assessment::STATUS_REJECTED, 'string', 'max:1000'],
        ];

        foreach (array_keys(Assessment::CRITERIA) as $column) {
            $rules[$column] = ['required', 'integer', Rule::in(array_values(Assessment::GRADES))];
        }

        return $rules;
    }

    private function payload(array $validated, ?Assessment $existing): array
    {
        $admin  = Auth::user()->username;
        $status = $validated['status'];

        $scores = collect(Assessment::CRITERIA)
            ->mapWithKeys(fn ($meta, $column) => [$column => (int) $validated[$column]])
            ->all();

        return $scores + [
            'final_score'    => Assessment::calculateFinalScore($scores),
            'status'         => $status,
            'rejection_note' => $status === Assessment::STATUS_REJECTED ? $validated['rejection_note'] : null,
            'verified_by'    => in_array($status, [Assessment::STATUS_VERIFIED, Assessment::STATUS_APPROVED], true)
                                    ? ($existing?->verified_by ?? $admin) : null,
            'approved_by'    => $status === Assessment::STATUS_APPROVED ? $admin : null,
        ];
    }

    private function formData(?Assessment $assessment, ?Employee $selected, string $period, string $status): array
    {
        $initial = [];
        foreach (array_keys(Assessment::CRITERIA) as $column) {
            $initial[$column] = old($column, $assessment?->{$column} ?? '');
        }

        return [
            'assessment'   => $assessment,
            'isEdit'       => $assessment !== null,
            'selected'     => $selected,
            'period'       => $period,
            'status'       => old('status', $status),
            'statuses'     => Assessment::STATUSES,
            'initial'      => $initial,
            'weights'      => collect(Assessment::CRITERIA)->map(fn ($m) => $m['weight'])->all(),
            'descriptions' => Assessment::guidelinesFor($selected?->department, $selected?->section),
        ];
    }
}
