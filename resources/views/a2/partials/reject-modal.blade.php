{{-- Send-back modal. Needs an Alpine parent with rejectOpen, rejectUrl, employeeName, note. --}}
<div x-show="rejectOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="rejectOpen = false">
    <div class="absolute inset-0 bg-slate-900/60" @click="rejectOpen = false"></div>
    <form method="POST" :action="rejectUrl" class="relative w-full rounded-t-lg bg-white shadow-xl sm:max-w-lg sm:rounded-lg">
        @csrf
        <div class="px-5 py-4">
            <h2 class="text-lg font-semibold text-slate-900">{{ tb('Kembalikan ke Penilai') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ t('Penilaian untuk') }} <span class="font-medium text-slate-700" x-text="employeeName"></span></p>
            <label for="rejection_note" class="mt-4 block text-sm font-medium text-slate-700">{{ t('Apa yang perlu diperbaiki?') }}</label>
            <textarea id="rejection_note" name="rejection_note" rows="4" required minlength="5" maxlength="1000" x-model="note"
                      class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500/30"></textarea>
            @error('rejection_note')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
            <button type="button" @click="rejectOpen = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Batal') }}</button>
            <button type="submit" class="rounded bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('Kembalikan') }}</button>
        </div>
    </form>
</div>
