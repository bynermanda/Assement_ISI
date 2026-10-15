@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Form Assessment')
@section('heading', tb('Form Assessment'))
@section('subheading', t('Periode') . ': ' . $periodLabel . ' | ' . t('Seksi') . ': ' . (implode(', ', $groups) ?: '-'))

@section('content')
<form method="POST" action="{{ route('assessments.store') }}"
      x-data="assessmentForm(@js($initial), @js($weights))"
      class="space-y-6">
    @csrf

    @if ($usesBulk)
        <div class="flex flex-col gap-2 rounded border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900 sm:flex-row sm:items-center sm:justify-between">
            <span>{{ tb('Departemen Anda punya :n karyawan. Menilai semuanya sekaligus lebih cepat di Assessment Massal.', ['n' => $employees->count()]) }}</span>
            <a href="{{ route('assessments.bulk') }}" class="font-semibold text-teal-800 hover:underline">{{ t('Buka Assessment Massal') }} →</a>
        </div>
    @endif

    {{-- Employee picker: searchable, grouped by section --}}
    @php
        $grouped = $employees->groupBy(fn ($e) => \App\Support\ReviewRules::unitOf($e));
    @endphp
    <div class="rounded-lg border border-slate-200 bg-white p-6 md:p-8"
         x-data="{
            q: '',
            match(text) { const q = this.q.toLowerCase().trim(); return q === '' || q.split(/\s+/).every(part => text.includes(part)); },
            any(texts) { return texts.some(text => this.match(text)); },
         }">
        <input type="hidden" name="id_employee" value="{{ $selected?->id_employee }}">

        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full md:max-w-md">
                <label for="employee_search" class="block text-sm font-medium text-slate-700">{{ t('Karyawan') }}</label>
                <input id="employee_search" type="search" x-model="q" autocomplete="off" placeholder="{{ t('Cari nama, NIK atau seksi') }}"
                       class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
            </div>
            @if ($selected)
                <p class="text-sm text-slate-600">{{ t('Dipilih') }}: <span class="font-semibold text-slate-900">{{ $selected->name }}</span>
                    <span class="text-slate-500">({{ \App\Support\ReviewRules::unitOf($selected) }})</span></p>
            @endif
        </div>

        @error('id_employee')
            <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
        @enderror

        <div class="mt-3 max-h-80 overflow-y-auto rounded border border-slate-200">
            @forelse ($grouped as $unit => $list)
                @php
                    $texts = $list->map(fn ($e) => mb_strtolower($e->name . ' ' . $e->id_employee . ' ' . $unit))->values()->all();
                @endphp
                <div x-show="any(@js($texts))">
                    <div class="sticky top-0 flex items-center justify-between bg-slate-100 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <span>{{ $unit }}</span>
                        <span class="font-normal normal-case text-slate-500">{{ t(':n karyawan', ['n' => $list->count()]) }}</span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($list as $employee)
                            @php
                                $current = $assessments->get($employee->id_employee);
                                $locked  = $current && ! in_array($current->status, Assessment::EDITABLE_STATUSES, true);
                                $text    = mb_strtolower($employee->name . ' ' . $employee->id_employee . ' ' . $unit);
                                $isSel   = $selected?->id_employee === $employee->id_employee;
                            @endphp
                            <li x-show="match(@js($text))">
                                @if ($locked)
                                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm text-slate-400">
                                        <span>{{ $employee->name }} <span class="text-xs">{{ $employee->id_employee }}</span></span>
                                        <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($current->status) }}">{{ \App\Support\Ui::status($current->status) }}</span>
                                    </div>
                                @else
                                    <a href="{{ route('assessments.create', ['employee' => $employee->id_employee]) }}"
                                       @class(['flex items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-teal-50', 'bg-teal-50 font-semibold' => $isSel])>
                                        <span class="text-slate-900">{{ $employee->name }} <span class="text-xs font-normal text-slate-500">{{ $employee->id_employee }} &middot; {{ $employee->position }}</span></span>
                                        <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($current?->status) }}">{{ \App\Support\Ui::status($current?->status) }}</span>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500">{{ tb('Belum ada karyawan untuk akun Anda.') }}</p>
            @endforelse
        </div>

        <p class="mt-2 text-xs text-slate-500">{{ tb('Karyawan yang menunggu persetujuan atau sudah disetujui terkunci. Draf dan penilaian yang dikembalikan bisa diedit.') }}</p>

        @if ($existing?->status === Assessment::STATUS_REJECTED && $existing->rejection_note)
            <div class="mt-4 rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900">
                <p class="font-semibold">{{ tb('Penilai 2 meminta perbaikan') }}</p>
                <p class="mt-1 whitespace-pre-line">{{ $existing->rejection_note }}</p>
            </div>
        @endif
    </div>

    @if (! $selected)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
            {{ tb('Pilih karyawan dari daftar di atas untuk mulai menilai.') }}
        </div>
    @else
        {{-- Criteria --}}
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-6 py-5 md:px-8">
                <h2 class="text-xl font-semibold text-slate-900">{{ $selected->name }}</h2>
                <p class="text-sm text-slate-500">{{ $selected->department }}@if ($selected->section) / {{ $selected->section }}@endif &middot; {{ $selected->position }} &middot; {{ $selected->grade }}</p>
                @if ($lockedColumns !== [])
                    <p class="mt-2 rounded border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800">
                        {{ tb('Kehadiran dan keterlambatan diisi oleh HRD dan tidak bisa diubah. Silakan nilai kriteria lainnya.') }}
                    </p>
                @endif
            </div>

            @include('assessments.partials.criteria-table', ['notes' => $notes])
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button type="submit" name="action" value="draft"
                    class="rounded border border-slate-300 bg-white px-6 py-3.5 text-base font-semibold text-slate-700 focus:outline-none">
                {{ t('Simpan draf') }}
            </button>
            <button type="submit" name="action" value="submit"
                    onclick="return confirm(@js(t('Kirim penilaian ini ke Penilai 2? Setelah dikirim tidak bisa diubah kecuali dikembalikan.')))"
                    class="rounded bg-teal-600 px-6 py-3.5 text-base font-semibold text-white focus:outline-none">
                {{ t('Kirim ke Penilai 2') }}
            </button>
        </div>
    @endif
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
