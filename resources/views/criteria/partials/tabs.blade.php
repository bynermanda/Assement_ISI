{{-- Guidelines tabs. Needs $active: 'a1', 'routes', 'a2' or 'scale'. --}}
<nav class="mb-5 flex gap-1 border-b border-slate-200" aria-label="Panduan">
    @foreach (['a1' => ['Assessment 1', 'Target per departemen', route('criteria.index')],
               'routes' => ['Jalur Khusus A1', 'Penilai & approver khusus', route('criteria.routes')],
               'a2' => ['Assessment 2', 'Pimpinan per unit', route('criteria.a2')],
               'scale' => ['Skala Nilai Akhir', 'Batas A–D', route('criteria.scale')]] as $key => [$label, $hint, $url])
        <a href="{{ $url }}" @if ($active === $key) aria-current="page" @endif
           @class([
               '-mb-px rounded-t border px-4 py-2 text-sm',
               'border-slate-200 border-b-white bg-white font-semibold text-slate-900' => $active === $key,
               'border-transparent text-slate-500 hover:text-slate-800' => $active !== $key,
           ])>
            {{ $label }}
            <span class="hidden text-xs font-normal text-slate-400 md:inline">· {{ $hint }}</span>
        </a>
    @endforeach
</nav>
