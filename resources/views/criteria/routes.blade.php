@extends('layouts.app')

@section('title', 'Jalur Khusus A1')
@section('heading', 'Panduan Penilaian')
@section('subheading', 'Assessment 1: karyawan yang dinilai akun pilihan HRD, bukan akun departemennya.')

@section('content')
@include('criteria.partials.tabs', ['active' => 'routes'])
@php
    $groupData = $groups->map(fn ($g) => ['key' => $g['key'], 'note' => $g['note'], 'assessor' => $g['assessor']?->id, 'approver' => $g['approver']?->id,
        'label' => ($g['note'] ?: 'Tanpa nama') . ' · ' . ($g['assessor']?->username ?? '?') . ' → ' . ($g['approver']?->username ?? '?')])->values();
@endphp

<div class="mb-6 rounded-lg border border-violet-200 bg-violet-50 px-5 py-4 text-sm text-violet-900">
    <p class="font-semibold">{{ $total }} karyawan Assessment 1 saat ini berjalur khusus, dalam {{ $groups->count() }} grup.</p>
    <p class="mt-1">Mereka <b>keluar dari alur Penilai 1 / 2 departemen</b> dan dinilai Penilai &amp; Approver grupnya. <b>Keluarkan</b> = kembali ke alur departemen.</p>
</div>

