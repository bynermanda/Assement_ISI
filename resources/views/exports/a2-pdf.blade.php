@php
    use App\Models\Assessment;
    use App\Support\A2Export;
    $h    = Assessment::HEADINGS;
    $fill = ['A' => '#059669', 'B' => '#0284c7', 'C' => '#fbbf24', 'D' => '#e11d48'];
    $pct  = fn ($w) => rtrim(rtrim(number_format($w, 2), '0'), '.') . '%';
    $total = $groups->sum(fn ($g) => $g['rows']->count());
@endphp
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 7.5pt; color: #0f172a; }
    h1 { font-size: 13pt; margin: 0 0 2pt 0; }
    h2 { font-size: 10.5pt; margin: 10pt 0 3pt 0; }
    .muted { color: #475569; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 0.5pt solid #94a3b8; padding: 2.5pt 3pt; vertical-align: top; }
    thead th { background: #e2e8f0; text-align: center; vertical-align: middle; font-weight: bold; }
    .c { text-align: center; } .r { text-align: right; }
    .grade { font-weight: bold; color: #ffffff; text-align: center; }
    .small { font-size: 6.5pt; color: #475569; }
    .plain td { border: none; padding: 1pt 8pt 1pt 0; }
</style>
</head>
<body>
    <h1>Hasil Assessment 2 (Approved)</h1>
    <p class="muted">PT Indosafety Sentosa · {{ $export->description() }} · {{ $total }} orang · exported {{ now()->format('d M Y H:i') }}</p>

    <table class="plain" style="width: auto; margin: 4pt 0 8pt 0;">
        <tr>
            <td><b>Nilai Akhir:</b></td>
            @foreach ($counts as $letter => $n)
                <td><span style="background: {{ $fill[$letter] }}; color: {{ $letter === 'C' ? '#451a03' : '#ffffff' }}; font-weight: bold;">&nbsp;{{ $letter }}&nbsp;</span> {{ $n }}</td>
            @endforeach
        </tr>
    </table>

    <table repeat_header="1">
        <thead>
            <tr>
                <th style="width: 4%;">No</th><th style="width: 8%;">NIK</th><th>Nama</th><th>Unit</th><th>Penilai</th><th>Approved by</th>
                <th style="width: 7%;">{{ $h['points'][0] }}<br>{{ $h['points'][1] }}</th><th style="width: 7%;">Nilai Akhir</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @forelse ($groups as $g)
                @foreach ($g['rows'] as $row)
                    <tr>
                        <td class="c">{{ $no++ }}</td>
                        <td>{{ $row['nik'] }}<br><span class="small">{{ $row['period'] }}</span></td>
                        <td>{{ $row['name'] }}<br><span class="small">{{ $row['position'] }}</span></td>
                        <td>{{ $g['unit']->name }}</td>
                        <td>{{ $row['penilai'] }}</td>
                        <td>{{ $row['approved_by'] }}<br><span class="small">{{ $row['approved_at'] }}</span></td>
                        <td class="r"><b>{{ number_format($row['final_score'], 1) }}</b></td>
                        <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#94a3b8' }};{{ $row['final_grade'] === 'C' ? ' color: #451a03;' : '' }}">{{ $row['final_grade'] }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="8" class="c">No approved Assessment 2 for these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    @foreach ($groups as $g)
        @php $layout = A2Export::layout($g['criteria']); @endphp
        <pagebreak />
        <h2>{{ $g['unit']->name }}</h2>
        <p class="muted">{{ $g['rows']->count() }} orang · {{ $g['criteria']->count() }} kriteria · total bobot {{ $pct($g['criteria']->sum('weight')) }}</p>
        <table repeat_header="1">
            <thead>
                <tr>
                    <th rowspan="3" style="width: 3%;">No</th>
                    <th rowspan="3" style="width: 13%;">Nama</th>
                    <th colspan="{{ $g['criteria']->count() }}">{{ $h['criteria'][0] }} {{ $h['criteria'][1] }} · {{ $h['weight'][0] }} {{ $h['weight'][1] }}</th>
                    <th rowspan="3" style="width: 5%;">{{ $h['points'][0] }}<br>{{ $h['points'][1] }}</th>
                    <th rowspan="3" style="width: 5%;">Nilai Akhir</th>
                    <th rowspan="3" style="width: 15%;">{{ $h['notes'][0] }}<br>{{ $h['notes'][1] }}</th>
                </tr>
                <tr>
                    @foreach ($g['criteria'] as $c)
                        @php [$group, $first, $size] = $layout[$c->id]; @endphp
                        @if ($group === null)
                            <th rowspan="2">{{ $c->name_zh }}<br>{{ $c->name_id }}<br><span class="small">{{ $pct($c->weight) }}</span></th>
                        @elseif ($first)
                            <th colspan="{{ $size }}">{{ $c->group_zh }}<br>{{ $c->group_id }}</th>
                        @endif
                    @endforeach
                </tr>
                <tr>
                    @foreach ($g['criteria'] as $c)
                        @if ($layout[$c->id][0] !== null)
                            <th>{{ $c->name_zh }}<br>{{ $c->name_id }}<br><span class="small">{{ $pct($c->weight) }}</span></th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($g['rows'] as $row)
                    <tr>
                        <td class="c">{{ $row['no'] }}</td>
                        <td>{{ $row['name'] }}<br><span class="small">{{ $row['nik'] }} · {{ $row['period'] }}</span></td>
                        @foreach ($row['grades'] as $letter)
                            <td class="c"><b>{{ $letter }}</b></td>
                        @endforeach
                        <td class="r"><b>{{ number_format($row['final_score'], 1) }}</b></td>
                        <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#94a3b8' }};{{ $row['final_grade'] === 'C' ? ' color: #451a03;' : '' }}">{{ $row['final_grade'] }}</td>
                        <td class="small">{!! nl2br(e($row['notes'])) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <table class="plain" style="width: auto; margin-top: 10pt;">
        <tr><td colspan="2"><b>{{ $h['scale'][0] }} {{ $h['scale'][1] }}</b></td></tr>
        @foreach (array_reverse(Assessment::finalGradeRanges(), true) as $letter => [$min, $max])
            <tr><td>{{ $letter }}級 ({{ $min }}~{{ $max }})</td><td>Nilai {{ $letter }} ({{ $min }}~{{ $max }})</td></tr>
        @endforeach
    </table>
</body>
</html>
