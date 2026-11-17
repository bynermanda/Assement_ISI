@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Approval A2')
@section('heading', tb('Approval Assessment 2'))
@section('subheading', t('Centang lalu klik Approve; langsung tersimpan. Buka satu penilaian untuk detail atau mengembalikannya.'))

@section('content')
@php
    $me   = auth()->user()->username;
    $rows = $assessments->map(fn ($a) => ['id' => $a->id, 'final' => Assessment::finalGradeFor($a->final_score, $a->period), 'selectable' => $a->created_by !== $me])->values();
@endphp
<div x-data="a2Approvals({ rows: @js($rows) })" class="pb-24">
    @if ($assessments->isEmpty())
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <p class="font-medium text-slate-700">{{ tb('Belum ada yang perlu disetujui') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ tb('Penilaian yang dikirim oleh Penilai akan muncul di sini.') }}</p>
            @if ($assessing > 0)
                <a href="{{ route('a2.index') }}" class="mt-3 inline-block text-sm font-semibold text-teal-700 hover:underline">{{ t('Ke daftar orang yang Anda nilai') }} →</a>
            @endif
        </div>
    @else
        <form method="POST" action="{{ route('a2.approve-selected') }}" x-ref="approveForm">
            @csrf
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="table-lines min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="w-10 px-3 py-3 text-center">
                                    <input type="checkbox" :checked="allSelected" x-effect="$el.indeterminate = selected.length > 0 && !allSelected" @change="toggleAll($event.target.checked)"
                                           aria-label="{{ t('Pilih semua') }}" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                                </th>
                                <th class="px-3 py-3 font-medium">{{ tb('Karyawan') }}</th>
                                <th class="px-3 py-3 font-medium">{{ tb('Unit / Periode') }}</th>
                                <th class="px-3 py-3 font-medium">{{ tb('Penilai') }}</th>
                                <th class="px-3 py-3 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                                <th class="px-3 py-3 text-center font-semibold text-slate-700">{{ tb('Nilai Akhir') }}</th>
                                <th class="px-3 py-3">@include('assessments.partials.heading', ['key' => 'notes'])</th>
                                <th class="px-3 py-3 font-medium">{{ tb('Lampiran') }}</th>
                                <th class="px-3 py-3 text-right font-medium">{{ tb('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($assessments as $i => $a)
                                @php $letter = $rows[$i]['final']; $general = $a->notes[Assessment::NOTE_GENERAL] ?? null; @endphp
                                <tr :class="selected.includes({{ $a->id }}) ? 'bg-emerald-50/70' : ''">
                                    <td class="px-3 py-2 text-center">
                                        @if ($rows[$i]['selectable'])
                                            <input type="checkbox" name="ids[]" value="{{ $a->id }}" x-model.number="selected" aria-label="Approve {{ $a->employee?->name }}"
                                                   class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                                        @else
                                            <span class="text-xs text-slate-400" title="{{ t('Penilaian ini Anda buat sendiri') }}">–</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <p class="font-medium text-slate-900">{{ $a->employee?->name ?? $a->employee_id }}</p>
                                        <p class="text-xs text-slate-500">{{ $a->employee_id }} · {{ $a->employee?->position }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-slate-700">{{ $a->unit?->name }}<span class="block text-xs text-slate-500">{{ \App\Support\ApprovedExport::labelFor($a->period) }}</span></td>
                                    <td class="px-3 py-2 text-slate-700">{{ $a->creator?->name ?? $a->created_by }}</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($a->final_score, 1) }}</td>
                                    <td class="px-3 py-2 text-center">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset {{ Assessment::finalGradeClass($letter) }}">{{ $letter }}</span>
                                    </td>
                                    <td class="max-w-xs px-3 py-2 text-xs text-slate-600">
                                        @php $count = count($a->notes ?? []); @endphp
                                        {{ $general ? Str::limit($general, 80) : '' }}
                                        @if ($count > ($general ? 1 : 0))
                                            <span class="text-slate-400">+{{ t(':n catatan per kriteria', ['n' => $count - ($general ? 1 : 0)]) }}</span>
                                        @elseif (! $general)
                                            <span class="text-slate-400">–</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">@include('partials.attachments.list', ['attachments' => $a->attachments, 'compact' => true])</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        <a href="{{ route('a2.show', $a) }}" class="rounded border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Lihat') }}</a>
                                        @if ($rows[$i]['selectable'])
                                            <button type="button" @click="openReject('{{ route('a2.reject', $a) }}', @js($a->employee?->name))"
                                                    class="ml-1 rounded border border-rose-300 px-3 py-1 text-sm font-medium text-rose-700 hover:bg-rose-50">{{ t('Kembalikan') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <div class="mt-4">@include('assessments.partials.final-scale')</div>

        {{-- Sticky action bar --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-white shadow-sticky">
            <div class="flex min-h-[64px] w-full flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between md:px-10">
                <p class="text-sm text-slate-600"><span class="font-semibold text-slate-900" x-text="selected.length"></span> {{ t('dicentang dari :n', ['n' => $assessments->count()]) }}</p>
                <button type="button" @click="confirmOpen = true" :disabled="selected.length === 0"
                        class="rounded bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        x-text="@js(t('Approve yang dicentang')) + ' (' + selected.length + ')'">{{ t('Approve yang dicentang') }}</button>
            </div>
        </div>

        {{-- Confirm --}}
        <div x-show="confirmOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="if (!sending) confirmOpen = false">
            <div class="absolute inset-0 bg-slate-900/60" @click="if (!sending) confirmOpen = false"></div>
            <div class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-lg sm:rounded-lg">
                <div class="px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ t('Approve') }} <span x-text="selected.length"></span> {{ t('penilaian?') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ tb('Langsung disimpan dan dikunci. Yang lain tetap di daftar.') }}</p>
                    <div class="mt-4 grid grid-cols-4 gap-2">
                        <template x-for="letter in ['A', 'B', 'C', 'D']" :key="letter">
                            <div class="rounded border border-slate-200 p-2 text-center">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset" :class="finalGrade.classFor(letter)" x-text="letter"></span>
                                <p class="mt-1 text-xl font-semibold tabular-nums" x-text="rows.filter(r => selected.includes(r.id) && r.final === letter).length"></p>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" @click="confirmOpen = false" :disabled="sending" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Kembali') }}</button>
                    <button type="button" @click="sending = true; $refs.approveForm.submit()" :disabled="sending"
                            class="rounded bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" x-text="sending ? @js(t('Menyimpan…')) : @js(t('Ya, approve'))"></button>
                </div>
            </div>
        </div>
    @endif

    @include('a2.partials.reject-modal')
</div>
@endsection

@push('scripts')
<script>
    function a2Approvals(config) {
        return {
            rows: config.rows,
            selected: [],
            confirmOpen: false,
            sending: false,
            rejectOpen: false,
            rejectUrl: '',
            employeeName: '',
            note: '',
            selectable() { return this.rows.filter(r => r.selectable).map(r => r.id); },
            get allSelected() { const ids = this.selectable(); return ids.length > 0 && ids.every(id => this.selected.includes(id)); },
            toggleAll(on) { this.selected = on ? this.selectable() : []; },
            openReject(url, name) { this.rejectUrl = url; this.employeeName = name; this.note = ''; this.rejectOpen = true; },
        };
    }
</script>
@endpush
