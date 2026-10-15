@extends('layouts.app')

@use('App\Models\Assessment')
@use('App\Support\Roles')

@php $isAdmin = auth()->user()->role === Roles::ADMIN; @endphp
@section('title', $isAdmin ? 'Dashboard' : 'Tugas Saya')
@section('heading', $isAdmin ? 'Dashboard' : tb('Tugas Saya'))
@section('subheading', t('Periode penilaian') . ': ' . $periodLabel)

@section('content')

{{-- ===================== ADMIN (HRD) ===================== --}}
@if (auth()->user()->role === Roles::ADMIN)
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Karyawan aktif', $activeEmployees, 'text-slate-900'],
            ['Menunggu Penilai 2', ($statusCounts[Assessment::STATUS_PENDING] ?? 0) + ($statusCounts[Assessment::STATUS_VERIFIED] ?? 0), 'text-amber-700'],
            ['Disetujui', $statusCounts[Assessment::STATUS_APPROVED] ?? 0, 'text-emerald-700'],
            ['Dikembalikan', $statusCounts[Assessment::STATUS_REJECTED] ?? 0, 'text-rose-700'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-3xl font-semibold {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Export: approved assessments only --}}
    @php $approvedNow = $statusCounts[Assessment::STATUS_APPROVED] ?? 0; @endphp
    <div class="mt-6 flex flex-col gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 class="font-semibold text-emerald-900">Export penilaian yang disetujui · {{ $periodLabel }}</h2>
            <p class="text-sm text-emerald-800">Hanya penilaian yang disetujui yang diekspor ({{ $approvedNow }} Assessment 1 periode ini).
                Per departemen / seksi: gunakan tombol export di halaman Penilaian; Assessment 2 di halamannya sendiri.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            @if ($approvedNow > 0)
                <a href="{{ route('exports.xlsx', ['period' => $period]) }}" class="rounded bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-700">Assessment 1 · Excel</a>
                <a href="{{ route('exports.pdf', ['period' => $period]) }}" class="rounded bg-rose-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-rose-700">Assessment 1 · PDF</a>
            @endif
            <a href="{{ route('a2.admin') }}" class="rounded border border-emerald-300 bg-white px-4 py-2 text-center text-sm font-semibold text-emerald-800 hover:bg-emerald-100">Assessment 2 →</a>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-slate-900">Progres per departemen</h2>
            <div class="flex gap-3 text-sm font-medium">
                <a href="{{ route('admin.assessments.create') }}" class="rounded bg-teal-600 px-3 py-1.5 text-white hover:bg-teal-700">Tambah penilaian manual</a>
                <a href="{{ route('admin.assessments.index') }}" class="px-2 py-1.5 text-teal-700 hover:underline">Semua penilaian</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Departemen</th>
                        <th class="px-4 py-3 text-right font-medium">Karyawan</th>
                        <th class="px-4 py-3 text-right font-medium">Menunggu persetujuan</th>
                        <th class="px-4 py-3 text-right font-medium">Disetujui</th>
                        <th class="px-4 py-3 font-medium">Selesai</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($departmentSummary as $row)
                        @php $pct = $row->total > 0 ? round($row->approved / $row->total * 100) : 0; @endphp
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $row->department }}</td>
                            <td class="px-4 py-3 text-right">{{ $row->total }}</td>
                            <td class="px-4 py-3 text-right">{{ $row->pending }}</td>
                            <td class="px-4 py-3 text-right">{{ $row->approved }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-32 overflow-hidden rounded bg-slate-100">
                                        <div class="h-2 bg-teal-600" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-xs text-slate-500">{{ $pct }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada karyawan aktif. Tambahkan di menu Karyawan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

{{-- ===================== PENILAI / APPROVAL / ASSESSMENT 2: "Tugas Saya" ===================== --}}
@else
    @include('dashboard.tasks', ['my' => $my])
@endif
@endsection
