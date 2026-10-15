{{--
    等計分數 Perhitungan Nilai: legend of the final grades, plus window.finalGrade() for Alpine
    (same thresholds as Assessment::finalGradeFor()). Include once per page; the script is pushed once.
--}}
@use('App\Models\Assessment')
<div class="inline-block overflow-hidden rounded border border-slate-300 bg-white text-sm">
    <div class="border-b border-slate-300 bg-slate-50 px-3 py-1.5 text-center">
        <span class="font-semibold text-slate-800">{{ Assessment::HEADINGS['scale'][0] }}</span>
        <span class="font-semibold text-slate-700">{{ Assessment::HEADINGS['scale'][1] }}</span>
    </div>
    <table class="text-sm">
        <tbody class="divide-y divide-slate-200">
            @foreach (array_reverse(Assessment::finalGradeRanges(), true) as $letter => [$min, $max])
                <tr>
                    <td class="px-3 py-1 text-center">
                        <span class="inline-flex w-6 justify-center rounded px-1 text-xs font-bold ring-1 ring-inset {{ Assessment::finalGradeClass($letter) }}">{{ $letter }}</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-1 text-slate-700">{{ $letter }}級 ({{ $min }}~{{ $max }})</td>
                    <td class="whitespace-nowrap px-3 py-1 font-medium text-slate-900">Nilai {{ $letter }} ({{ $min }}~{{ $max }})</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@once
    @push('scripts')
        <script>
            window.finalGrade = (function (thresholds, classes) {
                const grade = total => {
                    if (total === null || total === undefined || total === '' || isNaN(total)) return '-';
                    for (const [letter, minimum] of Object.entries(thresholds)) {
                        if (Number(total) >= minimum) return letter;
                    }
                    return 'D';
                };
                grade.classFor = letter => classes[letter] ?? classes['-'];
                return grade;
            })(@js(Assessment::FINAL_GRADES), @js(collect(['A', 'B', 'C', 'D', '-'])->mapWithKeys(fn ($l) => [$l => Assessment::finalGradeClass($l)])));
        </script>
    @endpush
@endonce
