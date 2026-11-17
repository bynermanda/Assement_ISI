<?php

namespace App\Http\Controllers;

use App\Models\A2Assessment;
use App\Models\A2Criterion;
use App\Models\A2Participant;
use App\Models\A2Unit;
use App\Models\Assessment;
use App\Support\Attachments;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Assessment 2 (leaders). Every participant has his own Penilai (assessor) and Approver (a2_participants);
 * criteria, weights and targets come from his unit. Flow as Assessment 1: Draft -> Pending -> Approved / Rejected.
 */
class Assessment2Controller extends Controller
{
    /** Start page of an Assessment 2 account: the assessment list, or the approval list for approvers only. */
    public function home(): RedirectResponse
    {
        $me = Auth::id();

        if (A2Participant::where('active', true)->where('assessor_id', $me)->exists()) {
            return redirect()->route('a2.index');
        }

        return redirect()->route('a2.approvals');
    }

    /* ------------------------------------------------------------------ Penilai */

    public function index(): View
    {
        $period       = $this->period();
        $participants = $this->myParticipants('assessor_id');
        $assessments  = $this->assessmentsFor($participants, $period);

        return view('a2.index', [
            'periodLabel'  => $this->periodLabel($period),
            'participants' => $participants,
            'assessments'  => $assessments,
            'approvals'    => $this->pendingForApprover()->count(),
        ]);
    }

    public function form(string $employee): View|RedirectResponse
    {
        $participant = $this->participantFor($employee, 'assessor_id');
        $period      = $this->period();
        $existing    = A2Assessment::where('employee_id', $participant->employee_id)->where('period', $period)->first();

        if ($existing && ! $existing->isEditable()) {
            return redirect()->route('a2.show', $existing)->with('error', t('Penilaian ini sudah berstatus: :status.', ['status' => \App\Support\Ui::status($existing->status)]));
        }

        $criteria = $participant->unit->criteria;

        return view('a2.form', [
            'periodLabel' => $this->periodLabel($period),
            'participant' => $participant,
            'criteria'    => $criteria,
            'existing'    => $existing,
            'scores'      => $criteria->mapWithKeys(fn ($c) => [$c->id => (string) old("scores.{$c->id}", $existing?->scores[$c->id] ?? '')])->all(),
            'notes'       => $this->noteValues($criteria, $existing),
        ]);
    }

    public function store(Request $request, string $employee): RedirectResponse
    {
        $participant = $this->participantFor($employee, 'assessor_id');
        $period      = $this->period();
        $criteria    = $participant->unit->criteria;
        $submit      = $request->input('action') === 'submit';

        abort_if($criteria->isEmpty(), 422, t('Unit ini belum punya kriteria.'));

        $rules = ['action' => ['required', Rule::in(['draft', 'submit'])], 'notes' => ['nullable', 'array']];
        foreach ($criteria as $c) {
            $rules["scores.{$c->id}"] = [$submit ? 'required' : 'nullable', 'integer', Rule::in(array_values(Assessment::GRADES))];
            $rules["notes.c{$c->id}"] = ['nullable', 'string', 'max:' . Assessment::NOTE_MAX];
        }
        $rules['notes.' . Assessment::NOTE_GENERAL] = ['nullable', 'string', 'max:' . Assessment::NOTE_MAX];

        $data = $request->validate($rules + Attachments::rules(), ['scores.*.required' => t('Beri nilai semua kriteria sebelum mengirim.')] + Attachments::messages());

        $existing = A2Assessment::where('employee_id', $participant->employee_id)->where('period', $period)->first();
        if ($existing && ! $existing->isEditable()) {
            return redirect()->route('a2.index')->with('error', t('Penilaian ini sudah berstatus: :status.', ['status' => \App\Support\Ui::status($existing->status)]));
        }

        // checked before anything is saved: a refused file must not leave an already submitted assessment behind
        $attachments = Attachments::prepare($existing, $request);

        $scores = [];
        foreach ($criteria as $c) {
            if (($data['scores'][$c->id] ?? null) !== null) {
                $scores[$c->id] = (int) $data['scores'][$c->id];
            }
        }
        $notes = collect($data['notes'] ?? [])->map(fn ($t) => trim((string) $t))->filter()->all();

        $assessment = A2Assessment::updateOrCreate(
            ['employee_id' => $participant->employee_id, 'period' => $period],
            [
                'unit_id'           => $participant->unit_id,
                'scores'            => $scores,
                'criteria_snapshot' => A2Assessment::snapshotOf($criteria),
                'final_score'       => A2Assessment::calculateFinalScore($criteria, $scores),
                'status'            => $submit ? Assessment::STATUS_PENDING : Assessment::STATUS_DRAFT,
                'notes'             => $notes ?: null,
                'created_by'        => Auth::user()->username,
                'approved_by'       => null,
                'rejection_note'    => null,
            ]
        );

        Attachments::sync($assessment, $attachments, Auth::user()->username);

        return redirect()->route('a2.index')->with('success', $submit
            ? t('Penilaian :name sudah dikirim ke :approver.', ['name' => $participant->employee->name, 'approver' => $participant->approver?->name])
            : t('Draf :name disimpan.', ['name' => $participant->employee->name]));
    }

