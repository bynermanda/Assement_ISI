<?php

namespace App\Http\Controllers;

use App\Models\A1Route;
use App\Models\Assessment;
use App\Models\Employee;
use App\Models\User;
use App\Support\ReviewRules;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panduan › Jalur Khusus A1: HRD manages the Assessment 1 special routes (a1_routes) here.
 *
 *  - A group = one label + one Penilai + one Approver (e.g. "Pimpinan seksi ASSY 1", a2.dedi -> a2.agus.tony).
 *  - Add a person to a group (or a new group): from then on the department's Penilai 1 / 2 no longer see him;
 *    only the group's Penilai and Approver do (ReviewRules::employeesFor() everywhere). A person in another group
 *    is moved. Remove = back to the normal flow of the department.
 *  - A department rule (e.g. all of DIES) is changed or removed as a whole; a person row always wins over it.
 *  - "Pindah ke A2": the person becomes Assessment 2 (employees.assessment_level = 2), his person row is removed and
 *    the admin continues in Panduan › Assessment 2 to put him in a unit.
 */
class A1RouteController extends Controller
{
    public function index(): View
    {
        $rows      = A1Route::with(['assessor:id,username,name', 'approver:id,username,name'])->orderBy('note')->orderBy('id')->get();
        $active    = ReviewRules::activeEmployees();
        $byNik     = $active->keyBy(fn (Employee $e) => ReviewRules::normalizeNik($e->id_employee));
        $map       = ReviewRules::routeMap()['routes'];
        $personNik = $rows->whereNotNull('employee_id')->map(fn ($r) => ReviewRules::normalizeNik($r->employee_id))->flip();

        $groups = $rows->groupBy(fn (A1Route $r) => $this->groupKey($r->note, $r->assessor_id, $r->approver_id))
            ->map(function (Collection $list) use ($byNik, $active, $personNik) {
                $first  = $list->first();
                $people = [];
                foreach ($list as $r) {
                    if ($r->employee_id) {
                        $e = $byNik->get(ReviewRules::normalizeNik($r->employee_id));
                        $people[] = ['route' => $r, 'employee' => $e, 'problem' => ! $e ? 'tidak aktif / tidak ditemukan' : (! ReviewRules::isAssessed($e) ? 'sudah di Assessment 2: baris ini tidak berlaku' : null)];
                    }
                }
                // department rules: everybody they reach today (people with their own row go to that row instead)
                $rules = $list->whereNull('employee_id')->map(fn (A1Route $r) => [
                    'route'  => $r,
                    'people' => $active->filter(fn (Employee $e) => ReviewRules::isAssessed($e) && $r->matches($e)
                        && ! $personNik->has(ReviewRules::normalizeNik($e->id_employee)))->sortBy('name')->values(),
                ])->values();

                return [
                    'key'      => $this->groupKey($first->note, $first->assessor_id, $first->approver_id),
                    'note'     => $first->note,
                    'assessor' => $first->assessor,
                    'approver' => $first->approver,
                    'people'   => collect($people)->sortBy(fn ($p) => ($p['problem'] ? '1' : '0') . ($p['employee']?->name ?? $p['route']->employee_id))->values(),
                    'rules'    => $rules,
                    'count'    => collect($people)->whereNull('problem')->count() + $rules->sum(fn ($x) => $x['people']->count()),
                ];
            })->sortBy('note', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return view('criteria.routes', [
            'groups'     => $groups,
            'total'      => count($map),
            'accounts'   => User::where('role', '!=', Roles::ADMIN)->orderBy('username')->get(['id', 'username', 'name']),
            // who can be added: active Assessment 1 employees without their own row (people under a department rule may be moved)
            'candidates' => $active->filter(fn (Employee $e) => ReviewRules::isAssessed($e) && ! $personNik->has(ReviewRules::normalizeNik($e->id_employee)))
                ->sortBy('name')->values()
                ->map(fn (Employee $e) => ['id' => $e->id_employee, 'label' => "{$e->name} · {$e->id_employee} · " . ReviewRules::unitOf($e) . ' · ' . $e->grade
                    . (isset($map[ReviewRules::normalizeNik($e->id_employee)]) ? ' (aturan departemen)' : '')]),
        ]);
    }

    /** Adds a person to a group (existing or new); a person already in another group is moved. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, withEmployee: true);
        $e    = Employee::find($data['employee_id']);

        if ($problem = $this->problem($e, (int) $data['assessor_id'], (int) $data['approver_id'])) {
            return back()->withInput()->with('error', "Tidak ditambahkan: {$problem}");
        }

        $existing = A1Route::whereNotNull('employee_id')->get()->first(fn ($r) => ReviewRules::normalizeNik($r->employee_id) === ReviewRules::normalizeNik($e->id_employee));
        $route    = $existing ?? new A1Route(['employee_id' => $e->id_employee]);
        $route->fill(['assessor_id' => $data['assessor_id'], 'approver_id' => $data['approver_id'], 'note' => $data['note']])->save();

        return redirect()->route('criteria.routes')->with('success', "{$e->name} " . ($existing ? 'dipindah ke' : 'masuk') . " jalur khusus \"{$data['note']}\". "
            . 'Ia tidak lagi muncul di alur Penilai 1 / 2 departemennya.' . $this->openWork($e));
    }

    /** Changes the label, Penilai or Approver of every row of a group. */
    public function updateGroup(Request $request): RedirectResponse
    {
        $data = $this->validated($request, withEmployee: false);
        $old  = $request->validate([
            'old_note'        => ['nullable', 'string', 'max:255'],
            'old_assessor_id' => ['required', 'integer'],
            'old_approver_id' => ['required', 'integer'],
        ]);

        $rows = A1Route::where('assessor_id', $old['old_assessor_id'])->where('approver_id', $old['old_approver_id'])
            ->where(fn ($q) => $old['old_note'] === null ? $q->whereNull('note') : $q->where('note', $old['old_note']))->get();
        if ($rows->isEmpty()) {
            return back()->with('error', 'Grup tidak ditemukan (mungkin baru diubah orang lain). Muat ulang halaman.');
        }

        $byNik = ReviewRules::activeEmployees()->keyBy(fn (Employee $e) => ReviewRules::normalizeNik($e->id_employee));
        foreach ($rows->whereNotNull('employee_id') as $r) {
            if (($e = $byNik->get(ReviewRules::normalizeNik($r->employee_id))) && ($problem = $this->problem($e, (int) $data['assessor_id'], (int) $data['approver_id']))) {
                return back()->with('error', "Grup tidak diubah: {$problem}");
            }
        }
        if ((int) $data['assessor_id'] === (int) $data['approver_id']) {
            return back()->with('error', 'Grup tidak diubah: Penilai dan Approver harus akun yang berbeda.');
        }

        DB::transaction(fn () => $rows->each(fn (A1Route $r) => $r->update(['assessor_id' => $data['assessor_id'], 'approver_id' => $data['approver_id'], 'note' => $data['note']])));

        return redirect()->route('criteria.routes')->with('success', "Grup \"{$data['note']}\" diperbarui ({$rows->count()} baris).");
    }

    /** Removes one row: a person goes back to the normal department flow; a department rule stops for everybody. */
    public function destroy(A1Route $a1Route): RedirectResponse
    {
        $route = $a1Route;
        $what  = $route->employee_id
            ? (Employee::find($route->employee_id)?->name ?? $route->employee_id) . ' kembali ke alur biasa departemennya'
            : "aturan departemen {$route->department} (" . (empty($route->jabatans) ? 'semua karyawan' : implode(', ', $route->jabatans)) . ") dihapus; karyawannya kembali ke alur biasa departemen";
        $route->delete();

        return redirect()->route('criteria.routes')->with('success', ucfirst($what) . '.');
    }

    /** Assessment 1 -> 2: level 2, person row removed; next step in Panduan › Assessment 2 (the person is preselected). */
    public function moveToA2(Employee $employee): RedirectResponse
    {
        $open = Assessment::where('employee_id', $employee->id_employee)->where('period', now()->format('Y-m'))->first();

        DB::transaction(function () use ($employee) {
            A1Route::whereNotNull('employee_id')->get()
                ->filter(fn ($r) => ReviewRules::normalizeNik($r->employee_id) === ReviewRules::normalizeNik($employee->id_employee))
                ->each->delete();
            $employee->update(['assessment_level' => 2]);
        });

        return redirect()->route('criteria.a2')->withInput(['employee_id' => $employee->id_employee])->with('success',
            "{$employee->name} sekarang Assessment 2 dan keluar dari jalur khusus A1. Pilih unitnya di bawah, lalu tambahkan sebagai peserta (namanya sudah terpilih di form \"Tambah peserta\")."
            . ($open ? " Catatan: penilaian A1 bulan ini ({$open->status}) tetap tersimpan sebagai riwayat." : ''));
    }

    private function validated(Request $request, bool $withEmployee): array
    {
        $accounts = User::where('role', '!=', Roles::ADMIN)->pluck('id')->all();

        return $request->validate(array_filter([
            'employee_id' => $withEmployee ? ['required', 'string', 'exists:employees,id_employee'] : null,
            'assessor_id' => ['required', 'integer', Rule::in($accounts)],
            'approver_id' => ['required', 'integer', Rule::in($accounts)],
            'note'        => ['required', 'string', 'max:120'],
        ]), [
            'employee_id.required' => 'Pilih karyawan.',
            'assessor_id.required' => 'Pilih akun Penilai.',
            'approver_id.required' => 'Pilih akun Approver.',
            'assessor_id.in'       => 'Akun Penilai tidak valid.',
            'approver_id.in'       => 'Akun Approver tidak valid.',
            'note.required'        => 'Isi nama grup (mis. "Pimpinan seksi ASSY 1").',
        ]);
    }

    /** Why this person cannot have this Penilai / Approver (null = fine). */
    private function problem(Employee $e, int $assessorId, int $approverId): ?string
    {
        if ($e->status !== 'Active') {
            return "{$e->name} tidak aktif.";
        }
        if (! ReviewRules::isAssessed($e)) {
            return "{$e->name} ada di Assessment 2 (jalur khusus hanya untuk Assessment 1).";
        }
        if ($assessorId === $approverId) {
            return 'Penilai dan Approver harus akun yang berbeda.';
        }
        $users = User::whereIn('id', [$assessorId, $approverId])->pluck('nik', 'id');
        $own   = ReviewRules::normalizeNik($e->id_employee);
        if (ReviewRules::normalizeNik($users[$assessorId] ?? null) === $own) {
            return "{$e->name} tidak boleh menilai dirinya sendiri.";
        }
        if (ReviewRules::normalizeNik($users[$approverId] ?? null) === $own) {
            return "{$e->name} tidak boleh menyetujui dirinya sendiri.";
        }

        return null;
    }

    /** A note when the person already has an assessment this month (it now goes to the new Penilai / Approver). */
    private function openWork(Employee $e): string
    {
        $a = Assessment::where('employee_id', $e->id_employee)->where('period', now()->format('Y-m'))->first();

        return $a ? " Penilaian bulan ini ({$a->status}) sekarang ditangani Penilai / Approver grup ini." : '';
    }

    private function groupKey(?string $note, $assessor, $approver): string
    {
        return md5(($note ?? '') . '|' . (int) $assessor . '|' . (int) $approver);
    }
}
