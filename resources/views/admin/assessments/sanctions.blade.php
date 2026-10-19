@extends('layouts.app')

@section('title', 'Impor sanksi')
@section('heading', 'Impor Sanksi (HRD) · Poin 6')
@section('subheading', 'Unggah daftar sanksi HRD. Surat sanksi pada bulan yang dipilih mengisi Poin 6 安全紀律 Kepatuhan Keselamatan: 1 sanksi = C, 2 sanksi atau lebih dalam bulan yang sama = D. Catatan Poin 6 otomatis "[No. Surat] - [Kasus]". Setelah disimpan, Poin 6 terkunci untuk Penilai dan Approver (badge "Sanksi HRD"); status tetap Draf.')

@section('content')
{{--
    Hybrid import like the attendance import: the browser parses the HRD sanction list (PapaParse / SheetJS, loaded on
    demand), keeps the letters of the chosen month and previews the Point 6 grade; SanctionImportController@store
    re-validates, stores the letters (hrd_sanctions) and recomputes Point 6 from the database.
--}}
@php
    use App\Models\HrdSanction;
    $letterOf = fn ($points) => array_search($points, \App\Models\Assessment::GRADES, true) ?: '-';
@endphp
<div x-data="sanctionImport(@js([
        'period'     => $period,
        'storeUrl'   => route('admin.assessments.sanctions.store'),
        'pageUrl'    => route('admin.assessments.sanctions'),
        'writable'   => $writable,
        'types'      => HrdSanction::TYPES,
        'storedKeys' => $storedKeys,
        'one'        => $letterOf(HrdSanction::GRADE_ONE),
        'many'       => $letterOf(HrdSanction::GRADE_MANY),
     ]))" class="space-y-6 pb-28">

    {{-- 1. period + file + rules --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_28rem]">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[12rem_1fr] sm:items-end">
                <label class="block text-sm font-bold text-slate-900">Periode
                    <input type="month" :value="period" @change="changePeriod($event.target.value)"
                           class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-base">
                </label>
                <label class="block text-sm font-bold text-slate-900">File sanksi HRD (CSV, XLS, XLSX)
                    <input type="file" accept=".csv,.txt,.xls,.xlsx" @change="readFile($event)"
                           class="mt-1 block w-full rounded border border-slate-300 bg-white text-base file:mr-3 file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:font-bold">
                </label>
            </div>
            <p class="mt-3 text-sm text-slate-600">Periode {{ $periodLabel }}: hanya surat dengan <b>tanggal sanksi</b> di bulan ini yang dipakai; bulan lain di file ditampilkan abu-abu dan tidak disimpan. File tidak dikirim ke server.</p>

            <div x-show="loading" x-cloak class="mt-4 text-base font-bold text-slate-700" role="status">Membaca file…</div>
            <div x-show="error" x-cloak class="mt-4 rounded border border-l-4 border-slate-200 border-l-rose-600 bg-white px-4 py-3 text-base text-slate-900" role="alert">
                <p class="font-bold" x-text="error"></p>
                <p class="mt-1 text-sm text-slate-600">Format yang dibaca: "DATA LIST SANKSI KARYAWAN" dengan kolom <b>NAMA</b>, <b>NIK</b>, <b>BAGIAN</b>, <b>TANGGAL</b>, <b>NO. SURAT</b>,
                    <b>SP 1</b> / <b>SP 2</b> / <b>SP 3</b> / <b>SP PERTAMA &amp; TERAKHIR</b> dan <b>KASUS</b>. Baris judul bulan ("Februari 2026") dan baris kosong diabaikan.</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm">
            <p class="text-base font-bold text-slate-900">Aturan Poin 6</p>
            <table class="table-lines mt-3 w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr><th class="px-3 py-2">Sanksi di bulan itu</th><th class="px-3 py-2">Poin 6</th></tr>
                </thead>
                <tbody>
                    <tr><td class="px-3 py-1.5">1 surat (SP 1 / SP 2 / SP 3 / SP Pertama &amp; Terakhir)<span class="block text-slate-600">pelanggaran kedisiplinan 1 kali</span></td><td class="px-3 py-1.5 font-bold">{{ $letterOf(HrdSanction::GRADE_ONE) }}</td></tr>
                    <tr><td class="px-3 py-1.5">2 surat atau lebih<span class="block text-slate-600">pelanggaran kedisiplinan 2 kali</span></td><td class="px-3 py-1.5 font-bold">{{ $letterOf(HrdSanction::GRADE_MANY) }}</td></tr>
                    <tr><td class="px-3 py-1.5">tidak ada sanksi</td><td class="px-3 py-1.5">diisi manual oleh Penilai</td></tr>
                </tbody>
            </table>
            <p class="mt-3 text-slate-600">Sesuai panduan Poin 6. Surat yang sudah tersimpan ikut dihitung, jadi impor ulang file yang sama tidak menggandakan sanksi.</p>
        </div>
    </div>

    {{-- 2. preview --}}
    <template x-if="rows.length">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <template x-for="card in summaryCards" :key="card.key">
                    <button type="button" @click="filter = card.key"
                            class="rounded border bg-white p-4 text-left" :class="filter === card.key ? 'border-2 border-slate-900' : 'border-slate-200'">
                        <span class="block text-sm text-slate-600" x-text="card.label"></span>
                        <span class="mt-1 block text-3xl font-bold text-slate-900" x-text="card.count"></span>
                    </button>
                </template>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <input type="search" x-model.debounce.200ms="q" placeholder="Cari nama, NIK, bagian atau no. surat" aria-label="Cari"
                       class="w-full rounded border border-slate-300 bg-white px-3 py-2 text-base sm:max-w-sm">
                <p class="text-sm text-slate-600"><span x-text="file"></span> · <span x-text="rows.length"></span> surat sanksi dibaca</p>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="max-h-[65vh] overflow-auto">
                    <table class="table-lines min-w-full text-sm">
                        <thead class="sticky top-0 z-20 bg-slate-100 text-left text-slate-700">
                            <tr>
                                <th class="px-3 py-2 text-center">No</th>
                                <th class="px-3 py-2">NIK</th>
                                <th class="min-w-[13rem] px-3 py-2">Nama</th>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2">No. Surat · Jenis</th>
                                <th class="min-w-[22rem] px-3 py-2">Kasus untuk catatan Poin 6<span class="block font-normal">boleh diringkas · maks. 450 karakter</span></th>
                                <th class="px-3 py-2 text-center">Poin 6</th>
                                <th class="min-w-[12rem] px-3 py-2">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in visibleRows" :key="row.key">
                                <tr class="align-top" :class="row.state === 'ok' ? '' : 'bg-slate-50 text-slate-500'">
                                    <td class="px-3 py-2 text-center text-slate-500" x-text="row.no"></td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs" x-text="row.nik"></td>
                                    <td class="px-3 py-2">
                                        <span class="font-bold text-slate-900" x-text="row.name"></span>
                                        <span class="block text-xs text-slate-600" x-text="row.unit || row.section"></span>
                                        <span x-show="row.nameInFile && row.nameInFile !== row.name" class="block text-xs text-slate-600" x-text="'Di file: ' + row.nameInFile"></span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2" x-text="row.dateLabel"></td>
                                    <td class="px-3 py-2">
                                        <span class="block whitespace-nowrap font-mono text-xs" x-text="row.letter || '(tanpa no. surat)'"></span>
                                        <span class="mt-1 inline-block rounded bg-slate-900 px-1.5 py-0.5 text-xs font-bold text-white" x-text="row.type"></span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <textarea x-model="row.summary" rows="3" maxlength="450" :disabled="row.state !== 'ok'" :aria-label="'Kasus ' + row.name"
                                                  class="block w-full resize-y rounded border border-slate-300 bg-white px-2 py-1.5 text-sm disabled:bg-slate-50"></textarea>
                                        <span class="mt-1 block text-xs text-slate-600"><span x-text="row.summary.length"></span>/450 ·
                                            <button type="button" class="font-bold underline" @click="row.open = ! row.open" x-text="row.open ? 'tutup teks asli' : 'lihat teks asli'"></button></span>
                                        <p x-show="row.open" x-cloak class="mt-1 whitespace-pre-line rounded bg-slate-50 p-2 text-xs text-slate-700" x-text="row.caseText"></p>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <span x-show="row.state === 'ok'" class="inline-block w-10 rounded py-1 text-base font-bold text-white"
                                              :class="gradeOf(row) === config.many ? 'bg-rose-600' : 'bg-amber-500'" x-text="gradeOf(row)"></span>
                                        <span x-show="row.state === 'ok' && countOf(row) > 1" class="block text-xs text-slate-600" x-text="countOf(row) + ' sanksi'"></span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-block rounded px-2 py-0.5 text-xs font-bold ring-1 ring-inset" :class="badge(row)" x-text="row.note"></span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="visibleRows.length === 0"><td colspan="8" class="px-3 py-8 text-center text-slate-500">Tidak ada baris untuk filter ini.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>

    {{-- result of the last save --}}
    <div x-show="result" x-cloak class="rounded border border-l-4 border-slate-200 bg-white px-5 py-4" :class="resultOk ? 'border-l-emerald-800' : 'border-l-rose-600'" role="status">
        <p class="text-base font-bold text-slate-900" x-text="result"></p>
        <ul x-show="skipped.length" class="mt-2 max-h-48 list-inside list-disc overflow-auto text-sm text-slate-700">
            <template x-for="(s, i) in skipped" :key="i"><li x-text="(s.name ? s.name + ' (' + s.id + ')' : s.id) + ': ' + s.reason"></li></template>
        </ul>
        <a x-show="resultOk" :href="config.pageUrl + '?period=' + period" class="mt-3 inline-block text-sm font-bold underline">Muat ulang daftar sanksi tersimpan</a>
    </div>

    {{-- 3. letters already stored for this period --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-col gap-1 border-b border-slate-200 px-6 py-4 sm:flex-row sm:items-baseline sm:justify-between">
            <p class="text-base font-bold text-slate-900">Sanksi tersimpan · {{ $periodLabel }}</p>
            <p class="text-sm text-slate-600">{{ $stored->count() }} surat · {{ $stored->pluck('employee_id')->unique()->count() }} karyawan · hapus = Poin 6 dihitung ulang (tanpa surat: terbuka lagi untuk Penilai)</p>
        </div>
        <div class="overflow-x-auto">
            <table class="table-lines min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-700">
                    <tr>
                        <th class="px-3 py-2">Karyawan</th>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">No. Surat · Jenis</th>
                        <th class="min-w-[20rem] px-3 py-2">Kasus (catatan Poin 6)</th>
                        <th class="px-3 py-2">Penilaian</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stored as $s)
                        @php $a = $existing->get($s->employee_id); @endphp
                        <tr class="align-top">
                            <td class="px-3 py-2"><span class="font-bold text-slate-900">{{ $s->employee?->name ?? $s->name_in_file }}</span><span class="block font-mono text-xs text-slate-600">{{ $s->employee_id }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $s->sanction_date->translatedFormat('d M Y') }}</td>
                            <td class="px-3 py-2"><span class="block whitespace-nowrap font-mono text-xs">{{ $s->letter_no ?: '(tanpa no. surat)' }}</span><span class="mt-1 inline-block rounded bg-slate-900 px-1.5 py-0.5 text-xs font-bold text-white">{{ $s->sanction_type }}</span></td>
                            <td class="px-3 py-2 text-slate-700">{{ $s->case_summary }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                @if ($a)
                                    {{ \App\Support\Ui::status($a->status) }}
                                    @if ($a->sanction_locked_at)<span class="mt-1 block w-fit rounded bg-rose-50 px-1.5 py-0.5 text-xs font-bold text-rose-800 ring-1 ring-inset ring-rose-300">Sanksi HRD</span>@endif
                                @else
                                    <span class="text-slate-500">belum ada</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <form method="POST" action="{{ route('admin.assessments.sanctions.destroy', $s) }}"
                                      onsubmit="return confirm('Hapus surat sanksi ini? Poin 6 karyawan ini dihitung ulang.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded border border-rose-300 bg-white px-3 py-1.5 text-sm font-bold">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Belum ada sanksi tersimpan untuk periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 4. save bar --}}
    <div x-show="rows.length" x-cloak class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-white shadow-sticky">
        <div class="flex min-h-[64px] w-full flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between md:px-10">
            <p class="text-base text-slate-700"><span class="font-bold text-slate-900" x-text="counts.ok"></span> surat sanksi
                (<span x-text="employeesOk"></span> karyawan) akan disimpan untuk periode <span class="font-bold" x-text="period"></span> · Poin 6 terkunci</p>
            <button type="button" @click="save()" :disabled="saving || counts.ok === 0"
                    class="rounded bg-teal-600 px-6 py-3 text-base font-bold" x-text="saving ? 'Menyimpan…' : 'Simpan ke Matriks Penilaian'"></button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // employees of the database, by normalised NIK (same rule as ReviewRules::normalizeNik): no dots, no leading zeros
    window.sanctionEmployees = @json($employees);

    function sanctionImport(config) {
        const LIBS = {
            papa: 'https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js',
            xlsx: 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
        };
        const loadScript = src => new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) return resolve();
            const s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('Library pembaca file tidak bisa dimuat (cek koneksi internet).'));
            document.head.appendChild(s);
        });
        const norm     = v => String(v ?? '').replace(/[^A-Za-z0-9]/g, '').replace(/^0+/, '').toUpperCase();
        const normName = v => String(v ?? '').toUpperCase().replace(/[^A-Z]/g, '');
        const squash   = v => String(v ?? '').replace(/\s+/g, ' ').trim();
        const pad      = n => String(n).padStart(2, '0');
        const MONTHS   = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, mei: 5, jun: 6, jul: 7, aug: 8, agu: 8, agt: 8, agus: 8, sep: 9, oct: 10, okt: 10, nov: 11, dec: 12, des: 12 };
        const BULAN    = { januari: 1, februari: 2, maret: 3, april: 4, mei: 5, juni: 6, juli: 7, agustus: 8, september: 9, oktober: 10, november: 11, desember: 12 };
        const LABEL    = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        const byName   = {};
        Object.values(window.sanctionEmployees).forEach(e => { const k = normName(e.name); byName[k] = byName[k] === undefined ? e : null; });   // null = not unique

        /** "4/Feb/26", "04-Feb-2026", "2026-02-04", "4/2/26" (day first) or a Date from Excel -> "2026-02-04" (or null). */
        function parseDate(v, sectionMonth) {
            let y, m, d;
            if (v instanceof Date) {
                const t = new Date(v.getTime() + 12 * 3600 * 1000);   // SheetJS dates can be a few seconds before midnight
                [y, m, d] = [t.getFullYear(), t.getMonth() + 1, t.getDate()];
            } else {
                const s = squash(v);
                let r;
                if ((r = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/))) [y, m, d] = [+r[1], +r[2], +r[3]];
                else if ((r = s.match(/^(\d{1,2})[\/\-. ]+([A-Za-z]{3,})[\/\-., ]+(\d{2,4})$/))) [d, m, y] = [+r[1], MONTHS[r[2].toLowerCase().slice(0, 3)], +r[3]];
                else if ((r = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/))) {
                    [d, m, y] = [+r[1], +r[2], +r[3]];
                    if (sectionMonth && m !== sectionMonth && d === sectionMonth && m <= 31) [d, m] = [m, d];   // m/d/y written by Excel in US format
                } else return null;
                if (y < 100) y += 2000;
            }
            const date = new Date(y, m - 1, d);
            if (! m || isNaN(date) || date.getMonth() !== m - 1 || date.getDate() !== d) return null;
            return `${y}-${pad(m)}-${pad(d)}`;
        }

        /** Default text for the Point 6 note: the HRD case without the standard opening, first sentence, max ~300 characters. */
        function summarize(text) {
            let s = squash(text);
            s = s.replace(/^(sesuai dengan|berdasarkan|berdsasarkan)\s+(adanya\s+)?(formuli?t?r?\s+)?[^,]*?\b(mengenai|terkait|terdapat|sehubungan dengan)\s+(adanya\s+)?(masalah\s+)?/i, '');
            const dot = s.search(/\.\s/);
            if (dot > 40 && dot < 320) s = s.slice(0, dot);
            if (s.length > 300) s = s.slice(0, 300).replace(/\s+\S*$/, '') + '…';
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        return {
            config, period: config.period,
            rows: [], file: '', loading: false, error: '', q: '', filter: 'all',
            saving: false, result: '', resultOk: true, skipped: [],

            changePeriod(value) {
                if (! value || value === this.period) return;
                if (this.rows.length && ! confirm('Ganti periode? File perlu dipilih ulang.')) return;
                window.location = config.pageUrl + '?period=' + encodeURIComponent(value);
            },

            async readFile(event) {
                const f = event.target.files[0];
                this.error = ''; this.result = ''; this.rows = [];
                if (! f) return;
                const ext = f.name.split('.').pop().toLowerCase();
                if (! ['csv', 'txt', 'xls', 'xlsx'].includes(ext)) { this.error = 'Format file tidak didukung. Pakai CSV, XLS atau XLSX.'; return; }
                if (f.size > 10 * 1024 * 1024) { this.error = 'File lebih dari 10 MB.'; return; }
                this.loading = true; this.file = f.name;
                try {
                    const table = ['csv', 'txt'].includes(ext) ? await this.readCsv(f) : await this.readExcel(f);
                    this.rows = this.parse(table);
                    this.filter = this.counts.ok ? 'ok' : 'all';
                } catch (e) {
                    this.error = e.message || 'File tidak bisa dibaca.';
                } finally {
                    this.loading = false;
                }
            },

            async readCsv(f) {
                await loadScript(LIBS.papa);
                const res = await new Promise((resolve, reject) => Papa.parse(f, { skipEmptyLines: false, complete: resolve, error: reject }));
                return res.data;
            },

            async readExcel(f) {
                await loadScript(LIBS.xlsx);
                const book  = XLSX.read(await f.arrayBuffer(), { type: 'array', cellDates: true });
                const sheet = book.Sheets[book.SheetNames.find(n => /sanksi/i.test(n)) ?? book.SheetNames[0]];
                if (! sheet || ! sheet['!ref']) return [];
                // dates as Date objects, everything else as the text Excel shows (NIK "05.251107" keeps its zeros)
                const range = XLSX.utils.decode_range(sheet['!ref']);
                const out = [];
                for (let r = range.s.r; r <= range.e.r; r++) {
                    const row = [];
                    for (let c = range.s.c; c <= range.e.c; c++) {
                        const cell = sheet[XLSX.utils.encode_cell({ r, c })];
                        row.push(! cell ? '' : cell.t === 'd' ? cell.v : (cell.w ?? String(cell.v ?? '')));
                    }
                    out.push(row);
                }
                return out;
            },

            /** Finds the header row and the columns, then one preview row per sanction letter. */
            parse(table) {
                const H = r => r.map(c => (c instanceof Date ? '' : squash(c).toUpperCase()));
                const h = table.findIndex(r => { const t = H(r); return t.includes('NIK') && t.includes('KASUS'); });
                if (h < 0) throw new Error('Baris judul tidak ditemukan: kolom "NIK" dan "KASUS" harus ada.');
                const head = H(table[h]);
                const col  = test => head.findIndex(test);
                const c = {
                    name: col(v => v === 'NAMA'), nik: col(v => v === 'NIK'), section: col(v => v === 'BAGIAN'),
                    date: col(v => v.startsWith('TANGGAL')), letter: col(v => /^NO\.?\s*SURAT/.test(v)),
                    kasus: col(v => v === 'KASUS'), article: col(v => v === 'KETERANGAN'),
                };
                const missing = Object.entries({ 'NAMA': c.name, 'NIK': c.nik, 'TANGGAL': c.date, 'NO. SURAT': c.letter, 'KASUS': c.kasus })
                    .filter(([, i]) => i < 0).map(([label]) => label);
                if (missing.length) throw new Error('Kolom tidak ditemukan: ' + missing.join(', ') + '.');
                const typeCols = config.types.map(type => [type, head.indexOf(type)]).filter(([, i]) => i >= 0);
                if (! typeCols.length) throw new Error('Kolom jenis sanksi (SP 1, SP 2, SP 3, SP PERTAMA & TERAKHIR) tidak ditemukan.');

                const cell = (r, i) => (i < 0 ? '' : (r[i] instanceof Date ? r[i] : squash(r[i])));
                const out = []; let sectionMonth = null;
                for (const r of table.slice(h + 1)) {
                    // month heading rows: "Februari 2026"
                    const heading = r.map(v => squash(v instanceof Date ? '' : v)).find(v => /^[A-Za-z]+\s+\d{4}$/.test(v) && BULAN[v.split(' ')[0].toLowerCase()]);
                    if (heading && ! /\d/.test(cell(r, c.nik))) { sectionMonth = BULAN[heading.split(' ')[0].toLowerCase()]; continue; }
                    const nik = String(cell(r, c.nik));
                    if (! /\d/.test(nik)) continue;

                    const date = parseDate(cell(r, c.date), sectionMonth);
                    const type = (typeCols.find(([, i]) => squash(r[i]) !== '') || [null])[0];
                    const nameInFile = String(cell(r, c.name));
                    let emp = window.sanctionEmployees[norm(nik)], byNameMatch = false;
                    if (! emp && byName[normName(nameInFile)]) { emp = byName[normName(nameInFile)]; byNameMatch = true; }

                    const letter  = squash(cell(r, c.letter));
                    const caseText = String(cell(r, c.kasus));
                    const row = {
                        key: out.length, no: out.length + 1, nik, nameInFile, name: emp ? emp.name : nameInFile, unit: emp ? emp.unit : '',
                        section: String(cell(r, c.section)), id: emp ? emp.id : nik, date, letter, type, caseText,
                        summary: summarize(caseText).slice(0, 450), article: String(cell(r, c.article)), open: false,
                        dateLabel: date ? `${+date.slice(8)} ${LABEL[+date.slice(5, 7)]} ${date.slice(0, 4)}` : String(cell(r, c.date)) || '-',
                    };
                    Object.assign(row, this.state(row, emp, byNameMatch));
                    out.push(row);
                }
                if (! out.length) throw new Error('Tidak ada baris sanksi di file (kolom NIK kosong).');
                return out;
            },

            state(row, emp, byNameMatch) {
                if (! row.date) return { state: 'invalid', note: 'Tanggal tidak terbaca' };
                if (! row.type) return { state: 'invalid', note: 'Jenis sanksi (SP 1 / 2 / 3 ...) kosong' };
                if (row.date.slice(0, 7) !== this.period) return { state: 'other', note: 'Bulan lain: tidak disimpan' };
                if (! emp) return { state: 'notfound', note: 'NIK tidak ada di data karyawan aktif' };
                if (! emp.a1) return { state: 'a2', note: 'Assessment 2 / pimpinan: Poin 6 tidak dipakai' };
                if (emp.status && ! config.writable.includes(emp.status)) return { state: 'locked', note: 'Penilaian sudah dikirim / disetujui: tidak diubah' };
                const stored = config.storedKeys.includes(this.keyOf({ ...row, id: emp.id }));
                return { state: 'ok', note: (stored ? 'Sudah tersimpan: diperbarui' : emp.status ? 'Draf yang ada: Poin 6 diisi' : 'Draf baru: Poin 6 diisi') + (byNameMatch ? ' · cocok lewat nama' : '') };
            },

            /** Same key as HrdSanction::keyFor: a letter imported twice counts once. */
            keyOf(row) { return [row.id, row.date, squash(row.letter).toUpperCase(), row.type].join('|'); },
            /** Letters of this employee in the month: already stored + new ones in the file. */
            countOf(row) {
                const keys = new Set(config.storedKeys.filter(k => k.startsWith(row.id + '|')));
                this.rows.forEach(r => { if (r.state === 'ok' && r.id === row.id) keys.add(this.keyOf(r)); });
                return keys.size;
            },
            gradeOf(row) { return this.countOf(row) > 1 ? config.many : config.one; },

            get counts() {
                const c = { all: this.rows.length, ok: 0, other: 0, notfound: 0, a2: 0, locked: 0, invalid: 0 };
                this.rows.forEach(r => c[r.state]++);
                return c;
            },
            get employeesOk() { return new Set(this.rows.filter(r => r.state === 'ok').map(r => r.id)).size; },
            get summaryCards() {
                const c = this.counts;
                return [
                    { key: 'all', label: 'Semua surat di file', count: c.all },
                    { key: 'ok', label: 'Akan disimpan (bulan ini)', count: c.ok },
                    { key: 'other', label: 'Bulan lain', count: c.other },
                    { key: 'notfound', label: 'Tidak ditemukan / tidak valid', count: c.notfound + c.invalid },
                    { key: 'locked', label: 'Terkunci / Assessment 2', count: c.locked + c.a2 },
                ];
            },
            get visibleRows() {
                const q = this.q.toLowerCase().trim();
                const groups = { notfound: ['notfound', 'invalid'], locked: ['locked', 'a2'] };
                return this.rows.filter(r => (this.filter === 'all' || (groups[this.filter] ?? [this.filter]).includes(r.state))
                    && (q === '' || (r.name + ' ' + r.nik + ' ' + r.unit + ' ' + r.section + ' ' + r.letter).toLowerCase().includes(q)));
            },
            badge(row) {
                return { ok: 'bg-emerald-50 text-emerald-800 ring-emerald-300', locked: 'bg-amber-50 text-amber-800 ring-amber-300',
                         notfound: 'bg-rose-50 text-rose-800 ring-rose-300', invalid: 'bg-rose-50 text-rose-800 ring-rose-300' }[row.state]
                       ?? 'bg-slate-100 text-slate-600 ring-slate-300';
            },

            async save() {
                const rows = this.rows.filter(r => r.state === 'ok');
                if (! rows.length) return;
                const empty = rows.find(r => squash(r.summary) === '');
                if (empty) { alert(`Kasus untuk ${empty.name} masih kosong.`); return; }
                if (! confirm(`Simpan ${rows.length} surat sanksi (${this.employeesOk} karyawan) untuk periode ${this.period}? Poin 6 karyawan tersebut menjadi C / D dan TERKUNCI (Penilai tidak bisa mengubahnya); status tetap Draf.`)) return;
                this.saving = true; this.result = ''; this.skipped = [];
                try {
                    const response = await fetch(config.storeUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ period: this.period, rows: rows.map(r => ({
                            nik: r.nik, id_employee: r.id, name: r.nameInFile, section: r.section, sanction_date: r.date,
                            letter_no: r.letter, type: r.type, case_text: r.caseText, case_summary: squash(r.summary), article: r.article })) }),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (! response.ok) {
                        this.resultOk = false;
                        this.result = response.status === 419 ? 'Sesi habis: muat ulang halaman lalu simpan lagi.'
                            : response.status === 429 ? 'Terlalu sering menyimpan: tunggu satu menit.'
                            : (body.message || ('Gagal menyimpan (kode ' + response.status + '). Tidak ada yang disimpan.'));
                        if (body.errors) this.skipped = Object.entries(body.errors).slice(0, 20).map(([k, m]) => ({ id: k, reason: [].concat(m)[0] }));
                        return;
                    }
                    this.resultOk = true;
                    this.result = body.message;
                    this.skipped = body.skipped || [];
                    rows.forEach(r => { config.storedKeys.push(this.keyOf(r)); r.note = 'Tersimpan · Poin 6 terkunci'; });
                } catch (e) {
                    this.resultOk = false;
                    this.result = 'Tidak ada koneksi ke server. Tidak ada yang disimpan; coba lagi.';
                } finally {
                    this.saving = false;
                }
            },
        };
    }
</script>
@endpush
