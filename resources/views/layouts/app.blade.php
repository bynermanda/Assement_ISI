@use('App\Support\Roles')
@use('App\Support\ReviewRules')
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | PT Indosafety Sentosa</title>

    {{-- Replace the CDN tags with @vite([...]) once Tailwind/Alpine are bundled. --}}
    @include('layouts.partials.theme')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="h-full bg-parchment text-ink antialiased">
@php
    $authUser  = auth()->user();
    $dashboard = ['Dashboard', 'dashboard', route('dashboard')];

    // Assessment 1 steps, including the ones a special route gives (an Assessment 2 account may approve some A1 people)
    $stages    = in_array($authUser->role, [Roles::REVIEWER, Roles::ASSESSOR], true) ? ReviewRules::dutyStages($authUser) : [];

    // Assessment 2 duties come from a2_participants, whatever the role
    $a2Duty  = in_array($authUser->role, [Roles::REVIEWER, Roles::ASSESSOR], true) ? ReviewRules::a2Duties($authUser) : ['assess' => false, 'approve' => false];
    $a2Links = array_values(array_filter([
        $a2Duty['assess'] ? ['Assessment 2', 'a2.index', route('a2.index')] : null,
        $a2Duty['approve'] ? ['Approval A2', 'a2.approvals', route('a2.approvals')] : null,
    ]));

    $links = match ($authUser->role) {
        Roles::ADMIN    => [$dashboard,
                            ['Karyawan', 'employees.*', route('employees.index')],
                            ['Pengguna', 'users.*', route('users.index')],
                            ['Penilaian', 'admin.assessments.*', route('admin.assessments.index')],
                            ['Assessment 2', 'a2.admin', route('a2.admin')],
                            ['Panduan', 'criteria.*', route('criteria.index')],
                            ['Backup', 'backup.*', route('backup.index')]],
        Roles::REVIEWER, Roles::ASSESSOR => array_values(array_filter([
                            ['Tugas Saya', 'dashboard', route('dashboard')],   // everything to do, in one page
                            in_array(1, $stages, true) && ReviewRules::usesBulk($authUser) ? ['Assessment Massal', 'assessments.bulk', route('assessments.bulk')] : null,
                            in_array(1, $stages, true) ? ['Form Assessment', 'assessments.create', route('assessments.create')] : null,
                            in_array(2, $stages, true) ? ['Approval', 'approval.*', route('approval.index')] : null,
                            ...$a2Links,
                        ])),
        default         => [$dashboard],
    };

    $whoAmI = match ($authUser->role) {
        Roles::REVIEWER => ReviewRules::stageLabel($authUser->stage) . ' · ' . (implode(', ', ReviewRules::userGroups($authUser)) ?: 'tanpa seksi'),
        Roles::ASSESSOR => 'Assessment 2' . ($authUser->job_title ? ' · ' . $authUser->job_title : ''),
        default         => Roles::label($authUser->role),
    };

    // While a password change is forced, hide the menu so the user stays on that page
    $forcedChange = (bool) $authUser->must_change_password;
@endphp

