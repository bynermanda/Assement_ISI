<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One person, one account: moves everything of $source into $target and deletes $source.
 *   - Assessment 1 duty: stage (Penilai 1 / 2 / both) and sections are combined.
 *   - Assessment 2 duty: participants assessed / approved by $source go to $target.
 *   - History: created_by / approved_by / edited_by of both assessment tables point to $target.
 * The target keeps its own username, name, NIK and password.
 */
final class AccountMerger
{
    /**
     * Reasons the merge is not allowed; empty when it is fine.
     * $allowSelf: a department head may score himself in Assessment 2 (the approver, GM / Director, still decides).
     */
    public static function problems(User $source, User $target, bool $allowSelf = false): array
    {
        $problems = [];

        if ($source->is($target)) {
            return ['Pilih dua akun yang berbeda.'];
        }
        if (in_array(Roles::ADMIN, [$source->role, $target->role], true)) {
            $problems[] = 'Akun admin tidak bisa digabung.';
        }

        $ids = [$source->id, $target->id];
        $nik = ReviewRules::normalizeNik($target->nik ?: $source->nik);

        foreach (A2Participant::with('employee')->where(fn ($q) => $q->whereIn('assessor_id', $ids)->orWhereIn('approver_id', $ids))->get() as $p) {
            $assessor = in_array($p->assessor_id, $ids, true) ? 'merged' : $p->assessor_id;
            $approver = in_array($p->approver_id, $ids, true) ? 'merged' : $p->approver_id;
            $name     = $p->employee?->name ?? $p->employee_id;

            if ($assessor === 'merged' && $approver === 'merged') {
                $problems[] = "Assessment 2 {$name}: akun gabungan akan menjadi Penilai sekaligus Approver.";
            }
            if ($nik !== '' && ReviewRules::normalizeNik($p->employee_id) === $nik) {
                if ($approver === 'merged') {
                    $problems[] = "Assessment 2 {$name}: orang ini akan menyetujui dirinya sendiri.";
                } elseif (! $allowSelf) {
                    $problems[] = "Assessment 2 {$name}: orang ini akan menilai dirinya sendiri (centang \"Izinkan menilai diri sendiri\" jika memang disengaja).";
                }
            }
        }

        return $problems;
    }

    public static function merge(User $source, User $target): void
    {
        DB::transaction(function () use ($source, $target) {
            $stages = array_unique([...ReviewRules::stagesFor($source), ...ReviewRules::stagesFor($target)]);
            $groups = array_values(array_unique([...ReviewRules::userGroups($target), ...ReviewRules::userGroups($source)]));
            $stage  = match (true) {
                count($stages) === 2       => ReviewRules::STAGE_BOTH,
                $stages === []             => null,
                default                    => reset($stages),
            };

            $target->forceFill([
                // an Assessment 1 duty needs the reviewer pages; Assessment 2 works with any role
                'role'      => $stage !== null ? Roles::REVIEWER : $target->role,
                'stage'     => $stage,
                'groups'    => $groups === [] ? null : json_encode($groups),
                'nik'       => $target->nik ?: $source->nik,
                'job_title' => $target->job_title ?: $source->job_title,
            ])->save();

            A2Participant::where('assessor_id', $source->id)->update(['assessor_id' => $target->id]);
            A2Participant::where('approver_id', $source->id)->update(['approver_id' => $target->id]);
            \App\Models\A1Route::where('assessor_id', $source->id)->update(['assessor_id' => $target->id]);
            \App\Models\A1Route::where('approver_id', $source->id)->update(['approver_id' => $target->id]);

            self::moveHistory($source->username, $target->username);

            $source->delete();
        });

        RefCache::flush('a2', 'routes');   // moved with queries, not through the models
        ReviewRules::forgetActive();
    }

    /** Assessments store the username (created_by / approved_by / ...): point them to another username. */
    public static function moveHistory(string $from, string $to): void
    {
        foreach (['created_by', 'verified_by', 'approved_by', 'edited_by'] as $column) {
            Assessment::where($column, $from)->update([$column => $to]);
        }
        foreach (['created_by', 'approved_by'] as $column) {
            A2Assessment::where($column, $from)->update([$column => $to]);
        }
    }

    /** New login name; the history follows. */
    public static function rename(User $user, string $username): void
    {
        DB::transaction(function () use ($user, $username) {
            self::moveHistory($user->username, $username);
            $user->forceFill(['username' => $username])->save();
        });
    }

    /**
     * Unmerge: a new account takes part of the duties of $source.
     *   $sections  : Assessment 1 sections to move (they keep $stage, default the source's step)
     *   $assessing / $approving : a2_participants ids whose Penilai / Approver becomes the new account
     * History stays with $source. Returns the new account.
     */
    public static function split(User $source, array $account, array $sections, ?int $stage, array $assessing, array $approving): User
    {
        return DB::transaction(function () use ($source, $account, $sections, $stage, $assessing, $approving) {
            $keep   = array_values(array_diff(ReviewRules::userGroups($source), $sections));
            $stage ??= $source->stage ? (int) $source->stage : ReviewRules::STAGE_ASSESS;

            $new = (new User())->forceFill([
                'username'             => $account['username'],
                'name'                 => $account['name'],
                'nik'                  => $account['nik'] ?? null,
                'job_title'            => $account['job_title'] ?? null,
                'password'             => $account['password'],
                'must_change_password' => false,
                'role'                 => $sections !== [] ? Roles::REVIEWER : Roles::ASSESSOR,
                'stage'                => $sections !== [] ? $stage : null,
                'groups'               => $sections !== [] ? json_encode(array_values($sections)) : null,
            ]);
            $new->save();

            if ($sections !== []) {
                // an account without Assessment 1 sections must not stay a "reviewer" (it would look outdated)
                $source->forceFill($keep === []
                    ? ['groups' => null, 'stage' => null, 'role' => Roles::ASSESSOR]
                    : ['groups' => json_encode($keep)])->save();
            }

            A2Participant::whereIn('id', $assessing)->where('assessor_id', $source->id)->update(['assessor_id' => $new->id]);
            A2Participant::whereIn('id', $approving)->where('approver_id', $source->id)->update(['approver_id' => $new->id]);
            RefCache::flush('a2', 'routes');

            return $new;
        });
    }

    /** Problems of a split: the new account must not approve himself, or assess and approve the same person. */
    public static function splitProblems(User $source, ?string $nik, array $assessing, array $approving): array
    {
        $problems = [];
        $nik      = ReviewRules::normalizeNik($nik);

        foreach (A2Participant::with('employee')->whereIn('id', array_merge($assessing, $approving))->get() as $p) {
            $name = $p->employee?->name ?? $p->employee_id;
            if (in_array($p->id, $assessing, true) && in_array($p->id, $approving, true)) {
                $problems[] = "{$name}: akun baru akan menjadi Penilai sekaligus Approver.";
            }
            if (in_array($p->id, $approving, true) && $nik !== '' && ReviewRules::normalizeNik($p->employee_id) === $nik) {
                $problems[] = "{$name}: akun baru akan menyetujui dirinya sendiri.";
            }
        }

        return $problems;
    }
}
