<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Assessment 1: who is assessed, by whom.
 *   - Employees in Assessment 1 are assessed: set by the admin per employee, or by grade (1 to 5) when left on Auto.
 *   - An employee belongs to a unit: his section, e.g. "APQP (QA)".
 *   - An account has a stage (Penilai 1 / Penilai 2 / both) and the units it covers.
 *     Penilai 1 and Penilai 2 of a unit see the same employees; Penilai 2 approves and may edit.
 */
final class ReviewRules
{
    public const STAGE_ASSESS  = 1;   // Penilai 1
    public const STAGE_APPROVE = 2;   // Penilai 2
    public const STAGE_BOTH    = 3;

    private static ?Collection $active = null;

    public static function key(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /** "Grade 5" => 5 */
    public static function gradeOf(?string $position): ?int
    {
        return preg_match('/\d+/', (string) $position, $m) ? (int) $m[0] : null;
    }

    /** "01.151002" and "1.151.002" are the same NIK. */
    public static function normalizeNik(?string $nik): string
    {
        return strtoupper(ltrim((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $nik), '0'));
    }

    /** Section first: "APQP (QA)", "PE (P.E / IMPROVEMENT)"; just "PRESS" when the section is empty or equals the department. */
    public static function unitOf(Employee $employee): string
    {
        $department = trim((string) $employee->department);
        $section    = trim((string) $employee->section);

        return ($section === '' || self::key($section) === self::key($department)) ? $department : "{$section} ({$department})";
    }

    /** Is this employee part of Assessment 1? */
    public static function isAssessed(Employee $employee): bool
    {
        return self::levelOf($employee) === 1;
    }

    /**
     * Assessment 1 or 2. The admin's choice (employees.assessment_level) wins; otherwise it follows the grade:
     * grade 1 up to the configured maximum (5) -> 1, higher -> 2. NULL when the position has no grade.
     */
    public static function levelOf(Employee $employee): ?int
    {
        if (in_array((int) $employee->assessment_level, [1, 2], true)) {
            return (int) $employee->assessment_level;
        }

        return self::autoLevelOf($employee);
    }

    /** The level the grade alone gives, ignoring the admin's choice. */
    public static function autoLevelOf(Employee $employee): ?int
    {
        $grade = self::gradeOf($employee->position);

        if ($grade === null || $grade < 1) {
            return null;
        }

        return $grade <= (int) config('assessment.assessed_max_grade', 5) ? 1 : 2;
    }

    /** @return string[] */
    public static function userGroups(User $user): array
    {
        $decoded = json_decode((string) ($user->groups ?? ''), true);

        return is_array($decoded) ? array_values(array_filter(array_map('trim', $decoded))) : [];
    }

    /** Stages this account takes part in: [1], [2] or [1, 2]. */
    public static function stagesFor(User $user): array
    {
        return match ((int) ($user->stage ?? 0)) {
            self::STAGE_ASSESS  => [self::STAGE_ASSESS],
            self::STAGE_APPROVE => [self::STAGE_APPROVE],
            self::STAGE_BOTH    => [self::STAGE_ASSESS, self::STAGE_APPROVE],
            default             => [],
        };
    }

    public static function stageLabel(?int $stage): string
    {
        return match ((int) $stage) {
            self::STAGE_ASSESS  => 'Penilai 1',
            self::STAGE_APPROVE => 'Penilai 2',
            self::STAGE_BOTH    => 'Penilai 1 + 2',
            default             => '-',
        };
    }

    /**
     * The employees this account handles at the given stage:
     *   - employees of its sections, unless a special route (a1_routes) sends them to other accounts;
     *   - employees whose special route names this account as Penilai (stage 1) or Approver (stage 2).
     */
    public static function employeesFor(User $user, int $stage): Collection
    {
        if (! in_array($stage, self::dutyStages($user), true)) {
            return collect();
        }

        $groups = in_array($stage, self::stagesFor($user), true) ? array_map([self::class, 'key'], self::userGroups($user)) : [];
        $own    = self::normalizeNik($user->nik);
        $column = $stage === self::STAGE_ASSESS ? 'assessor_id' : 'approver_id';

        return self::activeEmployees()
            ->filter(function (Employee $e) use ($groups, $own, $stage, $user) {
                if (! self::isAssessed($e) || ($own !== '' && self::normalizeNik($e->id_employee) === $own)) {
                    return false;
                }
                $route = self::routeFor($e);

                return $route
                    ? $route[$stage === self::STAGE_ASSESS ? 'assessor' : 'approver'] === (int) $user->id
                    : in_array(self::key(self::unitOf($e)), $groups, true);
            })
            ->values();
    }

    /**
     * Steps an account works in: its own Penilai step(s) plus the ones a special route gives it
     * (e.g. a2.agus.tony approves ASSY line leaders in Assessment 1). Use this to open Assessment 1 pages;
     * stagesFor() stays the account's own setting (merge, coverage).
     */
    public static function dutyStages(User $user): array
    {
        $stages = self::stagesFor($user);
        foreach (self::routeMap()['routes'] as $route) {
            if ($route['assessor'] === (int) $user->id) { $stages[] = self::STAGE_ASSESS; }
            if ($route['approver'] === (int) $user->id) { $stages[] = self::STAGE_APPROVE; }
        }
        $stages = array_values(array_unique($stages));
        sort($stages);

        return $stages;
    }

    /**
     * Four-eyes exception: an account set up as Penilai 1 + 2 (stage 3) is the only assessor AND approver of its own
     * sections (one-person departments such as FIN/ACC, PLO, R&D-ECR), so it may approve what it assessed itself there.
     * Never for employees on a special / leader route, and never for other accounts.
     */
    public static function mayApproveOwn(User $user, Employee $e): bool
    {
        if ((int) $user->stage !== self::STAGE_BOTH || self::routeFor($e) !== null) {
            return false;
        }

        return in_array(self::key(self::unitOf($e)), array_map([self::class, 'key'], self::userGroups($user)), true);
    }

    /** May this account approve this Assessment 1 (four-eyes principle with the Penilai 1 + 2 exception)? */
    public static function mayApprove(User $user, \App\Models\Assessment $a): bool
    {
        return $a->created_by !== $user->username || ($a->employee !== null && self::mayApproveOwn($user, $a->employee));
    }

    /** Departments with more than bulk_min_employees in Assessment 1: they use Assessment Massal and the leader rule. */
    public static function bulkDepartments(): array
    {
        return self::activeEmployees()->filter(fn (Employee $e) => self::isAssessed($e))
            ->countBy(fn (Employee $e) => self::key($e->department))
            ->filter(fn ($n) => $n > (int) config('assessment.bulk_min_employees', 25))
            ->keys()->all();
    }

    /** The special route of an employee: ['assessor' => user id, 'approver' => user id, 'why' => text], or null = normal flow. */
    public static function routeFor(Employee $e): ?array
    {
        return self::routeMap()['routes'][self::normalizeNik($e->id_employee)] ?? null;
    }

    /**
     * Special Assessment 1 routes (cached, flushed when employees, accounts or a1_routes change): only the a1_routes
     * rows HRD set up — one employee, or a whole department (optionally limited to job titles) — each with its own
     * Penilai and Approver (e.g. the section leaders list: ASSY 1 leaders -> Dedi Kurniawan / Agus Tony, all of DIES ->
     * Iffan Gita Saputra / Chen Hsin Chung). A route for the person wins over a department route. Everybody else
     * follows the normal flow of the department accounts. (The old automatic job-title rule was removed on request.)
     *
     * @return array{routes: array<string, array{assessor:int, approver:int, why:string}>, problems: string[]}
     */
    public static function routeMap(): array
    {
        return self::$routeMap ??= RefCache::remember('routes', 'map', function () {
            $routes   = [];
            $problems = [];
            $assessed = self::activeEmployees()->filter(fn (Employee $e) => self::isAssessed($e));
            $explicit = \App\Models\A1Route::all()->sortBy(fn ($r) => $r->employee_id ? 0 : 1);

            foreach ($assessed as $e) {
                if ($r = $explicit->first(fn ($r) => $r->matches($e))) {
                    $routes[self::normalizeNik($e->id_employee)] = ['assessor' => (int) $r->assessor_id, 'approver' => (int) $r->approver_id,
                        'why' => 'Rute khusus: ' . $r->describe(), 'label' => $r->note ?: $r->describe()];
                }
            }

            // rows that reach nobody: the person is not in Assessment 1 (e.g. moved to Assessment 2) or not active
            $byNik = self::activeEmployees()->keyBy(fn (Employee $e) => self::normalizeNik($e->id_employee));
            foreach ($explicit->whereNotNull('employee_id') as $r) {
                $e = $byNik->get(self::normalizeNik($r->employee_id));
                if (! $e || ! self::isAssessed($e)) {
                    $problems[] = ($e?->name ?? $r->employee_id) . ': rute tidak berlaku karena ' . (! $e ? 'karyawan tidak aktif / tidak ditemukan' : 'orang ini ada di Assessment 2');
                }
            }

            return ['routes' => $routes, 'problems' => $problems];
        });
    }

    private static ?array $routeMap = null;

    /**
     * Assessment Massal: when the account has people to assess and either
     *  - its own Penilai 1 sections belong to a department with more than bulk_min_employees (25) in Assessment 1
     *    (even when its list is shorter because some people go a special route), or
     *  - it assesses more than bulk_min_employees itself (e.g. a special route for a whole department: DIES -> Iffan).
     */
    public static function usesBulk(User $user): bool
    {
        if (! in_array(self::STAGE_ASSESS, self::dutyStages($user), true)) {
            return false;
        }
        $count = self::employeesFor($user, self::STAGE_ASSESS)->count();
        if ($count === 0) {
            return false;
        }
        if ($count > (int) config('assessment.bulk_min_employees', 25)) {
            return true;
        }
        if (! in_array(self::STAGE_ASSESS, self::stagesFor($user), true)) {
            return false;
        }
        $mine = array_map([self::class, 'key'], self::userGroups($user));
        $big  = self::bulkDepartments();

        return self::activeEmployees()->contains(fn (Employee $e) => in_array(self::key(self::unitOf($e)), $mine, true)
            && in_array(self::key($e->department), $big, true));
    }

    public static function canHandle(User $user, ?Employee $employee, int $stage): bool
    {
        if (! $employee || $employee->status !== 'Active') {
            return false;
        }

        return self::employeesFor($user, $stage)->contains('id_employee', $employee->id_employee);
    }

    /** Every unit (department / section) that has employees to assess, sorted. */
    public static function allUnits(): array
    {
        $units = [];

        foreach (self::activeEmployees() as $employee) {
            if (self::isAssessed($employee) && trim((string) $employee->department) !== '') {
                $units[self::unitOf($employee)] = true;
            }
        }

        $units = array_keys($units);
        natcasesort($units);

        return array_values($units);
    }

    /**
     * Department / section pairs of the employees in Assessment 1 (with how many), for the guideline check:
     * a guideline (target) is only needed where the Assessment 1 form is used. Assessment 2 people are left out.
     */
    public static function assessedPairs(): Collection
    {
        return self::activeEmployees()
            ->filter(fn (Employee $e) => self::isAssessed($e))
            ->groupBy(fn (Employee $e) => trim((string) $e->department) . '|' . trim((string) $e->section))
            ->map(fn (Collection $list) => (object) [
                'department' => $list->first()->department,
                'section'    => $list->first()->section,
                'total'      => $list->count(),
            ])
            ->sortBy(fn ($p) => mb_strtolower($p->department . '|' . $p->section))
            ->values();
    }

    /** Department => its units (sections) that have employees to assess, both sorted. */
    public static function departmentUnits(): array
    {
        $departments = [];

        foreach (self::activeEmployees() as $employee) {
            $department = trim((string) $employee->department);

            if (self::isAssessed($employee) && $department !== '') {
                $departments[$department][self::unitOf($employee)] = true;
            }
        }

        uksort($departments, 'strnatcasecmp');

        return array_map(function (array $units) {
            $units = array_keys($units);
            natcasesort($units);

            return array_values($units);
        }, $departments);
    }

    /** Per unit: employees of grade 1-5, and how many Penilai 1 / Penilai 2 accounts cover them. */
    /** Section keys whose Assessment 1 employees ALL follow a special route (e.g. DIES -> Iffan / Chen Hsin Chung). */
    public static function routedOnlyUnits(): array
    {
        return self::activeEmployees()->filter(fn (Employee $e) => self::isAssessed($e))
            ->groupBy(fn (Employee $e) => self::key(self::unitOf($e)))
            ->filter(fn ($list) => $list->every(fn (Employee $e) => self::routeFor($e) !== null))
            ->keys()->all();
    }

    public static function coverage(): array
    {
        $reviewers = User::query()->where('role', Roles::REVIEWER)->get();
        // employees in the normal flow only: people on a special route need no department account
        $counts    = self::activeEmployees()->filter(fn (Employee $e) => self::isAssessed($e) && self::routeFor($e) === null)
            ->countBy(fn (Employee $e) => self::key(self::unitOf($e)));
        $rows      = [];

        foreach (self::allUnits() as $unit) {
            $row = ['group' => $unit, 'employees' => (int) ($counts[self::key($unit)] ?? 0)];

            foreach ([self::STAGE_ASSESS, self::STAGE_APPROVE] as $stage) {
                $row['stage' . $stage] = $reviewers->filter(
                    fn (User $u) => in_array($stage, self::stagesFor($u), true)
                        && in_array(self::key($unit), array_map([self::class, 'key'], self::userGroups($u)), true)
                )->count();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** All active employees: read on most pages, so kept in RefCache (flushed whenever employees change). */
    public static function activeEmployees(): Collection
    {
        return self::$active ??= RefCache::remember('employees', 'active', fn () => Employee::query()
            ->where('status', 'Active')
            ->orderBy('department')->orderBy('section')->orderBy('name')
            ->get());
    }

    /** Forget the per-request copy too (after a write in the same request). */
    public static function forgetActive(): void
    {
        self::$active   = null;
        self::$routeMap = null;
    }

    /** Assessment 2 duties of an account for the menu: ['assess' => bool, 'approve' => bool]. */
    public static function a2Duties(User $user): array
    {
        return RefCache::remember('a2', "duties:{$user->id}", fn () => [
            'assess'  => \App\Models\A2Participant::where('active', true)->where('assessor_id', $user->id)->exists(),
            'approve' => \App\Models\A2Participant::where('approver_id', $user->id)->exists(),
        ]);
    }
}
