<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk | PT Indosafety Sentosa</title>
    @include('layouts.partials.theme')
</head>
{{-- hero-card-dark: black chapter, copy on the left, the form as a white card with the green corner square --}}
<body class="flex min-h-full flex-col bg-black text-white">
    <header class="flex h-16 items-center px-6 md:px-12">
        <span class="flex h-12 items-center rounded bg-white px-2"><img src="{{ asset('images/logo.png') }}" alt="PT Indosafety Sentosa" class="h-10 w-auto"></span>
    </header>

    <main class="flex flex-1 items-center px-6 py-12 md:px-12 md:py-20">
        <div class="grid w-full grid-cols-1 items-center gap-12 lg:grid-cols-[1fr_440px] lg:gap-20">
            <div class="max-w-2xl">
                <p class="text-sm font-bold uppercase text-primary">PT Indosafety Sentosa Industri</p>
                <h1 class="mt-3 text-[32px] font-bold leading-tight sm:text-4xl lg:text-5xl">
                    Penilaian Kinerja Karyawan
                    <span class="mt-2 block text-2xl font-normal text-white/70" lang="zh-Hant">員工績效考核</span>
                </h1>
                <p class="mt-6 text-[22px] leading-[1.75] text-white/70">Kawasan Industri Mitra Karawang (KIM), Jl. Mitra Barat I Blok F11-12, Parungmulya, Kec. Ciampel, Karawang, Jawa Barat 41363</p>
            </div>

            <div class="relative rounded border border-hairline bg-white p-6 text-ink sm:p-8">
                <span class="absolute -left-px -top-px h-4 w-4 bg-primary" aria-hidden="true"></span>
                <h2 class="text-xl font-bold">Masuk <span class="font-normal text-ink-48" lang="zh-Hant">登入</span></h2>
                <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="username" class="block text-base font-bold">Username <span class="font-normal text-ink-48" lang="zh-Hant">帳號</span></label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
                               autocapitalize="none" autocorrect="off" spellcheck="false"
                               @class(['mt-1.5 block h-11 w-full rounded border bg-white px-4 text-base',
                                       'border-hairline' => ! $errors->has('username'), 'border-rose-600' => $errors->has('username')])>
                        @error('username')
                            <p class="mt-1.5 text-sm font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-base font-bold">Password <span class="font-normal text-ink-48" lang="zh-Hant">密碼</span></label>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="mt-1.5 block h-11 w-full rounded border border-hairline bg-white px-4 text-base">
                        @error('password')
                            <p class="mt-1.5 text-sm font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex min-h-[44px] items-center gap-3 text-base text-ink-80">
                        <input type="checkbox" name="remember" value="1" class="h-5 w-5">
                        Tetap masuk <span class="text-ink-48" lang="zh-Hant">保持登入</span>
                    </label>

                    {{-- button-primary (button-lg on the hero) --}}
                    <button type="submit" class="block h-12 w-full rounded bg-teal-600 text-lg font-bold">
                        Masuk · <span lang="zh-Hant">登入</span>
                    </button>
                </form>
            </div>
        </div>
    </main>

    <footer class="border-t border-hairline-strong px-6 py-4 text-micro font-bold uppercase text-ink-48 md:px-12">
        Ada kendala login? Hubungi HRD · <span lang="zh-Hant">Byan Widya Ermanda</span>
    </footer>
</body>
</html>
