<?php

namespace App\Support;

use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\Assessment;
use App\Models\AssessmentAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supporting files ("lampiran") of an assessment, Assessment 1 and 2.
 *
 *  - Optional; at most MAX_FILES files of MAX_KB each per assessment; PDF, JPG or PNG only.
 *  - Uploaded with the assessment form (draft or submit) while the assessment is Draft / Rejected; then locked.
 *  - Stored in the private 'lampiran' disk (Supabase Storage); only shown through AttachmentController, after
 *    canView(): the Penilai, the approver and the admin.
 *  - Images are re-encoded by GD (drops EXIF / GPS and anything hidden in the file); a PDF must start with %PDF-.
 *  - Deleted KEEP_MONTHS months after the assessment was approved (prune(), once a day).
 */
final class Attachments
{
    public const DISK        = 'lampiran';
    public const MAX_FILES   = 3;
    public const MAX_KB      = 2048;
    public const KEEP_MONTHS = 6;

    /** Accepted content types (checked on the file content, not its name) => stored extension */
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** An image above this many pixels is refused (a small file can still decode to gigabytes of memory). */
    private const MAX_PIXELS = 30_000_000;

    /** Real storage configured (Supabase), or a developer's machine. On the server without it, uploads are off. */
    public static function enabled(): bool
    {
        return config('filesystems.disks.' . self::DISK . '.driver') === 's3' || app()->environment('local', 'testing');
    }

    /** Validation rules for the form fields lampiran[] (new files) and lampiran_hapus[] (ids to remove). */
    public static function rules(): array
    {
        return [
            'lampiran'         => ['nullable', 'array', 'max:' . self::MAX_FILES],
            'lampiran.*'       => ['file', 'max:' . self::MAX_KB, 'mimes:pdf,jpg,jpeg,png', 'mimetypes:' . implode(',', array_keys(self::TYPES))],
            'lampiran_hapus'   => ['nullable', 'array'],
            'lampiran_hapus.*' => ['integer'],
        ];
    }

    public static function messages(): array
    {
        return [
            'lampiran.max'         => t('Maksimal :n file lampiran per penilaian.', ['n' => self::MAX_FILES]),
            'lampiran.*.max'       => t('Setiap lampiran maksimal :mb MB.', ['mb' => self::MAX_KB / 1024]),
            'lampiran.*.mimes'     => t('Lampiran hanya boleh PDF, JPG atau PNG.'),
            'lampiran.*.mimetypes' => t('Lampiran hanya boleh PDF, JPG atau PNG.'),
            'lampiran.*.file'      => t('Lampiran gagal diunggah. Pilih ulang filenya.'),
            'lampiran.*.uploaded'  => t('Lampiran gagal diunggah. Pilih ulang filenya.'),
        ];
    }

