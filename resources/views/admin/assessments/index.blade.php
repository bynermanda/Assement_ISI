@extends('layouts.app')

@use('App\Models\Assessment')

@section('title', 'Penilaian')
@section('heading', 'Penilaian Assessment 1 (HRD)')
@section('subheading', 'Lihat, input, edit, atau hapus penilaian semua departemen.')

@section('content')
<div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <form method="GET" action="{{ route('admin.assessments.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap">
        <input type="month" name="period" value="{{ $period }}"
               class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
        <select name="department" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
            <option value="">Semua departemen</option>
            @foreach ($departments as $department)
                <option value="{{ $department }}" @selected(request('department') === $department)>{{ $department }}</option>
            @endforeach
        </select>
        <select name="section" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
            <option value="">Semua seksi</option>
            @foreach ($sections as $section)
                <option value="{{ $section }}" @selected(request('section') === $section)>{{ $section }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
            <option value="">Semua status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\Ui::status($status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Terapkan</button>
        <a href="{{ route('admin.assessments.index', ['period' => '']) }}" class="px-2 py-2 text-sm text-slate-500 hover:underline">Semua periode</a>
    </form>
    <div class="flex flex-col gap-2 sm:flex-row">
        <a href="{{ route('admin.assessments.import') }}" class="rounded bg-teal-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-teal-700">Impor kehadiran (HRD)</a>
        <a href="{{ route('admin.assessments.sanctions') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-900">Impor sanksi (HRD) · Poin 6</a>
        <a href="{{ route('admin.assessments.create') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Tambah manual</a>
    </div>
</div>

{{-- Export: approved only, with the period / department / section chosen above --}}
@php
    $exportQuery = array_filter([
        'period'     => $period === '' ? \App\Support\ApprovedExport::ALL_PERIODS : $period,
        'department' => request('department'),
        'section'    => request('section'),
    ], fn ($v) => filled($v));
@endphp
<div class="mb-4 flex flex-col gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
    <p class="text-sm text-emerald-900">
        <span class="font-semibold">Export Assessment 1</span> · hanya yang <span class="font-semibold">Disetujui</span> ·
        {{ $period === '' ? 'semua periode' : \App\Support\ApprovedExport::labelFor($period) }}{{ request('department') ? ' · ' . request('department') : '' }}{{ request('section') ? ' · ' . request('section') : '' }}
        <span class="text-emerald-700">(filter status tidak berlaku)</span>
    </p>
    <div class="flex gap-2">
        <a href="{{ route('exports.xlsx', $exportQuery) }}" class="rounded bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-700">Export Excel</a>
        <a href="{{ route('exports.pdf', $exportQuery) }}" class="rounded bg-rose-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-rose-700">Export PDF</a>
    </div>
</div>

@include('exports.partials.average-form', ['type' => 'a1'])

<div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">Karyawan</th>
                    <th class="px-4 py-3 font-medium">Departemen</th>
                    <th class="px-4 py-3 font-medium">Seksi</th>
                    <th class="px-4 py-3 font-medium">Periode</th>
                    <th class="px-4 py-3 text-right font-medium">Nilai Akhir</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Diinput oleh</th>
                    <th class="px-4 py-3 text-right font-medium">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($assessments as $assessment)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $assessment->employee?->name ?? $assessment->employee_id }}</p>
                            <p class="text-xs text-slate-500">{{ $assessment->employee_id }}</p>
                        </td>
                        <td class="px-4 py-3">{{ $assessment->employee?->department ?? '-' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $assessment->employee?->section ?: '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ \App\Support\ApprovedExport::labelFor($assessment->period) }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ number_format($assessment->final_score, 1) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ Assessment::badgeClass($assessment->status) }}">{{ \App\Support\Ui::status($assessment->status) }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $assessment->created_by ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('admin.assessments.edit', $assessment) }}" class="font-medium text-teal-700 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.assessments.destroy', $assessment) }}" class="ml-3 inline"
                                  onsubmit="return confirm('Hapus penilaian ini? Tindakan ini tidak bisa dibatalkan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-medium text-rose-700 hover:underline">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">Tidak ada penilaian untuk filter ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($assessments->hasPages())
        <div class="border-t border-slate-200 px-4 py-3">{{ $assessments->links() }}</div>
    @endif
</div>
@endsection
