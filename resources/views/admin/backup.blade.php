@extends('layouts.app')

@section('title', 'Backup Database')
@section('heading', 'Backup Database')
@section('subheading', 'Unduh seluruh database dalam satu file. Lakukan minimal sebulan sekali dan simpan di folder perusahaan.')

@section('content')
@php
    $lastAt = $last ? \Illuminate\Support\Carbon::parse($last['at'])->timezone('Asia/Jakarta') : null;
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_28rem]">
    <div class="space-y-6">
        {{-- download --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-sm font-bold uppercase text-slate-600">Backup terakhir</p>
            @if ($lastAt)
                <p class="mt-2 text-xl font-bold text-slate-900">{{ $lastAt->translatedFormat('d F Y, H:i') }} WIB</p>
                <p class="text-base text-slate-600">oleh {{ $last['by'] }} · {{ $lastAt->diffForHumans() }}</p>
            @else
                <p class="mt-2 text-xl font-bold text-slate-900">Belum pernah</p>
            @endif

            @if ($overdue)
                <div class="mt-4 rounded border border-l-4 border-slate-200 border-l-amber-500 bg-white px-4 py-3 text-base text-slate-900" role="status">
                    <b>Saatnya membuat backup.</b> {{ $lastAt ? 'Backup terakhir sudah lebih dari sebulan yang lalu.' : 'Database ini belum pernah di-backup dari aplikasi.' }}
                </div>
            @endif

            @if ($supported)
                <form method="POST" action="{{ route('backup.download') }}" class="mt-6">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded bg-teal-600 px-6 py-3 text-base font-bold text-white hover:bg-teal-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                        Unduh Backup
                    </button>
                    <p class="mt-2 text-sm text-slate-600">File <code>backup-penilaian-tanggal-jam.sql</code> langsung terunduh (beberapa detik).</p>
                </form>
            @else
                <p class="mt-6 text-base font-bold text-rose-700">Backup hanya bisa dibuat saat aplikasi memakai database Supabase (PostgreSQL).</p>
            @endif
        </div>

        {{-- contents --}}
        @if ($supported)
            <div class="rounded-lg border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-6 py-4 text-xl font-bold text-slate-900">Isi backup</h2>
                <table class="w-full text-base">
                    <thead class="bg-parchment text-left text-sm font-bold uppercase text-slate-600">
                        <tr><th class="px-6 py-3">Data</th><th class="px-6 py-3 text-right">Jumlah baris</th></tr>
                    </thead>
                    <tbody>
                        @foreach (collect($tables)->where('data', true) as $table)
                            <tr class="border-t border-slate-200">
                                <td class="px-6 py-3 text-slate-900">{{ $table['label'] }}</td>
                                <td class="px-6 py-3 text-right font-bold tabular-nums text-slate-900">{{ number_format($table['rows'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t border-slate-200">
                            <td class="px-6 py-3 text-slate-600" colspan="2">Termasuk struktur {{ count($tables) }} tabel (tanpa sesi login).</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- how to --}}
    <div class="space-y-6">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Menyimpan file backup</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-base text-slate-700">
                <li>Simpan di folder perusahaan berakses terbatas.</li>
                <li>Berisi <b>data pribadi karyawan</b>: jangan kirim lewat chat atau email pribadi.</li>
                <li>Simpan 12 file terakhir (satu per bulan).</li>
            </ul>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold text-slate-900">Memulihkan dari backup</h2>
            <p class="mt-2 text-base text-slate-700">Hanya bila data rusak. <b>Semua data saat ini diganti</b> isi backup.</p>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-base text-slate-700">
                <li>Buka file backup dengan Notepad, tekan <b>Ctrl+A</b> lalu <b>Ctrl+C</b>.</li>
                <li>Login ke <b>supabase.com</b>, buka proyek database aplikasi.</li>
                <li>Menu kiri <b>SQL Editor</b> → <b>New query</b>.</li>
                <li>Tempel (<b>Ctrl+V</b>) lalu klik <b>Run</b>. Tunggu sampai muncul <i>Success</i>.</li>
                <li>Buka aplikasi dan periksa datanya.</li>
            </ol>
            <p class="mt-3 text-sm text-slate-600">Jika muncul error, data tidak berubah. Kirim pesannya ke pengelola teknis.</p>
        </div>
    </div>
</div>
@endsection
