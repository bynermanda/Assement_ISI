<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttachment;
use App\Support\Attachments;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shows one assessment attachment (inline: PDF / image preview) to the Penilai, the approver and the admin.
 * The bucket is private: files never get a public link, every view goes through this check.
 */
class AttachmentController extends Controller
{
    public function show(AssessmentAttachment $attachment): StreamedResponse
    {
        abort_unless(Attachments::canView(Auth::user(), $attachment), 403, t('Anda tidak punya akses ke lampiran ini.'));

        $disk = Storage::disk(Attachments::DISK);
        abort_unless($disk->exists($attachment->path), 404, t('File lampiran tidak ditemukan.'));

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type'           => $attachment->mime,   // one of Attachments::TYPES, checked at upload
            'Cache-Control'          => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
