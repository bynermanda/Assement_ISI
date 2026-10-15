@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Assessment 2 · ' . ($assessment->employee?->name ?? ''))
@section('heading', 'Assessment 2 · ' . ($assessment->employee?->name ?? $assessment->employee_id))
@section('subheading', t('Periode') . ': ' . \App\Support\ApprovedExport::labelFor($assessment->period) . ' · ' . t('Unit') . ': ' . ($assessment->unit?->name ?? '-'))

@section('content')
<div x-data="Object.assign(a2Form(@js($scores), @js($criteria->mapWithKeys(fn ($c) => [$c->id => $c->weight]))), { rejectOpen: false, rejectUrl: '', employeeName: '', note: '' })" class="space-y-6">
    <div class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white px-5 py-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
        <p>
            {{ t('Penilai') }} <span class="font-medium text-slate-900">{{ $assessment->creator?->name ?? $assessment->created_by ?? '-' }}</span>
            · {{ t('Approver') }} <span class="font-medium text-slate-900">{{ $participant?->approver?->name ?? '-' }}</span>
            @if ($assessment->approved_by) · {{ t('disetujui oleh') }} <span class="font-medium text-slate-900">{{ $assessment->approver?->name ?? $assessment->approved_by }}</span>@endif
        </p>
        <span class="self-start rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($assessment->status) }}">{{ \App\Support\Ui::status($assessment->status) }}</span>
    </div>

    @if ($assessment->status === Assessment::STATUS_REJECTED && $assessment->rejection_note)
        <div class="rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900">
            <p class="font-semibold">{{ tb('Dikembalikan untuk diperbaiki') }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $assessment->rejection_note }}</p>
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold text-slate-900">{{ $assessment->employee?->name }}</h2>
            <p class="text-sm text-slate-500">{{ $assessment->employee_id }} · {{ $assessment->employee?->department }} · {{ $assessment->employee?->position }} · {{ $assessment->employee?->grade }}</p>
        </div>
        @include('a2.partials.table', ['editable' => false])
    </div>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard') }}"
           class="rounded border border-slate-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ t('Kembali') }}</a>
        @if ($canDecide)
            <button type="button" @click="rejectUrl = '{{ route('a2.reject', $assessment) }}'; employeeName = @js($assessment->employee?->name); note = ''; rejectOpen = true"
                    class="rounded border border-rose-300 bg-white px-5 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">{{ t('Kembalikan') }}</button>
            <form method="POST" action="{{ route('a2.approve-selected') }}" onsubmit="return confirm(@js(t('Approve penilaian ini? Setelah itu penilaian dikunci.')))">
                @csrf
                <input type="hidden" name="ids[]" value="{{ $assessment->id }}">
                <button type="submit" class="w-full rounded bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">{{ t('Approve') }}</button>
            </form>
        @endif
    </div>

    @if ($canDecide)
        @include('a2.partials.reject-modal')
    @endif
</div>
@endsection
