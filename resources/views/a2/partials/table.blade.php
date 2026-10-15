{{--
    Assessment 2 criteria table. Needs: $criteria (A2Criterion collection), $notes ("c{id}" / "general" => text),
    $editable (selects + textareas, or read-only), and an Alpine parent a2Form() with scores / contribution() / total / complete.
--}}
@use('App\Models\Assessment')
@use('App\Models\A2Criterion')
@php $layout = A2Criterion::layout($criteria); @endphp
<div class="overflow-x-auto">
    <table class="table-lines min-w-full text-sm">
        <thead class="bg-slate-50 text-left align-bottom">
            <tr>
                <th class="w-10 px-3 py-3 text-center font-medium text-slate-600">No</th>
                <th colspan="2" class="min-w-[16rem] px-4 py-3 text-center">@include('assessments.partials.heading', ['key' => 'criteria'])</th>
                <th class="px-3 py-3 text-center">@include('assessments.partials.heading', ['key' => 'weight'])</th>
                <th class="min-w-[22rem] px-4 py-3">@include('assessments.partials.heading', ['key' => 'target'])</th>
                <th class="px-3 py-3 font-semibold text-slate-700">{{ tb('Nilai') }}</th>
                <th class="px-3 py-3 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                <th class="min-w-[14rem] px-4 py-3">@include('assessments.partials.heading', ['key' => 'notes'])</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($criteria as $c)
                @php [$group, $first, $size] = $layout[$c->id]; @endphp
                <tr class="align-top">
                    <td class="px-3 py-4 text-center text-slate-500">{{ $loop->iteration }}</td>
                    @if ($group === null)
                        <td colspan="2" class="px-4 py-4">@include('assessments.partials.item-label', ['zh' => (string) $c->name_zh, 'id' => $c->name_id])</td>
                    @else
                        @if ($first)
                            <td rowspan="{{ $size }}" class="border-r border-slate-200 bg-slate-50/60 px-4 py-4 align-middle">
                                @include('assessments.partials.item-label', ['zh' => (string) $c->group_zh, 'id' => (string) $c->group_id, 'center' => true])
                            </td>
                        @endif
                        <td class="px-4 py-4">@include('assessments.partials.item-label', ['zh' => (string) $c->name_zh, 'id' => $c->name_id])</td>
                    @endif
                    <td class="px-3 py-4 text-center text-slate-600">{{ rtrim(rtrim(number_format($c->weight, 2), '0'), '.') }}%</td>
                    <td class="px-4 py-4 text-sm leading-relaxed text-slate-600">
                        @if ($c->target)
                            {!! nl2br(e($c->target)) !!}
                        @else
                            <span class="text-slate-400">{{ t('Belum ada target.') }}</span>
                        @endif
                        @if ($c->remark)
                            <details class="mt-2 text-xs text-slate-500">
                                <summary class="cursor-pointer font-medium text-slate-600">{{ t('Keterangan') }}</summary>
                                <p class="mt-1">{!! nl2br(e($c->remark)) !!}</p>
                            </details>
                        @endif
                    </td>
                    <td class="px-3 py-4">
                        @if ($editable)
                            <select name="scores[{{ $c->id }}]" x-model="scores['{{ $c->id }}']" aria-label="{{ t('Nilai') }}: {{ $c->text() }}"
                                    @class([
                                        'block w-24 rounded border bg-white px-2 py-2 text-sm focus:outline-none focus:ring-2',
                                        'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has("scores.{$c->id}"),
                                        'border-rose-500 focus:ring-rose-500/30' => $errors->has("scores.{$c->id}"),
                                    ])>
                                <option value="">{{ t('Pilih') }}</option>
                                @foreach (Assessment::GRADES as $letter => $points)
                                    <option value="{{ $points }}">{{ $letter }} ({{ $points }})</option>
                                @endforeach
                            </select>
                        @else
                            <span class="text-base font-semibold text-slate-900" x-text="({'100':'A','80':'B','60':'C','0':'D'})[String(scores['{{ $c->id }}'])] ?? '-'"></span>
                        @endif
                    </td>
                    <td class="px-3 py-4 text-right font-semibold tabular-nums" x-text="contribution('{{ $c->id }}').toFixed(1)">0.0</td>
                    <td class="px-4 py-3">
                        @if ($editable)
                            <textarea name="notes[c{{ $c->id }}]" rows="2" maxlength="{{ Assessment::NOTE_MAX }}" placeholder="{{ t('Opsional') }}" aria-label="{{ t('Catatan') }}: {{ $c->text() }}"
                                      class="block w-full resize-y rounded border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">{{ $notes["c{$c->id}"] ?? '' }}</textarea>
                        @else
                            <span class="text-sm text-slate-700">{!! nl2br(e($notes["c{$c->id}"] ?? '')) ?: '<span class="text-slate-400">–</span>' !!}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="bg-slate-50/60 align-top">
                <td></td>
                <td colspan="6" class="px-4 py-3 text-sm">
                    <p class="font-medium text-slate-900">{{ tb('Catatan umum') }}</p>
                    <p class="text-xs text-slate-500">{{ tb('Tentang karyawan secara keseluruhan.') }}</p>
                </td>
                <td class="px-4 py-3">
                    @if ($editable)
                        <textarea name="notes[{{ Assessment::NOTE_GENERAL }}]" rows="2" maxlength="{{ Assessment::NOTE_MAX }}" placeholder="{{ t('Opsional') }}" aria-label="{{ t('Catatan umum') }}"
                                  class="block w-full resize-y rounded border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">{{ $notes[Assessment::NOTE_GENERAL] ?? '' }}</textarea>
                    @else
                        <span class="text-sm text-slate-700">{!! nl2br(e($notes[Assessment::NOTE_GENERAL] ?? '')) ?: '<span class="text-slate-400">–</span>' !!}</span>
                    @endif
                </td>
            </tr>
        </tbody>
        <tfoot class="bg-slate-900 text-white">
            <tr>
                <td colspan="6" class="px-4 py-4 text-right text-sm font-medium">
                    總分 Nilai Akhir
                    <span class="ml-2 text-xs text-slate-400">(total bobot {{ rtrim(rtrim(number_format($criteria->sum('weight'), 2), '0'), '.') }}%)</span>
                </td>
                <td class="px-3 py-4 text-right text-xl font-semibold tabular-nums" x-text="total.toFixed(1)">0.0</td>
                <td class="px-4 py-4">
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded text-lg font-bold ring-1 ring-inset"
                              :class="finalGrade.classFor(complete ? finalGrade(total) : '-')" x-text="complete ? finalGrade(total) : '-'">-</span>
                        <span class="text-sm text-slate-300" x-text="complete ? 'Nilai ' + finalGrade(total) : @js(t('Belum semua kriteria diberi nilai'))"></span>
                    </span>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<div class="border-t border-slate-200 px-5 py-4">
    @include('assessments.partials.final-scale')
</div>

@once
    @push('scripts')
        <script>
            function a2Form(scores, weights) {
                return {
                    scores: scores,
                    weights: weights,
                    contribution(id) { return (Number(this.scores[id]) || 0) * (this.weights[id] || 0) / 100; },
                    get total() { return Object.keys(this.weights).reduce((sum, id) => sum + this.contribution(id), 0); },
                    get complete() { return Object.keys(this.weights).every(id => this.scores[id] !== '' && this.scores[id] !== undefined && this.scores[id] !== null); },
                };
            }
        </script>
    @endpush
@endonce
