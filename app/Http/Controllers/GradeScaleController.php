<?php

namespace App\Http\Controllers;

use App\Models\A2Assessment;
use App\Models\AppSetting;
use App\Models\Assessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Panduan › Skala Nilai Akhir (admin only): the lowest score of A, B and C (D = everything below C). One scale for every
 * employee and department, Assessment 1 and 2. The letter is computed from the stored final score whenever it is shown,
 * so a new scale also applies to assessments that already exist (forms, approvals, exports).
 */
class GradeScaleController extends Controller
{
    public function edit(): View
    {
        $period  = now()->format('Y-m');
        $setting = AppSetting::find('final_grades');

        return view('criteria.scale', [
            'grades'    => Assessment::finalGrades(),
            'defaults'  => Assessment::FINAL_GRADES,
            'setting'   => $setting,
            'period'    => \App\Support\ApprovedExport::labelFor($period),
            // final scores of this period (both assessments, every status but draft): for the "what changes" preview
            'scores'    => Assessment::where('period', $period)->where('status', '!=', Assessment::STATUS_DRAFT)->pluck('final_score')
                ->merge(A2Assessment::where('period', $period)->where('status', '!=', Assessment::STATUS_DRAFT)->pluck('final_score'))
                ->map(fn ($s) => (float) $s)->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer', 'min:3', 'max:100'],
            'b' => ['required', 'integer', 'min:2', 'lt:a'],
            'c' => ['required', 'integer', 'min:1', 'lt:b'],
        ], [
            'b.lt' => 'Batas B harus lebih kecil dari batas A.',
            'c.lt' => 'Batas C harus lebih kecil dari batas B.',
        ], ['a' => 'batas A', 'b' => 'batas B', 'c' => 'batas C']);

        $value = ['A' => (int) $data['a'], 'B' => (int) $data['b'], 'C' => (int) $data['c'], 'D' => 0];
        $old   = Assessment::finalGrades();

        AppSetting::updateOrCreate(['key' => 'final_grades'], ['value' => $value, 'updated_by' => Auth::user()->username]);
        Assessment::forgetFinalGrades();

        $text = fn (array $g) => "A {$g['A']}–100 · B {$g['B']}–" . ($g['A'] - 1) . " · C {$g['C']}–" . ($g['B'] - 1) . ' · D 0–' . ($g['C'] - 1);

        return redirect()->route('criteria.scale')->with('success', $old === $value
            ? 'Skala tidak berubah.'
            : 'Skala Nilai Akhir disimpan: ' . $text($value) . ' (sebelumnya ' . $text($old) . '). Berlaku untuk semua karyawan dan departemen.');
    }
}