<div class="min-h-full">
    {{-- primary-nav: black, 64px, 16px bold links, active = green underline; logo left, account right --}}
    <nav class="sticky top-0 z-50 bg-black" x-data="{ mobileOpen: false }" aria-label="Menu utama">
        <div class="flex h-16 w-full items-center justify-between gap-8 px-6 md:px-10">
            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2" aria-label="PT Indosafety Sentosa">
                {{-- the logo has a white background: shown on a small white plate on the black nav --}}
                <span class="flex h-11 items-center rounded bg-white px-2"><img src="{{ asset('images/logo.png') }}" alt="" class="h-9 w-auto"></span>
                <span class="hidden text-base font-semibold text-white xl:inline">PT Indosafety Sentosa</span>
            </a>
            @unless ($forcedChange)
                <div class="hidden flex-1 items-center justify-center gap-2 nav:flex">
                    @foreach ($links as [$label, $pattern, $url])
                        <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif
                           class="flex h-16 flex-col justify-center border-b-4 px-4 pt-1 text-center text-base font-bold leading-tight text-white {{ request()->routeIs($pattern) ? 'border-primary' : 'border-transparent' }}">
                            {{ $label }}
                            @if ($zh = tzh($label))<span class="block text-xs font-normal text-white/70" lang="zh-Hant">{{ $zh }}</span>@endif
                        </a>
                    @endforeach
                </div>
            @endunless

            <div class="hidden shrink-0 items-center gap-4 nav:flex">
                <div class="text-right leading-tight">
                    <p class="text-sm font-semibold text-white">{{ $authUser->name ?? $authUser->username }}</p>
                    <p class="text-xs text-white/70">{{ $whoAmI }}</p>
                </div>
                <a href="{{ route('password.edit') }}" class="text-sm font-bold text-white">{{ t('Password') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    {{-- button-outline-on-dark --}}
                    <button type="submit" class="min-h-[44px] rounded border border-white px-5 text-base font-bold text-white">{{ t('Keluar') }}</button>
                </form>
            </div>

            <button type="button" class="flex h-12 w-12 items-center justify-center text-white nav:hidden" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen" aria-label="Menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" d="M4 8h16M4 16h16"/></svg>
            </button>
        </div>

        <div class="border-t border-hairline-strong bg-black nav:hidden" x-show="mobileOpen" x-cloak>
            <div class="w-full px-6 pb-6 pt-2 md:px-10">
                @unless ($forcedChange)
                    @foreach ($links as [$label, $pattern, $url])
                        <a href="{{ $url }}" class="block border-b border-hairline-strong border-l-4 py-3 pl-3 text-xl font-bold text-white {{ request()->routeIs($pattern) ? 'border-l-primary' : 'border-l-transparent' }}">
                            {{ $label }}
                            @if ($zh = tzh($label))<span class="block text-sm font-normal text-white/70" lang="zh-Hant">{{ $zh }}</span>@endif
                        </a>
                    @endforeach
                @endunless
                <div class="pt-4">
                    <p class="text-base text-white">{{ $authUser->name ?? $authUser->username }}</p>
                    <p class="pb-3 text-sm text-white/70">{{ $whoAmI }}</p>
                    <a href="{{ route('password.edit') }}" class="block py-2 text-base font-bold text-primary">{{ t('Ganti password') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="mt-2 min-h-[44px] rounded border border-white px-5 text-base font-bold text-white">{{ t('Keluar') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    {{-- sub-nav-strip: soft surface, page name (heading-xl 24px / 700) left, the page's action right; sticky = 5px ambient --}}
    <header class="sticky top-16 z-40 border-b border-hairline bg-parchment shadow-sticky">
        <div class="flex min-h-[64px] w-full items-center justify-between gap-4 px-6 py-3 md:px-10">
            <h1 class="text-2xl font-bold text-ink">@yield('heading')</h1>
            @hasSection('subnav-actions')
                <div class="flex shrink-0 items-center gap-2">@yield('subnav-actions')</div>
            @endif
        </div>
    </header>

    <main class="w-full px-6 pb-16 pt-8 md:px-10">
        @hasSection('subheading')
            <p class="mb-8 max-w-4xl text-base text-ink-80">@yield('subheading')</p>
        @endif

        @if (session('success'))
            <div class="mb-6 rounded border border-l-4 border-hairline border-l-emerald-800 bg-white px-5 py-4 text-base font-bold text-ink" role="status">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded border border-l-4 border-hairline border-l-rose-600 bg-white px-5 py-4 text-base font-bold text-ink" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>
</div>

@include('partials.attachments.viewer')
@stack('scripts')
<script>
    // One submit per POST form: a second click while the first request is on its way is ignored.
    // Runs after the form's own onsubmit (a cancelled confirm() is not counted); unlocks after 10 s as a safety net.
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (event.defaultPrevented || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = '1';
        setTimeout(() => { delete form.dataset.submitting; }, 10000);
    });
</script>
</body>
</html>
