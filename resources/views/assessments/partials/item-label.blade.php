{{-- Evaluation item: Chinese above, Indonesian below in bold. Needs $zh, $id; optional $center. --}}
<span @class(['block leading-snug', 'text-center' => $center ?? false])>
    @if ($zh !== '')<span class="block text-sm text-slate-700">{{ $zh }}</span>@endif
    <span class="block font-bold text-slate-900">{{ $id }}</span>
</span>
