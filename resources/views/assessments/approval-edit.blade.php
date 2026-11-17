@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Periksa penilaian')
@section('heading', tb('Periksa dan edit penilaian'))
@section('subheading', t('Nilai dari Penilai 1; boleh diubah sebelum approve.'))

@section('content')
@php
    $initial = [];
    foreach (array_keys(Assessment::CRITERIA) as $column) {
        $initial[$column] = old($column, $assessment->{$column} ?? '');
    }
    $weights = collect(Assessment::CRITERIA)->map(fn ($m) => $m['weight'])->all();
@endphp

<form method="POST" action="{{ route('approval.update', $assessment) }}"
      x-data="assessmentForm(@js($initial), @js($weights))"
      class="space-y-6">
    @csrf
    @method('PUT')

    <div class="rounded-lg border border-slate-200 bg-white px-5 py-3 text-sm text-slate-600">
        {{ t('Dinilai oleh') }} <span class="font-medium text-slate-900">{{ $assessment->creator?->name ?? $assessment->created_by ?? '-' }}</span>
        &middot; {{ t('periode') }} {{ \App\Support\ApprovedExport::labelFor($assessment->period) }}
        @if ($assessment->edited_by)
            &middot; {{ t('terakhir diedit oleh') }} <span class="font-medium text-slate-900">{{ $assessment->editor?->name ?? $assessment->edited_by }}</span>
        @endif
    </div>

    {{-- Criteria --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold text-slate-900">{{ $employee->name }}</h2>
            <p class="text-sm text-slate-500">{{ $employee->id_employee }} &middot; {{ $employee->department }}@if ($employee->section) / {{ $employee->section }}@endif &middot; {{ $employee->position }} &middot; {{ $employee->grade }}</p>
            @if ($lockedColumns !== [])
                <p class="mt-2 rounded border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800">
                    @if (array_intersect($lockedColumns, \App\Models\Assessment::HRD_COLUMNS))<span class="block">{{ tb('Kehadiran dan keterlambatan diisi HRD (terkunci). Nilai kriteria lainnya.') }}</span>@endif
                    @if (array_intersect($lockedColumns, \App\Models\Assessment::SANCTION_COLUMNS))<span class="block font-bold text-rose-800">{{ tb('Poin 6 diisi dari Sanksi HRD (terkunci).') }}</span>@endif
                </p>
            @endif
        </div>

        @php
            $notes = [];
            foreach (Assessment::noteKeys() as $key) {
                $notes[$key] = old("notes.{$key}", $assessment->notes[$key] ?? '');
            }
        @endphp
        @include('assessments.partials.criteria-table', ['notes' => $notes, 'scalePeriod' => $assessment->period])
    </div>

    @include('partials.attachments.list', ['attachments' => $assessment->attachments])


    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('approval.index') }}" class="rounded border border-slate-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ t('Kembali') }}</a>
        <button type="submit" name="action" value="save"
                class="rounded border border-teal-600 bg-white px-5 py-2.5 text-sm font-semibold text-teal-700 hover:bg-teal-50 focus:outline-none focus:ring-2 focus:ring-teal-600">
            {{ t('Simpan perubahan') }}
        </button>
        <button type="submit" name="action" value="approve"
                onclick="return confirm(@js(t('Simpan dan approve? Penilaian akan terkunci.')))"
                class="rounded bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">
            {{ t('Simpan dan approve') }}
        </button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    function assessmentForm(initial, weights) {
        return {
            scores: initial,
            weights: weights,
            contribution(column) {
                return (Number(this.scores[column]) || 0) * this.weights[column] / 100;
            },
            get total() {
                return Object.keys(this.weights).reduce((sum, column) => sum + this.contribution(column), 0);
            },
            get complete() {
                return Object.keys(this.weights).every(column => this.scores[column] !== '' && this.scores[column] !== null);
            },
        };
    }
</script>
@endpush
