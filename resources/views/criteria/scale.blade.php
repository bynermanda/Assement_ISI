@extends('layouts.app')

@use('App\Models\Assessment')
@use('App\Support\ApprovedExport')

@section('title', 'Panduan · Skala Nilai Akhir')
@section('heading', 'Panduan Penilaian')
@section('subheading', 'Batas huruf A–D untuk semua karyawan (A1 & A2). Berlaku mulai periode terpilih; periode lalu tetap memakai skala lama.')

@section('content')
@include('criteria.partials.tabs', ['active' => 'scale'])

@php
    $range = fn (array $g) => "A {$g['A']}–100 · B {$g['B']}–" . ($g['A'] - 1) . " · C {$g['C']}–" . ($g['B'] - 1) . ' · D 0–' . ($g['C'] - 1);
@endphp

{{-- the scale in force now --}}
<div class="mb-6 rounded-lg border border-slate-200 bg-white p-6">
    <p class="text-sm font-bold uppercase text-slate-600">Berlaku untuk periode {{ $currentLabel }}</p>
    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach (array_reverse(Assessment::finalGradeRanges($current), true) as $letter => [$min, $max])
            <div class="flex items-center gap-3 rounded border border-slate-200 px-4 py-3">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded text-xl font-bold ring-1 ring-inset {{ Assessment::finalGradeClass($letter) }}">{{ $letter }}</span>
                <span class="text-lg font-bold tabular-nums text-slate-900">{{ $min }} – {{ $max }}</span>
            </div>
        @endforeach
    </div>
</div>

