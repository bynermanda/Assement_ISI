{{-- One preview window per page (in the layout). Opened with $dispatch('lampiran-open', { url, name, image }). --}}
<div x-data="{ open: false, url: '', name: '', image: false }"
     @lampiran-open.window="url = $event.detail.url; name = $event.detail.name; image = $event.detail.image; open = true"
     @keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-2 sm:p-6" role="dialog" aria-modal="true" :aria-label="name">
    <div class="absolute inset-0 bg-slate-900/70" @click="open = false"></div>
    <div class="relative flex h-full w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <p class="min-w-0 truncate text-sm font-semibold text-slate-900" x-text="name"></p>
            <div class="flex shrink-0 items-center gap-2">
                <a :href="url" target="_blank" rel="noopener" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ t('Buka di tab baru') }}</a>
                <button type="button" @click="open = false" class="rounded bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white">{{ t('Tutup') }}</button>
            </div>
        </div>
        <div class="min-h-0 flex-1 bg-slate-100">
            <template x-if="open && image">
                <div class="flex h-full items-center justify-center overflow-auto p-2"><img :src="url" :alt="name" class="max-h-full max-w-full object-contain"></div>
            </template>
            <template x-if="open && ! image">
                <iframe :src="url" :title="name" class="h-full w-full bg-white"></iframe>
            </template>
        </div>
    </div>
</div>
