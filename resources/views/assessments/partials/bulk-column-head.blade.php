{{-- One criterion column heading in Assessment Massal: item label, weight, "Set…" for the whole column. --}}
<th @if (($rowspan ?? 1) > 1) rowspan="{{ $rowspan }}" @endif class="px-2 py-2 text-center align-bottom font-normal"
    @if ($target) title="{{ $target }}" @endif>
    @include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id'], 'center' => true])
    <span class="mt-0.5 block text-xs text-slate-500">{{ $meta['weight'] }}%</span>
    <select @change="setColumn('{{ $column }}', $event.target.value); $event.target.value = ''" aria-label="Isi semua {{ $meta['text'] }}"
            class="mt-1 w-16 rounded border border-slate-300 bg-white px-1 py-0.5 text-xs">
        <option value="">Isi…</option>
        @foreach ($grades as $letter => $points)<option value="{{ $points }}">{{ $letter }}</option>@endforeach
    </select>
</th>
