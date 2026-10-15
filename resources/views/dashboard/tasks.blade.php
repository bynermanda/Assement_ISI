{{--
    "Tugas Saya": everything to do this period, for every Penilai / Approval / Assessment 2 account.
    Written for users who are not used to web apps: plain Indonesian, large text and buttons, one button per task,
    status always in words (never colour only). Needs $my from App\Support\MyTasks::for().
--}}
@php
    $icons = [
        'pencil' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/>',
        'check'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    ];
@endphp

<div class="space-y-6 text-base sm:text-lg">

    {{-- 1. one sentence: is there work? --}}
    @if ($my['tasks'] === [])
        <div class="rounded-xl border-2 border-slate-300 bg-white p-8" role="status">
            <p class="text-2xl font-bold text-slate-900">{{ tb('Akun Anda belum mendapat tugas penilaian.') }}</p>
            <p class="mt-1 text-slate-700">{{ tb('Jika seharusnya ada, silakan hubungi HRD.') }}</p>
        </div>
    @elseif ($my['open'] > 0 || $my['sentBack']->isNotEmpty())
        <div class="flex items-start gap-5 rounded-xl border-2 border-amber-400 bg-amber-50 p-6 md:p-8" role="status">
            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500 text-xl font-bold text-white" aria-hidden="true">!</span>
            <div>
                <p class="text-2xl font-bold text-slate-900">
                    {{ tb('Anda punya :n tugas yang perlu dikerjakan.', ['n' => $my['open'] + $my['sentBack']->count()]) }}
                </p>
                <p class="mt-1 text-slate-800">{{ tb('Klik tombol hijau pada kotak di bawah untuk mulai.') }}</p>
            </div>
        </div>
    @else
        <div class="flex items-start gap-5 rounded-xl border-2 border-emerald-400 bg-emerald-50 p-6 md:p-8" role="status">
            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white" aria-hidden="true">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </span>
            <div>
                <p class="text-2xl font-bold text-slate-900">{{ tb('Semua tugas bulan ini sudah selesai. Terima kasih!') }}</p>
                <p class="mt-1 text-slate-800">{{ tb('Anda masih bisa membuka daftar di bawah untuk melihat hasilnya.') }}</p>
            </div>
        </div>
    @endif

    {{-- 2. sent back by the approver: most urgent, first --}}
    @if ($my['sentBack']->isNotEmpty())
        <section class="rounded-xl border-2 border-rose-400 bg-white" aria-labelledby="sentback-title">
            <div class="border-b-2 border-rose-200 bg-rose-50 px-6 py-5 md:px-8">
                <h2 id="sentback-title" class="text-2xl font-bold text-rose-900">{{ tb('Perlu diperbaiki (:n)', ['n' => $my['sentBack']->count()]) }}</h2>
                <p class="text-rose-900">{{ tb('Penilaian ini dikembalikan oleh atasan. Baca catatannya, perbaiki, lalu kirim lagi.') }}</p>
            </div>
            <ul class="divide-y divide-slate-200">
                @foreach ($my['sentBack'] as $item)
                    <li class="flex flex-col gap-3 px-6 py-5 sm:flex-row sm:items-center sm:justify-between md:px-8">
                        <div>
                            <p class="font-bold text-slate-900">{{ $item['name'] }} <span class="font-normal text-slate-600">· {{ $item['which'] }}</span></p>
                            @if ($item['note'])
                                <p class="mt-1 text-slate-800"><span class="font-semibold">{{ t('Catatan') }}:</span> {{ $item['note'] }}</p>
                            @endif
                        </div>
                        <a href="{{ $item['url'] }}"
                           class="inline-flex min-h-[3.5rem] items-center justify-center rounded-lg bg-rose-700 px-8 py-4 text-lg font-bold text-white hover:bg-rose-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-300">
                            {{ t('Perbaiki') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 3. one card per task --}}
    @if ($my['tasks'] !== [])
        <div class="grid grid-cols-1 gap-6 md:grid-cols-[repeat(auto-fit,minmax(24rem,1fr))]">
            @foreach ($my['tasks'] as $task)
                @php
                    $pending = $task['open'] > 0;
                    $pct     = $task['total'] > 0 ? min(100, (int) round($task['done'] / $task['total'] * 100)) : 100;
                @endphp
                <section @class(['flex flex-col rounded-xl border-2 bg-white p-6 md:p-8', 'border-amber-400' => $pending, 'border-emerald-300' => ! $pending])
                         aria-labelledby="task-{{ $task['key'] }}">
                    <div class="flex items-start gap-4">
                        <span @class(['inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full',
                                      'bg-amber-100 text-amber-800' => $pending, 'bg-emerald-100 text-emerald-800' => ! $pending]) aria-hidden="true">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">{!! $icons[$task['icon']] !!}</svg>
                        </span>
                        <div>
                            <h2 id="task-{{ $task['key'] }}" class="text-2xl font-bold text-slate-900">{{ tb($task['title']) }}</h2>
                            <p class="text-slate-700">{{ t($task['what']) }}</p>
                        </div>
                    </div>

                    <div class="mt-5">
                        @if ($pending)
                            <p class="text-slate-900"><span class="text-5xl font-extrabold tabular-nums">{{ $task['open'] }}</span>
                                <span class="ml-1 text-lg">{{ t($task['unit']) }}</span></p>
                        @else
                            <p class="flex items-center gap-2 text-2xl font-bold text-emerald-800">
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                {{ t('Selesai, tidak ada yang menunggu') }}
                            </p>
                        @endif

                        @if ($task['total'] > 0)
                            <div class="mt-4">
                                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $task['total'] }}" aria-valuenow="{{ $task['done'] }}"
                                     aria-label="{{ $task['title'] }}: {{ $task['done'] }} / {{ $task['total'] }}">
                                    <div class="h-full rounded-full {{ $pending ? 'bg-amber-500' : 'bg-emerald-600' }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <p class="mt-1 text-slate-700">{{ t(str_contains($task['key'], 'approve') ? 'Sudah disetujui bulan ini' : 'Sudah dinilai bulan ini') }}: <span class="font-semibold text-slate-900">{{ t(':done dari :total', ['done' => $task['done'], 'total' => $task['total']]) }}</span></p>
                                @if ($task['notSent'] > 0)
                                    <p class="text-slate-700">{{ tb(':n belum dikirim oleh Penilai. Akan muncul di sini setelah dikirim.', ['n' => $task['notSent']]) }}</p>
                                @endif
                            </div>
                        @endif

                        @if ($pending && $task['names'])
                            <div class="mt-4">
                                <p class="font-semibold text-slate-900">{{ t('Yang perlu dikerjakan') }}:</p>
                                <ul class="mt-1 space-y-1">
                                    @foreach ($task['names'] as $n)
                                        <li class="flex flex-wrap items-baseline justify-between gap-x-3 border-b border-slate-100 pb-1">
                                            <span class="text-slate-900">{{ $n['name'] }}</span>
                                            <span class="text-base text-slate-700">{{ t($n['status']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($task['open'] > count($task['names']))
                                    <p class="mt-1 text-slate-700">{{ t('dan :n lainnya', ['n' => $task['open'] - count($task['names'])]) }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="mt-auto pt-6">
                        <a href="{{ $task['button'][1] }}"
                           @class(['flex min-h-[3.75rem] w-full items-center justify-center rounded-lg px-6 py-4 text-lg font-bold focus:outline-none focus-visible:ring-4',
                                   'bg-teal-700 text-white hover:bg-teal-800 focus-visible:ring-teal-300' => $pending,
                                   'border-2 border-slate-400 bg-white text-slate-900 hover:bg-slate-50 focus-visible:ring-slate-300' => ! $pending])>
                            {{ t($pending ? $task['button'][0] : 'Lihat daftar') }}
                            <span class="ml-2" aria-hidden="true">→</span>
                        </a>
                    </div>
                </section>
            @endforeach
        </div>

        {{-- 4. how it works, in three steps --}}
        <section class="rounded-xl border border-slate-300 bg-white p-6 md:p-8" aria-labelledby="howto-title">
            <h2 id="howto-title" class="text-xl font-semibold text-slate-900">{{ tb('Cara mengerjakan') }}</h2>
            <ol class="mt-2 grid grid-cols-1 gap-3 text-slate-800 sm:grid-cols-3">
                <li class="flex gap-3"><span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 font-bold text-white">1</span>
                    <span>{{ tb('Klik tombol hijau pada kotak tugas.') }}</span></li>
                <li class="flex gap-3"><span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 font-bold text-white">2</span>
                    <span>{{ tb('Pilih nilai A, B, C atau D (menilai), atau centang nama (menyetujui).') }}</span></li>
                <li class="flex gap-3"><span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 font-bold text-white">3</span>
                    <span>{{ tb('Klik Kirim / Approve. Kembali ke halaman ini lewat menu Tugas Saya.') }}</span></li>
            </ol>
            <p class="mt-3 text-slate-700">{{ tb('Ada kendala? Hubungi HRD.') }}</p>
        </section>
    @endif
</div>
