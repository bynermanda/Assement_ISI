@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Assessment 2')
@section('heading', 'Assessment 2 (Pimpinan)')
@section('subheading', 'Periode: ' . $periodLabel . ' · Unit, kriteria, dan siapa menilai siapa.')

@section('content')
<div class="space-y-5" x-data="{ open: null }">
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach ([
            ['Peserta', $participantCount, 'text-slate-900'],
            ['Belum dinilai', $participantCount - $assessments->count(), 'text-slate-500'],
            ['Draf / Dikembalikan', ($statusCounts[Assessment::STATUS_DRAFT] ?? 0) + ($statusCounts[Assessment::STATUS_REJECTED] ?? 0), 'text-slate-700'],
            ['Menunggu Approver', $statusCounts[Assessment::STATUS_PENDING] ?? 0, 'text-amber-700'],
            ['Disetujui', $statusCounts[Assessment::STATUS_APPROVED] ?? 0, 'text-emerald-700'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-3xl font-semibold {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Export: approved only --}}
    <form method="GET" action="{{ route('a2.export.xlsx') }}" x-data
          class="flex flex-col gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-900">Export Assessment 2 · hanya yang Disetujui</p>
            <p class="text-xs text-emerald-800">Excel: satu sheet ringkasan ditambah satu sheet per unit dengan kriterianya. PDF: sama, satu halaman per unit.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <label class="text-xs text-emerald-900">Periode
                <input type="month" name="period" value="{{ now()->format('Y-m') }}" class="mt-0.5 block rounded border border-emerald-300 bg-white px-2 py-1.5 text-sm">
            </label>
            <label class="flex items-center gap-1 pb-2 text-xs text-emerald-900">
                <input type="checkbox" @change="$el.form.period.value = $event.target.checked ? 'all' : @js(now()->format('Y-m')); $el.form.period.type = $event.target.checked ? 'hidden' : 'month'" class="rounded border-emerald-400"> Semua periode
            </label>
            <label class="text-xs text-emerald-900">Unit
                <select name="unit" class="mt-0.5 block rounded border border-emerald-300 bg-white px-2 py-1.5 text-sm">
                    <option value="">Semua unit</option>
                    @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
            </label>
            <button type="submit" formaction="{{ route('a2.export.xlsx') }}" class="rounded bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Export Excel</button>
            <button type="submit" formaction="{{ route('a2.export.pdf') }}" class="rounded bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Export PDF</button>
        </div>
    </form>

    @include('exports.partials.average-form', ['type' => 'a2'])

    <p class="text-sm text-slate-500">
        Untuk mengubah kriteria, bobot, target, Penilai atau Approver: <a href="{{ route('criteria.a2') }}" class="font-semibold text-teal-700 hover:underline">Panduan › Assessment 2</a>.
    </p>

    @foreach ($units as $unit)
        @php $total = $unit->totalWeight(); @endphp
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <button type="button" @click="open = open === {{ $unit->id }} ? null : {{ $unit->id }}" :aria-expanded="open === {{ $unit->id }}"
                    class="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                <span class="font-semibold text-slate-900">{{ $unit->name }}
                    @if ($unit->department && $unit->department !== $unit->name)<span class="font-normal text-slate-500">· {{ $unit->department }}</span>@endif
                </span>
                <span class="flex flex-wrap items-center gap-3 text-sm text-slate-600">
                    <span>{{ $unit->criteria->count() }} kriteria</span>
                    <span @class(['font-semibold', 'text-emerald-700' => abs($total - 100) < 0.01, 'text-rose-700' => abs($total - 100) >= 0.01])>{{ rtrim(rtrim(number_format($total, 2), '0'), '.') }}%</span>
                    <span>{{ $unit->participants->count() }} peserta</span>
                    <span class="text-slate-400" x-text="open === {{ $unit->id }} ? '▴' : '▾'"></span>
                </span>
            </button>

            {{-- participants --}}
            <div class="overflow-x-auto border-t border-slate-100">
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($unit->participants->sortBy(fn ($p) => $p->employee?->name) as $p)
                            @php $a = $assessments->get($p->employee_id); @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-slate-900">{{ $p->employee?->name ?? $p->employee_id }}</span>
                                    <span class="block text-xs text-slate-500">{{ $p->employee_id }} · {{ $p->employee?->grade }}</span>
                                </td>
                                @foreach (['Penilai' => $p->assessor, 'Approver' => $p->approver] as $label => $u)
                                    <td class="px-4 py-2">
                                        <span class="text-xs text-slate-500">{{ $label }}</span>
                                        <span @class(['block', 'text-amber-700' => $u && str_ends_with($u->username, '.' . strtolower($label)), 'text-slate-800' => ! ($u && str_ends_with($u->username, '.' . strtolower($label)))])>
                                            {{ $u?->name ?? '-' }}
                                        </span>
                                        <span class="block text-xs text-slate-400">{{ $u?->username }}{{ $u?->job_title ? ' · ' . $u->job_title : '' }}</span>
                                    </td>
                                @endforeach
                                <td class="px-4 py-2 text-right">
                                    @if ($a)
                                        <a href="{{ route('a2.show', $a) }}" class="inline-flex items-center gap-2">
                                            <span class="tabular-nums text-slate-700">{{ number_format($a->final_score, 1) }}</span>
                                            <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass($a->status) }}">{{ \App\Support\Ui::status($a->status) }}</span>
                                        </a>
                                    @else
                                        <span class="rounded px-2 py-0.5 text-xs ring-1 ring-inset {{ Assessment::badgeClass(null) }}">Belum dinilai</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- criteria --}}
            <div x-show="open === {{ $unit->id }}" x-cloak class="overflow-x-auto border-t border-slate-200 bg-slate-50/50">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">No</th>
                            <th class="px-4 py-2">@include('assessments.partials.heading', ['key' => 'criteria'])</th>
                            <th class="px-4 py-2 text-center">@include('assessments.partials.heading', ['key' => 'weight'])</th>
                            <th class="px-4 py-2">@include('assessments.partials.heading', ['key' => 'target'])</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($unit->criteria as $c)
                            <tr class="align-top">
                                <td class="px-4 py-2 text-slate-500">{{ $loop->iteration }}</td>
                                <td class="px-4 py-2">
                                    @if ($c->group_zh || $c->group_id)<span class="block text-xs text-slate-500">{{ trim($c->group_zh . ' ' . $c->group_id) }}</span>@endif
                                    @include('assessments.partials.item-label', ['zh' => (string) $c->name_zh, 'id' => $c->name_id])
                                </td>
                                <td class="px-4 py-2 text-center">{{ rtrim(rtrim(number_format($c->weight, 2), '0'), '.') }}%</td>
                                <td class="px-4 py-2 text-xs leading-relaxed text-slate-600">{!! nl2br(e($c->target ?? '–')) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
@endsection
