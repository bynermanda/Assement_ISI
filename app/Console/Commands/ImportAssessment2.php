<?php

namespace App\Console\Commands;

use App\Models\A2Criterion;
use App\Models\A2Participant;
use App\Models\A2Unit;
use App\Models\Assessment;
use App\Models\Employee;
use App\Models\User;
use App\Support\ReviewRules;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the filled "Template Assessment 2 per Departemen" workbook: one sheet per unit with
 *   A. criteria (group, names, weight, target, remark)   B. participants with their assessor and approver.
 * Creates / updates units, criteria, participants and one "assessor" account per person. Safe to run again.
 */
class ImportAssessment2 extends Command
{
    protected $signature = 'a2:import {file : Path of the filled workbook} {--dry-run : Show what would happen, write nothing}
                            {--password= : Password for NEW accounts (default: DEFAULT_ACCOUNT_PASSWORD from .env)}';

    protected $description = 'Import Assessment 2 criteria, participants and accounts from the HRD workbook';

    private const SKIP_SHEETS = ['petunjuk', 'ringkasan', 'pengaturan', 'contoh', 'departemen baru', 'daftar pimpinan'];

    /** Sheet department value => [unit name, employees.department]. Others keep their own name. */
    private const UNIT_NAMES = [
        'QA'         => ['QA - Customer Service', 'QA'],
        'QA - AUDIT' => ['QA - Audit', 'QA'],
        'ADVISOR'    => ['ADVISOR', null],
    ];

    private array $warnings = [];
    private array $people   = [];   // key => [username, name, title, nik, kind, units[]]

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $book      = IOFactory::load($file);
        $employees = Employee::all()->keyBy(fn ($e) => ReviewRules::normalizeNik($e->id_employee));
        $units     = [];
        $seen      = [];   // participant NIK => unit, to catch the same person active twice

        $this->checkSettings($book->getSheetByName('Pengaturan'));

        foreach ($book->getAllSheets() as $sort => $sheet) {
            if (in_array(mb_strtolower(trim($sheet->getTitle())), self::SKIP_SHEETS, true)) {
                continue;
            }

            $raw = trim((string) $sheet->getCell('B3')->getValue());
            if ($raw === '') {
                continue;
            }
            [$unitName, $department] = self::UNIT_NAMES[mb_strtoupper($raw)] ?? [$raw, $raw];

            $criteria = $this->readCriteria($sheet, $unitName);
            $total    = round(array_sum(array_column($criteria, 'weight')), 2);
            if ($criteria !== [] && abs($total - 100) > 0.001) {
                $this->warn2("{$unitName}: total bobot {$total}% (harus 100%).");
            }

            $participants = [];
            foreach ($this->readParticipants($sheet) as $p) {
                if (mb_strtolower($p['join']) !== 'ya') {
                    continue;
                }
                $key      = ReviewRules::normalizeNik($p['nik']);
                $employee = $employees->get($key);
                if (! $employee) {
                    $this->warn2("{$unitName}: NIK {$p['nik']} ({$p['name']}) tidak ada di daftar karyawan, dilewati.");
                    continue;
                }
                if ($employee->status !== 'Active') {
                    $this->warn2("{$unitName}: {$employee->name} berstatus {$employee->status}.");
                }
                if (isset($seen[$key])) {
                    $this->warn2("{$employee->name} ikut A2 di dua sheet ({$seen[$key]} dan {$unitName}); dipakai {$unitName}.");
                }
                $seen[$key] = $unitName;

                $current  = A2Participant::with(['assessor', 'approver'])->where('employee_id', $employee->id_employee)->first();
                $assessor = $this->person($p['assessor'], $employees, $unitName, 'penilai', $current?->assessor);
                $approver = $this->person($p['approver'], $employees, $unitName, 'approver', $current?->approver);

                if ($assessor === $approver) {
                    $this->warn2("{$unitName}: penilai dan approver {$employee->name} orang yang sama ({$this->people[$assessor]['name']}).");
                }
                foreach (['penilai' => $assessor, 'approver' => $approver] as $role => $who) {
                    if (($this->people[$who]['nik'] ?? null) && ReviewRules::normalizeNik($this->people[$who]['nik']) === $key) {
                        // a department head scoring himself is intended; approving himself never is
                        $this->warn2($role === 'approver'
                            ? "{$unitName}: {$employee->name} menjadi APPROVER untuk dirinya sendiri, harus diganti."
                            : "{$unitName}: {$employee->name} menilai dirinya sendiri (sesuai konsep; approver {$this->people[$approver]['name']}).");
                    }
                }

                $participants[] = ['employee_id' => $employee->id_employee, 'name' => $employee->name, 'assessor' => $assessor, 'approver' => $approver];
            }

            if ($criteria === [] && $participants === []) {
                $this->line("  (lewati {$sheet->getTitle()}: tidak ada kriteria dan peserta)");
                continue;
            }
            if ($criteria === [] && $participants !== []) {
                $this->warn2("{$unitName}: ada peserta tetapi belum ada kriteria.");
            }

            $units[$unitName] = compact('unitName', 'department', 'sort', 'criteria', 'participants', 'total');
        }

