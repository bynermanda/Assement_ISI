<?php

namespace App\Console\Commands;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\Assessment;
use App\Models\Employee;
use App\Support\ReviewRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dummy assessments for trying out the exports / recap (NOT a seeder: db:seed must never run on this database).
 *
 *   php artisan trial:assessments status             how many trial rows exist
 *   php artisan trial:assessments seed               fill the last 6 months (ending this month)
 *   php artisan trial:assessments purge              delete every trial row again
 *
 * Every row is marked created_by = 'trial-seeder', so purge only ever deletes trial rows. An employee who already has
 * a real assessment in a month is skipped for that month: real data is never overwritten.
 * Assessment 1 = every active employee in Assessment 1; Assessment 2 = every active participant with criteria.
 * Each employee gets a performance level; every month each criterion gets A/B/C around it (D rarely; more often for
 * ~10% weaker employees) and the final score is computed with the real weights, so the averages spread over A-D.
 * Per employee and month: --approved % Approved, --pending % Pending, the rest left empty (not assessed).
 */
class TrialAssessments extends Command
{
    public const MARK = 'trial-seeder';

    protected $signature = 'trial:assessments
        {action=status : seed | purge | status}
        {--months=6 : number of months, ending with --to}
        {--to= : last month YYYY-MM (default: this month)}
        {--approved=80 : % of months Approved}
        {--pending=10 : % of months Pending (the rest stays empty)}
        {--seed= : random seed, for the same data again}
        {--force : no confirmation question}';

