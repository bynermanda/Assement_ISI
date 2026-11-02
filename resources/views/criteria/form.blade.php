@extends('layouts.app')

@section('title', $isEdit ? 'Edit Panduan' : 'Panduan Baru')
@section('heading', $isEdit ? 'Edit Panduan' : 'Panduan Baru')
@section('subheading', 'Target yang tampil di samping setiap kriteria.')

@section('content')
<form method="POST" action="{{ $isEdit ? route('criteria.update', $row->id_desc) : route('criteria.store') }}" class="space-y-6">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    @if ($copied)
        <div class="rounded border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            Disalin dari panduan lain. Pilih departemen / seksi, lalu sesuaikan.
        </div>
    @endif

    <div class="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 md:grid-cols-2">
        <div>
            <label for="department" class="block text-sm font-medium text-slate-700">Departemen</label>
            <input id="department" name="department" type="text" list="department-options" required maxlength="100"
                   value="{{ old('department', $row->department) }}"
                   @class([
                       'mt-1 block w-full rounded border px-3 py-2.5 text-sm focus:outline-none focus:ring-2',
                       'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has('department'),
                       'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('department'),
                   ])>
            <datalist id="department-options">
                @foreach ($departments as $department)<option value="{{ $department }}">@endforeach
            </datalist>
            @error('department')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="section" class="block text-sm font-medium text-slate-700">Seksi <span class="font-normal text-slate-400">(kosong = seluruh departemen)</span></label>
            <input id="section" name="section" type="text" list="section-options" maxlength="100"
                   value="{{ old('section', $row->section) }}" placeholder="mis. IQC, PQC, TOTAL CHECK"
                   @class([
                       'mt-1 block w-full rounded border px-3 py-2.5 text-sm focus:outline-none focus:ring-2',
                       'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has('section'),
                       'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('section'),
                   ])>
            <datalist id="section-options">
                @foreach ($sections as $section)<option value="{{ $section }}">@endforeach
            </datalist>
            @error('section')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        @unless ($isEdit || $copied)
            <div class="md:col-span-2 border-t border-slate-100 pt-4 text-sm text-slate-600">
                Mulai dari panduan lain?
                <select onchange="if (this.value) window.location = '{{ route('criteria.create') }}?copy_from=' + this.value"
                        class="ml-1 rounded border border-slate-300 bg-white px-2 py-1 text-sm">
                    <option value="">Salin teks dari...</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->id_desc }}">{{ $source->department }}{{ filled($source->section) ? ' / ' . $source->section : '' }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
    </div>

    <div class="space-y-4">
        @foreach ($criteria as $column => $meta)
            @php $field = $meta['description']; @endphp
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label for="{{ $field }}" class="flex items-baseline gap-2">
                        <span class="font-medium text-slate-500">{{ $loop->iteration }}.</span>
                        @include('assessments.partials.item-label', [
                            'zh' => trim(($meta['group'] ? \App\Models\Assessment::CRITERIA_GROUPS[$meta['group']]['zh'] . ' · ' : '') . $meta['zh'], ' ·'),
                            'id' => ($meta['group'] ? \App\Models\Assessment::CRITERIA_GROUPS[$meta['group']]['id'] . ' – ' : '') . $meta['id'],
                        ])
                    </label>
                    <span class="text-xs text-slate-500">目標 Target &middot; {{ $meta['weight'] }}%</span>
                </div>
                <textarea id="{{ $field }}" name="{{ $field }}" rows="5" maxlength="5000"
                          @class([
                              'mt-2 block w-full rounded border px-3 py-2 text-sm leading-relaxed focus:outline-none focus:ring-2',
                              'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has($field),
                              'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has($field),
                          ])>{{ old($field, $row->{$field} ?? '') }}</textarea>
                @error($field)<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('criteria.index') }}" class="rounded border border-slate-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</a>
        <button type="submit" class="rounded bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
            {{ $isEdit ? 'Simpan perubahan' : 'Buat panduan' }}
        </button>
    </div>
</form>
@endsection
