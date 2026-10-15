<?php

namespace App\Http\Controllers;

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
            // people who can be added: active, in Assessment 2, not a participant yet
            'candidates'   => Employee::where('status', 'Active')->orderBy('name')->get()
                ->filter(fn ($e) => ReviewRules::levelOf($e) === 2 && ! $taken->has($e->id_employee))->values(),
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

        A2Participant::create($data + ['unit_id' => $unit->id, 'active' => true]);

        return redirect()->route('criteria.a2', ['unit' => $unit->id])->with('success', "{$employee->name} ditambahkan ke {$unit->name}.");
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
