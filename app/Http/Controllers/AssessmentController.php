<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use App\Support\ReviewRules;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Assessment 1, two steps:
 *   Penilai 1 fills in the form and submits  -> Pending
 *   Penilai 2 edits if needed, then approves -> Approved   (or sends it back -> Rejected, Penilai 1 revises)
 * Employees of grade 1-5 are assessed. Each group has a Penilai 1 and a Penilai 2 account, see App\Support\ReviewRules.
 */
class AssessmentController extends Controller
{
    /** Statuses that wait for Penilai 2 ("Verified" only exists in assessments created by the old 3-step flow). */
    private const AWAITING_APPROVAL = [Assessment::STATUS_PENDING, Assessment::STATUS_VERIFIED];

    /* ------------------------------------------------------------------
     | Dashboard
     * ----------------------------------------------------------------*/
    public function dashboard(): View|RedirectResponse
    {
        $user   = Auth::user();
        $period = $this->currentPeriod();
        $data   = ['period' => $period, 'periodLabel' => $this->periodLabel($period)];

        if ($user->role === Roles::ADMIN) {
            $statusCounts = Assessment::where('period', $period)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $departmentSummary = Employee::query()
                ->where('employees.status', 'Active')
                ->leftJoin('assessments', function ($join) use ($period) {
                    $join->on('assessments.employee_id', '=', 'employees.id_employee')
                         ->where('assessments.period', '=', $period);
                })
                ->selectRaw("employees.department,
                    COUNT(DISTINCT employees.id_employee) AS total,
                    COUNT(DISTINCT CASE WHEN assessments.status IN ('Pending','Verified') THEN assessments.employee_id END) AS pending,
                    COUNT(DISTINCT CASE WHEN assessments.status = 'Approved' THEN assessments.employee_id END) AS approved")
                ->groupBy('employees.department')
                ->orderBy('employees.department')
                ->get();

            return view('dashboard', $data + [
                'activeEmployees'   => Employee::where('status', 'Active')->count(),
                'statusCounts'      => $statusCounts,
                'departmentSummary' => $departmentSummary,
            ]);
        }

        // Penilai, Approval and Assessment 2 accounts: "Tugas Saya", everything to do this period in one page
        return view('dashboard', $data + [
            'my'          => \App\Support\MyTasks::for($user),
            'periodLabel' => $this->periodLabel($period),
        ]);
    }

    /* ------------------------------------------------------------------
     | Penilai 1: fill in the assessment form
     * ----------------------------------------------------------------*/
    public function create(Request $request): View
    {
        $user   = Auth::user();
        $period = $this->currentPeriod();

        abort_unless(in_array(ReviewRules::STAGE_ASSESS, ReviewRules::dutyStages($user), true), 403, t('Akun Anda bukan akun Penilai 1.'));

        $employees   = ReviewRules::employeesFor($user, ReviewRules::STAGE_ASSESS);
        $assessments = $this->assessmentsFor($employees, $period);

        $selectedId = (string) $request->query('employee', '');
        $selected   = $employees->firstWhere('id_employee', $selectedId);
        $existing   = $selected ? $assessments->get($selectedId) : null;

        if ($existing && ! in_array($existing->status, Assessment::EDITABLE_STATUSES, true)) {
            $selected = null;
            $existing = null;
        }

        $initial = [];
        foreach (array_keys(Assessment::CRITERIA) as $column) {
            $initial[$column] = old($column, $existing?->{$column} ?? '');
        }

        $notes = [];
        foreach (Assessment::noteKeys() as $key) {
            $notes[$key] = old("notes.{$key}", $existing?->notes[$key] ?? '');
        }

        return view('assessments.create', [
            'notes'         => $notes,
            'usesBulk'      => ReviewRules::usesBulk($user),
            'periodLabel'   => $this->periodLabel($period),
            'employees'     => $employees,
            'assessments'   => $assessments,
            'selected'      => $selected,
            'existing'      => $existing,
            'initial'       => $initial,
            'lockedColumns' => $existing?->lockedColumns() ?? [],
            'weights'       => collect(Assessment::CRITERIA)->map(fn ($m) => $m['weight'])->all(),
            'descriptions'  => Assessment::guidelinesFor($selected?->department, $selected?->section),
            'groups'        => ReviewRules::userGroups($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user   = Auth::user();
        $period = $this->currentPeriod();

        $allowed = ReviewRules::employeesFor($user, ReviewRules::STAGE_ASSESS)->pluck('id_employee')->all();

        // Criteria loaded by HRD (attendance import: Point 1 & 2, sanction import: Point 6) are locked: ignore what the form posts.
        $existing = Assessment::where('employee_id', (string) $request->input('id_employee'))
            ->where('period', $period)
            ->first();
        $locked = $existing?->lockedColumns() ?? [];

        $rules = [
            'id_employee' => ['required', Rule::in($allowed)],
            'action'      => ['required', Rule::in(['draft', 'submit'])],
            ...Assessment::noteRules(),
        ];

        foreach (array_keys(Assessment::CRITERIA) as $column) {
            if (! in_array($column, $locked, true)) {
                $rules[$column] = ['required', 'integer', Rule::in(array_values(Assessment::GRADES))];
            }
        }

        $validated = $request->validate($rules, ['id_employee.in' => t('Karyawan ini bukan karyawan yang Anda nilai.')]);

        if ($existing && ! in_array($existing->status, Assessment::EDITABLE_STATUSES, true)) {
            return back()->withInput()->withErrors([
                'id_employee' => t('Penilaian karyawan ini untuk :period sudah berstatus: :status.', ['period' => $this->periodLabel($period), 'status' => \App\Support\Ui::status($existing->status)]),
            ]);
        }

        $scores = [];
        foreach (array_keys(Assessment::CRITERIA) as $column) {
            $scores[$column] = in_array($column, $locked, true)
                ? (int) $existing->{$column}
                : (int) $validated[$column];
        }

        $status = $validated['action'] === 'submit' ? Assessment::STATUS_PENDING : Assessment::STATUS_DRAFT;

        Assessment::updateOrCreate(
            ['employee_id' => $validated['id_employee'], 'period' => $period],
            $scores + [
                'notes'          => Assessment::keepLockedNotes(Assessment::cleanNotes($validated['notes'] ?? null), $existing),
                'created_by'     => $user->username,
                'final_score'    => Assessment::calculateFinalScore($scores),
                'status'         => $status,
                'rejection_note' => null,
                'verified_by'    => null,
                'approved_by'    => null,
                'edited_by'      => null,
                'edited_at'      => null,
            ]
        );

        return redirect()->route('dashboard')->with(
            'success',
            $status === Assessment::STATUS_PENDING
                ? t('Penilaian dikirim untuk disetujui.')   // Penilai 2, or the approver of a special route
                : t('Penilaian disimpan sebagai draf.')
        );
    }

    /* ------------------------------------------------------------------
     | Penilai 2: approve, edit, send back
     * ----------------------------------------------------------------*/
    public function approvalIndex(): View
    {
        $user = Auth::user();

        abort_unless(in_array(ReviewRules::STAGE_APPROVE, ReviewRules::dutyStages($user), true), 403, t('Akun Anda bukan akun Penilai 2.'));

        $ids = ReviewRules::employeesFor($user, ReviewRules::STAGE_APPROVE)->pluck('id_employee');

        $assessments = Assessment::with(['employee', 'creator'])
            ->whereIn('employee_id', $ids)
            ->whereIn('status', self::AWAITING_APPROVAL)
            ->get()
            ->sortBy(fn (Assessment $a) => ReviewRules::unitOf($a->employee) . '|' . $a->employee->name)   // grouped by section
            ->values();

        return view('assessments.approval', compact('assessments'));
    }

    public function approvalEdit(Assessment $assessment): View|RedirectResponse
    {
        if ($blocked = $this->guardApproval($assessment)) {
            return $blocked;
        }

        return view('assessments.approval-edit', [
            'assessment'    => $assessment,
            'employee'      => $assessment->employee,
            'lockedColumns' => $assessment->lockedColumns(),
            'descriptions'  => Assessment::guidelinesFor($assessment->employee->department, $assessment->employee->section),
        ]);
    }

    /** Save the edited scores; with action "approve" the assessment is approved in the same step. */
    public function approvalUpdate(Request $request, Assessment $assessment): RedirectResponse
    {
        if ($blocked = $this->guardApproval($assessment)) {
            return $blocked;
        }

        $locked = $assessment->lockedColumns();

        $rules = ['action' => ['required', Rule::in(['save', 'approve'])], ...Assessment::noteRules()];
        foreach (array_keys(Assessment::CRITERIA) as $column) {
            if (! in_array($column, $locked, true)) {
                $rules[$column] = ['required', 'integer', Rule::in(array_values(Assessment::GRADES))];
            }
        }

        $validated = $request->validate($rules);
        $user      = Auth::user();

        $scores  = [];
        $changed = false;
        foreach (array_keys(Assessment::CRITERIA) as $column) {
            $scores[$column] = in_array($column, $locked, true) ? (int) $assessment->{$column} : (int) $validated[$column];
            $changed = $changed || $scores[$column] !== (int) $assessment->{$column};
        }

        $notes   = Assessment::keepLockedNotes(Assessment::cleanNotes($validated['notes'] ?? null), $assessment);
        $changed = $changed || $notes != $assessment->notes;
        $changes = $scores + ['final_score' => Assessment::calculateFinalScore($scores), 'notes' => $notes];

        if ($changed) {
            $changes += ['edited_by' => $user->username, 'edited_at' => now()];
        }

        if ($validated['action'] === 'approve') {
            $changes += ['status' => Assessment::STATUS_APPROVED, 'approved_by' => $user->username, 'rejection_note' => null];
        }

        $assessment->update($changes);

        if ($validated['action'] === 'approve') {
            return redirect()->route('approval.index')->with('success', t('Penilaian :name disetujui.', ['name' => $assessment->employee->name]));
        }

        return redirect()->route('approval.index')->with('success', t('Perubahan :name disimpan. Masih menunggu persetujuan Anda.', ['name' => $assessment->employee->name]));
    }

    public function approve(Assessment $assessment): RedirectResponse
    {
        if ($blocked = $this->guardApproval($assessment)) {
            return $blocked;
        }

        $assessment->update([
            'status'         => Assessment::STATUS_APPROVED,
            'rejection_note' => null,
            'approved_by'    => Auth::user()->username,
        ]);

        return back()->with('success', t('Penilaian :name disetujui.', ['name' => $assessment->employee->name]));
    }

    /**
     * Approval massal: approve only the ticked assessments, in ONE update. The rest keeps waiting
     * (they can be approved later or sent back). Rows that are not allowed are skipped, not fatal.
     */
    public function approveSelected(Request $request): RedirectResponse
    {
        $user = Auth::user();

        abort_unless(in_array(ReviewRules::STAGE_APPROVE, ReviewRules::dutyStages($user), true), 403, t('Akun Anda bukan akun Penilai 2.'));

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.required' => t('Centang minimal satu penilaian untuk di-approve.'),
        ]);

        $mine = ReviewRules::employeesFor($user, ReviewRules::STAGE_APPROVE)->pluck('id_employee')->flip();

        $candidates = Assessment::with('employee')->whereIn('id', $data['ids'])->get(['id', 'employee_id', 'status', 'created_by']);

        $approvable = $candidates->filter(fn (Assessment $a) => $mine->has($a->employee_id)
            && in_array($a->status, self::AWAITING_APPROVAL, true)
            && ReviewRules::mayApprove($user, $a));   // Four-Eyes Principle (Penilai 1 + 2 accounts excepted)

        $approved = $approvable->isEmpty() ? 0 : Assessment::whereIn('id', $approvable->pluck('id'))
            ->whereIn('status', self::AWAITING_APPROVAL)   // still waiting at the moment of writing
            ->update([
                'status'         => Assessment::STATUS_APPROVED,
                'approved_by'    => $user->username,
                'rejection_note' => null,
                'updated_at'     => now(),
            ]);

        $skipped = count($data['ids']) - $approved;

        return redirect()->route('approval.index')->with(
            $approved > 0 ? 'success' : 'error',
            $approved > 0
                ? t(':n penilaian disetujui.', ['n' => $approved]) . ' ' . ($skipped ? t(':n dilewati (sudah ditangani, bukan milik Anda, atau Anda buat sendiri).', ['n' => $skipped]) : t('Yang lain tetap di daftar sampai Anda approve atau kembalikan.'))
                : t('Tidak ada yang disetujui: penilaian yang dicentang sudah tidak menunggu Anda.')
        );
    }

    public function reject(Request $request, Assessment $assessment): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'rejection_note.required' => t('Tuliskan apa yang perlu diperbaiki.'),
            'rejection_note.min'      => t('Catatan perbaikan minimal 5 karakter.'),
        ]);

