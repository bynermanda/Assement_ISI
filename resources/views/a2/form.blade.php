@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Assessment 2 · ' . ($participant->employee?->name ?? ''))
@section('heading', 'Assessment 2 · ' . ($participant->employee?->name ?? $participant->employee_id))
@section('subheading', t('Periode') . ': ' . $periodLabel . ' · ' . t('Unit') . ': ' . $participant->unit->name . ' · ' . t('Approver') . ': ' . ($participant->approver?->name ?? '-'))

@section('content')
<form method="POST" action="{{ route('a2.store', $participant->employee_id) }}"
      x-data="a2Form(@js($scores), @js($criteria->mapWithKeys(fn ($c) => [$c->id => $c->weight])))"
      class="space-y-6">
    @csrf

    @if ($existing?->status === Assessment::STATUS_REJECTED && $existing->rejection_note)
        <div class="rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900">
            <p class="font-semibold">{{ tb('Approver meminta perbaikan') }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $existing->rejection_note }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold text-slate-900">{{ $participant->employee?->name }}</h2>
            <p class="text-sm text-slate-500">{{ $participant->employee_id }} · {{ $participant->employee?->department }}@if ($participant->employee?->section) / {{ $participant->employee->section }}@endif · {{ $participant->employee?->position }} · {{ $participant->employee?->grade }}</p>
        </div>
        @if ($criteria->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500">{{ tb('Unit ini belum punya kriteria. Hubungi HRD.') }}</p>
        @else
            @include('a2.partials.table', ['editable' => true])
        @endif
    </div>

    @if ($criteria->isNotEmpty())
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('a2.index') }}" class="rounded border border-slate-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ t('Kembali') }}</a>
            <button type="submit" name="action" value="draft"
                    class="rounded border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ t('Simpan draf') }}</button>
            <button type="submit" name="action" value="submit" :disabled="!complete"
                    onclick="return confirm(@js(t('Kirim ke Approver? Penilaian terkunci kecuali dikembalikan.')))"
                    class="rounded bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :title="complete ? '' : @js(t('Beri nilai semua kriteria terlebih dahulu'))">
                {{ t('Kirim ke Approver') }}
            </button>
        </div>
    @endif
</form>
@endsection
