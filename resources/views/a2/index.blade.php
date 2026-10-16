@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Assessment 2')
@section('heading', 'Assessment 2')
@section('subheading', t('Periode') . ': ' . $periodLabel . ' · ' . t('Pimpinan yang Anda nilai. Kriteria, bobot dan target mengikuti unit masing-masing.'))

@section('content')
<div class="space-y-4">
    @if ($approvals > 0)
        <div class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
            <span>{{ tb(':n penilaian menunggu persetujuan Anda.', ['n' => $approvals]) }}</span>
            <a href="{{ route('a2.approvals') }}" class="font-semibold text-amber-900 hover:underline">{{ t('Buka Approval A2') }} →</a>
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ tb('Karyawan') }}</th>
                    <th class="px-4 py-3 font-medium">{{ tb('Unit') }}</th>
                    <th class="px-4 py-3 font-medium">{{ tb('Approver') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ tb('Nilai Akhir') }}</th>
                    <th class="px-4 py-3 font-medium">{{ tb('Status') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ tb('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($participants as $p)
                    @php $a = $assessments->get($p->employee_id); $letter = $a && $a->status !== Assessment::STATUS_DRAFT ? Assessment::finalGradeFor($a->final_score, $a->period) : null; @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $p->employee?->name ?? $p->employee_id }}</p>
                            <p class="text-xs text-slate-500">{{ $p->employee_id }} · {{ $p->employee?->position }} · {{ $p->employee?->grade }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $p->unit->name }} <span class="text-xs text-slate-400">({{ t(':n kriteria', ['n' => $p->unit->criteria->count()]) }})</span></td>
                        <td class="px-4 py-3 text-slate-700">{{ $p->approver?->name ?? '-' }}<span class="block text-xs text-slate-400">{{ $p->approver?->job_title }}</span></td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($a)
                                <span class="font-semibold">{{ number_format($a->final_score, 1) }}</span>
                                @if ($letter)
                                    <span class="ml-1 inline-flex h-6 w-6 items-center justify-center rounded text-xs font-bold ring-1 ring-inset {{ Assessment::finalGradeClass($letter) }}">{{ $letter }}</span>
                                @endif
                            @else
                                <span class="text-slate-400">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($a?->status) }}">{{ \App\Support\Ui::status($a?->status) }}</span>
                            @if ($a?->status === Assessment::STATUS_REJECTED && $a->rejection_note)
                                <p class="mt-1 max-w-xs text-xs text-rose-700">{{ $a->rejection_note }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if (! $a || $a->isEditable())
                                <a href="{{ route('a2.form', $p->employee_id) }}" class="rounded bg-teal-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-700">
                                    {{ t($a ? 'Lanjutkan' : 'Nilai') }}
                                </a>
                            @else
                                <a href="{{ route('a2.show', $a) }}" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Lihat') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ tb('Belum ada pimpinan yang ditugaskan kepada Anda untuk Assessment 2.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
