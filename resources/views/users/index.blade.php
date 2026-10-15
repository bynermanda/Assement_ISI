@extends('layouts.app')

@use('App\Support\Roles')
@use('App\Support\ReviewRules')

@section('title', 'Pengguna')
@section('heading', 'Manajemen Pengguna')
@section('subheading', 'Akun departemen (A1): satu Penilai 1 (menilai) dan satu Penilai 2 (menyetujui) per departemen. Akun pribadi (A2): satu akun per orang yang menilai / menyetujui pimpinan. Admin untuk HRD.')

@section('content')
@php
    $countA1   = count($a1Duties);
    $a2Ids     = array_unique(array_merge(array_keys($a2Duties['assessor']), array_keys($a2Duties['approver'])));
    $countA2   = count($a2Ids);
    $countBoth = count(array_intersect(array_keys($a1Duties), $a2Ids));
    $chip      = 'inline-flex items-center rounded px-2 py-0.5 text-xs';
@endphp
<div x-data="userManager({
        storeUrl: '{{ route('users.store') }}',
        updateUrl: '{{ route('users.update', '__ID__') }}',
        resetUrl: '{{ route('users.reset-password', '__ID__') }}',
        deleteUrl: '{{ route('users.destroy', '__ID__') }}',
        splitUrl: '{{ route('users.split', '__ID__') }}',
        a2All: @js($a2All),
        reopen: @js($errors->any() ? old('_mode') : null),
        old: @js(old()),
     })">

    @if (session('generated_password'))
        <div class="mb-5 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Password baru untuk {{ session('generated_for') }}</p>
            <p class="mt-1">
                Berikan ke pengguna sekarang. Password ini tidak akan ditampilkan lagi:
                <code class="select-all rounded bg-white px-2 py-1 font-mono text-base">{{ session('generated_password') }}</code>
            </p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Summary: how many accounts work in A1, A2, both --}}
    <div class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-3">
        <div class="rounded-lg border border-black/[0.08] bg-white p-6">
            <p class="text-sm font-semibold text-ink">Assessment 1 <span class="font-normal text-ink-48">· karyawan grade 1–5</span></p>
            <p class="mt-2 text-4xl font-semibold text-ink">{{ $countA1 }} <span class="text-base font-normal text-ink-48">akun bertugas</span></p>
        </div>
        <div class="rounded-lg bg-tile-1 p-6 text-white">
            <p class="text-sm font-semibold">Assessment 2 <span class="font-normal text-[#cccccc]">· pimpinan</span></p>
            <p class="mt-2 text-4xl font-semibold">{{ $countA2 }} <span class="text-base font-normal text-[#cccccc]">akun bertugas</span></p>
        </div>
        <div class="rounded-lg border border-black/[0.08] bg-pearl p-6">
            <p class="text-sm font-semibold text-ink">Bertugas di keduanya</p>
            <p class="mt-2 text-4xl font-semibold text-ink">{{ $countBoth }} <span class="text-base font-normal text-ink-48">akun</span></p>
        </div>
    </div>

    {{-- Assessment 2: every participant needs a Penilai and an Approver --}}
    @if ($a2Missing->isNotEmpty() || $a2Unassigned->isNotEmpty())
        <div class="mb-6 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm">
            <p class="font-semibold text-rose-900">Assessment 2 belum lengkap</p>
            @if ($a2Missing->isNotEmpty())
                <p class="mt-2 text-rose-900">Peserta tanpa Penilai atau Approver: buka Edit pada akun yang seharusnya memegang, lalu centang namanya.</p>
                <ul class="mt-1 list-inside list-disc text-rose-800">
                    @foreach ($a2Missing as $p)
                        <li>{{ $p->employee?->name }} <span class="text-rose-600">({{ $p->unit?->name }})</span> –
                            {{ collect([! $p->assessor_id ? 'belum ada Penilai' : null, ! $p->approver_id ? 'belum ada Approver' : null])->filter()->implode(', ') }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($a2Unassigned->isNotEmpty())
                <p class="mt-2 text-amber-900">Masuk Assessment 2 (halaman Karyawan) tetapi belum dinilai siapa pun:
                    <span class="font-medium">{{ $a2Unassigned->map(fn ($e) => $e->name . ' (' . $e->department . ')')->implode(', ') }}</span>.
                    Tambahkan beserta Penilai dan Approver di <a href="{{ route('criteria.a2') }}" class="font-semibold underline">Panduan › Assessment 2</a>,
                    atau kembalikan ke Assessment 1 / Otomatis di halaman Karyawan jika mereka hanya penilai.</p>
            @endif
        </div>
    @else
        <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            Assessment 2: semua peserta sudah punya Penilai dan Approver.
        </div>
    @endif

    {{-- Coverage: every section needs a Penilai 1 and a Penilai 2 --}}
    @php $gaps = collect($coverage)->filter(fn ($r) => $r['employees'] > 0 && ($r['stage1'] === 0 || $r['stage2'] === 0))->count(); @endphp
    <details class="mb-6 overflow-hidden rounded-lg border border-black/[0.08] bg-white" @if ($gaps) open @endif>
        <summary class="cursor-pointer select-none border-b border-black/[0.08] px-5 py-4 font-semibold text-ink">
            Cakupan Assessment 1 per seksi
            @if ($gaps)
                <span class="ml-2 rounded bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ $gaps }} seksi belum lengkap</span>
            @else
                <span class="ml-2 text-sm font-normal text-ink-48">semua seksi punya Penilai 1 dan Penilai 2 (klik untuk melihat)</span>
            @endif
        </summary>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Departemen / seksi</th>
                        <th class="px-4 py-3 text-right font-medium">Karyawan (grade 1–5)</th>
                        <th class="px-4 py-3 text-right font-medium">Akun Penilai 1</th>
                        <th class="px-4 py-3 text-right font-medium">Akun Penilai 2</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($coverage as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-slate-900">{{ $row['group'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['employees'] }}</td>
                            @foreach ([1, 2] as $stage)
                                @php $n = $row['stage' . $stage]; $gap = $row['employees'] > 0 && $n === 0; @endphp
                                <td @class(['px-4 py-2 text-right tabular-nums', 'bg-rose-50 font-semibold text-rose-700' => $gap, 'text-slate-600' => ! $gap])>{{ $n }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
            Angka 0 merah = seksi ini punya karyawan tetapi belum ada akun untuk langkah tersebut. Karyawan grade 6 ke atas tidak termasuk Assessment 1.
            Agar satu orang memegang beberapa seksi, edit akunnya dan centang seksi lain.
        </p>
    </details>

    {{-- Default accounts per department --}}
    @if ($missingCount > 0 || $legacyCount > 0 || $oldPersonCount > 0)
        <form method="POST" action="{{ route('users.generate') }}" class="mb-6 rounded-lg border border-slate-200 bg-slate-50 p-4"
              onsubmit="return confirm('Buat akun sekarang?')">
            @csrf
            <p class="text-sm text-slate-900">
                @if ($missingCount > 0)
                    <span class="font-semibold">{{ $missingCount }}</span> akun bawaan belum ada: satu Penilai 1 dan satu Penilai 2 per departemen, mencakup semua seksinya
                    (login seperti <code class="rounded bg-white px-1">qa.penilai1</code>, <code class="rounded bg-white px-1">press.penilai2</code>).
                @endif
                Nama pemegang akun bisa diisi nanti lewat Edit.
            </p>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div>
                    <label for="gen_password" class="block text-xs font-medium text-slate-900">Password sementara untuk semua akun baru</label>
                    <input id="gen_password" name="password" type="text" required minlength="8" autocomplete="off"
                           class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600/30 sm:w-64">
                    <p class="mt-1 text-xs text-slate-700">Minimal 8 karakter berisi huruf dan angka.{{ config('assessment.force_password_change') ? ' Semua wajib mengganti saat login pertama.' : ' Tahap percobaan: tidak wajib ganti saat login pertama.' }}</p>
                </div>
                <div class="space-y-1 text-sm text-slate-900">
                    @if ($legacyCount > 0)
                        <label class="flex items-center gap-2"><input type="checkbox" name="delete_legacy" value="1" checked class="rounded border-slate-400"> Hapus {{ $legacyCount }} akun lama inputter / verifier / approver</label>
                    @endif
                    @if ($oldPersonCount > 0)
                        <label class="flex items-center gap-2"><input type="checkbox" name="delete_old_people" value="1" checked class="rounded border-slate-400"> Hapus {{ $oldPersonCount }} akun dari versi sebelumnya (grup gabungan / per orang)</label>
                    @endif
                </div>
                <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Buat akun</button>
            </div>
        </form>
    @endif

    {{-- Toolbar --}}
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <form method="GET" action="{{ route('users.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari nama, NIK atau username" aria-label="Cari"
                   class="w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30 sm:max-w-xs">
            <select name="role" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm" aria-label="Jenis akun">
                <option value="">Semua jenis akun</option>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="duty" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm" aria-label="Tugas">
                <option value="">Semua tugas</option>
                <option value="a1" @selected(request('duty') === 'a1')>Bertugas di Assessment 1</option>
                <option value="a2" @selected(request('duty') === 'a2')>Bertugas di Assessment 2</option>
                <option value="both" @selected(request('duty') === 'both')>Bertugas di keduanya</option>
                <option value="none" @selected(request('duty') === 'none')>Belum ada tugas</option>
            </select>
            <button type="submit" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Terapkan</button>
            @if (request()->hasAny(['q', 'role', 'duty']))
                <a href="{{ route('users.index') }}" class="px-2 py-2 text-sm text-slate-500 hover:underline">Reset filter</a>
            @endif
        </form>
        <div class="flex gap-2">
            <button type="button" @click="mergeOpen = true" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    title="Satu orang, satu akun: pindahkan tugas Assessment 1 dan 2 dari satu akun ke akun lain">Gabungkan akun</button>
            <button type="button" @click="openCreate()" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Tambah pengguna</button>
        </div>
    </div>

    {{-- Legend --}}
    <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-slate-600">
        <span class="flex items-center gap-1.5"><span class="h-3.5 w-3.5 rounded-sm bg-white ring-1 ring-black/25"></span> Assessment 1: karyawan grade 1–5 (per seksi)</span>
        <span class="flex items-center gap-1.5"><span class="h-3.5 w-3.5 rounded-sm bg-tile-1"></span> Assessment 2: pimpinan (per orang)</span>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Akun</th>
                        <th class="bg-white px-4 py-3 font-semibold text-ink">Tugas Assessment 1</th>
                        <th class="bg-tile-1 px-4 py-3 font-semibold text-white">Tugas Assessment 2</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($users as $user)
                        @php
                            $userGroups = ReviewRules::userGroups($user);
                            $a1         = $a1Duties[$user->id] ?? null;
                            $assessing  = $a2Duties['assessor'][$user->id] ?? [];
                            $approving  = $a2Duties['approver'][$user->id] ?? [];
                            $payload    = ['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'role' => $user->role, 'nik' => $user->nik,
                                           'job_title' => $user->job_title, 'stage' => $user->stage, 'groups' => $userGroups,
                                           'assessing' => $assessing, 'approving' => $approving];
                            $roleClass  = match ($user->role) {
                                Roles::ADMIN    => 'bg-slate-100 text-ink-80 ring-1 ring-inset ring-black/10',
                                Roles::REVIEWER => 'bg-white text-ink ring-1 ring-inset ring-black/25',
                                Roles::ASSESSOR => 'bg-tile-1 text-white',
                                default         => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <tr class="align-top">
                            {{-- account --}}
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-900">{{ $user->name }}</p>
                                <p class="font-mono text-xs text-slate-600">{{ $user->username }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-1">
                                    <span class="{{ $chip }} font-medium {{ $roleClass }}">{{ Roles::label($user->role) }}</span>
                                    @if ($user->must_change_password)
                                        <span class="{{ $chip }} bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-300" title="Wajib ganti password saat login berikutnya">sementara</span>
                                    @endif
                                </p>
                                @if ($user->nik || $user->job_title)
                                    <p class="mt-1 text-xs text-slate-500">{{ collect([$user->nik ? 'NIK ' . $user->nik : null, $user->job_title])->filter()->implode(' · ') }}</p>
                                @endif
                            </td>

                            {{-- Assessment 1 --}}
                            <td class="bg-white px-4 py-4">
                                @if ($a1)
                                    <div class="space-y-2">
                                        @foreach (['assess' => ['Penilai 1', 'menilai karyawan seksi'], 'approve' => ['Penilai 2', 'menyetujui karyawan seksi']] as $key => [$label, $what])
                                            @if ($a1[$key])
                                                <div>
                                                    <p class="text-xs text-ink"><span class="font-semibold">{{ $label }}</span> · {{ $what }}</p>
                                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                                        @foreach ($a1[$key] as $group)
                                                            <span class="{{ $chip }} bg-parchment text-ink ring-1 ring-inset ring-black/10">{{ $group }}</span>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                        @foreach (['leadAssess' => 'Menilai pimpinan seksi', 'leadApprove' => 'Menyetujui pimpinan seksi'] as $key => $label)
                                            @foreach ($a1[$key] as $department => $names)
                                                <details class="text-xs">
                                                    <summary class="cursor-pointer text-ink"><span class="font-semibold">{{ $label }}</span> · {{ $department }}
                                                        <span class="rounded bg-ink px-1.5 font-semibold text-white">{{ count($names) }}</span></summary>
                                                    <p class="mt-0.5 pl-3 text-slate-600">{{ implode(', ', $names) }}</p>
                                                </details>
                                            @endforeach
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Tidak ada</span>
                                @endif
                            </td>

                            {{-- Assessment 2 --}}
                            <td class="bg-tile-1 px-4 py-4 text-white">
                                @if ($assessing || $approving)
                                    <div class="space-y-2">
                                        @foreach (['Penilai' => [$assessing, 'menilai'], 'Approver' => [$approving, 'menyetujui']] as $label => [$people, $what])
                                            @if ($people)
                                                <div>
                                                    <p class="text-xs text-white"><span class="font-semibold">{{ $label }}</span> · {{ $what }}
                                                        <span class="rounded bg-white px-1.5 font-semibold text-ink">{{ count($people) }}</span> orang</p>
                                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                                        @foreach ($people as $person)
                                                            <span class="{{ $chip }} bg-white/10 text-white ring-1 ring-inset ring-white/20">{{ $person['name'] }}</span>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Tidak ada</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                {{-- @js() writes JSON.parse('...'): the attribute must use double quotes --}}
                                <div class="flex flex-col items-end gap-1">
                                    <button type="button" @click="openEdit(@js($payload))" class="font-medium text-teal-700 hover:underline">Edit</button>
                                    @if ($user->role !== Roles::ADMIN)
                                        <button type="button" @click="openSplit(@js($payload))" class="font-medium text-teal-700 hover:underline"
                                                title="Pisahkan: pindahkan sebagian tugas ke akun baru">Pisahkan</button>
                                    @endif
                                    <button type="button" @click="openReset(@js($payload))" class="font-medium text-slate-700 hover:underline">Reset password</button>
                                    @unless ($user->is(auth()->user()))
                                        <button type="button" @click="openDelete(@js($payload))" class="font-medium text-rose-700 hover:underline">Hapus</button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Tidak ada pengguna yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>

    {{-- Merge modal --}}
    <div x-show="mergeOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="mergeOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="mergeOpen = false"></div>
        <form method="POST" action="{{ route('users.merge') }}" class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-lg sm:rounded-lg"
              onsubmit="return confirm('Gabungkan akun ini? Akun pertama dihapus; tugas dan riwayatnya pindah ke akun kedua.')">
            @csrf
            <div class="space-y-4 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Gabungkan akun</h2>
                    <p class="mt-1 text-sm text-slate-500">Untuk satu orang yang punya dua login, misalnya akun Penilai 2 departemen (Assessment 1) dan akun pribadi Assessment 2.
                        Seksi dan langkah Penilai digabung, tugas Assessment 2 dan riwayat ikut pindah. Ditolak jika seseorang jadi menilai dirinya sendiri.</p>
                </div>
                <div>
                    <label for="merge_source" class="block text-sm font-medium text-slate-700">Akun yang dihapus</label>
                    <select id="merge_source" name="source_id" required class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Pilih</option>
                        @foreach ($mergeOptions as $option)<option value="{{ $option->id }}">{{ $option->username }} · {{ $option->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="merge_target" class="block text-sm font-medium text-slate-700">Akun yang dipertahankan (login dan password tetap)</label>
                    <select id="merge_target" name="target_id" required class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Pilih</option>
                        @foreach ($mergeOptions as $option)<option value="{{ $option->id }}">{{ $option->username }} · {{ $option->name }}</option>@endforeach
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="allow_self" value="1" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-600">
                    <span>Izinkan menilai diri sendiri di Assessment 2 (kepala departemen menilai dirinya; GM / Direktur tetap menyetujui). Menyetujui diri sendiri tidak pernah diizinkan.</span>
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" @click="mergeOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</button>
                <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Gabungkan</button>
            </div>
        </form>
    </div>

    {{-- Create / edit modal --}}
    <div x-show="formOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="formOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="formOpen = false"></div>
        <form method="POST" :action="formAction" class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl sm:max-w-xl sm:rounded-lg">
            @csrf
            <template x-if="mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>
            <input type="hidden" name="_mode" :value="mode">

            <div class="space-y-4 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900" x-text="mode === 'edit' ? 'Edit pengguna' : 'Tambah pengguna'"></h2>

                {{-- identity --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="f_role" class="block text-sm font-medium text-slate-700">Jenis akun</label>
                        <select id="f_role" name="role" x-model="f.role" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f_name" class="block text-sm font-medium text-slate-700">Nama</label>
                        <input id="f_name" name="name" x-model="f.name" type="text" maxlength="150" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="f_username" class="block text-sm font-medium text-slate-700">Username (login)</label>
                        <input id="f_username" name="username" x-model="f.username" type="text" maxlength="50" :required="mode === 'edit'" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm">
                    </div>
                    <p class="-mt-2 text-xs text-slate-500 sm:col-span-2" x-show="mode === 'create'">Huruf kecil, angka, titik, strip. Contoh: qa1.penilai1 atau a2.nama.orang</p>
                    <p class="-mt-2 text-xs text-amber-700 sm:col-span-2" x-show="mode === 'edit'">Mengubah username berarti mengubah login; riwayat penilaian ikut pindah. Beri tahu orangnya.</p>
                    <template x-if="f.role !== 'admin'">
                        <div class="contents">
                            <div>
                                <label for="f_nik" class="block text-sm font-medium text-slate-700">NIK pemegang (opsional)</label>
                                <input id="f_nik" name="nik" x-model="f.nik" type="text" maxlength="30" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                <p class="mt-1 text-xs text-slate-500">Harus ada di daftar karyawan. Kosongkan untuk TKA.</p>
                            </div>
                            <div>
                                <label for="f_job_title" class="block text-sm font-medium text-slate-700">Jabatan (opsional)</label>
                                <input id="f_job_title" name="job_title" x-model="f.job_title" type="text" maxlength="100" placeholder="mis. General Manager" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Assessment 1 --}}
                <template x-if="f.role === 'reviewer'">
                    <fieldset class="rounded-lg border border-black/15 bg-white p-4">
                        <legend class="rounded bg-white px-2 text-sm font-semibold text-ink ring-1 ring-black/15">Tugas Assessment 1 · karyawan grade 1–5</legend>
                        <div>
                            <label for="f_stage" class="block text-sm font-medium text-slate-700">Peran</label>
                            <select id="f_stage" name="stage" x-model="f.stage" required class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option value="1">Penilai 1 – menilai</option>
                                <option value="2">Penilai 2 – menyetujui (boleh mengedit)</option>
                                <option value="3">Penilai 1 + 2</option>
                            </select>
                        </div>
                        <p class="mt-3 text-sm font-medium text-slate-700">Seksi yang dipegang</p>
                        <div class="mt-1 grid max-h-48 grid-cols-2 gap-1 overflow-y-auto rounded border border-slate-200 bg-white p-2 text-sm">
                            @foreach ($groupOptions as $group)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="groups[]" value="{{ $group }}" x-model="f.groups" class="rounded border-slate-300 text-teal-600">
                                    {{ $group }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-slate-700">Pimpinan seksi di departemen Assessment Massal otomatis dinilai Penilai 2 dan disetujui atasannya (lihat Panduan › Assessment 1).</p>
                    </fieldset>
                </template>

                {{-- Assessment 2: who this account assesses / approves --}}
                <template x-if="mode === 'edit' && f.role !== 'admin'">
                    <fieldset class="rounded-lg border-2 border-tile-1 bg-white p-4">
                        <input type="hidden" name="a2_present" value="1">
                        <legend class="rounded bg-tile-1 px-2 py-0.5 text-sm font-semibold text-white">Tugas Assessment 2 · pimpinan</legend>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-slate-700">Centang siapa yang dinilai (Penilai) atau disetujui (Approver) akun ini. Mencentang berarti mengambil alih dari akun sebelumnya.
                                Untuk melepas seseorang, berikan ke akun lain (Penilai / Approver tidak boleh kosong).</p>
                            <input type="search" x-model.debounce.150ms="a2q" placeholder="Cari" class="rounded border border-slate-200 px-2 py-1 text-xs sm:w-36" aria-label="Cari peserta">
                        </div>
                        <div class="mt-2 max-h-64 overflow-y-auto rounded border border-slate-200 bg-white">
                            <table class="min-w-full text-xs">
                                <thead class="sticky top-0 bg-tile-1 text-left text-white">
                                    <tr><th class="px-2 py-1 font-medium">Peserta</th><th class="px-2 py-1 text-center font-medium">Penilai</th><th class="px-2 py-1 text-center font-medium">Approver</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <template x-for="p in a2All" :key="p.id">
                                        <tr x-show="!a2q || (p.name + ' ' + p.unit).toLowerCase().includes(a2q.toLowerCase())"
                                            :class="(f.a2assess.includes(p.id) || f.a2approve.includes(p.id)) ? 'bg-slate-50' : ''">
                                            <td class="px-2 py-1">
                                                <span class="font-medium text-slate-800" x-text="p.name"></span>
                                                <span class="text-slate-400" x-text="' · ' + p.unit"></span>
                                                <span class="block text-[11px] text-slate-500">
                                                    saat ini: <span :class="p.assessor ? '' : 'font-semibold text-rose-600'" x-text="p.assessor || 'belum ada Penilai'"></span>
                                                    / <span :class="p.approver ? '' : 'font-semibold text-rose-600'" x-text="p.approver || 'belum ada Approver'"></span>
                                                </span>
                                            </td>
                                            <td class="px-2 py-1 text-center"><input type="checkbox" name="a2_assessing[]" :value="p.id" x-model.number="f.a2assess" class="rounded border-slate-300 text-teal-600" :aria-label="'Penilai untuk ' + p.name"></td>
                                            <td class="px-2 py-1 text-center"><input type="checkbox" name="a2_approving[]" :value="p.id" x-model.number="f.a2approve" class="rounded border-slate-300 text-teal-600" :aria-label="'Approver untuk ' + p.name"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-1 text-xs text-slate-700"><span x-text="f.a2assess.length"></span> sebagai Penilai · <span x-text="f.a2approve.length"></span> sebagai Approver</p>
                    </fieldset>
                </template>

                <template x-if="mode === 'create'">
                    <div>
                        <label for="f_password" class="block text-sm font-medium text-slate-700">Password sementara</label>
                        <input id="f_password" name="password" type="text" required minlength="8" autocomplete="off" value="{{ config('assessment.default_password') }}" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-slate-500">Tugas Assessment 2 bisa diatur lewat Edit setelah akun dibuat.</p>
                    </div>
                </template>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" @click="formOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</button>
                <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Simpan</button>
            </div>
        </form>
    </div>

    {{-- Split (unmerge) modal --}}
    <div x-show="splitOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="splitOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="splitOpen = false"></div>
        <form method="POST" :action="splitAction" class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl sm:max-w-xl sm:rounded-lg">
            @csrf
            <div class="space-y-4 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Pisahkan akun</h2>
                    <p class="mt-1 text-sm text-slate-500">Pindahkan sebagian tugas <span class="font-mono font-medium text-slate-700" x-text="target.username"></span> ke akun BARU.
                        Yang tidak dicentang tetap di akun lama. Riwayat tetap di akun lama.</p>
                </div>

                <template x-if="(target.groups || []).length">
                    <fieldset class="rounded-lg border border-black/15 bg-white p-4">
                        <legend class="rounded bg-white px-2 text-sm font-semibold text-ink ring-1 ring-black/15">Assessment 1: seksi yang dipindahkan</legend>
                        <div class="grid grid-cols-1 gap-1 rounded border border-slate-200 bg-white p-2 text-sm sm:grid-cols-2">
                            <template x-for="g in target.groups" :key="g">
                                <label class="flex items-center gap-2"><input type="checkbox" name="sections[]" :value="g" x-model="split.sections" class="rounded border-slate-300"> <span x-text="g"></span></label>
                            </template>
                        </div>
                        <div class="mt-2" x-show="split.sections.length">
                            <label for="s_stage" class="text-sm text-slate-700">Peran akun baru</label>
                            <select id="s_stage" name="stage" x-model="split.stage" class="ml-2 rounded border border-slate-300 bg-white px-2 py-1 text-sm">
                                <option value="1">Penilai 1</option><option value="2">Penilai 2</option><option value="3">Penilai 1 + 2</option>
                            </select>
                        </div>
                    </fieldset>
                </template>

                <template x-if="(target.assessing || []).length || (target.approving || []).length">
                    <fieldset class="space-y-2 rounded-lg border-2 border-tile-1 bg-white p-4">
                        <legend class="rounded bg-tile-1 px-2 py-0.5 text-sm font-semibold text-white">Assessment 2: orang yang dipindahkan</legend>
                        <template x-if="(target.assessing || []).length">
                            <div>
                                <p class="text-xs font-semibold text-slate-900">Sebagai Penilai</p>
                                <div class="mt-1 grid grid-cols-1 gap-1 rounded border border-slate-200 bg-white p-2 text-sm sm:grid-cols-2">
                                    <template x-for="p in target.assessing" :key="p.id">
                                        <label class="flex items-center gap-2"><input type="checkbox" name="assessing[]" :value="p.id" x-model.number="split.assessing" class="rounded border-slate-300"> <span x-text="p.name"></span></label>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="(target.approving || []).length">
                            <div>
                                <p class="text-xs font-semibold text-slate-900">Sebagai Approver</p>
                                <div class="mt-1 grid grid-cols-1 gap-1 rounded border border-slate-200 bg-white p-2 text-sm sm:grid-cols-2">
                                    <template x-for="p in target.approving" :key="p.id">
                                        <label class="flex items-center gap-2"><input type="checkbox" name="approving[]" :value="p.id" x-model.number="split.approving" class="rounded border-slate-300"> <span x-text="p.name"></span></label>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </fieldset>
                </template>

                <div class="grid grid-cols-1 gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2">
                    <div>
                        <label for="s_username" class="block text-sm font-medium text-slate-700">Username baru</label>
                        <input id="s_username" name="username" type="text" required maxlength="50" placeholder="mis. qa2.penilai1" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm">
                    </div>
                    <div>
                        <label for="s_name" class="block text-sm font-medium text-slate-700">Nama</label>
                        <input id="s_name" name="name" type="text" required maxlength="150" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="s_nik" class="block text-sm font-medium text-slate-700">NIK pemegang (opsional)</label>
                        <input id="s_nik" name="nik" type="text" maxlength="30" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="s_job" class="block text-sm font-medium text-slate-700">Jabatan (opsional)</label>
                        <input id="s_job" name="job_title" type="text" maxlength="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="s_password" class="block text-sm font-medium text-slate-700">Password</label>
                        <input id="s_password" name="password" type="text" required minlength="8" autocomplete="off" value="{{ config('assessment.default_password') }}" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <p class="text-xs text-slate-500" x-text="(split.sections.length + split.assessing.length + split.approving.length) + ' tugas dipilih'"></p>
                <div class="flex gap-2">
                    <button type="button" @click="splitOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</button>
                    <button type="submit" :disabled="!(split.sections.length + split.assessing.length + split.approving.length)"
                            class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Buat akun</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Reset password modal --}}
    <div x-show="resetOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="resetOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="resetOpen = false"></div>
        <form method="POST" :action="resetAction" class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-md sm:rounded-lg">
            @csrf
            <div class="space-y-3 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">Reset password</h2>
                <p class="text-sm text-slate-500">Pengguna: <span class="font-medium text-slate-700" x-text="target.name + ' (' + target.username + ')'"></span></p>
                <label for="r_password" class="block text-sm font-medium text-slate-700">Password baru</label>
                <input id="r_password" name="password" type="text" autocomplete="off" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500">Kosongkan untuk dibuatkan otomatis.{{ config('assessment.force_password_change') ? ' Pengguna wajib menggantinya saat login berikutnya.' : '' }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" @click="resetOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700">Batal</button>
                <button type="submit" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Reset</button>
            </div>
        </form>
    </div>

    {{-- Delete modal --}}
    <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="deleteOpen = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="deleteOpen = false"></div>
        <form method="POST" :action="deleteAction" class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-md sm:rounded-lg">
            @csrf
            @method('DELETE')
            <div class="space-y-2 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">Hapus pengguna</h2>
                <p class="text-sm text-slate-600">Hapus <span class="font-medium" x-text="target.name + ' (' + target.username + ')'"></span>? Penilaian yang pernah ditangani tetap disimpan.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" @click="deleteOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700">Batal</button>
                <button type="submit" class="rounded bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Hapus</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function userManager(config) {
        const blank = () => ({ role: 'reviewer', nik: '', job_title: '', stage: '1', name: '', username: '', groups: [], assessing: [], approving: [], a2assess: [], a2approve: [] });

        return {
            formOpen: false, resetOpen: false, deleteOpen: false, mergeOpen: false, splitOpen: false,
            mode: 'create', f: blank(), target: {}, editId: null,
            a2All: config.a2All, a2q: '',
            split: { sections: [], assessing: [], approving: [], stage: '1' },

            init() {
                if (config.reopen) {
                    this.mode = config.reopen;
                    this.f = Object.assign(blank(), {
                        role: config.old.role || 'reviewer', nik: config.old.nik || '', stage: config.old.stage || '1',
                        name: config.old.name || '', username: config.old.username || '', groups: config.old.groups || [],
                    });
                    this.formOpen = true;
                }
            },
            get formAction() { return this.mode === 'edit' ? config.updateUrl.replace('__ID__', this.editId) : config.storeUrl; },
            get resetAction() { return config.resetUrl.replace('__ID__', this.target.id); },
            get deleteAction() { return config.deleteUrl.replace('__ID__', this.target.id); },

            openCreate() { this.mode = 'create'; this.editId = null; this.f = blank(); this.formOpen = true; },
            openEdit(user) {
                this.mode = 'edit'; this.editId = user.id;
                this.f = { role: user.role, nik: user.nik || '', job_title: user.job_title || '', stage: String(user.stage || 1), name: user.name || '',
                           username: user.username, groups: user.groups || [], assessing: user.assessing || [], approving: user.approving || [],
                           a2assess: (user.assessing || []).map(p => p.id), a2approve: (user.approving || []).map(p => p.id) };
                this.a2q = '';
                this.formOpen = true;
            },
            openSplit(user) {
                this.target = user;
                this.split = { sections: [], assessing: [], approving: [], stage: String(user.stage || 1) };
                this.splitOpen = true;
            },
            get splitAction() { return config.splitUrl.replace('__ID__', this.target.id); },
            openReset(user) { this.target = user; this.resetOpen = true; },
            openDelete(user) { this.target = user; this.deleteOpen = true; },
        };
    }
</script>
@endpush
