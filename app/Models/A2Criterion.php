<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class A2Criterion extends Model
{
    protected $table = 'a2_criteria';

    protected $fillable = ['unit_id', 'sort', 'group_zh', 'group_id', 'name_zh', 'name_id', 'weight', 'target', 'remark'];

    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(A2Unit::class, 'unit_id');
    }

    public function groupKey(): ?string
    {
        $key = trim((string) $this->group_zh) . '|' . trim((string) $this->group_id);

        return $key === '|' ? null : $key;
    }

    /** "Kualitas Kerja (工作品質)": plain text for labels and summaries. */
    public function text(): string
    {
        $id = trim(preg_replace('/\s+/', ' ', (string) $this->name_id));
        $zh = trim(preg_replace('/\s+/', ' ', (string) $this->name_zh));

        return $zh !== '' ? "{$id} ({$zh})" : $id;
    }

    /**
     * Rowspan helper for the tables: criterion id => [group key|null, first of a run, run length].
     * A group spans consecutive criteria with the same group (like 出勤表現 Performa Kehadiran in Assessment 1).
     */
    public static function layout(Collection $criteria): array
    {
        $layout = [];
        $list   = $criteria->values();

        for ($i = 0; $i < $list->count(); $i++) {
            $key = $list[$i]->groupKey();

            if ($key === null) {
                $layout[$list[$i]->id] = [null, false, 1];
                continue;
            }

            $start = $i;
            while ($i + 1 < $list->count() && $list[$i + 1]->groupKey() === $key) {
                $i++;
            }
            for ($j = $start; $j <= $i; $j++) {
                $layout[$list[$j]->id] = [$key, $j === $start, $i - $start + 1];
            }
        }

        return $layout;
    }
}
