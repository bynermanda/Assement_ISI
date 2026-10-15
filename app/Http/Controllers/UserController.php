<?php

namespace App\Http\Controllers;

use App\Models\A2Participant;
use App\Models\Employee;
use App\Models\User;
use App\Support\AccountMerger;
use App\Support\ReviewRules;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accounts: admin (HRD) and reviewers.
 * Every section of a department has one Penilai 1 and one Penilai 2 account.
 * Who sits behind an account (name, NIK) can be filled in later; the account itself works from day one.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $term = '%' . addcslashes((string) $request->query('q', ''), '%_\\') . '%';

        $all = User::query()
            ->when($request->filled('q'), fn ($query) => $query->where(
                fn ($w) => $w->where('name', 'ilike', $term)->orWhere('username', 'ilike', $term)->orWhere('nik', 'ilike', $term)
            ))
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->query('role')))
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('username')
            ->get();

        $a1Duties = $this->a1Duties($all);
        $a2Duties = $this->a2Duties();

        // filter by kind of duty: a1 / a2 / both / none
        $has = fn (User $u) => [! empty($a1Duties[$u->id]), ! empty($a2Duties['assessor'][$u->id]) || ! empty($a2Duties['approver'][$u->id])];
        $all = match ((string) $request->query('duty')) {
            'a1'    => $all->filter(fn ($u) => $has($u)[0]),
            'a2'    => $all->filter(fn ($u) => $has($u)[1]),
            'both'  => $all->filter(fn ($u) => $has($u)[0] && $has($u)[1]),
            'none'  => $all->filter(fn ($u) => $u->role !== Roles::ADMIN && ! $has($u)[0] && ! $has($u)[1]),
            default => $all,
        };

        $perPage = 60;
        $page    = max(1, (int) $request->query('page', 1));
        $users   = new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page, $perPage)->values(), $all->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        $groups = ReviewRules::allUnits();

        return view('users.index', [
            'users'        => $users,
            'roles'        => Roles::LABELS,
            'groupOptions' => $groups,
            'coverage'     => ReviewRules::coverage(),
            'a1Duties'     => $a1Duties,
            'a2Duties'     => $a2Duties,
            ...$this->a2Overview(),
            'mergeOptions' => User::where('role', '!=', Roles::ADMIN)->orderBy('username')->get(['id', 'username', 'name']),
            'legacyCount'  => User::whereIn('role', Roles::LEGACY)->count(),
            'oldPersonCount' => $this->outdatedAccounts($groups)->count(),
            'missingCount' => count($this->defaultAccounts()) - $this->existingDefaultAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'password' => ['required', Password::min(8)->letters()->numbers()],
            ...$this->profileRules($request),
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/'],
        ]);

        $attributes = $this->attributesFor($data);
        $username   = $data['username'] ?: strtolower((string) ($attributes['nik'] ?? ''));

        if ($username === '') {
            return back()->withInput()->withErrors(['username' => 'Isi username.']);
        }

        if (User::where('username', $username)->exists()) {
            return back()->withInput()->withErrors(['username' => 'Username ini sudah dipakai.']);
        }

        (new User())->forceFill($attributes + [
            'username'             => $username,
            'password'             => Hash::make($data['password']),
            'must_change_password' => (bool) config('assessment.force_password_change'),
        ])->save();

        return redirect()->route('users.index')->with('success', "Pengguna {$username} dibuat." . $this->passwordNote('saat login pertama'));
    }

    /** Profile and duties; a new username is allowed, the assessment history (stored by username) follows it. */
    public function update(Request $request, User $user): RedirectResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username', $user->username)))]);

        $data = $request->validate([
            ...$this->profileRules($request),
            'username'       => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'a2_present'     => ['nullable', 'boolean'],
            'a2_assessing'   => ['nullable', 'array'],
            'a2_assessing.*' => ['integer', 'exists:a2_participants,id'],
            'a2_approving'   => ['nullable', 'array'],
            'a2_approving.*' => ['integer', 'exists:a2_participants,id'],
        ]);

        if ($user->is(Auth::user()) && $data['role'] !== Roles::ADMIN) {
            return redirect()->route('users.index')->with('error', 'Anda tidak bisa melepas peran admin milik sendiri.');
        }

        // Assessment 2 duties (only when the form sent that section)
        $a2 = null;
        if ($request->boolean('a2_present') && $data['role'] !== Roles::ADMIN) {
            $a2 = $this->planA2Duties($user, $data['nik'] ?? null, array_map('intval', $data['a2_assessing'] ?? []), array_map('intval', $data['a2_approving'] ?? []));
            if ($a2['problems']) {
                return redirect()->route('users.index', ['q' => $user->username])->with('error', 'Tidak disimpan: ' . implode(' ', $a2['problems']));
            }
        }

        $old = $user->username;
        DB::transaction(function () use ($user, $data, $old, $a2) {
            $user->forceFill($this->attributesFor($data))->save();
            if ($data['username'] !== $old) {
                AccountMerger::rename($user, $data['username']);
            }
            if ($a2) {
                A2Participant::whereIn('id', $a2['assess'])->update(['assessor_id' => $user->id]);
                A2Participant::whereIn('id', $a2['approve'])->update(['approver_id' => $user->id]);
            }
        });
        if ($a2) {
            \App\Support\RefCache::flush('a2', 'routes');
        }

        $taken = $a2 && $a2['takenFrom'] ? ' Assessment 2 diambil alih: ' . implode('; ', $a2['takenFrom']) . '.' : '';

        return redirect()->route('users.index', ['q' => $user->username])->with('success', ($data['username'] !== $old
            ? "Pengguna {$old} diganti menjadi {$user->username} dan diperbarui; riwayat penilaiannya ikut pindah."
            : "Pengguna {$user->username} diperbarui.") . $taken);
    }

    /**
     * Assessment 2 duties chosen in the edit form. Ticking a person moves him to this account (taken over from his
     * previous Penilai / Approver). Unticking is refused: every participant must keep a Penilai and an Approver,
     * so give him to another account instead (that account's Edit, or Guidelines › Assessment 2).
     * @return array{assess: int[], approve: int[], takenFrom: string[], problems: string[]}
     */
    private function planA2Duties(User $user, ?string $nik, array $assess, array $approve): array
    {
        $plan = ['assess' => [], 'approve' => [], 'takenFrom' => [], 'problems' => []];
        $nik  = ReviewRules::normalizeNik(filled($nik) ? ($this->findEmployee($nik)?->id_employee ?? $nik) : $user->nik);

        foreach (A2Participant::with(['employee', 'assessor', 'approver'])->get() as $p) {
            $name       = $p->employee?->name ?? $p->employee_id;
            $isAssessor = in_array($p->id, $assess, true);
            $isApprover = in_array($p->id, $approve, true);

            foreach (['assessor' => $isAssessor, 'approver' => $isApprover] as $role => $ticked) {
                $was = $p->{"{$role}_id"} === $user->id;
                if ($was && ! $ticked) {
                    $plan['problems'][] = "{$name} akan tanpa " . ($role === 'assessor' ? 'Penilai' : 'Approver') . ': berikan ke akun lain, jangan hanya dihapus centangnya.';
                }
                if ($ticked && ! $was) {
                    $plan[$role === 'assessor' ? 'assess' : 'approve'][] = $p->id;
                    $plan['takenFrom'][] = "{$name} (" . ($role === 'assessor' ? 'Penilai' : 'Approver') . ', dari ' . ($p->{$role}?->username ?? 'tidak ada') . ')';
                }
            }

            $assessor = $isAssessor ? $user->id : $p->assessor_id;
            $approver = $isApprover ? $user->id : $p->approver_id;
            if (($isAssessor || $isApprover) && $assessor === $approver) {
                $plan['problems'][] = "{$name}: satu akun tidak boleh menjadi Penilai sekaligus Approver.";
            }
            if ($isApprover && $nik !== '' && ReviewRules::normalizeNik($p->employee_id) === $nik) {
                $plan['problems'][] = "{$name}: tidak boleh menyetujui dirinya sendiri.";
            }
        }

        return $plan;
    }

    /** Unmerge: move part of the duties of an account into a new account (App\Support\AccountMerger::split). */
    public function split(Request $request, User $user): RedirectResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'username'    => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'name'        => ['required', 'string', 'max:150'],
            'password'    => ['required', Password::min(8)->letters()->numbers()],
            'nik'         => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (filled($value) && ! $this->findEmployee($value)) { $fail('NIK ini tidak ada di daftar karyawan.'); }
            }],
            'job_title'   => ['nullable', 'string', 'max:100'],
            'stage'       => ['nullable', Rule::in([1, 2, 3])],
            'sections'    => ['nullable', 'array'],
            'sections.*'  => ['string', Rule::in(ReviewRules::userGroups($user))],
            'assessing'   => ['nullable', 'array'],
            'assessing.*' => ['integer'],
            'approving'   => ['nullable', 'array'],
            'approving.*' => ['integer'],
        ]);

        abort_if($user->role === Roles::ADMIN, 403, 'Akun admin tidak bisa dipisahkan.');

        $sections  = $data['sections'] ?? [];
        $assessing = array_map('intval', $data['assessing'] ?? []);
        $approving = array_map('intval', $data['approving'] ?? []);

        if ($sections === [] && $assessing === [] && $approving === []) {
            return redirect()->route('users.index')->with('error', 'Tidak ada yang dipindahkan: centang minimal satu seksi atau orang Assessment 2.');
        }

        $nik = filled($data['nik'] ?? null) ? $this->findEmployee($data['nik'])?->id_employee : null;
        if ($problems = AccountMerger::splitProblems($user, $nik, $assessing, $approving)) {
            return redirect()->route('users.index')->with('error', 'Tidak dipisahkan: ' . implode(' ', $problems));
        }

        $new = AccountMerger::split($user, [
            'username' => $data['username'], 'name' => $data['name'], 'nik' => $nik,
            'job_title' => $data['job_title'] ?? null, 'password' => Hash::make($data['password']),
        ], $sections, isset($data['stage']) ? (int) $data['stage'] : null, $assessing, $approving);

        return redirect()->route('users.index', ['q' => $new->username])
            ->with('success', "Akun baru {$new->username} dibuat dengan tugas terpilih dari {$user->username}.");
    }

    /**
     * Creates, for every department, the two default accounts that are still missing:
     *   {department}.penilai1  and  {department}.penilai2   (e.g. qa.penilai1, finacc.penilai2)
     * An existing default account gets the department sections it does not cover yet (extra sections it has are kept).
     * Optionally removes the old inputter / verifier / approver accounts and reviewer accounts of earlier versions.
     */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password'          => ['required', Password::min(8)->letters()->numbers()],
            'delete_legacy'     => ['nullable', 'boolean'],
            'delete_old_people' => ['nullable', 'boolean'],
        ]);

        set_time_limit(300);   // the database is remote: keep the number of queries small

        $now     = now();
        $hash    = Hash::make($data['password']);   // one hash for all: they must change it at first login
        $taken   = User::get()->keyBy(fn (User $u) => strtolower($u->username));
        $valid   = array_map([ReviewRules::class, 'key'], ReviewRules::allUnits());
        $rows    = [];
        $updates = [];

        foreach ($this->defaultAccounts() as [$username, $name, $units, $stage]) {
            if ($existing = $taken->get($username)) {
                $stored  = ReviewRules::userGroups($existing);
                $current = array_values(array_filter($stored, fn ($unit) => in_array(ReviewRules::key($unit), $valid, true)));   // drop sections that no longer exist
                $keys    = array_map([ReviewRules::class, 'key'], $current);
                $missing = array_filter($units, fn ($unit) => ! in_array(ReviewRules::key($unit), $keys, true));

                if ($existing->role === Roles::REVIEWER && ($missing !== [] || count($current) !== count($stored))) {
                    $updates[] = [$existing, array_values(array_merge($current, $missing))];
                }

                continue;
            }

            if ($this->coveredByAnother($taken, $units, $stage)) {
                continue;   // e.g. merged into a personal account: do not recreate the department account
            }

            $rows[] = [
                'username'             => $username,
                'name'                 => $name,
                'role'                 => Roles::REVIEWER,
                'stage'                => $stage,
                'groups'               => json_encode($units),
                'password'             => $hash,
                'must_change_password' => (bool) config('assessment.force_password_change'),
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        $created = 0;
        $removed = 0;

        try {
            DB::transaction(function () use ($rows, $updates, $request, &$created, &$removed) {
                foreach ($updates as [$user, $groups]) {
                    $user->forceFill(['groups' => json_encode($groups)])->save();
                }

                if ($request->boolean('delete_legacy')) {
                    $removed += User::whereIn('role', Roles::LEGACY)->delete();
                }

                if ($request->boolean('delete_old_people')) {
                    $ids = $this->outdatedAccounts(ReviewRules::allUnits())->pluck('id');
                    $removed += $ids->isEmpty() ? 0 : User::whereIn('id', $ids)->delete();
                }

                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('users')->insert($chunk);
                    $created += count($chunk);
                }
            });
        } catch (\Throwable $e) {
            return redirect()->route('users.index')->with('error', 'Tidak ada yang disimpan: ' . $e->getMessage());
        }

        return redirect()->route('users.index')->with(
            'success',
            "{$created} akun dibuat" . ($updates ? ', ' . count($updates) . ' akun mendapat seksi yang kurang' : '') . ($removed ? ", {$removed} akun lama dihapus" : '') . '.' . $this->passwordNote('saat login pertama')
        );
    }

    /** Admin sets a password, or leaves it blank to generate one. Must be changed at next login only when FORCE_PASSWORD_CHANGE is on. */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            return redirect()->route('users.index')->with('error', $validator->errors()->first());
        }

        $plain = $request->filled('password') ? $request->input('password') : Str::password(10, symbols: false);

        $user->forceFill([
            'password'             => Hash::make($plain),
            'must_change_password' => (bool) config('assessment.force_password_change'),
        ])->save();

        return redirect()->route('users.index')
            ->with('success', "Password {$user->username} sudah direset." . $this->passwordNote('saat login berikutnya'))
            ->with('generated_password', $plain)
            ->with('generated_for', $user->username);
    }

    /** One person, one account: see App\Support\AccountMerger. */
    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:users,id', 'different:target_id'],
            'target_id' => ['required', 'integer', 'exists:users,id'],
            'allow_self' => ['nullable', 'boolean'],
        ], ['source_id.different' => 'Pilih dua akun yang berbeda.']);

        $source = User::findOrFail($data['source_id']);
        $target = User::findOrFail($data['target_id']);

        if ($problems = AccountMerger::problems($source, $target, $request->boolean('allow_self'))) {
            return redirect()->route('users.index')->with('error', 'Tidak digabung: ' . implode(' ', $problems));
        }

        AccountMerger::merge($source, $target);

        return redirect()->route('users.index', ['q' => $target->username])
            ->with('success', "{$source->username} sudah digabung ke {$target->username}. Mulai sekarang login sebagai {$target->username}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(Auth::user())) {
            return redirect()->route('users.index')->with('error', 'Anda tidak bisa menghapus akun sendiri.');
        }

        // Deleting it would leave Assessment 1 special routes or Assessment 2 participants without Penilai / Approver
        $routes = \App\Models\A1Route::where('assessor_id', $user->id)->orWhere('approver_id', $user->id)->get();
        if ($routes->isNotEmpty()) {
            return redirect()->route('users.index', ['q' => $user->username])->with('error', "{$user->username} adalah Penilai / Approver di rute khusus Assessment 1 ("
                . $routes->map->describe()->implode('; ') . '). Ubah rute tersebut dulu (Panduan › Assessment 1).');
        }
        $duties = A2Participant::with('employee')->where('assessor_id', $user->id)->orWhere('approver_id', $user->id)->get();
        if ($duties->isNotEmpty()) {
            return redirect()->route('users.index', ['q' => $user->username])->with('error', "{$user->username} masih menilai atau menyetujui di Assessment 2 ("
                . $duties->map(fn ($p) => $p->employee?->name)->implode(', ') . '). Berikan dulu ke akun lain (Edit, Gabungkan akun, atau Panduan › Assessment 2).');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', "Pengguna {$user->username} dihapus.");
    }

    /* ------------------------------------------------------------------ */

    private function profileRules(Request $request): array
    {
        $isReviewer = $request->input('role') === Roles::REVIEWER;

        return [
            'role'     => ['required', Rule::in(Roles::all())],
            'name'     => ['required', 'string', 'max:150'],
            // Optional: the person behind the account. Must exist in the employee list.
            'nik'      => [
                'nullable',
                'string',
                'max:30',
                function ($attribute, $value, $fail) {
                    if (filled($value) && ! $this->findEmployee($value)) {
                        $fail('NIK ini tidak ada di daftar karyawan.');
                    }
                },
            ],
            'job_title' => ['nullable', 'string', 'max:100'],
            'stage'    => [$isReviewer ? 'required' : 'nullable', Rule::in([1, 2, 3])],
            'groups'   => [Rule::requiredIf($isReviewer), 'nullable', 'array'],
            'groups.*' => ['string', Rule::in(ReviewRules::allUnits())],
        ];
    }

    /** Columns to write, shaped by the role. */
    private function attributesFor(array $data): array
    {
        if ($data['role'] === Roles::ADMIN) {
            return ['name' => $data['name'], 'role' => Roles::ADMIN, 'nik' => null, 'grade' => null, 'stage' => null, 'groups' => null, 'department' => null, 'section' => null];
        }

        $employee = filled($data['nik'] ?? null) ? $this->findEmployee($data['nik']) : null;

        // Assessment 2 account: who it assesses / approves is set in a2_participants, not with stage and groups
        if ($data['role'] === Roles::ASSESSOR) {
            return [
                'name'       => $data['name'],
                'role'       => Roles::ASSESSOR,
                'nik'        => $employee?->id_employee,
                'job_title'  => $data['job_title'] ?? null,
                'grade'      => $employee ? ReviewRules::gradeOf($employee->position) : null,
                'stage'      => null,
                'groups'     => null,
                'department' => $employee?->department,
                'section'    => null,
            ];
        }

        return [
            'name'       => $data['name'],
            'job_title'  => $data['job_title'] ?? null,
            'role'       => Roles::REVIEWER,
            'nik'        => $employee?->id_employee,
            'grade'      => $employee ? ReviewRules::gradeOf($employee->position) : null,
            'stage'      => (int) $data['stage'],
            'groups'     => json_encode(array_values(array_unique($data['groups'] ?? []))),
            'department' => null,
            'section'    => null,
        ];
    }

    private function findEmployee(?string $nik): ?Employee
    {
        $wanted = ReviewRules::normalizeNik($nik);

        return $wanted === '' ? null : Employee::all()->first(fn ($e) => ReviewRules::normalizeNik($e->id_employee) === $wanted);
    }

    /**
     * One Penilai 1 and one Penilai 2 account per department, covering all its sections.
     * Username from the department: "FIN/ACC" -> finacc, "R&D" -> randd, "P.E / IMPROVEMENT" -> pe, "IT (INFORMATION TECHNOLOGY)" -> it.
     *
     * @return array<int, array{0:string,1:string,2:string[],3:int}> username, name, units, stage
     */
    private function defaultAccounts(): array
    {
        $accounts = [];

        foreach (ReviewRules::departmentUnits() as $department => $units) {
            $short = preg_replace('/\s+\(.*$|\s+\/.*$/', '', $department);
            $slug  = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', str_replace('&', 'and', $short)));

            foreach ([1 => 'penilai1', 2 => 'penilai2'] as $stage => $suffix) {
                $accounts[] = ["{$slug}.{$suffix}", ($stage === 1 ? 'Penilai 1' : 'Penilai 2') . " - {$department}", $units, $stage];
            }
        }

        return $accounts;
    }

    /** " They must change it at first login." while FORCE_PASSWORD_CHANGE is on (go-live), nothing during the trial. */
    private function passwordNote(string $when): string
    {
        return config('assessment.force_password_change') ? " Wajib diganti {$when}." : '';
    }

    /**
     * For the Users page: every Assessment 2 participant (choices in the edit form) and the gaps:
     * participants without Penilai or Approver, and Assessment 2 employees who are not participants yet.
     */
    private function a2Overview(): array
    {
        $participants = A2Participant::with(['employee', 'unit', 'assessor', 'approver'])->get()
            ->sortBy(fn ($p) => sprintf('%05d|%s', $p->unit?->sort, $p->employee?->name))->values();
        $taken = $participants->pluck('employee_id')->flip();

        // employees who hold an account that assesses / approves someone: evaluators only (e.g. Fiona Kusuma, Agus Tony)
        $evaluatorNiks = User::whereNotNull('nik')->whereIn('id', $participants->pluck('assessor_id')->merge($participants->pluck('approver_id'))->filter()->unique())
            ->pluck('nik')->map(fn ($n) => ReviewRules::normalizeNik($n))->flip();
        $notAssigned = Employee::where('status', 'Active')->orderBy('name')->get()
            ->filter(fn ($e) => ReviewRules::levelOf($e) === 2 && ! $taken->has($e->id_employee));

        return [
            'a2Evaluators' => $notAssigned->filter(fn ($e) => $evaluatorNiks->has(ReviewRules::normalizeNik($e->id_employee)))->values(),
            'a2All' => $participants->map(fn ($p) => [
                'id'       => $p->id,
                'name'     => $p->employee?->name ?? $p->employee_id,
                'unit'     => $p->unit?->name,
                'active'   => $p->active,
                'assessor' => $p->assessor?->username,
                'approver' => $p->approver?->username,
            ])->all(),
            'a2Missing'    => $participants->filter(fn ($p) => $p->active && (! $p->assessor_id || ! $p->approver_id))->values(),
            'a2Unassigned' => $notAssigned->reject(fn ($e) => $evaluatorNiks->has(ReviewRules::normalizeNik($e->id_employee)))->values(),
        ];
    }

    /**
     * Assessment 1 duties per account, for the Users page:
     * [user id => ['assess' => sections, 'approve' => sections, 'leadAssess' => [department => names], 'leadApprove' => [...]]]
     * (sections from the account's own Penilai step; leaders from the computed route, see ReviewRules::routeMap()).
     * Accounts without any Assessment 1 duty are left out.
     */
    private function a1Duties($users): array
    {
        $duties = [];
        foreach ($users as $u) {
            $stages = ReviewRules::stagesFor($u);
            $groups = ReviewRules::userGroups($u);
            $duties[$u->id] = [
                'assess'      => in_array(ReviewRules::STAGE_ASSESS, $stages, true) ? $groups : [],
                'approve'     => in_array(ReviewRules::STAGE_APPROVE, $stages, true) ? $groups : [],
                'leadAssess'  => [],
                'leadApprove' => [],
            ];
        }

        $byNik = ReviewRules::activeEmployees()->keyBy(fn (Employee $e) => ReviewRules::normalizeNik($e->id_employee));
        foreach (ReviewRules::routeMap()['routes'] as $nik => $route) {
            if (! $e = $byNik->get($nik)) {
                continue;
            }
            $department = trim((string) $e->department);
            foreach (['assessor' => 'leadAssess', 'approver' => 'leadApprove'] as $who => $key) {
                if (isset($duties[$route[$who]])) {
                    $duties[$route[$who]][$key][$department][] = $e->name;
                }
            }
        }

        return array_filter($duties, fn ($d) => $d['assess'] || $d['approve'] || $d['leadAssess'] || $d['leadApprove']);
    }

    /** Assessment 2 duties per account: ['assessor' => [user id => [{id, name}]], 'approver' => [...]]. */
    private function a2Duties(): array
    {
        $duties = ['assessor' => [], 'approver' => []];

        // all participants (also inactive ones), the same set the edit form checks
        foreach (A2Participant::with('employee')->get() as $p) {
            $row = ['id' => $p->id, 'name' => ($p->employee?->name ?? $p->employee_id) . ($p->active ? '' : ' (nonaktif)')];
            if ($p->assessor_id) { $duties['assessor'][$p->assessor_id][] = $row; }
            if ($p->approver_id) { $duties['approver'][$p->approver_id][] = $row; }
        }

        return $duties;
    }

    /** Default accounts that exist, or whose duty another reviewer account already holds (after a merge). */
    private function existingDefaultAccounts(): int
    {
        $users = User::get()->keyBy(fn (User $u) => strtolower($u->username));

        return collect($this->defaultAccounts())
            ->filter(fn ($a) => $users->has($a[0]) || $this->coveredByAnother($users, $a[2], $a[3]))
            ->count();
    }

    /** Do other reviewer accounts together cover all these sections at this step (e.g. QA split into qa1 + qa2, or a merge)? */
    private function coveredByAnother($users, array $units, int $stage): bool
    {
        $wanted  = array_map([ReviewRules::class, 'key'], $units);
        $covered = $users->filter(fn (User $u) => $u->role === Roles::REVIEWER && in_array($stage, ReviewRules::stagesFor($u), true))
            ->flatMap(fn (User $u) => array_map([ReviewRules::class, 'key'], ReviewRules::userGroups($u)))
            ->merge(ReviewRules::routedOnlyUnits())   // everybody there follows a special route (e.g. DIES): no account needed
            ->unique()->all();

        return array_diff($wanted, $covered) === [];
    }

    /** Reviewer accounts from earlier versions: no step set, or none of their sections exists any more. */
    private function outdatedAccounts(array $units)
    {
        $valid = array_map([ReviewRules::class, 'key'], $units);

        return User::where('role', Roles::REVIEWER)->get()->filter(function (User $user) use ($valid) {
            if ($user->stage === null) {
                return true;
            }

            return array_intersect(array_map([ReviewRules::class, 'key'], ReviewRules::userGroups($user)), $valid) === [];
        })->values();
    }
}
