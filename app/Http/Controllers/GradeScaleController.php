<?php

namespace App\Http\Controllers;

use App\Models\A2Assessment;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Support\ApprovedExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panduan › Skala Nilai Akhir (admin only). The scale (lowest score of A, B, C; D = below C) is versioned by period:
 * a new version is valid FROM the current period or a later one, never for a past period. Every assessment is graded
 * with the scale of its own period, so earlier months keep their scale forever (pages, approvals, exports, next year).
 * One scale for every employee and department, Assessment 1 and 2.
 */
class GradeScaleController extends Controller
{
    public function edit(): View
    {
        $current = now()->format('Y-m');

        // timeline: the original scale, then each version; status per version
        $timeline = [['from' => null, 'grades' => Assessment::FINAL_GRADES, 'by' => null, 'at' => null]];
        foreach (Assessment::scaleVersions() as $v) {
            $timeline[] = ['from' => $v['from'], 'grades' => Assessment::finalGrades($v['from']), 'by' => $v['by'] ?? null, 'at' => $v['at'] ?? null];
        }
        $active = collect($timeline)->filter(fn ($t) => $t['from'] === null || strcmp($t['from'], $current) <= 0)->keys()->last();

        return view('criteria.scale', [
            'current'      => $current,
            'currentLabel' => ApprovedExport::labelFor($current),
            'grades'       => Assessment::finalGrades($current),
            'defaults'     => Assessment::FINAL_GRADES,
            'timeline'     => $timeline,
            'active'       => $active,
            'periods'      => $this->allowedPeriods(),
            'scaleByPeriod' => collect($this->allowedPeriods())->mapWithKeys(fn ($label, $p) => [$p => Assessment::finalGrades($p)]),
            // final scores of the current period (both assessments, every status but draft): "what changes" preview
            'scores'       => Assessment::where('period', $current)->where('status', '!=', Assessment::STATUS_DRAFT)->pluck('final_score')
                ->merge(A2Assessment::where('period', $current)->where('status', '!=', Assessment::STATUS_DRAFT)->pluck('final_score'))
                ->map(fn ($s) => (float) $s)->values(),
        ]);
    }

    /** Adds (or replaces) the version valid from the chosen period. Past periods are refused. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', Rule::in(array_keys($this->allowedPeriods()))],
            'a'    => ['required', 'integer', 'min:3', 'max:100'],
            'b'    => ['required', 'integer', 'min:2', 'lt:a'],
            'c'    => ['required', 'integer', 'min:1', 'lt:b'],
        ], [
            'from.in' => 'Skala hanya bisa berlaku mulai periode berjalan atau sesudahnya.',
            'b.lt'    => 'Batas B harus lebih kecil dari batas A.',
            'c.lt'    => 'Batas C harus lebih kecil dari batas B.',
        ], ['a' => 'batas A', 'b' => 'batas B', 'c' => 'batas C', 'from' => 'periode berlaku']);

        $scale = ['A' => (int) $data['a'], 'B' => (int) $data['b'], 'C' => (int) $data['c'], 'D' => 0];
        if (Assessment::finalGrades($data['from']) === $scale) {
            return redirect()->route('criteria.scale')->with('success', 'Skala untuk ' . ApprovedExport::labelFor($data['from']) . ' sudah sama, tidak ada yang diubah.');
        }

        $versions   = array_values(array_filter(Assessment::scaleVersions(), fn ($v) => $v['from'] !== $data['from']));
        $versions[] = ['from' => $data['from']] + array_slice($scale, 0, 3) + ['by' => Auth::user()->username, 'at' => now()->toDateTimeString()];
        $this->store($versions);

        return redirect()->route('criteria.scale')->with('success', 'Skala baru berlaku mulai ' . ApprovedExport::labelFor($data['from']) . ': '
            . $this->text($scale) . '. Periode sebelumnya tetap memakai skala lamanya.');
    }

    /** Cancels a version that is not in the past yet (current or future period). */
    public function destroy(string $from): RedirectResponse
    {
        abort_unless(Assessment::isPeriod($from), 404);
        if (strcmp($from, now()->format('Y-m')) < 0) {
            return redirect()->route('criteria.scale')->with('error', 'Skala periode yang sudah lewat tidak bisa diubah atau dihapus.');
        }

        $versions = Assessment::scaleVersions();
        $left     = array_values(array_filter($versions, fn ($v) => $v['from'] !== $from));
        if (count($left) === count($versions)) {
            return redirect()->route('criteria.scale')->with('error', 'Versi skala tidak ditemukan.');
        }
        $this->store($left);

        return redirect()->route('criteria.scale')->with('success', 'Skala mulai ' . ApprovedExport::labelFor($from) . ' dibatalkan. '
            . ApprovedExport::labelFor($from) . ' kini memakai: ' . $this->text(Assessment::finalGrades($from)) . '.');
    }

    private function store(array $versions): void
    {
        usort($versions, fn ($x, $y) => strcmp($x['from'], $y['from']));
        AppSetting::updateOrCreate(['key' => Assessment::SCALE_SETTING], ['value' => $versions, 'updated_by' => Auth::user()->username]);
        Assessment::forgetFinalGrades();
    }

    /** The current period and the next 12: a version can only start there. ['YYYY-MM' => 'Oktober 2026', ...] */
    private function allowedPeriods(): array
    {
        $start = Carbon::now()->startOfMonth();
        $out   = [];
        for ($i = 0; $i <= 12; $i++) {
            $p       = $start->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $out[$p] = ApprovedExport::labelFor($p);
        }

        return $out;
    }

    private function text(array $g): string
    {
        return "A {$g['A']}–100 · B {$g['B']}–" . ($g['A'] - 1) . " · C {$g['C']}–" . ($g['B'] - 1) . ' · D 0–' . ($g['C'] - 1);
    }
}
