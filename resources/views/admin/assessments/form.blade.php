@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', $isEdit ? 'Edit Penilaian' : 'Penilaian Manual')
@section('heading', $isEdit ? 'Edit Penilaian' : 'Input Penilaian Manual')
@section('subheading', 'Input HRD. Status "Disetujui" langsung final; status lain mengikuti alur biasa.')

@section('content')
<form method="POST"
      action="{{ $isEdit ? route('admin.assessments.update', $assessment) : route('admin.assessments.store') }}"
      x-data="adminAssessmentForm(@js($initial), @js($weights), @js($status))"
      class="space-y-6">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 md:grid-cols-3">
        {{-- Employee --}}
        <div class="md:col-span-2">
            <label for="id_employee" class="block text-sm font-medium text-slate-700">Karyawan</label>

            @if ($isEdit)
                <p class="mt-1 rounded border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800">
                    {{ $selected->name }} ({{ $selected->id_employee }}) &middot; {{ $selected->department }}
                </p>
            @else
                <select id="id_employee" name="id_employee" required
                        data-url="{{ route('admin.assessments.create') }}"
                        onchange="window.location = this.value ? this.dataset.url + '?employee=' + encodeURIComponent(this.value) + '&period=' + encodeURIComponent(document.getElementById('period').value) : this.dataset.url"
                        @class([
                            'mt-1 block w-full rounded border bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2',
                            'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has('id_employee'),
                            'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('id_employee'),
                        ])>
                    <option value="">Pilih karyawan</option>
                    @foreach ($employees->groupBy('department') as $department => $group)
                        <optgroup label="{{ $department }}">
                            @foreach ($group as $employee)
                                <option value="{{ $employee->id_employee }}" @selected(old('id_employee', $selected?->id_employee) === $employee->id_employee)>
                                    {{ $employee->name }} - {{ $employee->id_employee }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('id_employee')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            @endif
        </div>

        {{-- Period --}}
        <div>
            <label for="period" class="block text-sm font-medium text-slate-700">Periode</label>
            <input id="period" name="period" type="month" required value="{{ old('period', $period) }}" @readonly($isEdit)
                   @class([
                       'mt-1 block w-full rounded border px-3 py-2.5 text-sm read-only:bg-slate-100 focus:outline-none focus:ring-2',
                       'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has('period'),
                       'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('period'),
                   ])>
            @error('period')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Status --}}
        <div>
            <label for="status" class="block text-sm font-medium text-slate-700">Status</label>
            <select id="status" name="status" x-model="status" required
                    class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                @foreach ($statuses as $option)
                    <option value="{{ $option }}">{{ \App\Support\Ui::status($option) }}</option>
                @endforeach
            </select>
        </div>

        {{-- Revision note --}}
        <div class="md:col-span-2" x-show="status === 'Rejected'" x-cloak>
            <label for="rejection_note" class="block text-sm font-medium text-slate-700">Catatan perbaikan</label>
            <textarea id="rejection_note" name="rejection_note" rows="2" :required="status === 'Rejected'" maxlength="1000"
                      class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">{{ old('rejection_note', $assessment?->rejection_note) }}</textarea>
            @error('rejection_note')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if (! $selected)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
            Pilih karyawan di atas untuk memuat kriteria penilaian departemennya.
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="font-semibold text-slate-900">{{ $selected->name }}</h2>
                <p class="text-sm text-slate-500">{{ $selected->department }}@if ($selected->section) &middot; {{ $selected->section }}@endif &middot; {{ $selected->position }} &middot; {{ $selected->grade }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="table-lines min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="w-10 px-3 py-3 text-center font-medium">No</th>
                            <th colspan="2" class="min-w-[14rem] px-4 py-3 text-center">@include('assessments.partials.heading', ['key' => 'criteria'])</th>
                            <th class="px-4 py-3 text-center">@include('assessments.partials.heading', ['key' => 'weight'])</th>
                            <th class="min-w-[24rem] px-4 py-3">@include('assessments.partials.heading', ['key' => 'target'])</th>
                            <th class="px-4 py-3 font-semibold text-slate-700">Nilai</th>
                            <th class="px-4 py-3 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @php $layout = Assessment::criteriaLayout(); @endphp
                        @foreach (Assessment::CRITERIA as $column => $meta)
                            @php
                                $guideline = $descriptions?->{$meta['description']};
                                [$group, $firstOfGroup, $groupSize] = $layout[$column];
                            @endphp
                            <tr class="align-top">
                                <td class="px-3 py-4 text-center text-slate-500">{{ $loop->iteration }}</td>
                                @if ($group === null)
                                    <td colspan="2" class="px-4 py-4">@include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id']])</td>
                                @else
                                    @if ($firstOfGroup)
                                        <td rowspan="{{ $groupSize }}" class="border-r border-slate-200 bg-slate-50/60 px-4 py-4 align-middle">
                                            @include('assessments.partials.item-label', ['zh' => Assessment::CRITERIA_GROUPS[$group]['zh'], 'id' => Assessment::CRITERIA_GROUPS[$group]['id'], 'center' => true])
                                        </td>
                                    @endif
                                    <td class="px-4 py-4">@include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id']])</td>
                                @endif
                                <td class="px-4 py-4 text-center text-slate-600">{{ $meta['weight'] }}%</td>
                                <td class="px-4 py-4 text-sm leading-relaxed text-slate-600">
                                    @if ($guideline)
                                        {!! Assessment::formatGuideline($guideline) !!}
                                    @else
                                        <span class="text-slate-400">Panduan untuk departemen ini belum diisi.</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <select name="{{ $column }}" x-model="scores['{{ $column }}']" required
                                            @class([
                                                'block w-28 rounded border bg-white px-2 py-2 text-sm focus:outline-none focus:ring-2',
                                                'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has($column),
                                                'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has($column),
                                            ])>
                                        <option value="" disabled>Pilih</option>
                                        @foreach (Assessment::GRADES as $letter => $points)
                                            <option value="{{ $points }}">{{ $letter }} ({{ $points }})</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-4 text-right font-semibold tabular-nums" x-text="contribution('{{ $column }}').toFixed(1)">0.0</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-900 text-white">
                        <tr>
                            <td colspan="6" class="px-4 py-4 text-right text-sm font-medium">總分 Nilai Akhir</td>
                            <td class="px-4 py-4 text-right text-xl font-semibold tabular-nums" x-text="total.toFixed(1)">0.0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('admin.assessments.index') }}" class="rounded border border-slate-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</a>
            <button type="submit" class="rounded bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                Simpan penilaian
            </button>
        </div>
    @endif
</form>
@endsection

@push('scripts')
<script>
    function adminAssessmentForm(initial, weights, status) {
        return {
            scores: initial,
            weights: weights,
            status: status,
            contribution(column) {
                return (Number(this.scores[column]) || 0) * this.weights[column] / 100;
            },
            get total() {
                return Object.keys(this.weights).reduce((sum, column) => sum + this.contribution(column), 0);
            },
        };
    }
</script>
@endpush