{{-- add a person --}}
<form method="POST" action="{{ route('criteria.routes.store') }}" class="mb-6 rounded-lg border border-slate-200 bg-white"
      x-data="routeAdd(@js(['groups' => $groupData, 'candidates' => $candidates, 'old' => ['employee_id' => old('employee_id'), 'group' => old('group'), 'assessor_id' => old('assessor_id'), 'approver_id' => old('approver_id'), 'note' => old('note')]]))">
    @csrf
    <div class="border-b border-slate-200 px-5 py-3">
        <p class="text-base font-semibold text-slate-900">Tambah orang ke jalur khusus</p>
        <p class="text-sm text-slate-600">Orang dari grup lain atau aturan departemen akan dipindah.</p>
    </div>
    <div class="grid grid-cols-1 gap-4 px-5 py-4 lg:grid-cols-2">
        <div>
            <label class="block text-sm font-semibold text-slate-900" for="route-search">Karyawan (Assessment 1, aktif)</label>
            <input id="route-search" type="search" x-model.debounce.150ms="q" placeholder="Ketik nama, NIK atau seksi…" autocomplete="off"
                   class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
            <select name="employee_id" x-model="employee" required size="6" aria-label="Pilih karyawan"
                    class="mt-2 w-full rounded border border-slate-300 bg-white px-2 py-1 text-sm">
                <template x-for="c in filtered" :key="c.id"><option :value="c.id" x-text="c.label" :selected="c.id === employee"></option></template>
            </select>
            <p class="mt-1 text-xs text-slate-500"><span x-text="filtered.length"></span> dari {{ $candidates->count() }} karyawan cocok
                <span x-show="filtered.length === 200">(maks. 200 ditampilkan, persempit pencarian)</span></p>
            @error('employee_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-3">
            <label class="block text-sm font-semibold text-slate-900">Masuk ke grup
                <select name="group" x-model="group" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                    <template x-for="g in groups" :key="g.key"><option :value="g.key" x-text="g.label" :selected="g.key === group"></option></template>
                    <option value="new">+ Grup baru…</option>
                </select>
            </label>
            {{-- the group's Penilai / Approver / name are what is posted; for an existing group they are filled in and hidden --}}
            <template x-if="group !== 'new'">
                <div>
                    <input type="hidden" name="note" :value="current.note">
                    <input type="hidden" name="assessor_id" :value="current.assessor">
                    <input type="hidden" name="approver_id" :value="current.approver">
                </div>
            </template>
            <div x-show="group === 'new'" x-cloak class="grid grid-cols-1 gap-3 rounded border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                <label class="text-sm font-semibold text-slate-900 sm:col-span-2">Nama grup
                    <input type="text" name="note" x-model="note" :disabled="group !== 'new'" maxlength="120" placeholder="mis. Pimpinan seksi MAINTENANCE"
                           class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                </label>
                @foreach (['assessor_id' => 'Penilai', 'approver_id' => 'Approver'] as $field => $label)
                    <label class="text-sm font-semibold text-slate-900">{{ $label }}
                        <select name="{{ $field }}" :disabled="group !== 'new'" class="mt-1 w-full rounded border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                            <option value="">Pilih akun</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old($field) === $a->id)>{{ $a->username }} · {{ Str::limit($a->name, 32) }}</option>@endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            @foreach (['note', 'assessor_id', 'approver_id'] as $field)
                @error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            @endforeach
            <button type="submit" class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700">Tambahkan ke jalur khusus</button>
        </div>
    </div>
</form>

{{-- groups --}}
<div class="space-y-6">
    @forelse ($groups as $g)
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white" x-data="{ edit: false }">
            <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-base font-semibold text-slate-900">{{ $g['note'] ?: 'Tanpa nama' }} <span class="font-normal text-slate-500">· {{ $g['count'] }} orang</span></p>
                    <p class="text-sm text-slate-600">Penilai <span class="font-mono text-slate-900">{{ $g['assessor']?->username ?? '?' }}</span> ({{ $g['assessor']?->name }})
                        → Approver <span class="font-mono text-slate-900">{{ $g['approver']?->username ?? '?' }}</span> ({{ $g['approver']?->name }})</p>
                </div>
                <button type="button" @click="edit = ! edit" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold" x-text="edit ? 'Tutup' : 'Ubah grup'"></button>
            </div>

            <form x-show="edit" x-cloak method="POST" action="{{ route('criteria.routes.group') }}" class="grid grid-cols-1 gap-3 border-b border-slate-200 bg-amber-50/50 px-5 py-3 md:grid-cols-4 md:items-end">
                @csrf @method('PUT')
                <input type="hidden" name="old_note" value="{{ $g['note'] }}">
                <input type="hidden" name="old_assessor_id" value="{{ $g['assessor']?->id }}">
                <input type="hidden" name="old_approver_id" value="{{ $g['approver']?->id }}">
                <label class="text-sm font-semibold text-slate-900">Nama grup
                    <input type="text" name="note" value="{{ $g['note'] }}" required maxlength="120" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                </label>
                @foreach (['assessor_id' => ['Penilai', $g['assessor']?->id], 'approver_id' => ['Approver', $g['approver']?->id]] as $field => [$label, $current])
                    <label class="text-sm font-semibold text-slate-900">{{ $label }}
                        <select name="{{ $field }}" required class="mt-1 w-full rounded border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($current === $a->id)>{{ $a->username }} · {{ Str::limit($a->name, 32) }}</option>@endforeach
                        </select>
                    </label>
                @endforeach
                <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Simpan grup</button>
                <p class="text-xs text-slate-600 md:col-span-4">Berlaku untuk seluruh grup, termasuk penilaian bulan ini yang belum disetujui.</p>
            </form>

            <table class="table-lines min-w-full text-sm">
                <thead class="bg-white text-left text-slate-600">
                    <tr><th class="px-4 py-2">Nama</th><th class="px-4 py-2">NIK</th><th class="px-4 py-2">Seksi · Grade</th><th class="px-4 py-2 text-right">Aksi</th></tr>
                </thead>
                <tbody>
                    @foreach ($g['people'] as $p)
                        @php $e = $p['employee']; @endphp
                        <tr class="{{ $p['problem'] ? 'bg-amber-50' : '' }}">
                            <td class="px-4 py-2 font-semibold text-slate-900">{{ $e?->name ?? '—' }}
                                @if ($p['problem'])<span class="block text-xs font-normal text-amber-800">{{ $p['problem'] }}</span>@endif</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $e?->id_employee ?? $p['route']->employee_id }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $e ? \App\Support\ReviewRules::unitOf($e) . ' · ' . $e->grade : '' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($e && ! $p['problem'])
                                        <form method="POST" action="{{ route('criteria.routes.a2', $e) }}" onsubmit="return confirm(@js("Pindahkan {$e->name} ke Assessment 2?"))">
                                            @csrf <button type="submit" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs font-semibold">Pindah ke A2</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('criteria.routes.destroy', $p['route']) }}" onsubmit="return confirm(@js($p['problem'] ? 'Hapus baris yang tidak berlaku ini?' : 'Keluarkan ' . ($e?->name) . ' dari jalur khusus?'))">
                                        @csrf @method('DELETE') <button type="submit" class="rounded border border-rose-300 bg-white px-3 py-1 text-xs font-semibold">{{ $p['problem'] ? 'Hapus baris' : 'Keluarkan' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($g['rules'] as $rule)
                        <tr class="bg-violet-50/60">
                            <td colspan="3" class="px-4 py-2">
                                <span class="font-semibold text-violet-900">Aturan departemen: {{ $rule['route']->department }} · {{ empty($rule['route']->jabatans) ? 'semua karyawan' : implode(', ', $rule['route']->jabatans) }}</span>
                                <span class="text-slate-600">({{ $rule['people']->count() }} orang)</span>
                                <span class="block text-xs text-slate-600">Untuk mengeluarkan satu orang: pindahkan ke grup lain atau ke A2.</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('criteria.routes.destroy', $rule['route']) }}" onsubmit="return confirm(@js("Hapus aturan {$rule['route']->department}? Semua {$rule['people']->count()} orang kembali ke alur departemen."))">
                                    @csrf @method('DELETE') <button type="submit" class="rounded border border-rose-300 bg-white px-3 py-1 text-xs font-semibold">Hapus aturan</button>
                                </form>
                            </td>
                        </tr>
                        @foreach ($rule['people'] as $e)
                            <tr>
                                <td class="px-4 py-1.5 pl-8 text-slate-900">{{ $e->name }}</td>
                                <td class="px-4 py-1.5 font-mono text-xs">{{ $e->id_employee }}</td>
                                <td class="px-4 py-1.5 text-slate-600">{{ \App\Support\ReviewRules::unitOf($e) }} · {{ $e->grade }}</td>
                                <td class="px-4 py-1.5 text-right">
                                    <form method="POST" action="{{ route('criteria.routes.a2', $e) }}" onsubmit="return confirm(@js("Pindahkan {$e->name} ke Assessment 2?"))">
                                        @csrf <button type="submit" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs font-semibold">Pindah ke A2</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="rounded-lg border border-slate-200 bg-white px-5 py-6 text-sm text-slate-500">Belum ada jalur khusus.</p>
    @endforelse
</div>
@endsection

@push('scripts')
<script>
    function routeAdd(config) {
        const norm = v => String(v ?? '').toLowerCase();
        return {
            groups: config.groups, q: '',
            employee: config.old.employee_id || '',
            group: config.old.group || (config.groups[0] ? config.groups[0].key : 'new'),
            note: config.old.note || '',
            get current() { return this.groups.find(g => g.key === this.group) || {}; },
            get filtered() {
                const q = norm(this.q).trim();
                const list = q === '' ? config.candidates : config.candidates.filter(c => norm(c.label).includes(q));
                return list.slice(0, 200);
            },
        };
    }
</script>
@endpush
