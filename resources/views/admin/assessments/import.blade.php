@extends('layouts.app')

@section('title', 'Impor kehadiran')
@section('heading', 'Impor Kehadiran (HRD)')
@section('subheading', 'Unggah file absensi HRD. File dibaca dan dihitung di browser, periksa / ubah nilainya di tabel, lalu simpan. Setelah disimpan, nilai Poin 1 dan 2 terkunci (tidak bisa diubah Penilai / Approver); status tetap Draf dan Penilai melengkapi 4 kriteria lain. Koreksi: impor ulang.')

@section('content')
{{--
    Hybrid import: the browser parses the HRD export (PapaParse for CSV, SheetJS for XLS/XLSX, both loaded on demand),
    computes the two attendance grades and shows an editable preview; AssessmentImportController@store re-validates
    and saves one JSON batch as Draft.
--}}
<div x-data="attendanceImport(@js([
        'period'   => $period,
        'storeUrl' => route('admin.assessments.import.store'),
        'pageUrl'  => route('admin.assessments.import'),
        'writable' => $writable,
        'grades'   => \App\Models\Assessment::GRADES,
     ]))" class="space-y-6 pb-28">

    {{-- 1. rules + file --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_28rem]">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[12rem_1fr] sm:items-end">
                <label class="block text-sm font-bold text-slate-900">Periode
                    <input type="month" :value="period" @change="changePeriod($event.target.value)"
                           class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-base">
                </label>
                <label class="block text-sm font-bold text-slate-900">File absensi (CSV, XLS, XLSX)
                    <input type="file" accept=".csv,.txt,.xls,.xlsx" @change="readFile($event)" x-ref="file"
                           class="mt-1 block w-full rounded border border-slate-300 bg-white text-base file:mr-3 file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:font-bold">
                </label>
            </div>
            <p class="mt-3 text-sm text-slate-600">Periode {{ $periodLabel }}. File tidak dikirim ke server: hanya hasil nilai yang disimpan.</p>

            <div x-show="loading" x-cloak class="mt-4 text-base font-bold text-slate-700" role="status">Membaca file…</div>
            <div x-show="error" x-cloak class="mt-4 rounded border border-l-4 border-slate-200 border-l-rose-600 bg-white px-4 py-3 text-base text-slate-900" role="alert">
                <p class="font-bold" x-text="error"></p>
                <p class="mt-1 text-sm text-slate-600">Pastikan file adalah ekspor absensi HRD dengan kolom <b>Empl ID</b>, <b>Nama Karyawan</b>, <b>Ijin Pribadi</b>, <b>Sakit</b> dan <b>Terlambat</b>
                    (boleh dua baris judul seperti "Full / 1/2" dan "Hari / Mnt"). Baris "Department :", "Sub Total" dan "Grand Total" diabaikan otomatis.</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm">
            <p class="text-base font-bold text-slate-900">Cara menghitung</p>
            <table class="table-lines mt-3 w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr><th class="px-3 py-2">Nilai</th><th class="px-3 py-2">Poin 1 · Tingkat Kehadiran<span class="block font-normal text-slate-600">terbesar dari Ijin Pribadi / Sakit (hari)</span></th><th class="px-3 py-2">Poin 2 · Terlambat<span class="block font-normal text-slate-600">jumlah kali</span></th></tr>
                </thead>
                <tbody>
                    <tr><td class="px-3 py-1.5 font-bold">A</td><td class="px-3 py-1.5">0 hari</td><td class="px-3 py-1.5">0 kali</td></tr>
                    <tr><td class="px-3 py-1.5 font-bold">B</td><td class="px-3 py-1.5">1 hari (termasuk ½ hari)</td><td class="px-3 py-1.5">1 kali</td></tr>
                    <tr><td class="px-3 py-1.5 font-bold">C</td><td class="px-3 py-1.5">2 – 3 hari</td><td class="px-3 py-1.5">2 – 3 kali</td></tr>
                    <tr><td class="px-3 py-1.5 font-bold">D</td><td class="px-3 py-1.5">lebih dari 3 hari</td><td class="px-3 py-1.5">lebih dari 3 kali</td></tr>
                </tbody>
            </table>
            <p class="mt-3 text-slate-600">Hari = kolom Full + kolom ½ (mis. 1 + 0,5 = 1,5 hari → C).</p>
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
                <input type="search" x-model.debounce.200ms="q" placeholder="Cari nama, NIK atau seksi" aria-label="Cari"
                       class="w-full rounded border border-slate-300 bg-white px-3 py-2 text-base sm:max-w-sm">
                <p class="text-sm text-slate-600"><span x-text="file"></span> · <span x-text="rows.length"></span> baris karyawan dibaca</p>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="max-h-[65vh] overflow-auto">
                    <table class="table-lines min-w-full text-sm">
                        <thead class="sticky top-0 z-20 bg-slate-100 text-left text-slate-700">
                            <tr>
                                <th class="px-3 py-2 text-center">No</th>
                                <th class="px-3 py-2">NIK</th>
                                <th class="min-w-[14rem] px-3 py-2">Nama</th>
                                <th class="px-3 py-2 text-right">Ijin<span class="block font-normal">hari</span></th>
                                <th class="px-3 py-2 text-right">Sakit<span class="block font-normal">hari</span></th>
                                <th class="px-3 py-2 text-right">Terlambat<span class="block font-normal">kali</span></th>
                                <th class="px-3 py-2 text-center">Poin 1<span class="block font-normal">Tingkat Kehadiran</span></th>
                                <th class="px-3 py-2 text-center">Poin 2<span class="block font-normal">Berada di area kerja</span></th>
                                <th class="min-w-[14rem] px-3 py-2">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in visibleRows" :key="row.key">
                                <tr :class="row.state === 'ok' ? '' : 'bg-slate-50 text-slate-500'">
                                    <td class="px-3 py-1.5 text-center text-slate-500" x-text="row.no"></td>
                                    <td class="whitespace-nowrap px-3 py-1.5 font-mono text-xs" x-text="row.nik"></td>
                                    <td class="px-3 py-1.5">
                                        <span class="font-bold text-slate-900" x-text="row.name"></span>
                                        <span class="block text-xs text-slate-600" x-text="row.unit || row.dept"></span>
                                    </td>
                                    <td class="px-3 py-1.5 text-right tabular-nums" x-text="fmt(row.ijin)"></td>
                                    <td class="px-3 py-1.5 text-right tabular-nums" x-text="fmt(row.sakit)"></td>
                                    <td class="px-3 py-1.5 text-right tabular-nums" x-text="fmt(row.late)"></td>
                                    <template x-for="field in ['p1', 'p2']" :key="field">
                                        <td class="px-2 py-1.5 text-center">
                                            <select x-model.number="row[field]" :disabled="row.state !== 'ok'" :aria-label="(field === 'p1' ? 'Poin 1 ' : 'Poin 2 ') + row.name"
                                                    class="w-20 rounded border border-slate-300 bg-white px-2 py-1 text-center text-base font-bold"
                                                    :class="row[field] !== row[field + 'Auto'] ? 'border-2 border-slate-900' : ''">
                                                <template x-for="(points, letter) in grades" :key="letter">
                                                    <option :value="points" x-text="letter" :selected="row[field] === points"></option>
                                                </template>
                                            </select>
                                            <span x-show="row.state === 'ok' && row[field] !== row[field + 'Auto']" class="block text-xs text-slate-600">diubah</span>
                                        </td>
                                    </template>
                                    <td class="px-3 py-1.5">
                                        <span class="inline-block rounded px-2 py-0.5 text-xs font-bold ring-1 ring-inset" :class="badge(row)" x-text="row.note"></span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="visibleRows.length === 0"><td colspan="9" class="px-3 py-8 text-center text-slate-500">Tidak ada baris untuk filter ini.</td></tr>
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
            <template x-for="s in skipped" :key="s.id"><li x-text="(s.name ? s.name + ' (' + s.id + ')' : s.id) + ': ' + s.reason"></li></template>
        </ul>
    </div>

    {{-- 3. save bar --}}
    <div x-show="rows.length" x-cloak class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-white shadow-sticky">
        <div class="flex min-h-[64px] w-full flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between md:px-10">
            <p class="text-base text-slate-700"><span class="font-bold text-slate-900" x-text="counts.ok"></span> karyawan akan disimpan (Draf, nilai kehadiran terkunci) untuk periode <span class="font-bold" x-text="period"></span>
                <span x-show="changed" class="ml-2 text-slate-600">· <span x-text="changed"></span> nilai diubah manual</span></p>
            <button type="button" @click="save()" :disabled="saving || counts.ok === 0"
                    class="rounded bg-teal-600 px-6 py-3 text-base font-bold" x-text="saving ? 'Menyimpan…' : 'Simpan ke Matriks Penilaian'"></button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
{{-- data as a JSON block (not inline in the script): @json escapes < > & ' " so it cannot close the tag --}}
<script type="application/json" id="import-employees">@json($employees)</script>
<script>
    // employees of the database, by normalised NIK (same rule as ReviewRules::normalizeNik): no dots, no leading zeros
    window.importEmployees = JSON.parse(document.getElementById('import-employees').textContent);

    function attendanceImport(config) {
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
        const norm = v => String(v ?? '').replace(/[^A-Za-z0-9]/g, '').replace(/^0+/, '').toUpperCase();
        const num  = v => { const n = parseFloat(String(v ?? '').trim().replace(',', '.')); return isNaN(n) ? 0 : n; };
        const cell = (row, i) => (i === null || i < 0 ? '' : String(row[i] ?? '').trim());
        // Point 1 days / Point 2 times -> grade points (A 100, B 80, C 60, D 0)
        const byDays  = d => d <= 0 ? 100 : d <= 1 ? 80 : d <= 3 ? 60 : 0;
        const byTimes = t => t <= 0 ? 100 : t <= 1 ? 80 : t <= 3 ? 60 : 0;

        return {
            period: config.period, grades: config.grades,
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
                    this.filter = 'all';
                } catch (e) {
                    this.error = e.message || 'File tidak bisa dibaca.';
                } finally {
                    this.loading = false;
                }
            },

            async readCsv(f) {
                await loadScript(LIBS.papa);
                // every cell as text: NIK "16.131220" must keep its digits
                const res = await new Promise((resolve, reject) => Papa.parse(f, { skipEmptyLines: false, complete: resolve, error: reject }));
                return res.data;
            },

            async readExcel(f) {
                await loadScript(LIBS.xlsx);
                const book  = XLSX.read(await f.arrayBuffer(), { type: 'array' });
                const sheet = book.Sheets[book.SheetNames[0]];
                // formatted text of each cell (raw: false) so NIK and decimals look like in Excel
                return XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '' });
            },

            /** Finds the header rows, the columns, and turns every employee row into a preview row. */
            parse(table) {
                const h = table.findIndex(r => { const t = r.join(' ').toLowerCase(); return t.includes('ijin') && t.includes('sakit') && t.includes('terlambat'); });
                if (h < 0) throw new Error('Baris judul tidak ditemukan: kolom "Ijin Pribadi", "Sakit" dan "Terlambat" harus ada.');
                const head = table[h].map(c => String(c ?? '').replace(/\s+/g, ' ').trim().toLowerCase());
                const col  = re => head.findIndex(c => re.test(c));
                const c = { nik: col(/empl|nik|^id$|id karyawan/), name: col(/nama/), ijin: col(/ijin/), sakit: col(/sakit/), late: col(/terlambat/) };
                const missing = Object.entries({ 'Empl ID / NIK': c.nik, 'Nama': c.name, 'Ijin Pribadi': c.ijin, 'Sakit': c.sakit, 'Terlambat': c.late })
                    .filter(([, i]) => i < 0).map(([label]) => label);
                if (missing.length) throw new Error('Kolom tidak ditemukan: ' + missing.join(', ') + '.');

                // second header row: "Full | 1/2" under Ijin / Sakit, "Hari | Mnt" under Terlambat
                const sub = (table[h + 1] || []).map(v => String(v ?? '').trim().toLowerCase());
                const hasSub = sub.some(v => ['full', '1/2', 'hari', 'mnt'].includes(v));
                const half = i => (hasSub && sub[i + 1] === '1/2' ? i + 1 : null);
                c.ijinHalf = half(c.ijin); c.sakitHalf = half(c.sakit);

                const out = []; const seen = {}; let dept = '';
                for (const r of table.slice(h + (hasSub ? 2 : 1))) {
                    const first = cell(r, 0);
                    const dm = first.match(/^department\s*:\s*(.*)$/i);
                    if (dm) { dept = dm[1].trim(); continue; }
                    if (/total/i.test(first) || /total/i.test(cell(r, 1))) continue;
                    const nik = cell(r, c.nik);
                    if (! /\d/.test(nik)) continue;

                    const ijin  = num(cell(r, c.ijin)) + num(cell(r, c.ijinHalf));
                    const sakit = num(cell(r, c.sakit)) + num(cell(r, c.sakitHalf));
                    const late  = num(cell(r, c.late));
                    const emp   = window.importEmployees[norm(nik)];
                    const p1 = byDays(Math.max(ijin, sakit)), p2 = byTimes(late);
                    const row = { key: out.length, no: out.length + 1, nik, name: emp ? emp.name : cell(r, c.name), dept, unit: emp ? emp.unit : '',
                                  id: emp ? emp.id : nik, ijin, sakit, late, p1, p2, p1Auto: p1, p2Auto: p2, ...this.state(emp) };
                    if (row.state === 'ok' && seen[row.id] !== undefined) {   // the same employee twice: the last row wins
                        Object.assign(out[seen[row.id]], { state: 'dupe', note: 'Duplikat di file (dipakai baris berikutnya)' });
                    }
                    if (row.state === 'ok') seen[row.id] = out.length;
                    out.push(row);
                }
                if (! out.length) throw new Error('Tidak ada baris karyawan di file (kolom Empl ID berisi NIK).');
                return out;
            },

            state(emp) {
                if (! emp) return { state: 'notfound', note: 'Tidak ada di data karyawan aktif' };
                if (! emp.a1) return { state: 'a2', note: 'Assessment 2 / pimpinan: tidak diimpor' };
                if (emp.status && ! config.writable.includes(emp.status)) return { state: 'locked', note: 'Sudah dikirim / disetujui: tidak diubah' };
                return { state: 'ok', note: emp.status ? 'Perbarui draf yang ada' : 'Draf baru' };
            },

            get counts() {
                const c = { all: this.rows.length, ok: 0, a2: 0, notfound: 0, locked: 0, dupe: 0 };
                this.rows.forEach(r => c[r.state]++);
                return c;
            },
            get summaryCards() {
                const c = this.counts;
                return [
                    { key: 'all', label: 'Semua baris', count: c.all },
                    { key: 'ok', label: 'Akan disimpan', count: c.ok },
                    { key: 'a2', label: 'Assessment 2 (dilewati)', count: c.a2 },
                    { key: 'notfound', label: 'Tidak ditemukan', count: c.notfound },
                    { key: 'locked', label: 'Terkunci / duplikat', count: c.locked + c.dupe },
                ];
            },
            get changed() { return this.rows.filter(r => r.state === 'ok' && (r.p1 !== r.p1Auto || r.p2 !== r.p2Auto)).length; },
            get visibleRows() {
                const q = this.q.toLowerCase().trim();
                return this.rows.filter(r => (this.filter === 'all' || r.state === this.filter || (this.filter === 'locked' && r.state === 'dupe'))
                    && (q === '' || (r.name + ' ' + r.nik + ' ' + r.unit + ' ' + r.dept).toLowerCase().includes(q)));
            },
            fmt(v) { return Number.isInteger(v) ? v : v.toFixed(1).replace('.', ','); },
            badge(row) {
                return { ok: 'bg-emerald-50 text-emerald-800 ring-emerald-300', locked: 'bg-amber-50 text-amber-800 ring-amber-300',
                         dupe: 'bg-amber-50 text-amber-800 ring-amber-300', notfound: 'bg-rose-50 text-rose-800 ring-rose-300' }[row.state]
                       ?? 'bg-slate-100 text-slate-600 ring-slate-300';
            },

            async save() {
                const rows = this.rows.filter(r => r.state === 'ok');
                if (! rows.length) return;
                if (! confirm(`Simpan ${rows.length} karyawan untuk periode ${this.period}? Nilai Poin 1 dan 2 akan TERKUNCI (Penilai tidak bisa mengubahnya); status tetap Draf. Penilaian yang sudah dikirim / disetujui tidak diubah.`)) return;
                this.saving = true; this.result = ''; this.skipped = [];
                try {
                    const response = await fetch(config.storeUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ period: this.period, rows: rows.map(r => ({
                            id_employee: r.id, score_attendance: r.p1, score_tardiness: r.p2, ijin: r.ijin, sakit: r.sakit, late: r.late })) }),
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
                    // saved rows are drafts now: mark them so a second save updates instead of creating
                    rows.forEach(r => { if (window.importEmployees[norm(r.nik)]) window.importEmployees[norm(r.nik)].status = 'Draft'; r.note = 'Tersimpan sebagai draf'; });
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