    /* ------------------------------------------------------------------ Approver */

    public function approvals(): View
    {
        return view('a2.approvals', [
            'assessments' => $this->pendingForApprover(),
            'assessing'   => $this->myParticipants('assessor_id')->count(),
        ]);
    }

    /** Approve only the ticked assessments, in one update; the rest keeps waiting. */
    public function approveSelected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ], ['ids.required' => t('Centang minimal satu penilaian untuk di-approve.')]);

        $me  = Auth::user();
        $ids = $this->pendingForApprover()
            ->whereIn('id', $data['ids'])
            ->reject(fn (A2Assessment $a) => $a->created_by === $me->username)   // Four-Eyes Principle
            ->pluck('id');

        $approved = $ids->isEmpty() ? 0 : A2Assessment::whereIn('id', $ids)
            ->where('status', Assessment::STATUS_PENDING)
            ->update(['status' => Assessment::STATUS_APPROVED, 'approved_by' => $me->username, 'rejection_note' => null, 'updated_at' => now()]);

        $skipped = count($data['ids']) - $approved;

        return redirect()->route('a2.approvals')->with($approved ? 'success' : 'error', $approved
            ? t(':n penilaian disetujui.', ['n' => $approved]) . ($skipped ? ' ' . t(':n dilewati (sudah tidak menunggu Anda).', ['n' => $skipped]) : '')
            : t('Tidak ada yang disetujui: penilaian yang dicentang sudah tidak menunggu Anda.'));
    }

    public function reject(Request $request, A2Assessment $a2Assessment): RedirectResponse
    {
        $data = $request->validate(['rejection_note' => ['required', 'string', 'min:5', 'max:1000']], [
            'rejection_note.required' => t('Tuliskan apa yang perlu diperbaiki.'),
        ]);

        abort_unless($this->pendingForApprover()->contains('id', $a2Assessment->id), 403, t('Penilaian ini tidak sedang menunggu Anda.'));

        $a2Assessment->update([
            'status'         => Assessment::STATUS_REJECTED,
            'rejection_note' => $data['rejection_note'] . ' (' . Auth::user()->name . ')',
            'approved_by'    => null,
        ]);

        return redirect()->route('a2.approvals')->with('success', t('Penilaian :name dikembalikan ke Penilai.', ['name' => $a2Assessment->employee?->name]));
    }

    /** Read-only view: for its Penilai, its Approver and the admin. The approver can decide from here. */
    public function show(A2Assessment $a2Assessment): View
    {
        $user        = Auth::user();
        $participant = A2Participant::with(['assessor', 'approver'])->where('employee_id', $a2Assessment->employee_id)->first();

        abort_unless($user->role === Roles::ADMIN
            || ($participant && in_array($user->id, [$participant->assessor_id, $participant->approver_id], true)), 403);

        $criteria = $a2Assessment->snapshotCriteria();

        return view('a2.show', [
            'assessment'  => $a2Assessment->load(['employee', 'unit', 'creator', 'approver']),
            'participant' => $participant,
            'criteria'    => $criteria,
            'scores'      => collect($a2Assessment->scores ?? [])->mapWithKeys(fn ($p, $id) => [(int) $id => (string) $p])->all(),
            'notes'       => $this->noteValues($criteria, $a2Assessment),
            'canDecide'   => $participant && $participant->approver_id === $user->id
                && $a2Assessment->status === Assessment::STATUS_PENDING && $a2Assessment->created_by !== $user->username,
        ]);
    }

    /* ------------------------------------------------------------------ Admin */

    public function admin(Request $request): View
    {
        $period = $this->period();
        $units  = A2Unit::with(['criteria', 'participants' => fn ($q) => $q->where('active', true)->with(['employee', 'assessor', 'approver'])])
            ->orderBy('sort')->get();

        $assessments = A2Assessment::where('period', $period)->get()->keyBy('employee_id');

        return view('a2.admin', [
            'periodLabel' => $this->periodLabel($period),
            'units'       => $units,
            'assessments' => $assessments,
            'statusCounts' => $assessments->countBy('status'),
            'participantCount' => $units->sum(fn ($u) => $u->participants->count()),
        ]);
    }

    /* ------------------------------------------------------------------ helpers */

    private function myParticipants(string $column): Collection
    {
        return A2Participant::with(['employee', 'unit.criteria', 'assessor', 'approver'])
            ->where('active', true)->where($column, Auth::id())
            ->get()
            ->sortBy(fn ($p) => $p->unit->sort . '|' . $p->employee?->name)
            ->values();
    }

    private function participantFor(string $employee, string $column): A2Participant
    {
        $participant = A2Participant::with(['employee', 'unit.criteria', 'approver'])
            ->where('active', true)->where('employee_id', $employee)->where($column, Auth::id())->first();

        abort_unless($participant, 403, t('Karyawan ini tidak ditugaskan kepada Anda untuk Assessment 2.'));

        return $participant;
    }

    /** Pending assessments (any period) of the participants this account approves. */
    private function pendingForApprover(): Collection
    {
        $employees = A2Participant::where('approver_id', Auth::id())->pluck('employee_id');

        return A2Assessment::with(['employee', 'unit', 'creator', 'attachments'])
            ->whereIn('employee_id', $employees)
            ->where('status', Assessment::STATUS_PENDING)
            ->orderBy('period')->get()
            ->sortBy(fn ($a) => $a->period . '|' . $a->unit?->sort . '|' . $a->employee?->name)
            ->values();
    }

    private function assessmentsFor(Collection $participants, string $period): Collection
    {
        return A2Assessment::where('period', $period)->whereIn('employee_id', $participants->pluck('employee_id'))->get()->keyBy('employee_id');
    }

    /** Note fields: "c{criterion id}" per criterion plus "general". */
    private function noteValues(Collection $criteria, ?A2Assessment $a): array
    {
        $notes = [];
        foreach ($criteria as $c) {
            $notes["c{$c->id}"] = (string) old("notes.c{$c->id}", $a?->notes["c{$c->id}"] ?? '');
        }
        $notes[Assessment::NOTE_GENERAL] = (string) old('notes.' . Assessment::NOTE_GENERAL, $a?->notes[Assessment::NOTE_GENERAL] ?? '');

        return $notes;
    }

    private function period(): string
    {
        return now()->format('Y-m');
    }

    private function periodLabel(string $period): string
    {
        return \App\Support\ApprovedExport::labelFor($period);
    }
}
