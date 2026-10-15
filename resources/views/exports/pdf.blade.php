@php
    use App\Models\Assessment;
    $layout = Assessment::criteriaLayout();
    $h      = Assessment::HEADINGS;
    $fill   = ['A' => '#059669', 'B' => '#0284c7', 'C' => '#fbbf24', 'D' => '#e11d48'];
@endphp
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 7.5pt; color: #0f172a; }
    h1 { font-size: 13pt; margin: 0 0 2pt 0; }
    .muted { color: #475569; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 0.5pt solid #94a3b8; padding: 2.5pt 3pt; vertical-align: top; }
    thead th { background: #e2e8f0; text-align: center; vertical-align: middle; font-weight: bold; }
    .c { text-align: center; }
    .r { text-align: right; }
    .grade { font-weight: bold; color: #ffffff; text-align: center; }
    .small { font-size: 6.5pt; color: #475569; }
    .summary td { border: none; padding: 1pt 8pt 1pt 0; }
    .scale td { padding: 1.5pt 5pt; }
</style>
</head>
<body>
    <h1>Hasil Penilaian Karyawan (Approved)</h1>
    <p class="muted">PT Indosafety Sentosa · {{ $export->description() }} · {{ $rows->count() }} karyawan · exported {{ now()->format('d M Y H:i') }}</p>

    <table class="summary" style="width: auto; margin: 4pt 0 8pt 0;">
        <tr>
            <td><b>Nilai Akhir:</b></td>
            @foreach ($counts as $letter => $n)
                <td><span style="background: {{ $fill[$letter] }}; color: {{ $letter === 'C' ? '#451a03' : '#ffffff' }}; font-weight: bold; padding: 1pt 4pt;">&nbsp;{{ $letter }}&nbsp;</span> {{ $n }}</td>
            @endforeach
            <td><b>Rata-rata:</b> {{ $rows->isEmpty() ? '-' : number_format($rows->avg('final_score'), 1) }}</td>
        </tr>
    </table>

    <table repeat_header="1">
        <thead>
            <tr>
                <th rowspan="3" style="width: 3%;">No</th>
                <th rowspan="3" style="width: 7%;">NIK</th>
                <th rowspan="3" style="width: 14%;">Nama</th>
                <th rowspan="3" style="width: 11%;">Department / Section</th>
                <th colspan="{{ count(Assessment::CRITERIA) }}">{{ $h['criteria'][0] }} {{ $h['criteria'][1] }} · {{ $h['weight'][0] }} {{ $h['weight'][1] }}</th>
                <th rowspan="3" style="width: 5%;">{{ $h['points'][0] }}<br>{{ $h['points'][1] }}</th>
                <th rowspan="3" style="width: 5%;">Nilai Akhir</th>
                <th rowspan="3" style="width: 10%;">Penilai 1 /<br>Approved by</th>
                <th rowspan="3">{{ $h['notes'][0] }}<br>{{ $h['notes'][1] }}</th>
            </tr>
            <tr>
                @foreach (Assessment::CRITERIA as $column => $meta)
                    @php [$group, $firstOfGroup, $size] = $layout[$column]; @endphp
                    @if ($group === null)
                        <th rowspan="2" style="width: 6%;">{{ $meta['zh'] }}<br>{{ $meta['id'] }}<br><span class="small">{{ $meta['weight'] }}%</span></th>
                    @elseif ($firstOfGroup)
                        <th colspan="{{ $size }}">{{ Assessment::CRITERIA_GROUPS[$group]['zh'] }}<br>{{ Assessment::CRITERIA_GROUPS[$group]['id'] }}</th>
                    @endif
                @endforeach
            </tr>
            <tr>
                @foreach (Assessment::CRITERIA as $column => $meta)
                    @if ($layout[$column][0] !== null)
                        <th style="width: 6%;">{{ $meta['zh'] }}<br>{{ $meta['id'] }}<br><span class="small">{{ $meta['weight'] }}%</span></th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="c">{{ $row['no'] }}</td>
                    <td>{{ $row['nik'] }}<br><span class="small">{{ $row['period'] }}</span></td>
                    <td>{{ $row['name'] }}<br><span class="small">{{ $row['position'] }}</span></td>
                    <td>{{ $row['department'] }}<br><span class="small">{{ $row['section'] }}</span></td>
                    @foreach ($row['grades'] as $letter)
                        <td class="c"><b>{{ $letter }}</b></td>
                    @endforeach
                    <td class="r"><b>{{ number_format($row['final_score'], 1) }}</b></td>
                    <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#94a3b8' }};{{ $row['final_grade'] === 'C' ? ' color: #451a03;' : '' }}">{{ $row['final_grade'] }}</td>
                    <td>{{ $row['penilai1'] }}<br><span class="small">{{ $row['approved_by'] }} · {{ $row['approved_at'] }}</span></td>
                    <td class="small">{!! nl2br(e($row['notes'])) !!}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 8 + count(Assessment::CRITERIA) }}" class="c">No approved assessments for these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="scale" style="width: auto; margin-top: 8pt;">
        <tr><th colspan="2">{{ $h['scale'][0] }} {{ $h['scale'][1] }}</th></tr>
        @foreach (array_reverse(Assessment::finalGradeRanges(), true) as $letter => [$min, $max])
            <tr><td>{{ $letter }}級 ({{ $min }}~{{ $max }})</td><td>Nilai {{ $letter }} ({{ $min }}~{{ $max }})</td></tr>
        @endforeach
    </table>
</body>
</html>
