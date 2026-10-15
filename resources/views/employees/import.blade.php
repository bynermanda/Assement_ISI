@extends('layouts.app')

@section('title', 'Impor karyawan')
@section('heading', 'Impor Karyawan')
@section('subheading', 'Unggah daftar karyawan HRD apa adanya. Karyawan baru ditambahkan ke data karyawan.')

@section('content')
@php $report = session('employee_import'); @endphp

@if ($report)
    <div class="mb-6 rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold text-slate-900">Hasil impor</h2>
        </div>

        <div class="grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
            @foreach ([
                ['Karyawan baru', $report['created'], 'text-emerald-700'],
                ['Diperbarui', $report['updated'], 'text-sky-700'],
                ['Tidak berubah', $report['unchanged'], 'text-slate-700'],
                ['Gagal', $report['failed'], 'text-rose-700'],
            ] as [$label, $value, $color])
                <div>
                    <p class="text-sm text-slate-500">{{ $label }}</p>
                    <p class="text-3xl font-semibold {{ $color }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto border-t border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-5 py-2 font-medium">File</th>
                        <th class="px-5 py-2 text-right font-medium">Baru</th>
                        <th class="px-5 py-2 text-right font-medium">Diperbarui</th>
                        <th class="px-5 py-2 text-right font-medium">Tidak berubah</th>
                        <th class="px-5 py-2 text-right font-medium">Gagal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report['files'] as $file)
                        <tr>
                            <td class="px-5 py-2">
                                <span class="font-medium text-slate-800">{{ $file['name'] }}</span>
                                @if ($file['error'])
                                    <p class="text-xs text-rose-700">{{ $file['error'] }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right">{{ $file['created'] }}</td>
                            <td class="px-5 py-2 text-right">{{ $file['updated'] }}</td>
                            <td class="px-5 py-2 text-right">{{ $file['unchanged'] }}</td>
                            <td class="px-5 py-2 text-right">{{ $file['failed'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (count($report['problems']) > 0)
            <div class="border-t border-slate-200">
                <p class="px-5 py-3 text-sm font-medium text-slate-700">Baris yang tidak diimpor ({{ count($report['problems']) }})</p>
                <div class="max-h-60 overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <tbody class="divide-y divide-slate-100">
                            @foreach (array_slice($report['problems'], 0, 200) as $problem)
                                <tr>
                                    <td class="px-5 py-2 text-slate-500">{{ $problem['file'] }} &middot; baris {{ $problem['line'] }}</td>
                                    <td class="px-5 py-2 font-mono text-xs">{{ $problem['nik'] }}</td>
                                    <td class="px-5 py-2 text-rose-700">{{ $problem['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="border-t border-slate-200 px-5 py-3 text-sm">
            <a href="{{ route('employees.index') }}" class="font-medium text-teal-700 hover:underline">Lihat karyawan</a>
        </div>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-5">
    <div class="space-y-6 lg:col-span-3">
        <form method="POST" action="{{ route('employees.import.store') }}" enctype="multipart/form-data" class="rounded-lg border border-slate-200 bg-white p-5">
            @csrf
            <h2 class="font-semibold text-slate-900">Unggah file karyawan</h2>
            <p class="mt-1 text-sm text-slate-600">Bisa memilih beberapa file sekaligus (misalnya daftar utama dan daftar outsourcing).</p>

            @unless ($excelSupported)
                <div class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    File Excel (.xls / .xlsx) belum bisa dibaca. Jalankan <code class="font-mono">composer require phpoffice/phpspreadsheet</code> sekali,
                    atau unggah sebagai <strong>.csv</strong> untuk sementara.
                </div>
            @endunless

            <div class="mt-4">
                <label for="files" class="block text-sm font-medium text-slate-700">File</label>
                <input id="files" name="files[]" type="file" multiple required accept=".xls,.xlsx,.csv,.txt"
                       class="mt-1 block w-full rounded border border-slate-300 bg-white text-sm file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-2.5 file:text-sm file:font-medium">
                @error('files')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                @error('files.*')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="update_existing" value="1" checked class="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-600">
                <span>
                    <span class="font-medium">Perbarui juga karyawan yang sudah ada</span>
                    <span class="block text-xs text-slate-500">
                        Mengisi Seksi dan memperbarui nama, departemen, grade dan jabatan. Sel kosong tidak pernah menghapus data yang ada.
                    </span>
                </span>
            </label>

            <button type="submit" class="mt-5 rounded bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                Impor karyawan
            </button>
        </form>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-semibold text-slate-900">Kolom yang dibaca</h2>
            <p class="mt-1 text-sm text-slate-600">Baris judul dicari otomatis. Kolom lain (No, Line, Tanggal Masuk) diabaikan.</p>

            <table class="mt-3 w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr><th class="py-1 font-medium">Di file</th><th class="py-1 font-medium">Disimpan sebagai</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ([
                        ['NIK', 'NIK (wajib)'],
                        ['Nama', 'Nama (wajib)'],
                        ['Department', 'Departemen (wajib)'],
                        ['Section', 'Seksi'],
                        ['Grade (Grade 1, Grade 2, ...)', 'Grade (kolom position)'],
                        ['Jabatan (Operator, Staff, ...)', 'Jabatan (kolom grade)'],
                    ] as [$from, $to])
                        <tr><td class="py-1.5 font-mono text-xs text-slate-700">{{ $from }}</td><td class="py-1.5 text-slate-600">{{ $to }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <p class="mt-3 text-xs text-slate-500">
                NIK dicocokkan tanpa titik dan nol di depan, jadi <span class="font-mono">01.151002</span> di file dikenali sebagai
                <span class="font-mono">1.151.002</span> di database dan tidak ditambahkan dua kali.
            </p>
        </div>
    </div>

    {{-- Guideline coverage --}}
    <div class="lg:col-span-2">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-semibold text-slate-900">Cakupan panduan</h2>
            <p class="mt-1 text-sm text-slate-600">
                {{ $activeEmployees }} karyawan aktif. Setiap departemen / seksi perlu satu baris panduan.
            </p>

            @if ($missingGuidelines->isEmpty())
                <p class="mt-4 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Semua departemen dan seksi sudah punya panduan.</p>
            @else
                <p class="mt-4 text-sm font-medium text-amber-800">{{ $missingGuidelines->count() }} belum punya panduan</p>
                <div class="mt-2 max-h-96 overflow-y-auto rounded border border-slate-200">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($missingGuidelines as $pair)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium text-slate-800">{{ $pair->department }}</td>
                                    <td class="px-3 py-1.5 text-slate-600">{{ $pair->section ?: '(tanpa seksi)' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    Selama belum ada, form penilaian menampilkan "target belum diisi". Baris dengan Seksi kosong berlaku untuk seluruh departemen.
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
