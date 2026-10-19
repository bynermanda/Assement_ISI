{{--
    "Rekap rata-rata" export form (multi-period averages of APPROVED assessments). Needs $type ('a1' | 'a2');
    A1: $departments, $sections; A2: $units. Excel / PDF via ExportController@averageXlsx / averagePdf.
--}}
@php
    $to   = now()->format('Y-m');
    $from = now()->startOfMonth()->subMonthsNoOverflow(11)->format('Y-m');
@endphp
<form method="GET" action="{{ route('exports.average.xlsx') }}" x-data="{ all: false }"
      class="mb-4 rounded-lg border border-slate-200 bg-white p-5">
    <input type="hidden" name="type" value="{{ $type }}">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
        <p class="text-base font-bold text-slate-900">Rekap rata-rata {{ $type === 'a2' ? 'Assessment 2' : 'Assessment 1' }} (beberapa periode)</p>
        <p class="text-sm text-slate-600">Rata-rata = jumlah nilai akhir ÷ jumlah bulan yang ada nilainya · hanya Disetujui · huruf memakai skala periode terakhir</p>
    </div>
    <p class="mt-1 text-sm text-slate-600">Rentang maksimal: PDF {{ \App\Support\AverageExport::MAX_PDF_MONTHS }} bulan, Excel {{ \App\Support\AverageExport::MAX_XLSX_MONTHS }} bulan (termasuk "Semua periode").</p>
    @php $rangeError = collect(['range', 'from', 'to', 'type', 'unit', 'department', 'section'])->map(fn ($k) => $errors->first($k))->filter()->first(); @endphp
    @if ($rangeError)
        <p class="mt-3 rounded border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-bold text-rose-800" role="alert">{{ $rangeError }}</p>
    @endif
    <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
        <label class="text-sm font-bold text-slate-900">Dari periode
            <input type="month" name="from" value="{{ $from }}" :disabled="all" class="mt-1 block rounded border border-slate-300 bg-white px-3 py-2 text-base disabled:bg-slate-100">
        </label>
        <label class="text-sm font-bold text-slate-900">Sampai periode
            <input type="month" name="to" value="{{ $to }}" class="mt-1 block rounded border border-slate-300 bg-white px-3 py-2 text-base">
        </label>
        <label class="flex min-h-[44px] items-center gap-2 text-base text-slate-900">
            <input type="checkbox" name="all" value="1" x-model="all" class="h-5 w-5"> Semua periode
        </label>
        @if ($type === 'a2')
            <label class="text-sm font-bold text-slate-900">Unit
                <select name="unit" class="mt-1 block rounded border border-slate-300 bg-white px-3 py-2 text-base">
                    <option value="">Semua unit</option>
                    @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
            </label>
        @else
            <label class="text-sm font-bold text-slate-900">Departemen
                <select name="department" class="mt-1 block rounded border border-slate-300 bg-white px-3 py-2 text-base">
                    <option value="">Semua departemen</option>
                    @foreach ($departments as $d)<option value="{{ $d }}" @selected(request('department') === $d)>{{ $d }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm font-bold text-slate-900">Seksi
                <select name="section" class="mt-1 block rounded border border-slate-300 bg-white px-3 py-2 text-base">
                    <option value="">Semua seksi</option>
                    @foreach ($sections as $s)<option value="{{ $s }}" @selected(request('section') === $s)>{{ $s }}</option>@endforeach
                </select>
            </label>
        @endif
        <div class="flex gap-2">
            <button type="submit" formaction="{{ route('exports.average.xlsx') }}" class="rounded bg-teal-600 px-5 py-2 text-base font-bold">Rekap Excel</button>
            <button type="submit" formaction="{{ route('exports.average.pdf') }}" class="rounded border border-slate-300 bg-white px-5 py-2 text-base font-bold">Rekap PDF</button>
        </div>
    </div>
</form>
