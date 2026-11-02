<?php

namespace App\Http\Controllers;

use App\Models\A1Route;
use App\Models\A2Assessment;
use App\Models\A2Criterion;
use App\Models\A2Participant;
use App\Models\A2Unit;
use App\Models\Employee;
use App\Models\User;
use App\Support\ReviewRules;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Guidelines > Assessment 2: admin edits, per unit, the criteria (group, names, weight, target, remark) and
 * the participants with their Penilai / Approver. Same data as `php artisan a2:import`, editable in the app.
 */
class A2GuidelineController extends Controller
{
    public function index(Request $request): View
    {
        $units = A2Unit::with('criteria')->withCount(['participants' => fn ($q) => $q->where('active', true)])->orderBy('sort')->get();
        $unit  = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();

        $participants = $unit
            ? A2Participant::with(['employee', 'assessor', 'approver'])->where('unit_id', $unit->id)->get()
                ->sortBy(fn ($p) => ($p->active ? '0' : '1') . $p->employee?->name)->values()
            : collect();

        $taken = A2Participant::pluck('employee_id')->flip();

        return view('criteria.a2', [
            'units'        => $units,
            'unit'         => $unit,
            'participants' => $participants,
            'accounts'     => User::where('role', '!=', Roles::ADMIN)->orderBy('username')->get(['id', 'username', 'name', 'nik']),
            // people who can be added: active and not a participant yet; Assessment 2 first, Assessment 1 people are moved on add
            'candidates'   => Employee::where('status', 'Active')->orderBy('name')->get()
                ->reject(fn ($e) => $taken->has($e->id_employee))
                ->groupBy(fn ($e) => ReviewRules::levelOf($e) === 2 ? 'a2' : 'a1'),
            'departments'  => Employee::where('status', 'Active')->distinct()->orderBy('department')->pluck('department')
                ->merge($units->pluck('department'))->filter()->unique()->sort(SORT_NATURAL)->values(),
            'unitStats'    => $unit ? ['assessments' => A2Assessment::where('unit_id', $unit->id)->count()] : null,
        ]);
    }