<div x-data="gradeScale(@js([
        'current' => $current, 'scaleByPeriod' => $scaleByPeriod, 'defaults' => $defaults, 'scores' => $scores,
        'old' => ['from' => old('from'), 'a' => old('a'), 'b' => old('b'), 'c' => old('c')],
     ]))" class="grid grid-cols-1 gap-6 xl:grid-cols-2">

    {{-- new version --}}
    <form method="POST" action="{{ route('criteria.scale.update') }}" class="rounded-lg border border-slate-200 bg-white p-6"
          @submit="if (! valid || ! confirm(confirmText())) $event.preventDefault()">
        @csrf
        @method('PUT')
        <h2 class="text-xl font-bold text-slate-900">Ubah skala</h2>
        <p class="mt-1 text-base text-slate-600">Isi nilai <b>terendah</b> tiap huruf. D = 0 sampai di bawah batas C.</p>

        @if ($errors->any())
            <div class="mt-4 rounded border border-l-4 border-slate-200 border-l-rose-600 bg-white px-4 py-3 text-base font-bold text-slate-900" role="alert">{{ $errors->first() }}</div>
        @endif

        <label class="mt-5 block text-base font-bold text-slate-900">Berlaku mulai periode
            <select name="from" x-model="from" @change="loadScale()" class="mt-1 block h-12 w-full rounded border border-slate-300 bg-white px-3 text-base">
                @foreach ($periods as $p => $label)
                    <option value="{{ $p }}">{{ $label }}{{ $p === $current ? ' (periode berjalan)' : '' }}</option>
                @endforeach
            </select>
            <span class="mt-1 block text-sm font-normal text-slate-600">Hanya periode berjalan atau sesudahnya. Berlaku sampai ada versi berikutnya.</span>
        </label>

        <div class="mt-5 space-y-4">
            <template x-for="row in inputs" :key="row.key">
                <div class="grid grid-cols-[3rem_1fr_9rem] items-center gap-4">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded text-xl font-bold ring-1 ring-inset" :class="classes[row.letter]" x-text="row.letter"></span>
                    <label :for="'f_' + row.key" class="text-base text-slate-900">Nilai <b x-text="row.letter"></b> mulai dari
                        <span class="block text-sm text-slate-600" x-text="'sampai ' + upper(row.letter)"></span></label>
                    <input :id="'f_' + row.key" :name="row.key" type="number" min="1" max="100" step="1" required x-model.number="form[row.key]"
                           class="h-12 w-full rounded border border-slate-300 bg-white px-3 text-right text-xl font-bold tabular-nums">
                </div>
            </template>
            <div class="grid grid-cols-[3rem_1fr_9rem] items-center gap-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded text-xl font-bold ring-1 ring-inset" :class="classes.D">D</span>
                <span class="text-base text-slate-900">Nilai <b>D</b> <span class="block text-sm text-slate-600" x-text="'0 sampai ' + (form.c - 1)"></span></span>
                <span class="text-right text-xl font-bold tabular-nums text-slate-500">0</span>
            </div>
        </div>

        <p x-show="! valid" x-cloak class="mt-4 text-base font-bold text-rose-700" role="alert">Batas harus berurutan: 0 &lt; C &lt; B &lt; A ≤ 100 (bilangan bulat).</p>
        <p x-show="valid && ! changed" x-cloak class="mt-4 text-base text-slate-600">Sama dengan skala yang berlaku untuk periode ini.</p>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button type="button" @click="form = { a: defaults.A, b: defaults.B, c: defaults.C }" class="rounded border border-slate-300 bg-white px-5 py-3 text-base font-bold">
                Isi skala awal ({{ $defaults['A'] }} / {{ $defaults['B'] }} / {{ $defaults['C'] }})
            </button>
            <button type="submit" :disabled="! valid || ! changed" class="rounded bg-teal-600 px-6 py-3 text-base font-bold">Simpan skala</button>
        </div>
    </form>

    <div class="space-y-6">
        {{-- preview --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Pratinjau <span class="font-normal text-slate-600" x-text="'· mulai ' + periodLabel()"></span></h2>
            <table class="table-lines mt-4 w-full text-center text-lg">
                <thead class="bg-slate-50"><tr><th class="px-4 py-3 text-xl">{{ Assessment::HEADINGS['scale'][0] }} {{ Assessment::HEADINGS['scale'][1] }}</th></tr></thead>
                <tbody>
                    <template x-for="letter in ['D', 'C', 'B', 'A']" :key="letter">
                        <tr><td class="px-4 py-3"><span x-text="letter + '級 (' + rangeOf(letter) + ')'"></span>&nbsp;&nbsp;&nbsp;<span x-text="'Nilai ' + letter + ' (' + rangeOf(letter) + ')'"></span></td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- impact: only the current period can change now --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Dampak ke penilaian {{ $currentLabel }}</h2>
            <template x-if="from !== current">
                <p class="mt-2 text-base text-slate-600">Skala ini mulai berlaku <b x-text="periodLabel()"></b>: penilaian {{ $currentLabel }} dan sebelumnya tidak berubah.</p>
            </template>
            <template x-if="from === current">
                <div>
                    <p class="mt-1 text-base text-slate-600"><span x-text="scores.length"></span> penilaian bulan ini (A1 + A2, selain draf). Bulan lalu tidak berubah.</p>
                    <table class="table-lines mt-4 w-full text-base" x-show="scores.length">
                        <thead class="bg-slate-50 text-left"><tr><th class="px-3 py-2">Nilai</th><th class="px-3 py-2 text-right">Skala sekarang</th><th class="px-3 py-2 text-right">Skala baru</th><th class="px-3 py-2 text-right">Selisih</th></tr></thead>
                        <tbody>
                            <template x-for="letter in ['A', 'B', 'C', 'D']" :key="letter">
                                <tr>
                                    <td class="px-3 py-2"><span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset" :class="classes[letter]" x-text="letter"></span></td>
                                    <td class="px-3 py-2 text-right tabular-nums" x-text="count(scaleByPeriod[current], letter)"></td>
                                    <td class="px-3 py-2 text-right font-bold tabular-nums" x-text="count(proposed, letter)"></td>
                                    <td class="px-3 py-2 text-right tabular-nums" x-text="diff(letter)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</div>

{{-- history --}}
<div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-xl font-bold text-slate-900">Riwayat skala</h2>
        <p class="text-base text-slate-600">Tiap penilaian memakai skala periodenya. Skala periode lalu terkunci.</p>
    </div>
    <table class="table-lines min-w-full text-base">
        <thead class="bg-slate-50 text-left">
            <tr><th class="px-4 py-3">Berlaku mulai</th><th class="px-4 py-3">Skala</th><th class="px-4 py-3">Diubah oleh</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
        </thead>
        <tbody>
            @foreach ($timeline as $i => $t)
                @php
                    $isPast   = $t['from'] !== null && strcmp($t['from'], $current) < 0 && $i !== $active;
                    $isFuture = $t['from'] !== null && strcmp($t['from'], $current) > 0;
                    $canUndo  = $t['from'] !== null && strcmp($t['from'], $current) >= 0;
                @endphp
                <tr class="{{ $i === $active ? 'bg-slate-50' : '' }}">
                    <td class="px-4 py-3 font-bold">{{ $t['from'] ? ApprovedExport::labelFor($t['from']) : 'Skala awal aplikasi' }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $range($t['grades']) }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ $t['by'] ? $t['by'] . ' · ' . \Illuminate\Support\Carbon::parse($t['at'])->locale('id')->translatedFormat('d M Y H:i') : '–' }}</td>
                    <td class="px-4 py-3">
                        @if ($i === $active)
                            <span class="rounded bg-emerald-50 px-2 py-0.5 text-sm font-bold text-emerald-800 ring-1 ring-inset ring-emerald-300">Berlaku sekarang</span>
                        @elseif ($isFuture)
                            <span class="rounded bg-amber-50 px-2 py-0.5 text-sm font-bold text-amber-800 ring-1 ring-inset ring-amber-300">Akan datang</span>
                        @else
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-sm font-bold text-slate-600 ring-1 ring-inset ring-slate-300">Sudah lewat · terkunci</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($canUndo)
                            <form method="POST" action="{{ route('criteria.scale.destroy', $t['from']) }}"
                                  onsubmit="return confirm('Batalkan skala mulai {{ ApprovedExport::labelFor($t['from']) }}? Periode itu kembali memakai skala sebelumnya.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded border border-rose-300 px-4 py-2 text-sm font-bold">Batalkan</button>
                            </form>
                        @else
                            <span class="text-sm text-slate-500">–</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script type="application/json" id="scale-period-labels">@json(collect($periods))</script>
