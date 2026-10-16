{{-- Guidelines tabs. Needs $active: 'a1', 'a2' or 'scale'. --}}
<nav class="mb-5 flex gap-1 border-b border-slate-200" aria-label="Panduan">
    @foreach (['a1' => ['Assessment 1', 'Grade 1-5 · satu panduan per departemen / seksi', route('criteria.index')],
               'a2' => ['Assessment 2', 'Pimpinan · kriteria, bobot, target dan penilai per unit', route('criteria.a2')],
               'scale' => ['Skala Nilai Akhir', 'Batas A / B / C / D untuk semua karyawan', route('criteria.scale')]] as $key => [$label, $hint, $url])
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
