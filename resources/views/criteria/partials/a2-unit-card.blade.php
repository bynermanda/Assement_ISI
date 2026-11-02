{{-- Panduan › Assessment 2: name / department of the selected unit, and delete. Needs $unit, $participants, $unitStats. --}}
@php
    $people = $participants->count();
    $scored = $unitStats['assessments'] ?? 0;
@endphp
<div class="rounded-lg border border-slate-200 bg-white" x-data="{ edit: @js((bool) old('_unit_edit')) }">
    <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $unit->name }}</h2>
            <p class="text-xs text-slate-500">Departemen: {{ $unit->department ?: '—' }} · {{ $unit->criteria->count() }} kriteria · {{ $people }} peserta · {{ $scored }} penilaian tersimpan</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="edit = ! edit" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold" x-text="edit ? 'Tutup' : 'Ubah nama / departemen'"></button>
            <form method="POST" action="{{ route('criteria.a2.units.destroy', $unit) }}"
                  onsubmit="return confirm(@js($people || $scored ? 'Unit ini masih punya peserta atau penilaian, jadi tidak bisa dihapus. Lanjutkan untuk melihat alasannya?' : "Hapus unit {$unit->name} beserta kriterianya?"))">
                @csrf @method('DELETE')
                <button type="submit" class="rounded border border-rose-300 bg-white px-3 py-1.5 text-sm font-semibold" @if ($people || $scored) title="Pindahkan / hapus pesertanya dulu; unit dengan penilaian tersimpan tidak bisa dihapus" @endif>Hapus unit</button>
            </form>
        </div>
    </div>
    <form x-show="edit" x-cloak method="POST" action="{{ route('criteria.a2.units.update', $unit) }}"
          class="grid grid-cols-1 gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 md:grid-cols-3 md:items-end">
        @csrf @method('PUT')
        <input type="hidden" name="_unit_edit" value="1">
        <label class="text-sm font-semibold text-slate-900">Nama unit
            <input type="text" name="name" value="{{ old('_unit_edit') ? old('name') : $unit->name }}" required maxlength="100" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-900">Departemen
            <input type="text" name="department" list="a2-departments" value="{{ old('_unit_edit') ? old('department') : $unit->department }}" maxlength="100" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
        </label>
        <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Simpan</button>
        @if (old('_unit_edit'))@error('name')<p class="text-xs text-rose-600 md:col-span-3">{{ $message }}</p>@enderror @endif
    </form>
</div>
