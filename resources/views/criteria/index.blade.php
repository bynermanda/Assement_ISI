@extends('layouts.app')

@section('title', 'Panduan')
@section('heading', 'Panduan Penilaian')
@section('subheading', 'Assessment 1: target per departemen dan seksi.')

@section('content')
@include('criteria.partials.tabs', ['active' => 'a1'])

@if ($routes->isNotEmpty() || $routeProblems)
    <div class="mb-6 overflow-hidden rounded-lg border border-violet-200 bg-white">
        <div class="border-b border-violet-200 bg-violet-50 px-4 py-2">
            <p class="flex items-center justify-between gap-3 text-sm font-semibold text-violet-900">Pimpinan seksi (jalur khusus)
                <a href="{{ route('criteria.routes') }}" class="rounded border border-violet-300 bg-white px-3 py-1 text-xs font-semibold text-violet-900">Kelola: tambah / keluarkan orang</a></p>
            <p class="text-xs text-violet-800">
                Dinilai akun pilihan HRD, bukan akun departemen. Kriteria dan target tetap sama.
            </p>
        </div>
        @if ($routes->isNotEmpty())
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-600">
                    <tr><th class="px-4 py-2 font-medium">Aturan</th><th class="px-4 py-2 font-medium">Penilai</th><th class="px-4 py-2 font-medium">Approver</th><th class="px-4 py-2 font-medium">Karyawan saat ini</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($routes as $route)
                        <tr class="align-top">
                            <td class="px-4 py-2 text-xs text-slate-700">{{ $route['why'] }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $route['assessor']?->username }}<span class="block font-sans text-slate-500">{{ $route['assessor']?->name }}</span></td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $route['approver']?->username }}<span class="block font-sans text-slate-500">{{ $route['approver']?->name }}</span></td>
                            <td class="px-4 py-2 text-xs text-slate-600"><span class="font-semibold text-slate-800">{{ $route['people']->count() }}</span> · {{ $route['people']->pluck('name')->implode(', ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @foreach ($routeProblems as $problem)
            <p class="border-t border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900">{{ $problem }}.</p>
        @endforeach
    </div>
@endif
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <p class="text-sm text-slate-600">
        <strong>Tanpa seksi</strong> = seluruh departemen. <strong>Dengan seksi</strong> = khusus seksi itu.
    </p>
    <a href="{{ route('criteria.create') }}" class="rounded bg-teal-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-teal-700">Tambah panduan</a>
</div>

@if ($missing->isNotEmpty())
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50">
        <div class="border-b border-amber-200 px-4 py-3">
            <p class="text-sm font-semibold text-amber-900">{{ $missing->count() }} departemen / seksi belum punya panduan</p>
            <p class="text-xs text-amber-800">Penilai di sana melihat "target belum diisi". Klik Buat untuk menambahkan.</p>
        </div>
        <div class="max-h-72 overflow-y-auto">
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-amber-100">
                    @foreach ($missing as $pair)
                        <tr>
                            <td class="px-4 py-2 font-medium text-slate-800">{{ $pair->department }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $pair->section ?: '(tanpa seksi)' }}</td>
                            <td class="px-4 py-2 text-right text-xs text-slate-500">{{ $pair->total }} karyawan</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('criteria.create', ['department' => $pair->department, 'section' => $pair->section]) }}" class="font-medium text-teal-700 hover:underline">Buat</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">Departemen</th>
                    <th class="px-4 py-3 font-medium">Seksi</th>
                    <th class="px-4 py-3 font-medium">Cuplikan (Kehadiran)</th>
                    <th class="px-4 py-3 font-medium">Diperbarui</th>
                    <th class="px-4 py-3 text-right font-medium">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr class="align-top">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $row->department }}</td>
                        <td class="px-4 py-3">
                            @if (filled($row->section))
                                {{ $row->section }}
                            @else
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Seluruh departemen</span>
                            @endif
                        </td>
                        <td class="max-w-md px-4 py-3 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $row->desc1, 90) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ $row->updated_at ? \Carbon\Carbon::parse($row->updated_at)->format('d M Y') : '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('criteria.edit', $row->id_desc) }}" class="font-medium text-teal-700 hover:underline">Edit</a>
                            <a href="{{ route('criteria.create', ['copy_from' => $row->id_desc]) }}" class="ml-3 font-medium text-slate-600 hover:underline">Duplikat</a>
                            <form method="POST" action="{{ route('criteria.destroy', $row->id_desc) }}" class="ml-3 inline"
                                  onsubmit="return confirm('Hapus panduan ini? Target tidak tampil lagi di form.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-medium text-rose-700 hover:underline">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">Belum ada panduan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
