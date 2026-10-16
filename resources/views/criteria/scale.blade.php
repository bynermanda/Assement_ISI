@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Panduan · Skala Nilai Akhir')
@section('heading', 'Panduan Penilaian')
@section('subheading', 'Skala Nilai Akhir: batas nilai untuk huruf A, B, C dan D. Berlaku untuk semua karyawan dan departemen, Assessment 1 dan Assessment 2.')

@section('content')
@include('criteria.partials.tabs', ['active' => 'scale'])

<div x-data="gradeScale(@js(['current' => $grades, 'defaults' => $defaults, 'scores' => $scores, 'old' => ['a' => old('a'), 'b' => old('b'), 'c' => old('c')]]))"
     class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1fr]">

    {{-- form --}}
    <form method="POST" action="{{ route('criteria.scale.update') }}" class="rounded-lg border border-slate-200 bg-white p-6"
          @submit="if (! valid || ! confirm(confirmText())) $event.preventDefault()">
        @csrf
        @method('PUT')
        <h2 class="text-xl font-bold text-slate-900">Batas nilai</h2>
        <p class="mt-1 text-base text-slate-600">Isi nilai <b>terendah</b> untuk setiap huruf. Batas atas mengikuti otomatis; D = 0 sampai di bawah batas C.</p>

        @if ($errors->any())
            <div class="mt-4 rounded border border-l-4 border-slate-200 border-l-rose-600 bg-white px-4 py-3 text-base font-bold text-slate-900" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="mt-5 space-y-4">
            <template x-for="row in inputs" :key="row.key">
                <div class="grid grid-cols-[3rem_1fr_9rem] items-center gap-4">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded text-xl font-bold ring-1 ring-inset" :class="classes[row.letter]" x-text="row.letter"></span>
                    <label :for="'f_' + row.key" class="text-base text-slate-900">
                        Nilai <span class="font-bold" x-text="row.letter"></span> mulai dari
                        <span class="block text-sm text-slate-600" x-text="'sampai ' + upper(row.letter)"></span>
                    </label>
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

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button type="button" @click="form = { a: defaults.A, b: defaults.B, c: defaults.C }" class="rounded border border-slate-300 bg-white px-5 py-3 text-base font-bold">
                Isi nilai bawaan ({{ $defaults['A'] }} / {{ $defaults['B'] }} / {{ $defaults['C'] }})
            </button>
            <button type="submit" :disabled="! valid || ! changed" class="rounded bg-teal-600 px-6 py-3 text-base font-bold">Simpan skala</button>
        </div>

        <p class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">
            @if ($setting)
                Terakhir diubah {{ $setting->updated_at?->locale('id')->translatedFormat('d F Y H:i') }} oleh <b>{{ $setting->updated_by }}</b>.
            @else
                Saat ini memakai skala bawaan aplikasi.
            @endif
            Huruf dihitung dari nilai akhir setiap kali ditampilkan, jadi skala baru langsung berlaku di semua form, approval dan export, termasuk penilaian yang sudah ada.
        </p>
    </form>

    <div class="space-y-6">
        {{-- preview, same layout as the printed scale --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Pratinjau</h2>
            <table class="table-lines mt-4 w-full text-center text-lg">
                <thead class="bg-slate-50"><tr><th class="px-4 py-3 text-xl">{{ Assessment::HEADINGS['scale'][0] }} {{ Assessment::HEADINGS['scale'][1] }}</th></tr></thead>
                <tbody>
                    <template x-for="letter in ['D', 'C', 'B', 'A']" :key="letter">
                        <tr><td class="px-4 py-3"><span x-text="letter + '級 (' + range(letter) + ')'"></span>&nbsp;&nbsp;&nbsp;<span x-text="'Nilai ' + letter + ' (' + range(letter) + ')'"></span></td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- impact on this month's assessments --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Dampak ke penilaian {{ $period }}</h2>
            <p class="mt-1 text-base text-slate-600"><span x-text="scores.length"></span> penilaian bulan ini (Assessment 1 + 2, selain draf).</p>
            <table class="table-lines mt-4 w-full text-base" x-show="scores.length">
                <thead class="bg-slate-50 text-left"><tr><th class="px-3 py-2">Nilai</th><th class="px-3 py-2 text-right">Skala sekarang</th><th class="px-3 py-2 text-right">Skala baru</th><th class="px-3 py-2 text-right">Selisih</th></tr></thead>
                <tbody>
                    <template x-for="letter in ['A', 'B', 'C', 'D']" :key="letter">
                        <tr>
                            <td class="px-3 py-2"><span class="inline-flex h-7 w-7 items-center justify-center rounded text-sm font-bold ring-1 ring-inset" :class="classes[letter]" x-text="letter"></span></td>
                            <td class="px-3 py-2 text-right tabular-nums" x-text="count(current, letter)"></td>
                            <td class="px-3 py-2 text-right font-bold tabular-nums" x-text="count(proposed, letter)"></td>
                            <td class="px-3 py-2 text-right tabular-nums" x-text="diff(letter)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function gradeScale(config) {
        const pick = (v, d) => (v === null || v === undefined || v === '' ? d : Number(v));
        return {
            current: config.current, defaults: config.defaults, scores: config.scores,
            form: { a: pick(config.old.a, config.current.A), b: pick(config.old.b, config.current.B), c: pick(config.old.c, config.current.C) },
            inputs: [{ key: 'a', letter: 'A' }, { key: 'b', letter: 'B' }, { key: 'c', letter: 'C' }],
            classes: @js(collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn ($l) => [$l => Assessment::finalGradeClass($l)])),

            get proposed() { return { A: this.form.a, B: this.form.b, C: this.form.c, D: 0 }; },
            get valid() {
                const { a, b, c } = this.form;
                return [a, b, c].every(Number.isInteger) && c > 0 && c < b && b < a && a <= 100;
            },
            get changed() { return this.form.a !== this.current.A || this.form.b !== this.current.B || this.form.c !== this.current.C; },
            upper(letter) { return { A: 100, B: this.form.a - 1, C: this.form.b - 1 }[letter]; },
            range(letter) {
                const g = this.proposed;
                return { A: g.A + '~100', B: g.B + '~' + (g.A - 1), C: g.C + '~' + (g.B - 1), D: '0~' + (g.C - 1) }[letter];
            },
            letterOf(scale, score) { for (const l of ['A', 'B', 'C']) { if (score >= scale[l]) return l; } return 'D'; },
            count(scale, letter) { return this.scores.filter(s => this.letterOf(scale, s) === letter).length; },
            diff(letter) { const d = this.count(this.proposed, letter) - this.count(this.current, letter); return d > 0 ? '+' + d : (d === 0 ? '–' : d); },
            confirmText() {
                return 'Simpan skala baru?\n\nA ' + this.range('A') + ' · B ' + this.range('B') + ' · C ' + this.range('C') + ' · D ' + this.range('D')
                    + '\n\nBerlaku untuk SEMUA karyawan dan departemen (Assessment 1 dan 2), termasuk penilaian yang sudah ada dan export.';
            },
        };
    }
</script>
@endpush