<script>
    function gradeScale(config) {
        const num = (v, d) => (v === null || v === undefined || v === '' ? d : Number(v));
        const from = config.old.from || config.current;
        const base = config.scaleByPeriod[from] || config.scaleByPeriod[config.current];
        const labels = JSON.parse(document.getElementById('scale-period-labels').textContent);
        return {
            current: config.current, scaleByPeriod: config.scaleByPeriod, defaults: config.defaults, scores: config.scores, from,
            form: { a: num(config.old.a, base.A), b: num(config.old.b, base.B), c: num(config.old.c, base.C) },
            inputs: [{ key: 'a', letter: 'A' }, { key: 'b', letter: 'B' }, { key: 'c', letter: 'C' }],
            classes: @js(collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn ($l) => [$l => Assessment::finalGradeClass($l)])),

            loadScale() { const s = this.scaleByPeriod[this.from]; this.form = { a: s.A, b: s.B, c: s.C }; },
            periodLabel() { return labels[this.from] || this.from; },
            get proposed() { return { A: this.form.a, B: this.form.b, C: this.form.c, D: 0 }; },
            get valid() { const { a, b, c } = this.form; return [a, b, c].every(Number.isInteger) && c > 0 && c < b && b < a && a <= 100; },
            get changed() { const s = this.scaleByPeriod[this.from]; return this.form.a !== s.A || this.form.b !== s.B || this.form.c !== s.C; },
            upper(letter) { return { A: 100, B: this.form.a - 1, C: this.form.b - 1 }[letter]; },
            rangeOf(letter) { const g = this.proposed; return { A: g.A + '~100', B: g.B + '~' + (g.A - 1), C: g.C + '~' + (g.B - 1), D: '0~' + (g.C - 1) }[letter]; },
            letterOf(scale, s) { for (const l of ['A', 'B', 'C']) { if (s >= scale[l]) return l; } return 'D'; },
            count(scale, letter) { return this.scores.filter(s => this.letterOf(scale, s) === letter).length; },
            diff(letter) { const d = this.count(this.proposed, letter) - this.count(this.scaleByPeriod[this.current], letter); return d > 0 ? '+' + d : (d === 0 ? '–' : d); },
            confirmText() {
                return 'Simpan skala baru mulai ' + this.periodLabel() + '?\n\nA ' + this.rangeOf('A') + ' · B ' + this.rangeOf('B') + ' · C ' + this.rangeOf('C') + ' · D ' + this.rangeOf('D')
                    + '\n\nBerlaku untuk semua karyawan dan departemen mulai ' + this.periodLabel() + '. Periode sebelumnya tetap memakai skala lamanya.';
            },
        };
    }
</script>
@endpush
