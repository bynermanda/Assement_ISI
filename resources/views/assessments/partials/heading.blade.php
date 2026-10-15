{{-- Two-line column heading: Chinese above, Indonesian below. Usage: @include('assessments.partials.heading', ['key' => 'criteria']) --}}
@php [$zh, $id] = \App\Models\Assessment::HEADINGS[$key]; @endphp
<span class="block whitespace-nowrap font-semibold text-slate-700">{{ $zh }}</span>
<span class="block text-xs font-normal text-slate-500">{{ $id }}</span>
