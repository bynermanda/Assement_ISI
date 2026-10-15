<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\Assessment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Tugas Saya": everything an account has to do this period, in one place (Assessment 1 and 2, assess and approve,
 * and what was sent back). Each task: what, how many open, how many done, the first names, one button.
 *
 * The database is far away (each round trip costs 0.3-0.7 s from the office network), so the page runs three
 * queries only: all relevant Assessment 1 rows, the account's Assessment 2 participants, and their Assessment 2 rows.
 * Employee names come from the cached employee list.
 */
final class MyTasks
{
    private const WAITING = [Assessment::STATUS_PENDING, Assessment::STATUS_VERIFIED];

    /** @return array{period: string, tasks: array, sentBack: Collection, open: int} */
    public static function for(User $user): array
    {
        $period  = now()->format('Y-m');
        $stages  = ReviewRules::dutyStages($user);
        $people  = ReviewRules::activeEmployees()->keyBy('id_employee');
        $name    = fn (string $id) => $people->get($id)?->name ?? $id;
        $tasks   = [];

        // ---------- Assessment 1: one query for assess + approve + sent back
        $assessList  = in_array(ReviewRules::STAGE_ASSESS, $stages, true) ? ReviewRules::employeesFor($user, ReviewRules::STAGE_ASSESS) : collect();
        $approveList = in_array(ReviewRules::STAGE_APPROVE, $stages, true) ? ReviewRules::employeesFor($user, ReviewRules::STAGE_APPROVE) : collect();
        $a1Ids       = $assessList->pluck('id_employee')->merge($approveList->pluck('id_employee'))->unique()->values();

        $a1 = Assessment::query()
            ->where(fn ($q) => $q->where('created_by', $user->username)->where('status', Assessment::STATUS_REJECTED))
            ->when($a1Ids->isNotEmpty(), fn ($q) => $q->orWhere(fn ($w) => $w->whereIn('employee_id', $a1Ids)
                ->where(fn ($s) => $s->where('period', $period)->orWhereIn('status', self::WAITING))))
            ->orderBy('period')
            ->get();
        self::attachEmployees($a1, $people);

        if (in_array(ReviewRules::STAGE_ASSESS, $stages, true)) {
            $byId = $a1->where('period', $period)->keyBy('employee_id');
            $open = $assessList->filter(fn ($e) => ! $byId->has($e->id_employee) || in_array($byId[$e->id_employee]->status, Assessment::EDITABLE_STATUSES, true))->values();
            $bulk = ReviewRules::usesBulk($user);

            $tasks[] = [
                'key'    => 'a1-assess',
                'title'  => 'Penilaian karyawan',
                'what'   => 'Assessment 1 · Anda sebagai Penilai',
                'open'   => $open->count(),
                'total'  => $assessList->count(),
                'unit'   => 'karyawan belum selesai dinilai',
                'names'  => $open->take(5)->map(fn ($e) => ['name' => $e->name, 'status' => self::statusText($byId->get($e->id_employee)?->status)])->all(),
                'button' => [$bulk ? 'Buka Assessment Massal' : 'Mulai menilai', route($bulk ? 'assessments.bulk' : 'assessments.create')],
                'icon'   => 'pencil',
            ];
        }

        if (in_array(ReviewRules::STAGE_APPROVE, $stages, true)) {
            $scope   = $approveList->pluck('id_employee')->flip();
            $waiting = $a1->filter(fn ($a) => $scope->has($a->employee_id) && in_array($a->status, self::WAITING, true) && ReviewRules::mayApprove($user, $a))->values();
            $approved = $a1->filter(fn ($a) => $scope->has($a->employee_id) && $a->period === $period && $a->status === Assessment::STATUS_APPROVED)->count();

            $tasks[] = [
                'key'    => 'a1-approve',
                'title'  => 'Persetujuan penilaian',
                'what'   => 'Assessment 1 · Anda sebagai Approval',
                'open'   => $waiting->count(),
                'total'  => $approveList->count(),
                'done'   => $approved,
                'unit'   => 'penilaian menunggu persetujuan Anda',
                'names'  => $waiting->take(5)->map(fn ($a) => ['name' => $name($a->employee_id), 'status' => 'Menunggu persetujuan'])->all(),
                'button' => ['Buka daftar persetujuan', route('approval.index')],
                'icon'   => 'check',
            ];
        }

        // ---------- Assessment 2: one query for the participants, one for their assessments (+ sent back)
        $parts     = A2Participant::query()
            ->where(fn ($q) => $q->where('assessor_id', $user->id)->where('active', true))
            ->orWhere('approver_id', $user->id)
            ->get(['id', 'employee_id', 'assessor_id', 'approver_id', 'active']);
        $mineA2    = $parts->filter(fn ($p) => (int) $p->assessor_id === (int) $user->id && $p->active)->values();
        $approveA2 = $parts->filter(fn ($p) => (int) $p->approver_id === (int) $user->id)->pluck('employee_id')->values();
        $a2Ids     = $mineA2->pluck('employee_id')->merge($approveA2)->unique()->values();

        $a2 = A2Assessment::query()
            ->where(fn ($q) => $q->where('created_by', $user->username)->where('status', Assessment::STATUS_REJECTED))
            ->when($a2Ids->isNotEmpty(), fn ($q) => $q->orWhere(fn ($w) => $w->whereIn('employee_id', $a2Ids)
                ->where(fn ($s) => $s->where('period', $period)->orWhere('status', Assessment::STATUS_PENDING))))
            ->orderBy('period')
            ->get();

        if ($mineA2->isNotEmpty()) {
            $byId = $a2->where('period', $period)->keyBy('employee_id');
            $open = $mineA2->filter(fn ($p) => ! $byId->has($p->employee_id) || $byId[$p->employee_id]->isEditable())->values();

            $tasks[] = [
                'key'    => 'a2-assess',
                'title'  => 'Penilaian pimpinan',
                'what'   => 'Assessment 2 · Anda sebagai Penilai',
                'open'   => $open->count(),
                'total'  => $mineA2->count(),
                'unit'   => 'pimpinan belum selesai dinilai',
                'names'  => $open->take(5)->map(fn ($p) => ['name' => $name($p->employee_id), 'status' => self::statusText($byId->get($p->employee_id)?->status)])->all(),
                'button' => ['Mulai menilai', route('a2.index')],
                'icon'   => 'pencil',
            ];
        }

        if ($approveA2->isNotEmpty()) {
            $scope    = $approveA2->flip();
            $waiting  = $a2->filter(fn ($a) => $scope->has($a->employee_id) && $a->status === Assessment::STATUS_PENDING && $a->created_by !== $user->username)->values();
            $approved = $a2->filter(fn ($a) => $scope->has($a->employee_id) && $a->period === $period && $a->status === Assessment::STATUS_APPROVED)->count();

            $tasks[] = [
                'key'    => 'a2-approve',
                'title'  => 'Persetujuan pimpinan',
                'what'   => 'Assessment 2 · Anda sebagai Approval',
                'open'   => $waiting->count(),
                'total'  => $approveA2->count(),
                'done'   => $approved,
                'unit'   => 'penilaian menunggu persetujuan Anda',
                'names'  => $waiting->take(5)->map(fn ($a) => ['name' => $name($a->employee_id), 'status' => 'Menunggu persetujuan'])->all(),
                'button' => ['Buka daftar persetujuan', route('a2.approvals')],
                'icon'   => 'check',
            ];
        }

        foreach ($tasks as &$t) {
            $t['done'] ??= $t['total'] - $t['open'];
            // approvals: people not sent yet by their Penilai (nothing the approver can do about it yet)
            $t['notSent'] = str_ends_with($t['key'], 'approve') ? max(0, $t['total'] - $t['done'] - $t['open']) : 0;
        }
        unset($t);

        // ---------- sent back by the approver: most urgent
        $sentBack = $a1->filter(fn ($a) => $a->created_by === $user->username && $a->status === Assessment::STATUS_REJECTED)
            ->map(fn ($a) => ['name' => $name($a->employee_id), 'note' => $a->rejection_note, 'url' => route('assessments.create', ['employee' => $a->employee_id]), 'which' => 'Assessment 1'])
            ->concat($a2->filter(fn ($a) => $a->created_by === $user->username && $a->status === Assessment::STATUS_REJECTED)
                ->map(fn ($a) => ['name' => $name($a->employee_id), 'note' => $a->rejection_note, 'url' => route('a2.form', $a->employee_id), 'which' => 'Assessment 2']))
            ->values();

        return [
            'period'   => $period,
            'tasks'    => $tasks,
            'sentBack' => $sentBack,
            'open'     => array_sum(array_column($tasks, 'open')),
        ];
    }

    /** Employee relation from the cached list (needed by ReviewRules::mayApprove) without another query. */
    private static function attachEmployees(Collection $rows, Collection $people): void
    {
        $missing = $rows->pluck('employee_id')->unique()->reject(fn ($id) => $people->has($id))->values();
        $extra   = $missing->isEmpty() ? collect() : Employee::whereIn('id_employee', $missing)->get()->keyBy('id_employee');   // resigned people only
        foreach ($rows as $row) {
            $row->setRelation('employee', $people->get($row->employee_id) ?? $extra->get($row->employee_id));
        }
    }

    public static function statusText(?string $status): string
    {
        return match ($status) {
            null                          => 'Belum dinilai',
            Assessment::STATUS_DRAFT      => 'Draf, belum dikirim',
            Assessment::STATUS_REJECTED   => 'Perlu diperbaiki',
            Assessment::STATUS_PENDING,
            Assessment::STATUS_VERIFIED   => 'Menunggu persetujuan',
            Assessment::STATUS_APPROVED   => 'Disetujui',
            default                       => $status,
        };
    }
}
