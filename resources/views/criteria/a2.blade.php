@extends('layouts.app')

@section('title', 'Panduan · Assessment 2')
@section('heading', 'Panduan Penilaian')
@section('subheading', 'Assessment 2: kriteria, bobot, penilai dan approver per unit.')

@section('content')
@include('criteria.partials.tabs', ['active' => 'a2'])

<div class="mb-4 rounded border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-900">
    <b>1 unit = 1 form penilaian.</b> Kriteria berbeda dalam satu departemen? Buat unit terpisah, mis. <i>PRESS · Kepala Seksi 1</i> dan <i>PRESS · Kepala Seksi 2</i>.
</div>

@include('criteria.partials.a2-new-unit')

@if ($units->isEmpty())
    <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
        Belum ada unit Assessment 2. Buat unit pertama dengan tombol di atas.
    </div>
@else
<div class="grid grid-cols-1 gap-5 lg:grid-cols-[16rem_1fr]">
    {{-- units --}}
    <nav class="overflow-hidden rounded-lg border border-slate-200 bg-white lg:self-start" aria-label="Unit">
        @foreach ($units as $u)
            @php $sum = $u->totalWeight(); @endphp
            <a href="{{ route('criteria.a2', ['unit' => $u->id]) }}" @if ($u->is($unit)) aria-current="page" @endif
               @class(['flex items-center justify-between gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0',
                       'bg-teal-50 font-semibold text-teal-900' => $u->is($unit), 'text-slate-700 hover:bg-slate-50' => ! $u->is($unit)])>
                <span>{{ $u->name }}@if ($u->department && $u->department !== $u->name)<span class="block text-xs font-normal text-slate-500">{{ $u->department }}</span>@endif</span>
                <span class="flex items-center gap-2 text-xs font-normal">
                    <span @class(['text-emerald-700' => abs($sum - 100) < 0.01, 'font-semibold text-rose-700' => abs($sum - 100) >= 0.01])>{{ rtrim(rtrim(number_format($sum, 2), '0'), '.') }}%</span>
                    <span class="text-slate-400">{{ $u->participants_count }}p</span>
                </span>
            </a>
        @endforeach
    </nav>

    <div class="space-y-6">
        @include('criteria.partials.a2-unit-card')

        {{-- criteria editor --}}
        @php
            $initial = old('rows', $unit->criteria->map(fn ($c) => [
                'id' => $c->id, 'group_zh' => $c->group_zh, 'group_id' => $c->group_id, 'name_zh' => $c->name_zh, 'name_id' => $c->name_id,
                'weight' => rtrim(rtrim(number_format($c->weight, 2, '.', ''), '0'), '.'), 'target' => $c->target, 'remark' => $c->remark, 'delete' => false,
            ])->values()->all());
        @endphp
        <form method="POST" action="{{ route('criteria.a2.criteria', $unit) }}" x-data="a2CriteriaEditor(@js(array_values($initial)))"
              class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-semibold text-slate-900">Kriteria · {{ $unit->name }}</h2>
                    <p class="text-xs text-slate-500">Grup yang sama pada baris berurutan digabung di form.</p>
                </div>
                <p class="text-sm">Total bobot:
                    <span class="font-semibold tabular-nums" :class="Math.abs(total - 100) < 0.01 ? 'text-emerald-700' : 'text-rose-700'" x-text="total.toFixed(2).replace(/\.?0+$/, '') + '%'"></span>
                </p>
            </div>
            <div class="divide-y divide-slate-100">
                <template x-for="(row, i) in rows" :key="row.key">
                    <div class="grid grid-cols-1 gap-2 px-4 py-3 md:grid-cols-12" :class="row.delete ? 'bg-rose-50 opacity-60' : ''">
                        <input type="hidden" :name="'rows[' + i + '][id]'" :value="row.id ?? ''">
                        <input type="hidden" :name="'rows[' + i + '][delete]'" :value="row.delete ? 1 : 0">
                        <div class="flex items-start gap-1 md:col-span-1">
                            <span class="mt-2 w-5 text-right text-xs text-slate-400" x-text="i + 1"></span>
                            <div class="flex flex-col">
                                <button type="button" @click="move(i, -1)" :disabled="i === 0" class="px-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="Naik">▲</button>
                                <button type="button" @click="move(i, 1)" :disabled="i === rows.length - 1" class="px-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="Turun">▼</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 md:col-span-4">
                            <input type="text" :name="'rows[' + i + '][group_zh]'" x-model="row.group_zh" placeholder="Grup (中文)" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="text" :name="'rows[' + i + '][group_id]'" x-model="row.group_id" placeholder="Grup (Indonesia)" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="text" :name="'rows[' + i + '][name_zh]'" x-model="row.name_zh" placeholder="Kriteria (中文)" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="text" :name="'rows[' + i + '][name_id]'" x-model="row.name_id" placeholder="Kriteria (Indonesia)" class="rounded border border-slate-300 px-2 py-1.5 text-sm font-semibold">
                        </div>
                        <div class="md:col-span-1">
                            <label class="text-xs text-slate-500">Bobot %
                                <input type="number" step="0.01" min="0" max="100" :name="'rows[' + i + '][weight]'" x-model="row.weight" class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1.5 text-sm tabular-nums">
                            </label>
                        </div>
                        <div class="md:col-span-5">
                            <textarea :name="'rows[' + i + '][target]'" x-model="row.target" rows="3" placeholder="目標 Target" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                            <textarea :name="'rows[' + i + '][remark]'" x-model="row.remark" rows="1" placeholder="Keterangan (opsional)" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea>
                        </div>
                        <div class="md:col-span-1 md:text-right">
                            <label class="inline-flex items-center gap-1 text-xs text-rose-700">
                                <input type="checkbox" x-model="row.delete" class="rounded border-slate-300 text-rose-600"> Hapus
                            </label>
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" @click="add()" class="self-start rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">+ Tambah kriteria</button>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-rose-700" x-show="Math.abs(total - 100) >= 0.01">Total bobot harus 100% sebelum disimpan.</span>
                    <button type="submit" :disabled="Math.abs(total - 100) >= 0.01"
                            onclick="return confirm('Simpan kriteria? Penilaian yang sudah dikirim tetap memakai kriteria lama.')"
                            class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">Simpan kriteria</button>
                </div>
            </div>
        </form>

        {{-- participants --}}
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="font-semibold text-slate-900">Peserta & atasan · {{ $unit->name }}</h2>
                <p class="text-xs text-slate-500">Penilai dan Approver harus berbeda; menyetujui diri sendiri tidak diizinkan.</p>
            </div>
            @if ($participants->isNotEmpty())
                <form method="POST" action="{{ route('criteria.a2.participants', $unit) }}">
                    @csrf
                    @method('PUT')
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-slate-600">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Peserta</th>
                                    <th class="px-3 py-2 font-medium">Penilai</th>
                                    <th class="px-3 py-2 font-medium">Approver</th>
                                    <th class="px-3 py-2 font-medium">Unit</th>
                                    <th class="px-3 py-2 text-center font-medium">Aktif</th>
                                    <th class="px-3 py-2 text-right font-medium">Hapus</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($participants as $p)
                                    <tr @class(['bg-slate-50 text-slate-400' => ! $p->active])>
                                        <td class="px-3 py-2">
                                            <span class="font-medium text-slate-900">{{ $p->employee?->name ?? $p->employee_id }}</span>
                                            <span class="block text-xs text-slate-500">{{ $p->employee_id }} · {{ $p->employee?->position }}</span>
                                        </td>
                                        @foreach (['assessor_id' => $p->assessor_id, 'approver_id' => $p->approver_id] as $field => $current)
                                            <td class="px-3 py-2">
                                                <select name="participants[{{ $p->id }}][{{ $field }}]" class="w-full min-w-[14rem] rounded border border-slate-300 bg-white px-2 py-1.5 text-sm">
                                                    @foreach ($accounts as $a)
                                                        <option value="{{ $a->id }}" @selected($a->id === $current)>{{ $a->username }} · {{ Str::limit($a->name, 40) }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2">
                                            <select name="participants[{{ $p->id }}][unit_id]" class="rounded border border-slate-300 bg-white px-2 py-1.5 text-sm">
                                                @foreach ($units as $u)<option value="{{ $u->id }}" @selected($u->id === $p->unit_id)>{{ $u->name }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <input type="hidden" name="participants[{{ $p->id }}][active]" value="0">
                                            <input type="checkbox" name="participants[{{ $p->id }}][active]" value="1" @checked($p->active) class="rounded border-slate-300 text-teal-600" aria-label="Aktif">
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex flex-col items-end gap-1">
                                                <button type="submit" form="del-p{{ $p->id }}" class="whitespace-nowrap rounded border border-rose-300 bg-white px-2 py-1 text-xs font-semibold"
                                                        onclick="return confirm(@js('Hapus ' . ($p->employee?->name ?? $p->employee_id) . ' dari peserta? Riwayat penilaian tetap tersimpan.'))">Hapus</button>
                                                <button type="submit" form="del-p{{ $p->id }}-a1" class="whitespace-nowrap rounded border border-slate-300 bg-white px-2 py-1 text-xs font-semibold"
                                                        onclick="return confirm(@js('Hapus ' . ($p->employee?->name ?? $p->employee_id) . ' dari Assessment 2 dan kembalikan ke Assessment 1?'))">Hapus &amp; ke A1</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <button type="submit" class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700">Simpan peserta</button>
                    </div>
                </form>
                @foreach ($participants as $p)
                    <form id="del-p{{ $p->id }}" method="POST" action="{{ route('criteria.a2.participants.destroy', $p) }}" class="hidden">@csrf @method('DELETE')</form>
                    <form id="del-p{{ $p->id }}-a1" method="POST" action="{{ route('criteria.a2.participants.destroy', $p) }}" class="hidden">@csrf @method('DELETE')<input type="hidden" name="to_a1" value="1"></form>
                @endforeach
            @else
                <p class="px-4 py-6 text-sm text-slate-500">Belum ada peserta di unit ini.</p>
            @endif

            {{-- add --}}
            <form method="POST" action="{{ route('criteria.a2.participants.add', $unit) }}" class="grid grid-cols-1 gap-2 border-t border-slate-200 px-4 py-3 md:grid-cols-4 md:items-end">
                @csrf
                <label class="text-xs text-slate-600">Tambah peserta ke {{ $unit->name }}
                    <select name="employee_id" required class="mt-0.5 w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm">
                        <option value="">Pilih</option>
                        @foreach (['a2' => 'Assessment 2 (belum ditugaskan)', 'a1' => 'Assessment 1 (akan dipindah ke A2)'] as $level => $label)
                            @if ($candidates->has($level))
                                <optgroup label="{{ $label }}">
                                    @foreach ($candidates[$level] as $e)<option value="{{ $e->id_employee }}" @selected(old('employee_id') === $e->id_employee)>{{ $e->name }} · {{ $e->department }} · {{ $e->position }}</option>@endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </label>
                @foreach (['assessor_id' => 'Penilai', 'approver_id' => 'Approver'] as $field => $label)
                    <label class="text-xs text-slate-600">{{ $label }}
                        <select name="{{ $field }}" required class="mt-0.5 w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm">
                            <option value="">Pilih</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old($field) === $a->id)>{{ $a->username }} · {{ Str::limit($a->name, 40) }}</option>@endforeach
                        </select>
                    </label>
                @endforeach
                <button type="submit" class="rounded border border-teal-600 bg-white px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Tambah</button>
                <p class="text-xs text-slate-500 md:col-span-4">Orang Assessment 1 otomatis dipindah ke A2. Pindah unit: ubah kolom Unit di tabel.</p>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    function a2CriteriaEditor(initial) {
        let next = 0;
        const withKey = row => Object.assign({ key: next++, id: null, group_zh: '', group_id: '', name_zh: '', name_id: '', weight: '', target: '', remark: '', delete: false }, row,
            { delete: row.delete === true || row.delete === '1' || row.delete === 1 });
        return {
            rows: initial.map(withKey),
            get total() { return this.rows.reduce((s, r) => s + (r.delete ? 0 : (parseFloat(r.weight) || 0)), 0); },
            add() { this.rows.push(withKey({})); },
            move(i, step) {
                const j = i + step;
                if (j < 0 || j >= this.rows.length) return;
                [this.rows[i], this.rows[j]] = [this.rows[j], this.rows[i]];
            },
        };
    }
</script>
@endpush
