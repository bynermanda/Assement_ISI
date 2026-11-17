{{-- Read-only list of an assessment's attachments (approver / admin / locked assessment).
     $attachments: collection of AssessmentAttachment; $compact (bool): small chips for table cells. --}}
@php $compact = $compact ?? false; @endphp
@if ($compact)
    @forelse ($attachments as $file)
        <button type="button" title="{{ $file->original_name }} · {{ $file->sizeLabel() }}"
                @click="$dispatch('lampiran-open', { url: @js(route('attachments.show', $file)), name: @js($file->original_name), image: @js($file->isImage()) })"
                class="mb-1 flex max-w-[11rem] items-center gap-1 rounded border border-slate-300 bg-white px-2 py-0.5 text-xs text-slate-700 hover:bg-slate-50">
            <span class="shrink-0 font-bold text-slate-500">{{ $file->isImage() ? 'IMG' : 'PDF' }}</span>
            <span class="truncate">{{ $file->original_name }}</span>
        </button>
    @empty
        <span class="text-slate-400">–</span>
    @endforelse
@else
    <div class="rounded-lg border border-slate-200 bg-white px-5 py-4">
        <h3 class="text-sm font-semibold text-slate-900">{{ tb('Lampiran dari Penilai') }}</h3>
        @if ($attachments->isEmpty())
            <p class="mt-1 text-sm text-slate-500">{{ t('Tidak ada lampiran.') }}</p>
        @else
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($attachments as $file)
                    <li>
                        <button type="button"
                                @click="$dispatch('lampiran-open', { url: @js(route('attachments.show', $file)), name: @js($file->original_name), image: @js($file->isImage()) })"
                                class="flex max-w-xs items-center gap-2 rounded border border-slate-300 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50">
                            <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-bold text-slate-600">{{ $file->isImage() ? 'IMG' : 'PDF' }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-900">{{ $file->original_name }}</span>
                                <span class="block text-xs text-slate-500">{{ $file->sizeLabel() }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