        if ($blocked = $this->guardApproval($assessment)) {
            return $blocked;
        }

        $user = Auth::user();

        $assessment->update([
            'status'         => Assessment::STATUS_REJECTED,
            'rejection_note' => $validated['rejection_note'] . ' (' . $user->name . ')',
            'approved_by'    => null,
        ]);

        return back()->with('success', t('Penilaian :name dikembalikan ke Penilai 1.', ['name' => $assessment->employee->name]));
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------*/
    private function guardApproval(Assessment $assessment): ?RedirectResponse
    {
        $user = Auth::user();
        $assessment->loadMissing('employee');

        abort_unless(
            ReviewRules::canHandle($user, $assessment->employee, ReviewRules::STAGE_APPROVE),
            403,
            t('Karyawan ini tidak termasuk seksi yang Anda pegang.')
        );

        // Four-Eyes Principle (an account that is Penilai 1 + 2 of the employee's section is the exception)
        abort_unless(ReviewRules::mayApprove($user, $assessment), 403, t('Anda tidak bisa menyetujui penilaian yang Anda buat sendiri.'));

        if (! in_array($assessment->status, self::AWAITING_APPROVAL, true)) {
            return redirect()->route('approval.index')->with('error', t('Penilaian ini sudah tidak menunggu persetujuan.'));
        }

        return null;
    }

    private function assessmentsFor(Collection $employees, string $period): Collection
    {
        return Assessment::where('period', $period)
            ->whereIn('employee_id', $employees->pluck('id_employee'))
            ->get()
            ->keyBy('employee_id');
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