    protected $description = 'Dummy (trial) assessments for testing exports: seed, purge or status';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'seed'   => $this->seed(),
            'purge'  => $this->purge(),
            'status' => $this->status(),
            default  => $this->invalid(),
        };
    }

    private function seed(): int
    {
        $to = (string) ($this->option('to') ?: now()->format('Y-m'));
        if (! Assessment::isPeriod($to)) {
            $this->error('--to must be YYYY-MM.');
            return self::FAILURE;
        }
        $months   = max(1, min(24, (int) $this->option('months')));
        $approved = max(0, min(100, (int) $this->option('approved')));
        $pending  = max(0, min(100 - $approved, (int) $this->option('pending')));
        $periods  = collect(range($months - 1, 0))->map(fn ($i) => Carbon::createFromFormat('!Y-m', $to)->subMonthsNoOverflow($i)->format('Y-m'))->all();
        if ($this->option('seed') !== null) {
            mt_srand((int) $this->option('seed'));
        }

        $a1 = ReviewRules::activeEmployees()->filter(fn (Employee $e) => ReviewRules::isAssessed($e))->values();
        $a2 = A2Participant::with('unit.criteria')->where('active', true)->get()->filter(fn ($p) => $p->unit && $p->unit->criteria->isNotEmpty())->values();

        $this->info('Data trial: ' . reset($periods) . ' s/d ' . end($periods) . " ({$months} bulan) · {$a1->count()} karyawan A1 · {$a2->count()} peserta A2");
        $this->line("Per bulan: {$approved}% Approved, {$pending}% Pending, " . (100 - $approved - $pending) . '% kosong. Penilaian asli tidak ditimpa.');
        if (! $this->option('force') && ! $this->confirm('Isi data trial ke database sekarang?', true)) {
            return self::SUCCESS;
        }

        $now   = now();
        $taken = Assessment::whereIn('period', $periods)->get(['employee_id', 'period'])->map(fn ($a) => "{$a->employee_id}|{$a->period}")->flip();
        $taken2 = A2Assessment::whereIn('period', $periods)->get(['employee_id', 'period'])->map(fn ($a) => "{$a->employee_id}|{$a->period}")->flip();
        $status = function () use ($approved, $pending) {
            $roll = mt_rand(1, 100);
            return $roll <= $approved ? Assessment::STATUS_APPROVED : ($roll <= $approved + $pending ? Assessment::STATUS_PENDING : null);
        };
        // ~88% of employees: only A / B / C on each criterion, so final scores stay 60-100;
        // ~12% "weak" ones get D on a criterion 35% of the time, so some averages end up D.
        // (Simulated: 6-month averages spread roughly A 30% · B 27% · C 30% · D 13%.)
        $profile = fn () => ($weak = mt_rand(1, 100) <= 12) ? [mt_rand(45, 60), true] : [mt_rand(68, 99), false];
        $grade = function (array $profile): int {
            [$level, $weak] = $profile;
            if ($weak && mt_rand(1, 100) <= 35) {
                return 0;
            }
            $v = $level + mt_rand(-7, 7);
            return $v >= 88 ? 100 : ($v >= 72 ? 80 : 60);
        };
        $note = json_encode([Assessment::NOTE_GENERAL => 'Data uji coba (trial), akan dihapus.'], JSON_UNESCAPED_UNICODE);

        // ---- Assessment 1
        $rows = []; $skipped = 0;
        foreach ($a1 as $e) {
            $level = $profile();
            foreach ($periods as $p) {
                if ($taken->has("{$e->id_employee}|{$p}")) { $skipped++; continue; }
                if (! $st = $status()) continue;
                $scores = [];
                foreach (array_keys(Assessment::CRITERIA) as $column) {
                    $scores[$column] = $grade($level);
                }
                $rows[] = ['employee_id' => $e->id_employee, 'period' => $p] + $scores + [
                    'final_score' => Assessment::calculateFinalScore($scores), 'status' => $st, 'notes' => $note,
                    'created_by' => self::MARK, 'approved_by' => $st === Assessment::STATUS_APPROVED ? self::MARK : null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        // ---- Assessment 2
        $rows2 = []; $skipped2 = 0;
        foreach ($a2 as $p) {
            $level    = $profile();
            $criteria = $p->unit->criteria;
            $snapshot = json_encode(A2Assessment::snapshotOf($criteria), JSON_UNESCAPED_UNICODE);
            foreach ($periods as $period) {
                if ($taken2->has("{$p->employee_id}|{$period}")) { $skipped2++; continue; }
                if (! $st = $status()) continue;
                $scores = $criteria->mapWithKeys(fn ($c) => [$c->id => $grade($level)])->all();
                $rows2[] = ['unit_id' => $p->unit_id, 'employee_id' => $p->employee_id, 'period' => $period,
                    'scores' => json_encode($scores), 'criteria_snapshot' => $snapshot,
                    'final_score' => A2Assessment::calculateFinalScore($criteria, $scores), 'status' => $st, 'notes' => $note,
                    'created_by' => self::MARK, 'approved_by' => $st === Assessment::STATUS_APPROVED ? self::MARK : null,
                    'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::transaction(function () use ($rows, $rows2) {
            foreach (array_chunk($rows, 500) as $chunk) DB::table('assessments')->insert($chunk);
            foreach (array_chunk($rows2, 300) as $chunk) DB::table('a2_assessments')->insert($chunk);
        });

        $this->table(['', 'Assessment 1', 'Assessment 2'], [
            ['Approved', $this->count($rows, 'Approved'), $this->count($rows2, 'Approved')],
            ['Pending', $this->count($rows, 'Pending'), $this->count($rows2, 'Pending')],
            ['Dilewati (sudah ada penilaian asli)', $skipped, $skipped2],
        ]);
        $this->info('Selesai. Hapus lagi dengan: php artisan trial:assessments purge');

        return self::SUCCESS;
    }

    private function purge(): int
    {
        [$n1, $n2] = [Assessment::where('created_by', self::MARK)->count(), A2Assessment::where('created_by', self::MARK)->count()];
        if ($n1 + $n2 === 0) {
            $this->info('Tidak ada data trial.');
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Hapus {$n1} penilaian A1 dan {$n2} penilaian A2 bertanda trial? (data asli tidak disentuh)", true)) {
            return self::SUCCESS;
        }
        DB::transaction(function () {
            Assessment::where('created_by', self::MARK)->delete();
            A2Assessment::where('created_by', self::MARK)->delete();
        });
        $this->info("Dihapus: {$n1} penilaian A1, {$n2} penilaian A2.");

        return self::SUCCESS;
    }

    private function status(): int
    {
        $rows = fn ($q) => $q->where('created_by', self::MARK)->selectRaw('period, status, count(*) as n')->groupBy('period', 'status')->orderBy('period')->get();
        $out  = [];
        foreach (['A1' => $rows(Assessment::query()), 'A2' => $rows(A2Assessment::query())] as $type => $list) {
            foreach ($list as $r) $out[] = [$type, $r->period, $r->status, $r->n];
        }
        $out === [] ? $this->info('Tidak ada data trial.') : $this->table(['Jenis', 'Periode', 'Status', 'Jumlah'], $out);

        return self::SUCCESS;
    }

    private function count(array $rows, string $status): int
    {
        return count(array_filter($rows, fn ($r) => $r['status'] === $status));
    }

    private function invalid(): int
    {
        $this->error('Pilih: seed, purge atau status.');
        return self::FAILURE;
    }
}
