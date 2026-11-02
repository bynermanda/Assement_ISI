@extends('layouts.app')

@use('App\Models\Assessment')
@use('App\Support\ReviewRules')

{{-- Penilai 2: tick and approve (part of the list is fine), or send single assessments back --}}
@section('title', 'Approval')
@section('heading', tb('Approval Massal'))
@section('subheading', t('Centang lalu klik Approve; langsung tersimpan. Sisanya bisa di-approve nanti atau dikembalikan ke Penilai 1.'))

@section('content')
@php
    $rows  = $assessments->map(fn (Assessment $a) => [
        'id'         => $a->id,
        'unit'       => ReviewRules::unitOf($a->employee),
        'text'       => mb_strtolower($a->employee->name . ' ' . $a->employee->id_employee),
        'final'      => Assessment::finalGradeFor($a->final_score, $a->period),
        'selectable' => ReviewRules::mayApprove(auth()->user(), $a),   // four-eyes; Penilai 1 + 2 accounts may approve their own section
    ])->values();
    $units = $rows->pluck('unit')->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
@endphp

<div x-data="approvalList({ rows: @js($rows), note: @js(old('rejection_note', '')) })" class="pb-24">

    @if ($assessments->isEmpty())
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <p class="font-medium text-slate-700">{{ tb('Belum ada yang perlu disetujui') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ tb('Penilaian yang dikirim Penilai 1 akan muncul di sini.') }}</p>
        </div>
    @else
        {{-- Toolbar --}}
        <div class="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
                <input type="search" x-model.debounce.200ms="q" placeholder="{{ t('Cari nama atau NIK') }}" aria-label="{{ t('Cari') }}"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30 sm:max-w-xs">
                @if ($units->count() > 1)
                    <select x-model="unit" aria-label="{{ t('Seksi') }}"
                            class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                        <option value="">{{ t('Semua seksi') }} ({{ $rows->count() }})</option>
                        @foreach ($units as $u)
                            <option value="{{ $u }}">{{ $u }} ({{ $rows->where('unit', $u)->count() }})</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <p class="text-sm text-slate-600">
                <span class="font-semibold text-slate-900">{{ $rows->count() }}</span> {{ t('menunggu persetujuan') }}
            </p>
        </div>

        <form method="POST" action="{{ route('approval.approve-selected') }}" x-ref="approveForm">
            @csrf
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="max-h-[70vh] overflow-auto">
                    <table class="table-lines min-w-full text-sm">
                        <thead class="sticky top-0 z-20 bg-slate-100 text-left align-bottom text-slate-600">
                            <tr>
                                <th class="w-10 px-3 py-3 text-center">
                                    <input type="checkbox" :checked="allVisibleSelected" x-effect="$el.indeterminate = someVisibleSelected" @change="toggleVisible($event.target.checked)"
                                           aria-label="{{ t('Pilih semua yang tampil') }}" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                                </th>
                                <th class="min-w-[14rem] px-3 py-3 font-semibold text-slate-700">{{ tb('Karyawan') }}</th>
                                <th class="px-3 py-3 font-semibold text-slate-700">Penilai 1</th>
                                @foreach (Assessment::CRITERIA as $column => $meta)
                                    <th class="px-2 py-3 text-center font-normal" title="{{ Assessment::itemText($column) }} ({{ $meta['weight'] }}%)">
                                        @include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id'], 'center' => true])
                                        <span class="block text-xs text-slate-500">{{ $meta['weight'] }}%</span>
                                    </th>
                                @endforeach
                                <th class="px-3 py-3 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                                <th class="min-w-[6rem] px-3 py-3 text-center"><span class="block whitespace-nowrap font-semibold text-slate-700">總分</span><span class="block whitespace-nowrap text-xs font-normal text-slate-500">{{ t('Nilai Akhir') }}</span></th>
                                <th class="min-w-[14rem] px-3 py-3">@include('assessments.partials.heading', ['key' => 'notes'])</th>
                                <th class="px-3 py-3 text-right font-semibold text-slate-700">{{ tb('Kembalikan') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($assessments as $i => $assessment)
                                @php
                                    $row         = $rows[$i];
                                    $finalLetter = $row['final'];
                                    $noteLines   = collect($assessment->notes ?? [])->map(fn ($text, $key) => ($key === Assessment::NOTE_GENERAL ? t('Catatan umum') : Assessment::itemText($key)) . ': ' . $text);
                                @endphp
                                <tr x-show="visible(rows[{{ $i }}])" class="align-middle"
                                    :class="selected.includes({{ $assessment->id }}) ? 'bg-emerald-50/70' : ''">
                                    <td class="px-3 py-2 text-center">
                                        @if ($row['selectable'])
                                            <input type="checkbox" name="ids[]" value="{{ $assessment->id }}" x-model.number="selected"
                                                   aria-label="Approve {{ $assessment->employee->name }}"
                                                   class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                                        @else
                                            <span class="text-xs text-slate-400" title="{{ t('Penilaian ini Anda buat sendiri; harus di-approve Penilai 2 lain') }}">–</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <p class="font-medium text-slate-900">{{ $assessment->employee->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $assessment->employee->id_employee }} &middot; {{ $row['unit'] }} &middot; {{ \App\Support\ApprovedExport::labelFor($assessment->period) }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-slate-700">{{ $assessment->creator?->name ?? $assessment->created_by ?? '-' }}</td>
                                    @foreach (Assessment::CRITERIA as $column => $meta)
                                        <td class="px-2 py-2 text-center font-semibold">{{ Assessment::letterFor($assessment->{$column}) }}</td>
                                    @endforeach
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($assessment->final_score, 1) }}</td>
                                    <td class="px-3 py-2 text-center">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset {{ Assessment::finalGradeClass($finalLetter) }}">{{ $finalLetter }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700">
                                        @forelse ($noteLines as $line)
                                            <p class="max-w-xs truncate" title="{{ $line }}">{{ $line }}</p>
                                        @empty
                                            <span class="text-slate-400">–</span>
                                        @endforelse
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        @if ($row['selectable'])
                                            <button type="button"
                                                    @click="openReject('{{ route('approval.reject', $assessment) }}', @js($assessment->employee->name))"
                                                    class="rounded border border-rose-300 px-3 py-1 text-sm font-medium text-rose-700 hover:bg-rose-50">
                                                {{ t('Kembalikan') }}
                                            </button>
                                        @else
                                            <span class="text-xs text-slate-500">{{ t('Dibuat oleh Anda') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <p class="text-xs text-slate-500 sm:max-w-md">
                {{ tb('Yang disetujui terkunci dan keluar dari daftar. Klik Kembalikan untuk minta perbaikan ke Penilai 1.') }}
            </p>
            @include('assessments.partials.final-scale')
        </div>

        {{-- Sticky action bar --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-white shadow-sticky">
            <div class="flex min-h-[64px] w-full flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between md:px-10">
                <p class="text-sm text-slate-600">
                    <span class="font-semibold text-slate-900" x-text="selected.length"></span> {{ t('dicentang') }}
                    <button type="button" x-show="selected.length" @click="selected = []" class="ml-2 text-slate-500 hover:underline">{{ t('Hapus centang') }}</button>
                </p>
                <button type="button" @click="confirmOpen = true" :disabled="selected.length === 0"
                        class="rounded bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 disabled:opacity-50"
                        x-text="@js(t('Approve yang dicentang')) + ' (' + selected.length + ')'">{{ t('Approve yang dicentang') }}</button>
            </div>
        </div>

        {{-- Confirm modal --}}
        <div x-show="confirmOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="confirm-title"
             @keydown.escape.window="if (!sending) confirmOpen = false">
            <div class="absolute inset-0 bg-slate-900/60" @click="if (!sending) confirmOpen = false"></div>
            <div class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-lg sm:rounded-lg">
                <div class="px-5 py-4">
                    <h2 id="confirm-title" class="text-lg font-semibold text-slate-900">{{ t('Approve') }} <span x-text="selected.length"></span> {{ t('penilaian?') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ tb('Langsung disimpan dan dikunci. Yang lain tetap di daftar.') }}</p>
                    <p class="mt-4 text-sm font-semibold text-slate-900">{{ tb('Nilai Akhir penilaian yang dicentang') }}</p>
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        <template x-for="letter in ['A', 'B', 'C', 'D']" :key="letter">
                            <div class="rounded border border-slate-200 p-2 text-center">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset" :class="finalGrade.classFor(letter)" x-text="letter"></span>
                                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900" x-text="selectedFinals[letter]"></p>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" @click="confirmOpen = false" :disabled="sending"
                            class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Kembali') }}</button>
                    <button type="button" @click="sending = true; $refs.approveForm.submit()" :disabled="sending"
                            class="rounded bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            x-text="sending ? @js(t('Menyimpan…')) : @js(t('Ya, approve'))"></button>
                </div>
            </div>
        </div>
    @endif

    {{-- Reject modal --}}
    <div x-show="rejectOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="rejectOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="rejectOpen = false"></div>

        <form method="POST" :action="rejectUrl" class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-lg sm:rounded-lg">
            @csrf
            <div class="px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ tb('Kembalikan ke Penilai 1') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('Penilaian untuk') }} <span class="font-medium text-slate-700" x-text="employeeName"></span></p>

                <label for="rejection_note" class="mt-4 block text-sm font-medium text-slate-700">{{ t('Apa yang perlu diperbaiki?') }}</label>
                <textarea id="rejection_note" name="rejection_note" rows="4" required minlength="5" maxlength="1000" x-model="note"
                          @class([
                              'mt-1 block w-full rounded border px-3 py-2 text-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500/30',
                              'border-slate-300' => ! $errors->has('rejection_note'),
                              'border-rose-500' => $errors->has('rejection_note'),
                          ])></textarea>
                @error('rejection_note')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" @click="rejectOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Batal') }}</button>
                <button type="submit" class="rounded bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('Kembalikan') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function approvalList(config) {
        return {
            rows: config.rows,
            selected: [],
            q: '',
            unit: '',
            confirmOpen: false,
            sending: false,
            rejectOpen: false,
            rejectUrl: '',
            employeeName: '',
            note: config.note,

            visible(row) {
                const q = this.q.toLowerCase().trim();
                return (this.unit === '' || row.unit === this.unit) && (q === '' || row.text.includes(q));
            },
            visibleSelectable() { return this.rows.filter(row => row.selectable && this.visible(row)); },
            get allVisibleSelected() {
                const rows = this.visibleSelectable();
                return rows.length > 0 && rows.every(row => this.selected.includes(row.id));
            },
            get someVisibleSelected() {
                const rows = this.visibleSelectable();
                const n = rows.filter(row => this.selected.includes(row.id)).length;
                return n > 0 && n < rows.length;
            },
            /** Ticks / unticks only the rows that are visible with the current search and section filter. */
            toggleVisible(on) {
                const ids = this.visibleSelectable().map(row => row.id);
                this.selected = on
                    ? [...new Set([...this.selected, ...ids])]
                    : this.selected.filter(id => !ids.includes(id));
            },
            get selectedFinals() {
                const counts = { A: 0, B: 0, C: 0, D: 0 };
                this.rows.forEach(row => { if (this.selected.includes(row.id) && row.final in counts) counts[row.final]++; });
                return counts;
            },
            openReject(url, name) { this.rejectUrl = url; this.employeeName = name; this.note = ''; this.rejectOpen = true; },
        };
    }
</script>
@endpush
