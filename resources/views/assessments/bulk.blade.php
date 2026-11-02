@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Assessment Massal')
@section('heading', 'Assessment Massal' . ($unit ? ' · ' . $unit : ''))
@section('subheading', 'Periode: ' . $periodLabel . ' | Nilai seluruh seksi di sini, lalu simpan sekaligus.')

@section('content')
<div x-data="bulkGrid({
        rows: @js($rows),
        criteria: @js($criteria),
        grades: @js($grades),
        storeUrl: @js(route('assessments.bulk.store')),
        storageKey: @js('bulk:' . auth()->user()->username . ':' . $period . ':' . $unit),
     })"
     @beforeunload.window="if (dirty && !saving) { $event.preventDefault(); $event.returnValue = ''; }"
     class="space-y-4 pb-24">

    {{-- Section tabs --}}
    @if ($units->count() > 1)
        <div class="flex flex-wrap gap-2">
            @foreach ($units as $name => $count)
                <a href="{{ route('assessments.bulk', ['unit' => $name]) }}"
                   @class([
                       'rounded border px-3 py-1.5 text-sm',
                       'border-teal-600 bg-teal-600 font-semibold text-white' => $name === $unit,
                       'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' => $name !== $unit,
                   ])>
                    {{ $name }} <span class="ml-1 text-xs opacity-75">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($rows->isEmpty())
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
            Belum ada karyawan untuk akun Anda.
        </div>
    @else
        {{-- Messages --}}
        <div x-show="flash" x-cloak class="rounded border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status" x-text="flash"></div>
        <div x-show="restored" x-cloak class="flex flex-col gap-2 rounded border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900 sm:flex-row sm:items-center sm:justify-between">
            <span>Nilai yang belum disimpan dari kunjungan terakhir dipulihkan dari browser ini.</span>
            <button type="button" @click="discardLocal()" class="font-semibold text-sky-800 hover:underline">Buang</button>
        </div>

        {{-- Toolbar --}}
        <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
                <input type="search" x-model.debounce.200ms="q" placeholder="Cari nama atau NIK" aria-label="Cari karyawan"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30 sm:max-w-xs">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" x-model="onlyIncomplete" class="rounded border-slate-300 text-teal-600 focus:ring-teal-600">
                    Hanya yang belum lengkap
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="showTargets = !showTargets" :aria-expanded="showTargets"
                        class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    目標 Target <span x-text="showTargets ? '▴' : '▾'"></span>
                </button>
                <button type="button" @click="setAll(grades.B)"
                        class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                    Isi semua dengan B
                </button>
                <button type="button" @click="fillEmpty(grades.B)"
                        class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Isi yang kosong dengan B
                </button>
            </div>
        </div>

        {{-- 目標 Target per criterion (guidelines of this section) --}}
        <div x-show="showTargets" x-cloak class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-2">@include('assessments.partials.heading', ['key' => 'target'])</div>
            <dl class="grid grid-cols-1 divide-y divide-slate-100 md:grid-cols-2 md:divide-y-0">
                @foreach ($criteria as $column => $meta)
                    <div class="px-4 py-3 md:border-b md:border-slate-100">
                        <dt class="flex items-end justify-between gap-3">
                            @include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id']])
                            <span class="text-sm text-slate-500">{{ $meta['weight'] }}%</span>
                        </dt>
                        <dd class="mt-1 text-sm leading-relaxed text-slate-600">
                            @if ($targets[$column])
                                {!! Assessment::formatGuideline($targets[$column]) !!}
                            @else
                                <span class="text-slate-400">Target untuk seksi ini belum diisi.</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Grid --}}
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="max-h-[70vh] overflow-auto">
                <table class="table-lines min-w-full text-sm">
                    <thead class="sticky top-0 z-20 bg-slate-100 text-left align-bottom text-slate-600">
                        {{-- Row 1: item / weight heading over all criteria · Row 2: one column per criterion (all aligned) --}}
                        <tr>
                            <th rowspan="2" class="w-10 px-2 py-2 text-center font-medium">No</th>
                            <th rowspan="2" class="sticky left-0 z-30 min-w-[14rem] bg-slate-100 px-3 py-2 font-semibold text-slate-700">Karyawan</th>
                            <th colspan="{{ count($criteria) }}" class="px-3 py-2 text-center">
                                <div class="flex flex-wrap items-baseline justify-center gap-x-6 gap-y-1">
                                    <span>@include('assessments.partials.heading', ['key' => 'criteria'])</span>
                                    <span>@include('assessments.partials.heading', ['key' => 'weight'])</span>
                                </div>
                            </th>
                            <th rowspan="2" class="px-3 py-2 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                            <th rowspan="2" class="min-w-[6rem] px-3 py-2 text-center">
                                <span class="block whitespace-nowrap font-semibold text-slate-700">總分</span>
                                <span class="block whitespace-nowrap text-xs font-normal text-slate-500">Nilai Akhir</span>
                            </th>
                            <th rowspan="2" class="px-3 py-2 font-semibold text-slate-700">Status</th>
                            <th rowspan="2" class="min-w-[16rem] px-3 py-2">@include('assessments.partials.heading', ['key' => 'notes'])</th>
                        </tr>
                        <tr>
                            @foreach ($criteria as $column => $meta)
                                @include('assessments.partials.bulk-column-head', ['rowspan' => 1, 'target' => $targets[$column]])
                            @endforeach
                        </tr>
                    </thead>
                    {{-- Rows are rendered by Blade (fast for 100+ employees); Alpine only binds the values. --}}
                    <tbody class="divide-y divide-slate-100" @change="persist()">
                        @foreach ($rows as $i => $row)
                            <tr x-show="visible(rows[{{ $i }}])"
                                :class="errors[@js($row['id'])] ? 'bg-rose-50' : {{ $row['editable'] ? "(complete(rows[{$i}]) ? '' : 'bg-amber-50/40')" : "'bg-slate-50 text-slate-500'" }}">
                                <td class="px-2 py-1.5 text-center text-xs text-slate-400">{{ $i + 1 }}</td>
                                <td @class(['sticky left-0 z-10 px-3 py-1.5', 'bg-white' => $row['editable'], 'bg-slate-50' => ! $row['editable']])>
                                    <div class="font-medium text-slate-900">{{ $row['name'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $row['id'] }} · {{ $row['position'] }}</div>
                                    @if ($row['revision'])
                                        <div class="mt-0.5 max-w-xs text-xs text-rose-700">Perbaikan: {{ $row['revision'] }}</div>
                                    @endif
                                    <div x-show="errors[@js($row['id'])]" x-cloak class="mt-0.5 text-xs font-medium text-rose-700" x-text="errors[@js($row['id'])]"></div>
                                </td>
                                @foreach ($criteria as $column => $meta)
                                    <td class="px-1 py-1.5 text-center">
                                        @if ($row['editable'] && ! in_array($column, $row['locked'], true))
                                            <select x-model="rows[{{ $i }}].scores.{{ $column }}" aria-label="{{ $meta['text'] }} untuk {{ $row['name'] }}"
                                                    :class="cellClass(rows[{{ $i }}].scores.{{ $column }})"
                                                    class="w-16 rounded border px-1 py-1 text-center text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-teal-600/40">
                                                <option value="">–</option>
                                                @foreach ($grades as $letter => $points)<option value="{{ $points }}">{{ $letter }}</option>@endforeach
                                            </select>
                                        @else
                                            <span class="inline-block w-16 py-1 font-semibold" @if (in_array($column, $row['locked'], true)) title="{{ in_array($column, Assessment::SANCTION_COLUMNS, true) ? 'Sanksi HRD' : 'Dari HRD' }}" @endif>{{ Assessment::letterFor($row['scores'][$column]) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-right font-semibold tabular-nums" x-text="hasAny(rows[{{ $i }}]) ? total(rows[{{ $i }}]).toFixed(1) : '–'"></td>
                                <td class="px-3 py-1.5 text-center">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset"
                                          :class="finalGrade.classFor(finalOf(rows[{{ $i }}]))" x-text="finalOf(rows[{{ $i }}])">-</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-1.5">
                                    <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($row['status']) }}">{{ \App\Support\Ui::status($row['status']) }}</span>
                                </td>
                                <td class="px-3 py-1.5">
                                    @if ($row['editable'])
                                        <input type="text" x-model="rows[{{ $i }}].note" maxlength="{{ Assessment::NOTE_MAX }}" placeholder="Opsional"
                                               @input.debounce.400ms="persist()" aria-label="Catatan untuk {{ $row['name'] }}"
                                               class="block w-full rounded border border-slate-300 px-2 py-1 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                                    @else
                                        <span class="text-sm text-slate-600">{{ $row['note'] ?: '–' }}</span>
                                    @endif
                                    @if ($row['criterionNotes'])
                                        <div class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ collect($row['criterionNotes'])->map(fn ($t, $c) => "{$c}: {$t}")->implode("\n") }}">
                                            + {{ count($row['criterionNotes']) }} catatan per kriteria dari Form Assessment
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <p class="text-xs text-slate-500 sm:max-w-md">
                Nilai per item: @foreach ($grades as $letter => $points){{ $letter }} = {{ $points }}@if (! $loop->last), @endif @endforeach.
                Nilai Akhir muncul setelah semua item terisi. Baris terkirim / disetujui terkunci. Isian tersimpan sementara di browser sampai disimpan.
            </p>
            @include('assessments.partials.final-scale')
        </div>

        {{-- Sticky action bar --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-white shadow-sticky">
            <div class="flex min-h-[64px] w-full flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between md:px-10">
                <p class="text-sm text-slate-600">
                    <span class="font-semibold text-slate-900" x-text="summary.complete"></span> dari
                    <span x-text="summary.editable"></span> lengkap
                    <span x-show="dirty" class="ml-2 text-amber-700">· ada perubahan belum disimpan</span>
                </p>
                <div class="flex gap-2">
                    <button type="button" @click="send('draft')" :disabled="saving || summary.complete === 0"
                            class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                        Simpan draf
                    </button>
                    <button type="button" @click="openReview()" :disabled="saving"
                            class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:opacity-50">
                        Periksa &amp; Kirim Semua
                    </button>
                </div>
            </div>
        </div>

        {{-- Review modal --}}
        <div x-show="review" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="review-title"
             @keydown.escape.window="if (!saving) review = false">
            <div class="absolute inset-0 bg-slate-900/60" @click="if (!saving) review = false"></div>
            <div class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl sm:max-w-2xl sm:rounded-lg">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 id="review-title" class="text-lg font-semibold text-slate-900">Periksa sebelum mengirim · {{ $unit }}</h2>
                    <p class="text-sm text-slate-500">Baris yang dikirim masuk ke Penilai 2 dan terkunci.</p>
                </div>

                <div class="space-y-5 px-5 py-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded border border-emerald-200 bg-emerald-50 p-3">
                            <p class="text-xs text-emerald-800">Akan dikirim</p>
                            <p class="text-2xl font-semibold text-emerald-900" x-text="summary.complete"></p>
                        </div>
                        <div class="rounded border p-3" :class="summary.incomplete.length ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50'">
                            <p class="text-xs text-amber-800">Kosong / belum lengkap</p>
                            <p class="text-2xl font-semibold text-amber-900" x-text="summary.incomplete.length"></p>
                        </div>
                        <div class="rounded border border-slate-200 bg-slate-50 p-3">
                            <p class="text-xs text-slate-600">Sudah terkunci</p>
                            <p class="text-2xl font-semibold text-slate-800" x-text="summary.locked"></p>
                        </div>
                        <div class="rounded border border-slate-200 bg-slate-50 p-3">
                            <p class="text-xs text-slate-600">Rata-rata nilai</p>
                            <p class="text-2xl font-semibold text-slate-800" x-text="summary.average"></p>
                        </div>
                    </div>

                    <div x-show="summary.incomplete.length" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-semibold">Karyawan berikut belum lengkap dan TIDAK akan dikirim:</p>
                        <p class="mt-1" x-text="summary.incomplete.slice(0, 12).join(', ') + (summary.incomplete.length > 12 ? ' dan ' + (summary.incomplete.length - 12) + ' lainnya' : '')"></p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-slate-900">Nilai Akhir (jumlah orang per nilai, baris yang akan dikirim)</p>
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="letter in ['A', 'B', 'C', 'D']" :key="letter">
                                <div class="rounded border border-slate-200 p-2 text-center">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset"
                                          :class="finalGrade.classFor(letter)" x-text="letter"></span>
                                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900" x-text="summary.finals[letter]"></p>
                                    <p class="text-xs text-slate-500" x-text="summary.complete ? Math.round(summary.finals[letter] / summary.complete * 100) + '%' : '–'"></p>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-slate-900">Sebaran nilai per item (jumlah orang per item, baris yang akan dikirim)</p>
                        <div class="overflow-x-auto rounded border border-slate-200">
                            <table class="table-lines min-w-full text-sm">
                                <thead class="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">Kriteria</th>
                                        <template x-for="(points, letter) in grades" :key="letter">
                                            <th class="px-3 py-2 text-center font-medium" x-text="letter"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <template x-for="(meta, column) in criteria" :key="column">
                                        <tr>
                                            <td class="px-3 py-1.5">
                                                <span class="block text-xs text-slate-500" x-show="meta.zh" x-text="meta.zh"></span>
                                                <span class="font-semibold text-slate-900" x-text="meta.id"></span>
                                                <span class="text-slate-500" x-text="' · ' + meta.weight + '%'"></span>
                                            </td>
                                            <template x-for="(points, letter) in grades" :key="letter">
                                                <td class="px-3 py-1.5 text-center tabular-nums" x-text="summary.distribution[column][letter]"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="bg-slate-900 text-white">
                                    <tr>
                                        <td class="px-3 py-2 font-medium">Semua nilai</td>
                                        <template x-for="(points, letter) in grades" :key="letter">
                                            <td class="px-3 py-2 text-center font-semibold tabular-nums" x-text="summary.totals[letter]"></td>
                                        </template>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div x-show="serverMessage" class="rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">
                        <p class="font-semibold" x-text="serverMessage"></p>
                        <ul class="mt-1 list-inside list-disc">
                            <template x-for="(message, id) in errors" :key="id"><li x-text="message"></li></template>
                        </ul>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" @click="review = false" :disabled="saving"
                            class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Kembali</button>
                    <button type="button" @click="send('submit')" :disabled="saving || summary.complete === 0"
                            class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                            x-text="saving ? 'Mengirim…' : 'Konfirmasi & Kirim (' + summary.complete + ')'"></button>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script type="application/json" id="bulk-status-badges">@json(collect(Assessment::STATUSES)->mapWithKeys(fn ($s) => [$s => Assessment::badgeClass($s)]))</script>
<script>
    function bulkGrid(config) {
        const columns = Object.keys(config.criteria);
        const letters = Object.fromEntries(Object.entries(config.grades).map(([l, p]) => [String(p), l]));
        const badges  = JSON.parse(document.getElementById('bulk-status-badges').textContent);
        const store   = {
            get(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } },
            set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {} },
            remove(key) { try { localStorage.removeItem(key); } catch (e) {} },
        };

        return {
            rows: config.rows,
            criteria: config.criteria,
            grades: config.grades,
            q: '',
            onlyIncomplete: false,
            showTargets: false,
            review: false,
            saving: false,
            dirty: false,
            restored: false,
            flash: '',
            serverMessage: '',
            errors: {},
            original: '',

            init() {
                this.original = this.snapshot();

                try { this.flash = sessionStorage.getItem('bulk-flash') || ''; sessionStorage.removeItem('bulk-flash'); } catch (e) {}

                // Restore grades typed earlier in this browser but not saved yet
                const local = store.get(config.storageKey);
                if (local) {
                    this.editableRows().forEach(row => {
                        const saved = local[row.id];
                        if (!saved) return;
                        columns.forEach(column => {
                            if (!row.locked.includes(column) && saved.scores && column in saved.scores) {
                                row.scores[column] = saved.scores[column];
                            }
                        });
                        if (typeof saved.note === 'string') row.note = saved.note;
                    });
                    this.dirty = this.snapshot() !== this.original;
                    this.restored = this.dirty;
                    if (!this.dirty) store.remove(config.storageKey);
                }
            },

            editableRows() { return this.rows.filter(row => row.editable); },
            snapshot() { return JSON.stringify(Object.fromEntries(this.editableRows().map(row => [row.id, { scores: row.scores, note: row.note }]))); },

            persist() {
                const current = this.snapshot();
                this.dirty = current !== this.original;
                this.dirty ? store.set(config.storageKey, JSON.parse(current)) : store.remove(config.storageKey);
            },

            discardLocal() {
                store.remove(config.storageKey);
                this.dirty = false;
                window.location.reload();
            },

            letter(value) { return letters[String(value)] ?? '–'; },
            badge(status) { return badges[status] ?? 'bg-slate-50 text-slate-500 ring-slate-200'; },
            cellClass(value) {
                return {
                    '100': 'border-emerald-400 bg-emerald-50 text-emerald-800',
                    '80':  'border-slate-300 bg-white text-slate-800',
                    '60':  'border-amber-400 bg-amber-50 text-amber-800',
                    '0':   'border-rose-400 bg-rose-50 text-rose-800',
                }[String(value)] ?? 'border-dashed border-slate-300 bg-white text-slate-400';
            },

            hasAny(row) { return columns.some(column => row.scores[column] !== ''); },
            complete(row) { return columns.every(column => row.scores[column] !== ''); },
            total(row) {
                return columns.reduce((sum, column) => sum + (Number(row.scores[column]) || 0) * this.criteria[column].weight / 100, 0);
            },
            /** Nilai Akhir (A/B/C/D) of a complete row, "-" while items are missing. */
            finalOf(row) { return this.complete(row) ? finalGrade(this.total(row)) : '-'; },

            visible(row) {
                if (this.onlyIncomplete && (!row.editable || this.complete(row))) return false;
                const q = this.q.toLowerCase().trim();
                return q === '' || (row.name + ' ' + row.id).toLowerCase().includes(q);
            },

            /** Every editable cell gets this grade; asks first when grades would be overwritten. */
            setAll(points) {
                const value = String(points);
                const overwrites = this.editableRows().some(row => columns.some(c => !row.locked.includes(c) && row.scores[c] !== '' && row.scores[c] !== value));
                if (overwrites && !confirm('Sebagian karyawan sudah punya nilai lain. Timpa SEMUA dengan ' + this.letter(value) + '?')) return;
                this.editableRows().forEach(row => columns.forEach(c => { if (!row.locked.includes(c)) row.scores[c] = value; }));
                this.persist();
            },

            fillEmpty(points) {
                const value = String(points);
                this.editableRows().forEach(row => columns.forEach(c => { if (!row.locked.includes(c) && row.scores[c] === '') row.scores[c] = value; }));
                this.persist();
            },

            setColumn(column, points) {
                if (points === '') return;
                this.editableRows().forEach(row => { if (!row.locked.includes(column)) row.scores[column] = String(points); });
                this.persist();
            },

            get summary() {
                const editable   = this.editableRows();
                const ready      = editable.filter(row => this.complete(row));
                const blank      = () => Object.fromEntries(Object.keys(this.grades).map(l => [l, 0]));
                const totals     = blank();
                const distribution = Object.fromEntries(columns.map(c => [c, blank()]));

                const finals = blank();
                ready.forEach(row => {
                    columns.forEach(c => {
                        const l = this.letter(row.scores[c]);
                        if (l in totals) { distribution[c][l]++; totals[l]++; }
                    });
                    const f = this.finalOf(row);
                    if (f in finals) finals[f]++;
                });

                return {
                    editable: editable.length,
                    complete: ready.length,
                    incomplete: editable.filter(row => !this.complete(row)).map(row => row.name),
                    locked: this.rows.length - editable.length,
                    average: ready.length ? (ready.reduce((s, row) => s + this.total(row), 0) / ready.length).toFixed(1) : '–',
                    distribution,
                    totals,
                    finals,
                };
            },

            openReview() {
                this.serverMessage = '';
                this.review = true;
            },

            /** ONE request with every complete, editable row. */
            async send(action) {
                const ready = this.editableRows().filter(row => this.complete(row));
                if (ready.length === 0) return;
                if (action === 'draft' && this.summary.incomplete.length
                    && !confirm(this.summary.incomplete.length + ' karyawan yang belum lengkap tidak disimpan ke server (tetap di browser ini). Lanjutkan?')) return;

                this.saving = true;
                this.errors = {};
                this.serverMessage = '';

                const payload = {
                    action,
                    rows: ready.map(row => ({
                        id_employee: row.id,
                        ...Object.fromEntries(columns.map(c => [c, row.locked.includes(c) ? null : Number(row.scores[c])])),
                        note: row.note.trim(),
                    })),
                };

                try {
                    const response = await fetch(config.storeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        // "rows.3" or "rows.3.score_safety" -> employee of the 4th row sent
                        Object.entries(body.errors || {}).forEach(([key, message]) => {
                            const match = key.match(/^rows\.(\d+)/);
                            const id = match ? ready[Number(match[1])]?.id : key;
                            this.errors[id ?? key] = Array.isArray(message) ? message[0] : message;
                        });
                        this.serverMessage = body.message || ('Server menjawab ' + response.status + '. Tidak ada yang disimpan.');
                        if (response.status === 419) this.serverMessage = 'Sesi Anda habis. Nilai tetap tersimpan di browser ini: muat ulang halaman lalu kirim lagi.';
                        if (action === 'draft') { this.review = true; }
                        return;
                    }

                    store.remove(config.storageKey);
                    this.dirty = false;
                    try { sessionStorage.setItem('bulk-flash', body.message || 'Tersimpan.'); } catch (e) {}
                    window.location.reload();
                } catch (e) {
                    this.serverMessage = 'Tidak ada koneksi ke server. Nilai tetap tersimpan di browser ini; coba lagi.';
                    if (action === 'draft') { this.review = true; }
                } finally {
                    this.saving = false;
                }
            },
        };
    }
</script>
@endpush
