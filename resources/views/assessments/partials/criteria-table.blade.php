{{--
    Criteria table of one assessment (manual form and approval page).
    Needs: $descriptions (guideline row or null), $lockedColumns, $notes (key => text), and an Alpine
    parent with scores / contribution() / total (assessmentForm()).
--}}
@use('App\Models\Assessment')
<div class="overflow-x-auto">
    <table class="table-lines min-w-full text-sm">
        <thead class="bg-slate-50 text-left align-bottom">
            <tr>
                <th class="w-10 px-3 py-3 text-center font-medium text-slate-600">No</th>
                <th colspan="2" class="min-w-[14rem] px-4 py-3 text-center">@include('assessments.partials.heading', ['key' => 'criteria'])</th>
                <th class="px-4 py-3 text-center">@include('assessments.partials.heading', ['key' => 'weight'])</th>
                <th class="min-w-[24rem] px-4 py-3">@include('assessments.partials.heading', ['key' => 'target'])</th>
                <th class="px-4 py-3 font-semibold text-slate-700">{{ tb('Nilai') }}</th>
                <th class="px-4 py-3 text-right">@include('assessments.partials.heading', ['key' => 'points'])</th>
                <th class="min-w-[16rem] px-4 py-3">@include('assessments.partials.heading', ['key' => 'notes'])</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @php $layout = Assessment::criteriaLayout(); @endphp
            @foreach (Assessment::CRITERIA as $column => $meta)
                @php
                    $guideline = $descriptions?->{$meta['description']};
                    [$group, $firstOfGroup, $groupSize] = $layout[$column];
                @endphp
                <tr class="align-top">
                    <td class="px-3 py-4 text-center text-slate-500">{{ $loop->iteration }}</td>
                    @if ($group === null)
                        <td colspan="2" class="px-4 py-4">
                            @include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id']])
                        </td>
                    @else
                        @if ($firstOfGroup)
                            <td rowspan="{{ $groupSize }}" class="border-r border-slate-200 bg-slate-50/60 px-4 py-4 align-middle">
                                @include('assessments.partials.item-label', ['zh' => Assessment::CRITERIA_GROUPS[$group]['zh'], 'id' => Assessment::CRITERIA_GROUPS[$group]['id'], 'center' => true])
                            </td>
                        @endif
                        <td class="px-4 py-4">
                            @include('assessments.partials.item-label', ['zh' => $meta['zh'], 'id' => $meta['id']])
                        </td>
                    @endif
                    <td class="px-4 py-4 text-center text-slate-600">{{ $meta['weight'] }}%</td>
                    <td class="px-4 py-4 text-sm leading-relaxed text-slate-600">
                        @if ($guideline)
                            {!! Assessment::formatGuideline($guideline) !!}
                        @else
                            <span class="text-slate-400">{{ t('Target untuk departemen ini belum diisi. Minta HRD menambahkannya di Panduan.') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-4">
                        @if (in_array($column, $lockedColumns, true))
                            {{-- Provided by HRD: shown, not editable --}}
                            <p class="font-semibold text-slate-900" x-text="({'100':'A','80':'B','60':'C','0':'D'})[String(scores['{{ $column }}'])] ?? '-'"></p>
                            <p class="mt-0.5 flex items-center gap-1 text-xs text-teal-700">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 10-8 0v4m-1 0h10a1 1 0 011 1v7a1 1 0 01-1 1H7a1 1 0 01-1-1v-7a1 1 0 011-1z"/></svg>
                                {{ t('Dari HRD') }}
                            </p>
                        @else
                            <select name="{{ $column }}" x-model="scores['{{ $column }}']" required aria-label="{{ t('Nilai') }}: {{ Assessment::itemText($column) }}"
                                    @class([
                                        'block w-28 rounded border bg-white px-2 py-2 text-sm focus:outline-none focus:ring-2',
                                        'border-slate-300 focus:border-teal-600 focus:ring-teal-600/30' => ! $errors->has($column),
                                        'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has($column),
                                    ])>
                                <option value="" disabled>{{ t('Pilih') }}</option>
                                @foreach (Assessment::GRADES as $letter => $points)
                                    <option value="{{ $points }}">{{ $letter }} ({{ $points }})</option>
                                @endforeach
                            </select>
                            @error($column)
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        @endif
                    </td>
                    <td class="px-4 py-4 text-right font-semibold tabular-nums" x-text="contribution('{{ $column }}').toFixed(1)">0.0</td>
                    <td class="px-4 py-3">
                        <textarea name="notes[{{ $column }}]" rows="2" maxlength="{{ Assessment::NOTE_MAX }}" aria-label="{{ t('Catatan') }}: {{ Assessment::itemText($column) }}"
                                  placeholder="{{ t('Opsional') }}"
                                  class="block w-full resize-y rounded border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">{{ $notes[$column] ?? '' }}</textarea>
                        @error("notes.{$column}")
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </td>
                </tr>
            @endforeach
            <tr class="bg-slate-50/60 align-top">
                <td></td>
                <td colspan="6" class="px-4 py-3 text-sm">
                    <p class="font-medium text-slate-900">{{ tb('Catatan umum') }}</p>
                    <p class="text-xs text-slate-500">{{ tb('Tentang karyawan secara keseluruhan (sama dengan catatan di Assessment Massal).') }}</p>
                </td>
                <td class="px-4 py-3">
                    <textarea name="notes[{{ Assessment::NOTE_GENERAL }}]" rows="2" maxlength="{{ Assessment::NOTE_MAX }}" aria-label="{{ t('Catatan umum') }}"
                              placeholder="{{ t('Opsional') }}"
                              class="block w-full resize-y rounded border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">{{ $notes[Assessment::NOTE_GENERAL] ?? '' }}</textarea>
                    @error('notes.' . Assessment::NOTE_GENERAL)
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </td>
            </tr>
        </tbody>
        <tfoot class="bg-slate-900 text-white">
            <tr>
                <td colspan="6" class="px-4 py-4 text-right text-sm font-medium">總分 Nilai Akhir</td>
                <td class="px-4 py-4 text-right text-xl font-semibold tabular-nums" x-text="total.toFixed(1)">0.0</td>
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