    /** Saves the whole criteria list of a unit: rows are updated in place (ids kept, so saved scores still match). */
    public function saveCriteria(Request $request, A2Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'rows'            => ['required', 'array', 'min:1', 'max:40'],
            'rows.*.id'       => ['nullable', 'integer'],
            'rows.*.delete'   => ['nullable', 'boolean'],
            'rows.*.group_zh' => ['nullable', 'string', 'max:100'],
            'rows.*.group_id' => ['nullable', 'string', 'max:150'],
            'rows.*.name_zh'  => ['nullable', 'string', 'max:500'],
            'rows.*.name_id'  => ['nullable', 'string', 'max:500'],
            'rows.*.weight'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.target'   => ['nullable', 'string', 'max:5000'],
            'rows.*.remark'   => ['nullable', 'string', 'max:5000'],
        ]);

        $keep = collect($data['rows'])->reject(fn ($r) => ! empty($r['delete']))->values();
        $back = redirect()->route('criteria.a2', ['unit' => $unit->id])->withInput();

        foreach ($keep as $i => $r) {
            if (trim((string) ($r['name_id'] ?? '')) === '' && trim((string) ($r['name_zh'] ?? '')) === '') {
                return $back->with('error', 'Baris ' . ($i + 1) . ': isi nama kriteria, atau centang Hapus.');
            }
        }
        $total = round($keep->sum(fn ($r) => (float) ($r['weight'] ?? 0)), 2);
        if ($keep->isEmpty() || abs($total - 100) > 0.001) {
            return $back->with('error', "Tidak disimpan: total bobot {$total}%, harus 100%.");
        }

        DB::transaction(function () use ($unit, $data, $keep) {
            $mine = A2Criterion::where('unit_id', $unit->id)->pluck('id')->all();

            $deleted = collect($data['rows'])->filter(fn ($r) => ! empty($r['delete']) && in_array((int) ($r['id'] ?? 0), $mine, true))->pluck('id');
            A2Criterion::whereIn('id', $deleted)->delete();

            foreach ($keep as $i => $r) {
                $attributes = [
                    'unit_id'  => $unit->id,
                    'sort'     => $i + 1,
                    'group_zh' => trim((string) ($r['group_zh'] ?? '')) ?: null,
                    'group_id' => trim((string) ($r['group_id'] ?? '')) ?: null,
                    'name_zh'  => trim((string) ($r['name_zh'] ?? '')) ?: null,
                    'name_id'  => trim((string) ($r['name_id'] ?? '')) ?: trim((string) $r['name_zh']),
                    'weight'   => round((float) ($r['weight'] ?? 0), 2),
                    'target'   => trim((string) ($r['target'] ?? '')) ?: null,
                    'remark'   => trim((string) ($r['remark'] ?? '')) ?: null,
                ];
                $id = (int) ($r['id'] ?? 0);
                in_array($id, $mine, true) ? A2Criterion::whereKey($id)->update($attributes) : A2Criterion::create($attributes);
            }
        });

        return redirect()->route('criteria.a2', ['unit' => $unit->id])
            ->with('success', "Kriteria {$unit->name} disimpan ({$keep->count()} kriteria, 100%). Penilaian yang sudah dikirim tetap memakai kriteria saat dinilai.");
    }

    /** Penilai / Approver / unit / active of the participants of a unit. */
    public function saveParticipants(Request $request, A2Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'participants'               => ['required', 'array'],
            'participants.*.assessor_id' => ['required', 'integer', 'exists:users,id'],
            'participants.*.approver_id' => ['required', 'integer', 'exists:users,id'],
            'participants.*.unit_id'     => ['required', 'integer', 'exists:a2_units,id'],
            'participants.*.active'      => ['nullable', 'boolean'],
        ]);

        $rows = A2Participant::with('employee')->where('unit_id', $unit->id)->whereIn('id', array_keys($data['participants']))->get();

        if ($problems = $this->problems($rows->map(fn ($p) => [$p, $data['participants'][$p->id]])->all())) {
            return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('error', 'Tidak disimpan: ' . implode(' ', $problems));
        }

        DB::transaction(function () use ($rows, $data) {
            foreach ($rows as $p) {
                $r = $data['participants'][$p->id];
                $p->update(['assessor_id' => $r['assessor_id'], 'approver_id' => $r['approver_id'], 'unit_id' => $r['unit_id'], 'active' => ! empty($r['active'])]);
            }
        });

        return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('success', "Peserta {$unit->name} disimpan.");
    }

    public function addParticipant(Request $request, A2Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,id_employee', Rule::unique('a2_participants', 'employee_id')],
            'assessor_id' => ['required', 'integer', 'exists:users,id'],
            'approver_id' => ['required', 'integer', 'exists:users,id'],
        ], ['employee_id.unique' => 'Orang ini sudah menjadi peserta (mungkin di unit lain).']);

        $employee = Employee::find($data['employee_id']);
        $p        = new A2Participant(['employee_id' => $employee->id_employee]);
        $p->setRelation('employee', $employee);

        if ($problems = $this->problems([[$p, $data]])) {
            return redirect()->route('criteria.a2', ['unit' => $unit->id])->withInput()->with('error', 'Tidak ditambahkan: ' . implode(' ', $problems));
        }

        if ($employee->status !== 'Active') {
            return redirect()->route('criteria.a2', ['unit' => $unit->id])->withInput()->with('error', "Tidak ditambahkan: {$employee->name} tidak aktif.");
        }
        $moved = ReviewRules::levelOf($employee) !== 2;

        DB::transaction(function () use ($data, $unit, $employee, $moved) {
            if ($moved) {   // still Assessment 1: becomes Assessment 2, and leaves any A1 special route
                $employee->update(['assessment_level' => 2]);
                A1Route::whereNotNull('employee_id')->get()
                    ->filter(fn ($r) => ReviewRules::normalizeNik($r->employee_id) === ReviewRules::normalizeNik($employee->id_employee))
                    ->each->delete();
            }
            A2Participant::create($data + ['unit_id' => $unit->id, 'active' => true]);
        });

        return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('success', "{$employee->name} ditambahkan ke {$unit->name}."
            . ($moved ? ' Ia dipindah dari Assessment 1 ke Assessment 2 (dan keluar dari jalur khusus A1).' : ''));
    }

    /**
     * A new unit = a new assessment form with its own criteria, e.g. "PRESS · Kepala Seksi 1" next to
     * "PRESS · Kepala Seksi 2" when one department scores its section heads differently. Optionally starts with a copy
     * of another unit's criteria (then edit them); it is listed right after the other units of its department.
     */
    public function storeUnit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100', Rule::unique('a2_units', 'name')],
            'department' => ['nullable', 'string', 'max:100'],
            'copy_from'  => ['nullable', 'integer', 'exists:a2_units,id'],
        ], [
            'name.required' => 'Isi nama unit penilaian.',
            'name.unique'   => 'Nama unit ini sudah dipakai.',
        ], ['name' => 'nama unit', 'department' => 'departemen']);

        $unit = DB::transaction(function () use ($data) {
            $department = trim((string) ($data['department'] ?? '')) ?: null;
            // after the last unit of the same department, else at the end; the units behind it move down one place
            $after = $department ? A2Unit::where('department', $department)->max('sort') : null;
            $sort  = $after !== null ? $after + 1 : (int) A2Unit::max('sort') + 1;
            A2Unit::where('sort', '>=', $sort)->increment('sort');
            $unit = A2Unit::create(['name' => trim($data['name']), 'department' => $department, 'sort' => $sort]);

            if (! empty($data['copy_from'])) {
                foreach (A2Criterion::where('unit_id', $data['copy_from'])->orderBy('sort')->get() as $c) {
                    A2Criterion::create(['unit_id' => $unit->id] + $c->only(['sort', 'group_zh', 'group_id', 'name_zh', 'name_id', 'weight', 'target', 'remark']));
                }
            }

            return $unit;
        });

        $copied = $unit->criteria()->count();

        return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('success', "Unit \"{$unit->name}\" dibuat"
            . ($copied ? " dengan {$copied} kriteria salinan; sesuaikan kriteria dan bobotnya, lalu simpan." : '; tambahkan kriterianya (total bobot 100%), lalu tambahkan peserta.'));
    }

    /** Rename a unit or change its department (criteria, participants and saved scores stay with it). */
    public function updateUnit(Request $request, A2Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100', Rule::unique('a2_units', 'name')->ignore($unit->id)],
            'department' => ['nullable', 'string', 'max:100'],
        ], ['name.unique' => 'Nama unit ini sudah dipakai.'], ['name' => 'nama unit']);

        $unit->update(['name' => trim($data['name']), 'department' => trim((string) ($data['department'] ?? '')) ?: null]);

        return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('success', "Unit \"{$unit->name}\" disimpan.");
    }

    /**
     * Deletes an unused unit (with its criteria). Refused while it still has participants (move them first) or saved
     * assessments (history must keep its unit).
     */
    public function destroyUnit(A2Unit $unit): RedirectResponse
    {
        $people = A2Participant::where('unit_id', $unit->id)->count();
        $scored = A2Assessment::where('unit_id', $unit->id)->count();
        if ($people || $scored) {
            return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('error', "Unit \"{$unit->name}\" tidak dihapus: "
                . collect([$people ? "masih ada {$people} peserta (pindahkan ke unit lain atau hapus dulu)" : null,
                           $scored ? "sudah punya {$scored} penilaian tersimpan (riwayat tetap memakai unit ini)" : null])->filter()->implode(' dan ') . '.');
        }

        $name = $unit->name;
        $unit->delete();   // criteria go with it (cascade)

        return redirect()->route('criteria.a2')->with('success', "Unit \"{$name}\" dihapus.");
    }

    /**
     * Removes a participant from Assessment 2 assignments. Saved assessments stay as history. With to_a1 the person
     * also goes back to Assessment 1 (employees.assessment_level = 1), e.g. a wrong move.
     */
    public function destroyParticipant(Request $request, A2Participant $participant): RedirectResponse
    {
        $participant->load(['employee', 'unit']);
        $name   = $participant->employee?->name ?? $participant->employee_id;
        $unitId = $participant->unit_id;
        $open   = A2Assessment::where('employee_id', $participant->employee_id)->where('period', now()->format('Y-m'))->first();
        $toA1   = $request->boolean('to_a1') && $participant->employee;

        DB::transaction(function () use ($participant, $toA1) {
            $participant->delete();
            if ($toA1) {
                $participant->employee->update(['assessment_level' => 1]);
            }
        });

        return redirect()->route('criteria.a2', ['unit' => $unitId])->with('success', "{$name} dihapus dari peserta Assessment 2"
            . ($toA1 ? ' dan kembali ke Assessment 1 (alur departemennya; atur jalur khusus di tab Jalur Khusus A1 bila perlu).' : '.')
            . ($open ? " Penilaian A2 bulan ini ({$open->status}) tetap tersimpan sebagai riwayat." : ''));
    }

    /**
     * Penilai and Approver must be different accounts, and nobody approves himself (account NIK = participant).
     * Assessing oneself is allowed (a department head scores himself, the GM / Director approves).
     * @param array<int, array{0: A2Participant, 1: array}> $pairs
     */
    private function problems(array $pairs): array
    {
        $users    = User::whereIn('id', collect($pairs)->flatMap(fn ($p) => [$p[1]['assessor_id'], $p[1]['approver_id']])->unique())->get()->keyBy('id');
        $problems = [];

        foreach ($pairs as [$p, $r]) {
            $name = $p->employee?->name ?? $p->employee_id;
            if ((int) $r['assessor_id'] === (int) $r['approver_id']) {
                $problems[] = "{$name}: Penilai dan Approver harus akun yang berbeda.";
            }
            $approver = $users->get((int) $r['approver_id']);
            if ($approver?->nik && ReviewRules::normalizeNik($approver->nik) === ReviewRules::normalizeNik($p->employee_id)) {
                $problems[] = "{$name}: tidak boleh menyetujui dirinya sendiri.";
            }
        }

        return $problems;
    }
}
