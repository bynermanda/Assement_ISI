<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\DatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Backup: one click downloads the whole database as a .sql file (DatabaseBackup) that can be restored in the
 * Supabase SQL Editor. The last download (who / when) is kept in app_settings so the page can remind the admin.
 */
class BackupController extends Controller
{
    public const LAST_BACKUP = 'last_backup';

    /** After this many days without a download the page shows a reminder. */
    public const REMIND_AFTER_DAYS = 35;

    public function index(): View
    {
        $last = AppSetting::get(self::LAST_BACKUP);

        return view('admin.backup', [
            'supported' => DatabaseBackup::supported(),
            'tables'    => DatabaseBackup::supported() ? (new DatabaseBackup)->tables() : [],
            'last'      => $last,
            'overdue'   => ! $last || Carbon::parse($last['at'])->diffInDays(now()) > self::REMIND_AFTER_DAYS,
        ]);
    }

    public function download(): StreamedResponse|RedirectResponse
    {
        if (! DatabaseBackup::supported()) {
            return back()->with('error', 'Backup hanya bisa dibuat dari database PostgreSQL (Supabase).');
        }

        set_time_limit(300);
        $user = Auth::user();
        $by   = "{$user->name} ({$user->username})";

        AppSetting::updateOrCreate(['key' => self::LAST_BACKUP], [
            'value'      => ['at' => now()->toIso8601String(), 'by' => $by],
            'updated_by' => $user->username,
        ]);

        return response()->streamDownload(function () use ($by) {
            (new DatabaseBackup)->write(function (string $piece) {
                echo $piece;
            }, $by);
        }, DatabaseBackup::filename(), [
            'Content-Type'  => 'application/sql; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
