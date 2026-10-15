@extends('layouts.app')

@section('title', 'Ganti password')
@section('heading', tb('Ganti password'))
@section('subheading', t('Pilih password yang hanya Anda ketahui.'))

@section('content')
<div class="max-w-md">
    @if (auth()->user()->must_change_password)
        <div class="mb-5 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ tb('Password Anda dibuat oleh admin. Buat password baru sebelum melanjutkan.') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5 rounded-lg border border-slate-200 bg-white p-6">
        @csrf
        @method('PUT')

        @foreach ([
            ['current_password', 'Password lama', 'current-password'],
            ['password', 'Password baru', 'new-password'],
            ['password_confirmation', 'Ulangi password baru', 'new-password'],
        ] as [$name, $label, $autocomplete])
            <div>
                <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">{{ t($label) }}</label>
                <input id="{{ $name }}" name="{{ $name }}" type="password" required autocomplete="{{ $autocomplete }}"
                       @class([
                           'mt-1 block w-full rounded border px-3 py-2 text-sm focus:outline-none focus:ring-2',
                           'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has($name),
                           'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has($name),
                       ])>
                @error($name)
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        <p class="text-xs text-slate-500">{{ t('Minimal 8 karakter, berisi huruf dan angka.') }}</p>

        <button type="submit" class="w-full rounded bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
            {{ t('Simpan password baru') }}
        </button>
    </form>
</div>
@endsection
