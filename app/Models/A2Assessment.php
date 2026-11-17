<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * One Assessment 2 per employee per period. Scores are keyed by criterion id; the criteria are copied into
 * criteria_snapshot when saved, so later changes to a unit's criteria never alter old results.
 * Statuses, grade points (A=100 ... D=0) and the final grade scale are the ones of Assessment 1.
 */
class A2Assessment extends Model
{
    protected $table = 'a2_assessments';

    protected $fillable = [
        'unit_id', 'employee_id', 'period', 'scores', 'criteria_snapshot', 'final_score', 'status',
        'notes', 'created_by', 'approved_by', 'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'scores'            => 'array',
            'criteria_snapshot' => 'array',
            'notes'             => 'array',
            'final_score'       => 'float',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(A2Unit::class, 'unit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id_employee');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'username');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'username');
    }

    /** Supporting files from the Penilai (App\Support\Attachments). */
    public function attachments(): MorphMany
    {
        return $this->morphMany(AssessmentAttachment::class, 'attachable')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, Assessment::EDITABLE_STATUSES, true);
    }

    /** Weighted total (0 - 100): points x weight% of every criterion. */
    public static function calculateFinalScore(Collection $criteria, array $scores): float
    {
        $total = 0.0;

        foreach ($criteria as $criterion) {
            $total += (int) ($scores[$criterion->id] ?? 0) * (float) $criterion->weight / 100;
        }

        return round($total, 2);
    }

    /** Criteria as stored with the assessment, as A2Criterion objects (for the read-only tables). */
    public function snapshotCriteria(): Collection
    {
        return collect($this->criteria_snapshot ?? [])->map(fn (array $c) => (new A2Criterion())->forceFill($c));
    }

    public static function snapshotOf(Collection $criteria): array
    {
        return $criteria->map(fn (A2Criterion $c) => $c->only(['id', 'sort', 'group_zh', 'group_id', 'name_zh', 'name_id', 'weight', 'target', 'remark']))->values()->all();
    }
}