    /**
     * BEFORE the assessment is saved (so a refused file never leaves a half-saved, already submitted assessment):
     * storage available, kept + new files within MAX_FILES, and every new file checked / cleaned.
     *
     * @return array{remove: int[], files: array<int, array{bytes: string, mime: string, name: string}>}
     *
     * @throws ValidationException
     */
    public static function prepare(?Model $assessment, Request $request): array
    {
        $remove = array_values(array_unique(array_map('intval', (array) $request->input('lampiran_hapus', []))));
        $files  = array_values(array_filter((array) $request->file('lampiran', []), fn ($f) => $f instanceof UploadedFile));

        if ($files === []) {
            return ['remove' => $remove, 'files' => []];
        }
        if (! self::enabled()) {
            throw ValidationException::withMessages(['lampiran' => t('Penyimpanan lampiran belum aktif. Hubungi HRD.')]);
        }

        $kept = $assessment ? $assessment->attachments()->whereNotIn('id', $remove)->count() : 0;
        if ($kept + count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'lampiran' => t('Maksimal :n file lampiran per penilaian. Hapus lampiran lama terlebih dahulu.', ['n' => self::MAX_FILES]),
            ]);
        }

        return ['remove' => $remove, 'files' => array_map(function (UploadedFile $file) {
            [$bytes, $mime] = self::sanitize($file);

            return ['bytes' => $bytes, 'mime' => $mime, 'name' => Str::limit(self::cleanName($file->getClientOriginalName()), 250, '')];
        }, $files)];
    }

    /** AFTER the assessment is saved: remove the ticked files (only its own) and store the prepared ones. */
    public static function sync(Model $assessment, array $prepared, string $by): void
    {
        if ($prepared['remove'] !== []) {
            $assessment->attachments()->whereIn('id', $prepared['remove'])->get()->each(fn (AssessmentAttachment $a) => self::delete($a));
        }

        $type = $assessment instanceof A2Assessment ? 'a2' : 'a1';

        foreach ($prepared['files'] as $file) {
            $path = sprintf('%s/%s/%s.%s', $type, $assessment->period, Str::random(40), self::TYPES[$file['mime']]);
            Storage::disk(self::DISK)->put($path, $file['bytes']);

            try {
                $assessment->attachments()->create([
                    'path'          => $path,
                    'original_name' => $file['name'],
                    'mime'          => $file['mime'],
                    'size'          => strlen($file['bytes']),
                    'uploaded_by'   => $by,
                ]);
            } catch (\Throwable $e) {
                Storage::disk(self::DISK)->delete($path);
                throw $e;
            }
        }
    }

    /** Penilai or approver of the assessment's employee, or the admin. */
    public static function canView(User $user, AssessmentAttachment $attachment): bool
    {
        if ($user->role === Roles::ADMIN) {
            return true;
        }

        $owner = $attachment->attachable;

        if ($owner instanceof Assessment) {
            $owner->loadMissing('employee');

            return ReviewRules::canHandle($user, $owner->employee, ReviewRules::STAGE_ASSESS)
                || ReviewRules::canHandle($user, $owner->employee, ReviewRules::STAGE_APPROVE);
        }

        if ($owner instanceof A2Assessment) {
            $participant = A2Participant::where('employee_id', $owner->employee_id)->first();

            return $participant && in_array($user->id, [$participant->assessor_id, $participant->approver_id], true);
        }

        return false;
    }

    public static function delete(AssessmentAttachment $attachment): void
    {
        try {
            Storage::disk(self::DISK)->delete($attachment->path);
        } catch (\Throwable $e) {
            Log::warning('Lampiran tidak bisa dihapus dari storage', ['path' => $attachment->path, 'error' => $e->getMessage()]);
        }
        $attachment->delete();
    }

    /**
     * Retention: files of assessments approved more than KEEP_MONTHS months ago, and files whose assessment no longer
     * exists (employee deleted). "Approved at" = updated_at of an Approved assessment (nothing changes it afterwards;
     * a later admin edit only keeps the files longer). The scores and notes themselves are never touched.
     */
    public static function prune(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $before = now()->subMonths(self::KEEP_MONTHS);
        $old    = fn ($q) => $q->where('status', Assessment::STATUS_APPROVED)->where('updated_at', '<', $before);

        $expired = AssessmentAttachment::whereHasMorph('attachable', [Assessment::class, A2Assessment::class], $old)->get();
        $orphans = AssessmentAttachment::where(fn ($q) => $q
            ->where(fn ($a1) => $a1->where('attachable_type', 'a1')->whereNotIn('attachable_id', Assessment::select('id')))
            ->orWhere(fn ($a2) => $a2->where('attachable_type', 'a2')->whereNotIn('attachable_id', A2Assessment::select('id'))))
            ->get();

        $all = $expired->concat($orphans)->unique('id');
        $all->each(fn (AssessmentAttachment $a) => self::delete($a));

        return $all->count();
    }

    /** @return array{files: int, bytes: int} */
    public static function usage(): array
    {
        return [
            'files' => AssessmentAttachment::count(),
            'bytes' => (int) AssessmentAttachment::sum('size'),
        ];
    }

    /**
     * Checks the content and returns [bytes, mime]. Images are decoded and encoded again.
     *
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    private static function sanitize(UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();
        $mime = (string) $file->getMimeType();   // from the content (finfo)
        $fail = fn (string $why) => throw ValidationException::withMessages(['lampiran' => "{$name}: {$why}"]);

        if (! isset(self::TYPES[$mime])) {
            $fail(t('Lampiran hanya boleh PDF, JPG atau PNG.'));
        }

        $bytes = (string) file_get_contents($file->getRealPath());

        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-')) {
                $fail(t('File PDF tidak valid.'));
            }

            return [$bytes, $mime];
        }

        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            $fail(t('File gambar tidak valid.'));
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            $fail(t('Resolusi gambar terlalu besar. Perkecil fotonya terlebih dahulu.'));
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            $fail(t('File gambar tidak valid.'));
        }

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imageinterlace($image, true);
            imagejpeg($image, null, 85);
        }
        $clean = (string) ob_get_clean();

        return [$clean, $mime];
    }

    /** Only the file name, without paths or control characters. */
    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename(str_replace('\\', '/', $name))) ?? '');

        return $name !== '' ? $name : 'lampiran';
    }
}