        $this->report($units);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written.');
            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: config('assessment.default_password'));
        if ($password === '') {
            $this->error('No password for new accounts: pass --password= or set DEFAULT_ACCOUNT_PASSWORD in .env.');

            return self::FAILURE;
        }
        $this->write($units, $password);

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ reading */

    private function readCriteria(Worksheet $sheet, string $unit): array
    {
        $head = $this->findRow($sheet, fn ($r) => $this->text($sheet, "A{$r}") === 'No' && str_starts_with($this->text($sheet, "B{$r}"), 'Grup'));
        if (! $head) {
            return [];
        }

        // Column B merges = groups (like the merged 工作品質 cell over two criteria)
        $merged = [];
        foreach ($sheet->getMergeCells() as $range) {
            if (preg_match('/^B(\d+):B(\d+)$/', $range, $m)) {
                for ($r = (int) $m[1]; $r <= (int) $m[2]; $r++) {
                    $merged[$r] = (int) $m[1];
                }
            }
        }

        $rows  = [];
        // A group written in one unmerged cell also covers the NEXT criterion when that one has no group of its own
        // (confirmed by HRD: 年度計畫 over KPI + planning, 工作品質 over the two quality items, 出勤表現 over 出勤率 + 上崗作業).
        $carry = null;
        for ($r = $head + 1; $r <= $head + 40; $r++) {
            if ($this->text($sheet, "E{$r}") === 'Total bobot') {
                break;
            }
            $nameId = $this->clean($sheet->getCell("E{$r}")->getValue());
            $nameZh = $this->clean($sheet->getCell("D{$r}")->getValue());
            if ($nameId === '' && $nameZh === '') {
                continue;
            }

            $top   = $merged[$r] ?? null;
            $group = $top !== null ? $this->group($sheet, $top) : null;
            if ($group === null && $this->clean($sheet->getCell("B{$r}")->getValue()) !== '') {
                $group = $this->group($sheet, $r);
                $carry = $group;
            } elseif ($group === null && $carry !== null) {
                $group = $carry;     // the one criterion right below an unmerged group cell
                $carry = null;
            } else {
                $carry = null;
            }

            // Known slip in the HRD sheet: 工作態度 is "Sikap Kerja", not "Efisiensi Kerja" (confirmed)
            if ($nameZh === '工作態度' && $nameId === 'Efisiensi Kerja') {
                $nameId = 'Sikap Kerja';
                $this->warn2("{$unit}: 工作態度 \"Efisiensi Kerja\" dikoreksi menjadi \"Sikap Kerja\".");
            }

            $weight = (float) $sheet->getCell("F{$r}")->getCalculatedValue();
            $weight = $weight <= 1 ? $weight * 100 : $weight;   // 0.15 (=15%) or 15
            if ($weight == 0) {
                $this->warn2("{$unit}: kriteria \"" . Str::limit($nameId ?: $nameZh, 40) . '" berbobot 0%.');
            }

            $rows[] = [
                'group_zh' => $group[0] ?? null,
                'group_id' => $group[1] ?? null,
                'name_zh'  => $nameZh ?: null,
                'name_id'  => $nameId ?: $nameZh,
                'weight'   => round($weight, 2),
                'target'   => $this->clean($sheet->getCell("G{$r}")->getValue(), true) ?: null,
                'remark'   => $this->clean($sheet->getCell("H{$r}")->getValue(), true) ?: null,
            ];
        }

        return $rows;
    }

    /** [zh, id] of the group cell in row $r: column B (Chinese) and C (Indonesian); B may hold both on two lines. */
    private function group(Worksheet $sheet, int $r): ?array
    {
        $b = $this->clean($sheet->getCell("B{$r}")->getValue(), true);
        $c = $this->clean($sheet->getCell("C{$r}")->getValue());
        if ($b === '' && $c === '') {
            return null;
        }
        if ($c === '' && str_contains($b, "\n")) {
            $lines = explode("\n", $b);
            $zh    = implode(' ', array_filter($lines, fn ($l) => preg_match('/\p{Han}/u', $l)));
            $id    = implode(' ', array_filter($lines, fn ($l) => ! preg_match('/\p{Han}/u', $l)));
            return [$zh ?: null, $id ?: null];
        }

        return [str_replace("\n", ' ', $b) ?: null, $c ?: null];
    }

    private function readParticipants(Worksheet $sheet): array
    {
        $head = $this->findRow($sheet, fn ($r) => $this->text($sheet, "A{$r}") === 'No' && $this->text($sheet, "C{$r}") === 'Nama');
        if (! $head) {
            return [];
        }

        $rows = [];
        for ($r = $head + 1; $r <= $head + 60; $r++) {
            if (str_starts_with($this->text($sheet, "C{$r}"), 'Peserta ikut')) {
                break;
            }
            $nik  = $this->text($sheet, "B{$r}");
            $name = $this->text($sheet, "C{$r}");
            if ($nik === '' && $name === '') {
                continue;
            }
            $rows[] = [
                'nik'      => $nik,
                'name'     => $name,
                'join'     => $this->text($sheet, "G{$r}") ?: 'Ya',
                'assessor' => ['name' => $this->text($sheet, "H{$r}"), 'title' => $this->text($sheet, "I{$r}"), 'status' => $this->text($sheet, "J{$r}"), 'nik' => $this->text($sheet, "K{$r}")],
                'approver' => ['name' => $this->text($sheet, "L{$r}"), 'title' => $this->text($sheet, "M{$r}"), 'status' => $this->text($sheet, "N{$r}"), 'nik' => $this->text($sheet, "O{$r}")],
            ];
        }

        return $rows;
    }

    /**
     * Key of the account for an assessor / approver. Employees are matched by NIK (or exact name),
     * TKA and outsiders by name. An empty cell gives a temporary account for the unit.
     */
    private function person(array $p, $employees, string $unit, string $role, ?User $current = null): string
    {
        $name   = trim(preg_replace(['/\s+/', '/\b(Mr|Ms|Mrs)\s*\.\s*/i'], [' ', '$1. '], $p['name']));
        $status = mb_strtolower($p['status']);

        // Empty in the workbook but already assigned in the app (e.g. merged into the department's Penilai 2 account): keep it
        if ($name === '' && $current) {
            $key = "user:{$current->id}";
            $this->people[$key] ??= [
                'username' => $current->username, 'name' => $current->name, 'title' => $current->job_title,
                'nik' => $current->nik, 'kind' => 'Sudah ada', 'units' => [], 'existing' => true,
            ];
            $this->people[$key]['units'][$unit] = $role;
            return $key;
        }

        if ($name === '') {
            $key = "temp:{$unit}:{$role}";
            $this->people[$key] ??= [
                'username' => 'a2.' . Str::slug($unit, '-') . '.' . $role,
                'name'     => ($role === 'penilai' ? 'Penilai' : 'Approver') . " A2 - {$unit} (sementara)",
                'title'    => null, 'nik' => null, 'kind' => 'Sementara', 'units' => [],
            ];
            $this->people[$key]['units'][$unit] = $role;
            $this->warn2("{$unit}: {$role} kosong, dibuat akun sementara {$this->people[$key]['username']}.");
            return $key;
        }

        $employee = null;
        if ($status === 'karyawan' || ($status === '' && $p['nik'] !== '')) {
            $employee = $employees->get(ReviewRules::normalizeNik($p['nik']))
                ?? $employees->first(fn ($e) => mb_strtolower($e->name) === mb_strtolower($name));
            if (! $employee) {
                $this->warn2("{$unit}: {$role} \"{$name}\" berstatus Karyawan tetapi NIK/nama tidak ditemukan; dibuat sebagai akun non-karyawan.");
            }
        } elseif ($p['nik'] !== '') {
            $this->warn2("{$unit}: {$role} \"{$name}\" berstatus {$p['status']} tetapi NIK {$p['nik']} diisi; NIK diabaikan.");
        }

        // An employee who already holds an account (personal, or a department account with his NIK as holder): use it
        if ($employee && ($holder = User::whereNotNull('nik')->get()->first(fn (User $u) => $u->role !== Roles::ADMIN
                && ReviewRules::normalizeNik($u->nik) === ReviewRules::normalizeNik($employee->id_employee)))) {
            return $this->person(['name' => '', 'title' => '', 'status' => '', 'nik' => ''], $employees, $unit, $role, $holder);
        }

        if ($employee) {
            $key  = 'nik:' . ReviewRules::normalizeNik($employee->id_employee);
            $base = $employee->name;
        } else {
            $base = trim(preg_replace('/^(Mr|Ms|Mrs)\.\s*/i', '', $name));
            $key  = 'name:' . mb_strtolower($base);
        }

        if (! isset($this->people[$key])) {
            $this->people[$key] = [
                'username' => $this->uniqueUsername('a2.' . Str::slug($base, '.'), $key),
                'name'     => $employee ? $employee->name : $name,
                'title'    => $p['title'] ? $this->fixTitle($p['title']) : null,
                'nik'      => $employee?->id_employee,
                'kind'     => $employee ? 'Karyawan' : ($status === 'tka' ? 'TKA' : 'Lainnya'),
                'units'    => [],
            ];
        }
        $this->people[$key]['units'][$unit] = isset($this->people[$key]['units'][$unit]) && $this->people[$key]['units'][$unit] !== $role ? 'penilai+approver' : $role;

        return $key;
    }

    private function uniqueUsername(string $base, string $key): string
    {
        $taken = array_column(array_filter($this->people, fn ($p, $k) => $k !== $key, ARRAY_FILTER_USE_BOTH), 'username');
        $name  = $base;
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = "{$base}{$i}";
        }

        return $name;
    }

    private function fixTitle(string $title): string
    {
        return str_ireplace('Managaer', 'Manager', $title);
    }

    private function checkSettings(?Worksheet $sheet): void
    {
        if (! $sheet) {
            return;
        }
        $grades = [];
        for ($r = 6; $r <= 9; $r++) {
            $grades[$this->text($sheet, "A{$r}")] = (int) $sheet->getCell("B{$r}")->getCalculatedValue();
        }
        if ($grades !== Assessment::GRADES) {
            $this->warn2('Pengaturan: nilai per kriteria berbeda dari Assessment 1 (' . json_encode($grades) . '); aplikasi tetap memakai A=100, B=80, C=60, D=0.');
        }
    }

    /* ------------------------------------------------------------------ writing */

    private function write(array $units, string $password): void
    {
        $hash = Hash::make($password);

        DB::transaction(function () use ($units, $hash) {
            $ids = [];
            foreach ($this->people as $key => $p) {
                if ($p['existing'] ?? false) {   // an account kept as it is (role, sections, password untouched)
                    $ids[$key] = (int) substr($key, 5);
                    continue;
                }
                $user = User::where('username', $p['username'])->first();
                if ($user) {
                    // existing account: never touch its role, Assessment 1 sections, name or password (it may have been merged)
                    $user->forceFill(['nik' => $user->nik ?: $p['nik'], 'job_title' => $user->job_title ?: $p['title']])->save();
                } else {
                    $user = (new User())->forceFill([
                        'username'             => $p['username'],
                        'name'                 => $p['name'],
                        'role'                 => Roles::ASSESSOR,
                        'nik'                  => $p['nik'],
                        'job_title'            => $p['title'],
                        'password'             => $hash,
                        'must_change_password' => false,
                    ]);
                    $user->save();
                }
                $ids[$key] = $user->id;
            }

            $keep = [];
            foreach ($units as $u) {
                $unit = A2Unit::updateOrCreate(['name' => $u['unitName']], ['department' => $u['department'], 'sort' => $u['sort']]);

                // criteria are updated in place by position, so their ids (the keys of saved scores) stay the same;
                // existing assessments also keep their own snapshot
                $old = A2Criterion::where('unit_id', $unit->id)->orderBy('sort')->orderBy('id')->get()->values();
                foreach (array_values($u['criteria']) as $i => $c) {
                    $attributes = $c + ['unit_id' => $unit->id, 'sort' => $i + 1];
                    isset($old[$i]) ? $old[$i]->update($attributes) : A2Criterion::create($attributes);
                }
                if ($old->count() > count($u['criteria'])) {
                    A2Criterion::whereIn('id', $old->slice(count($u['criteria']))->pluck('id'))->delete();
                }

                foreach ($u['participants'] as $p) {
                    A2Participant::updateOrCreate(['employee_id' => $p['employee_id']], [
                        'unit_id'     => $unit->id,
                        'assessor_id' => $ids[$p['assessor']],
                        'approver_id' => $ids[$p['approver']],
                        'active'      => true,
                    ]);
                    $keep[] = $p['employee_id'];
                }
            }

            // people no longer in the workbook stop being assessed (history stays)
            A2Participant::whereNotIn('employee_id', $keep)->update(['active' => false]);
        });

        \App\Support\RefCache::flush('a2', 'routes');

        $this->info(count($units) . ' units, ' . array_sum(array_map(fn ($u) => count($u['participants']), $units)) . ' participants and ' . count($this->people) . ' accounts saved.');
    }

    /* ------------------------------------------------------------------ helpers */

    private function report(array $units): void
    {
        $this->newLine();
        $this->table(['Unit', 'Department', 'Kriteria', 'Bobot', 'Peserta'], array_map(fn ($u) => [
            $u['unitName'], $u['department'] ?? '-', count($u['criteria']), $u['total'] . '%',
            implode(', ', array_column($u['participants'], 'name')) ?: '-',
        ], $units));

        $rows = [];
        foreach ($units as $u) {
            foreach ($u['participants'] as $p) {
                $rows[] = [$u['unitName'], $p['name'], $this->people[$p['assessor']]['username'], $this->people[$p['approver']]['username']];
            }
        }
        $this->table(['Unit', 'Peserta', 'Penilai (akun)', 'Approver (akun)'], $rows);

        $this->table(['Akun', 'Nama', 'Jabatan', 'Jenis', 'NIK', 'Tugas'], array_map(fn ($p) => [
            $p['username'], $p['name'], $p['title'] ?? '-', $p['kind'], $p['nik'] ?? '-',
            implode('; ', array_map(fn ($u, $r) => "{$r} {$u}", array_keys($p['units']), $p['units'])),
        ], array_values($this->people)));

        if ($this->warnings) {
            $this->warn('Catatan / perlu konfirmasi:');
            foreach (array_unique($this->warnings) as $w) {
                $this->line('  - ' . $w);
            }
        }
    }

    private function warn2(string $message): void
    {
        $this->warnings[] = $message;
    }

    private function findRow(Worksheet $sheet, callable $match): ?int
    {
        for ($r = 1; $r <= min(80, $sheet->getHighestRow()); $r++) {
            if ($match($r)) {
                return $r;
            }
        }

        return null;
    }

    private function text(Worksheet $sheet, string $cell): string
    {
        return $this->clean($sheet->getCell($cell)->getValue());
    }

    /** Trims every line, drops empty lines and stray "/" left from the template; one line unless $multiline. */
    private function clean(mixed $value, bool $multiline = false): string
    {
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l), " /\t"), $lines), fn ($l) => $l !== ''));

        return $multiline ? implode("\n", $lines) : implode(' ', $lines);
    }
}
