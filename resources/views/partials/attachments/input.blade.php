{{-- Attachments in the Penilai's form (Assessment 1 and 2): keep / remove the current files, add new ones.
     The form needs enctype="multipart/form-data". Rules: App\Support\Attachments. $attachments: current files. --}}
@use('App\Support\Attachments')
@php
    $enabled  = Attachments::enabled();
    $messages = [
        'type'    => t('Lampiran hanya boleh PDF, JPG atau PNG.'),
        'size'    => t('File :name lebih dari :mb MB.', ['mb' => Attachments::MAX_KB / 1024]),
        'tooMany' => t('Maksimal :n file lampiran per penilaian.', ['n' => Attachments::MAX_FILES]),
    ];
@endphp
<div class="rounded-lg border border-slate-200 bg-white px-5 py-4"
     x-data="lampiranInput(@js(['existing' => $attachments->count(), 'max' => Attachments::MAX_FILES, 'maxBytes' => Attachments::MAX_KB * 1024, 'messages' => $messages]))">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
        <h3 class="text-sm font-semibold text-slate-900">{{ tb('Lampiran (opsional)') }}</h3>
        <p class="text-xs text-slate-500">{{ t('PDF, JPG atau PNG · maks. :n file · :mb MB per file. Foto otomatis dikecilkan.', ['n' => Attachments::MAX_FILES, 'mb' => Attachments::MAX_KB / 1024]) }}</p>
    </div>

    @if ($attachments->isNotEmpty())
        <ul class="mt-3 space-y-2">
            @foreach ($attachments as $file)
                <li class="flex items-center gap-3 rounded border border-slate-200 px-3 py-2 text-sm"
                    :class="removed.includes('{{ $file->id }}') ? 'bg-rose-50 opacity-70' : ''">
                    <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-bold text-slate-600">{{ $file->isImage() ? 'IMG' : 'PDF' }}</span>
                    <button type="button" class="min-w-0 flex-1 truncate text-left font-medium text-teal-800 hover:underline"
                            @click="$dispatch('lampiran-open', { url: @js(route('attachments.show', $file)), name: @js($file->original_name), image: @js($file->isImage()) })">{{ $file->original_name }}</button>
                    <span class="shrink-0 text-xs text-slate-500">{{ $file->sizeLabel() }}</span>
                    <label class="flex shrink-0 items-center gap-1 text-xs text-rose-700">
                        <input type="checkbox" name="lampiran_hapus[]" value="{{ $file->id }}" x-model="removed"> {{ t('Hapus') }}
                    </label>
                </li>
            @endforeach
        </ul>
        <p x-show="removed.length" x-cloak class="mt-1 text-xs text-rose-700">{{ t('Lampiran yang dicentang dihapus saat penilaian disimpan.') }}</p>
    @endif

    @if ($enabled)
        <ul class="mt-3 space-y-2" x-show="files.length" x-cloak>
            <template x-for="(file, i) in files" :key="i">
                <li class="flex items-center gap-3 rounded border border-teal-200 bg-teal-50 px-3 py-2 text-sm">
                    <span class="shrink-0 rounded bg-white px-1.5 py-0.5 text-xs font-bold text-slate-600" x-text="file.type === 'application/pdf' ? 'PDF' : 'IMG'"></span>
                    <span class="min-w-0 flex-1 truncate text-slate-900" x-text="file.name"></span>
                    <span class="shrink-0 text-xs text-slate-500" x-text="sizeLabel(file.size)"></span>
                    <button type="button" @click="remove(i)" class="shrink-0 text-xs font-semibold text-rose-700 hover:underline">{{ t('Batal') }}</button>
                </li>
            </template>
        </ul>

        {{-- the chosen (and compressed) files are put in this input, which is what the form sends --}}
        <input type="file" name="lampiran[]" multiple x-ref="upload" class="hidden" tabindex="-1" aria-hidden="true">
        <input type="file" id="lampiran_picker" x-ref="picker" multiple class="hidden"
               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" @change="add($event)">

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" @click="$refs.picker.click()" :disabled="room <= 0 || busy"
                    class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                + {{ t('Tambah file') }}
            </button>
            <span class="text-xs text-slate-500" x-show="busy" x-cloak>{{ t('Memproses foto…') }}</span>
            <span class="text-xs text-slate-500" x-show="! busy && room <= 0" x-cloak>{{ t('Maksimal :n file lampiran per penilaian.', ['n' => Attachments::MAX_FILES]) }}</span>
        </div>
        <p x-show="error" x-cloak class="mt-2 text-sm text-rose-600" x-text="error"></p>
    @else
        <p class="mt-3 text-sm text-slate-500">{{ t('Penyimpanan lampiran belum aktif. Hubungi HRD.') }}</p>
    @endif

    @if ($errors->has('lampiran') || $errors->has('lampiran.*'))
        <p class="mt-2 text-sm text-rose-600">{{ $errors->first('lampiran') ?: $errors->first('lampiran.*') }}</p>
    @endif
    @if ($errors->any())
        <p class="mt-1 text-xs text-slate-500">{{ t('File yang tadi dipilih belum tersimpan: pilih ulang lampirannya.') }}</p>
    @endif
</div>

@once
@push('scripts')
<script>
    // Attachment picker: checks type / size / count in the browser and makes photos smaller before upload
    // (longest side 1600 px, JPEG). The server checks everything again (App\Support\Attachments).
    function lampiranInput(cfg) {
        const TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
        return {
            files: [], removed: [], error: '', busy: false,
            get room() { return cfg.max - (cfg.existing - this.removed.length) - this.files.length; },
            sizeLabel(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB'; },
            async add(event) {
                const picked = [...event.target.files];
                event.target.value = '';
                this.error = '';
                this.busy = true;
                for (const original of picked) {
                    if (this.room <= 0) { this.error = cfg.messages.tooMany; break; }
                    if (! TYPES.includes(original.type)) { this.error = cfg.messages.type; continue; }
                    const file = original.type === 'application/pdf' ? original : await this.shrink(original);
                    if (file.size > cfg.maxBytes) { this.error = cfg.messages.size.replace(':name', original.name); continue; }
                    this.files.push(file);
                }
                this.busy = false;
                this.sync();
            },
            remove(i) { this.files.splice(i, 1); this.sync(); },
            sync() {
                const transfer = new DataTransfer();
                this.files.forEach(file => transfer.items.add(file));
                this.$refs.upload.files = transfer.files;
            },
            async shrink(file) {
                if (file.size <= 400 * 1024) return file;
                try {
                    const bitmap = await createImageBitmap(file);
                    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(bitmap.width * scale);
                    canvas.height = Math.round(bitmap.height * scale);
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';                       // transparent PNG -> white, not black
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
                    if (! blob || blob.size >= file.size) return file;
                    return new File([blob], file.name.replace(/\.(png|jpe?g)$/i, '') + '.jpg', { type: 'image/jpeg' });
                } catch (e) {
                    return file;
                }
            },
        };
    }
</script>
@endpush
@endonce
