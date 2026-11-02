{{-- Panduan › Assessment 2: create a unit (= an assessment form). Needs $units, $departments. --}}
<div x-data="{ open: @js($errors->has('name') && ! old('_unit_edit')) }" class="mb-5">
    <button type="button" @click="open = ! open" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700"
            x-text="open ? 'Tutup' : '+ Unit penilaian baru'"></button>

    <form x-show="open" x-cloak method="POST" action="{{ route('criteria.a2.units.store') }}"
          class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-4 md:items-end">
        @csrf
        <label class="text-sm font-semibold text-slate-900">Nama unit
            <input type="text" name="name" value="{{ old('_unit_edit') ? '' : old('name') }}" required maxlength="100" placeholder="mis. PRESS · Kepala Seksi 1"
                   class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-900">Departemen
            <input type="text" name="department" list="a2-departments" value="{{ old('_unit_edit') ? '' : old('department') }}" maxlength="100" placeholder="mis. PRESS (boleh kosong)"
                   class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-900">Kriteria awal
            <select name="copy_from" class="mt-1 w-full rounded border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">Kosong (isi sendiri)</option>
                @foreach ($units as $u)<option value="{{ $u->id }}" @selected((int) old('copy_from') === $u->id)>Salin dari {{ $u->name }} ({{ $u->criteria->count() }} kriteria)</option>@endforeach
            </select>
        </label>
        <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Buat unit</button>
        @error('name')<p class="text-xs text-rose-600 md:col-span-4">{{ $message }}</p>@enderror
        <p class="text-xs text-slate-500 md:col-span-4">Salin kriteria unit lain lalu sesuaikan, atau mulai kosong. Total bobot 100%.</p>
    </form>
    <datalist id="a2-departments">@foreach ($departments as $d)<option value="{{ $d }}"></option>@endforeach</datalist>
</div>
