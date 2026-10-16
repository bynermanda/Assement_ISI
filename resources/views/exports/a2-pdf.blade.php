@php
    use App\Models\Assessment;
    use App\Support\A2Export;
    $h     = Assessment::HEADINGS;
    $fill  = ['A' => '#3f8500', 'B' => '#0284c7', 'C' => '#f2b100', 'D' => '#e52020'];
    $ink   = ['C' => '#3d2b00'];
    $pct   = fn ($w) => rtrim(rtrim(number_format($w, 2), '0'), '.') . '%';
    $total = $groups->sum(fn ($g) => $g['rows']->count());
    $all   = $groups->flatMap(fn ($g) => $g['rows']);
@endphp
<html>
<head>
@include('exports.partials.pdf-style')
</head>
<body>
    {{-- summary --}}
    <table class="kpi">
        <tr>
            <td style="width: 32mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">Jumlah pimpinan</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $total }}</span></td>
            @foreach ($counts as $letter => $n)
                <td style="width: 27mm; border-left: 3pt solid {{ $fill[$letter] }};"><span style="font-size: 6.5pt; color: #5e5e5e;">Nilai&nbsp;Akhir&nbsp;{{ $letter }}</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $n }}</span></td>
            @endforeach
            <td style="width: 32mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">Rata-rata nilai</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $all->isEmpty() ? '-' : number_format($all->avg('final_score'), 1) }}</span></td>
        </tr>
    </table>

    <table class="grid" repeat_header="1">
        <thead>
            <tr>
                <th style="width: 9mm;">No</th>
                <th style="width: 24mm;">NIK</th>
                <th style="width: 60mm;">Nama</th>
                <th style="width: 40mm;">Unit</th>
                <th style="width: 50mm;">Penilai</th>
                <th>Disetujui oleh</th>
                <th style="width: 16mm;">{{ $h['points'][0] }}<br><span class="sub">{{ $h['points'][1] }}</span></th>
                <th style="width: 16mm;">總分<br><span class="sub">Nilai Akhir</span></th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @forelse ($groups as $g)
                @foreach ($g['rows'] as $row)
                    <tr class="{{ $no % 2 ? '' : 'even' }}">
                        <td class="c">{{ $no++ }}</td>
                        <td>{{ $row['nik'] }}<br><span class="small">{{ $row['period'] }}</span></td>
                        <td><span class="b">{{ $row['name'] }}</span><br><span class="small">{{ $row['position'] }}</span></td>
                        <td>{{ $g['unit']->name }}</td>
                        <td>{{ $row['penilai'] }}</td>
                        <td>{{ $row['approved_by'] }}<br><span class="small">{{ $row['approved_at'] }}</span></td>
                        <td class="r b" style="font-size: 8.5pt;">{{ number_format($row['final_score'], 1) }}</td>
                        <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#9a9a9a' }}; color: {{ $ink[$row['final_grade']] ?? '#ffffff' }};">{{ $row['final_grade'] }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="8" class="c">Belum ada Assessment 2 yang disetujui untuk filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- one page per unit, with its own criteria --}}
    @foreach ($groups as $g)
        @php $layout = A2Export::layout($g['criteria']); @endphp
        <pagebreak />
        <h2>{{ $g['unit']->name }}</h2>
        <p class="muted" style="margin: 0 0 3mm 0;">{{ $g['rows']->count() }} orang · {{ $g['criteria']->count() }} kriteria · total bobot {{ $pct($g['criteria']->sum('weight')) }}</p>
        <table class="grid" repeat_header="1">
            <thead>
                <tr>
                    <th rowspan="3" style="width: 8mm;">No</th>
                    <th rowspan="3" style="width: 40mm;">Nama</th>
                    <th colspan="{{ $g['criteria']->count() }}">{{ $h['criteria'][0] }} {{ $h['criteria'][1] }} &nbsp;·&nbsp; {{ $h['weight'][0] }} {{ $h['weight'][1] }}</th>
                    <th rowspan="3" style="width: 13mm;">{{ $h['points'][0] }}<br><span class="sub">{{ $h['points'][1] }}</span></th>
                    <th rowspan="3" style="width: 13mm;">總分<br><span class="sub">Nilai Akhir</span></th>
                    <th rowspan="3" style="width: 45mm;">{{ $h['notes'][0] }}<br><span class="sub">{{ $h['notes'][1] }}</span></th>
                </tr>
                <tr>
                    @foreach ($g['criteria'] as $c)
                        @php [$group, $first, $size] = $layout[$c->id]; @endphp
                        @if ($group === null)
                            <th rowspan="2">{{ $c->name_zh }}<br>{{ $c->name_id }}<br><span class="sub">{{ $pct($c->weight) }}</span></th>
                        @elseif ($first)
                            <th colspan="{{ $size }}">{{ $c->group_zh }}<br>{{ $c->group_id }}</th>
                        @endif
                    @endforeach
                </tr>
                <tr>
                    @foreach ($g['criteria'] as $c)
                        @if ($layout[$c->id][0] !== null)
                            <th>{{ $c->name_zh }}<br>{{ $c->name_id }}<br><span class="sub">{{ $pct($c->weight) }}</span></th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($g['rows'] as $i => $row)
                    <tr class="{{ $i % 2 ? 'even' : '' }}">
                        <td class="c">{{ $row['no'] }}</td>
                        <td><span class="b">{{ $row['name'] }}</span><br><span class="small">{{ $row['nik'] }} · {{ $row['period'] }}</span></td>
                        @foreach ($row['grades'] as $letter)
                            <td class="c b" style="font-size: 8.5pt;">{{ $letter }}</td>
                        @endforeach
                        <td class="r b" style="font-size: 8.5pt;">{{ number_format($row['final_score'], 1) }}</td>
                        <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#9a9a9a' }}; color: {{ $ink[$row['final_grade']] ?? '#ffffff' }};">{{ $row['final_grade'] }}</td>
                        <td class="small">{!! nl2br(e(preg_replace('/^Overall:/m', 'Catatan umum:', $row['notes']))) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    {{-- the scale of the exported period (each letter above used its own period's scale); "all periods": every version --}}
    @foreach (Assessment::scaleLegends($export->filters['period']) as $legend)
    <table class="scale">
        <tr><th colspan="3">{{ $h['scale'][0] }} {{ $h['scale'][1] }}@if ($legend['label']) · {{ $legend['label'] }}@endif</th></tr>
        @foreach (array_reverse($legend['ranges'], true) as $letter => [$min, $max])
            <tr>
                <td style="width: 8mm; background: {{ $fill[$letter] }}; color: {{ $ink[$letter] ?? '#ffffff' }}; text-align: center; font-weight: bold;">{{ $letter }}</td>
                <td style="width: 28mm;">{{ $letter }}級 ({{ $min }}~{{ $max }})</td>
                <td style="width: 32mm;">Nilai {{ $letter }} ({{ $min }}~{{ $max }})</td>
            </tr>
        @endforeach
    </table>
    @endforeach
</body>
</html>
