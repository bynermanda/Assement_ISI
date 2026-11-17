<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A file attached to an assessment ('a1' Assessment or 'a2' A2Assessment). Handled by App\Support\Attachments. */
class AssessmentAttachment extends Model
{
    protected $fillable = ['attachable_type', 'attachable_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** "1,4 MB" / "320 KB" */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.') . ' MB'
            : max(1, (int) round($this->size / 1024)) . ' KB';
    }
}
