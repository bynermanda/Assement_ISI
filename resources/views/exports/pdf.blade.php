@php
    use App\Models\Assessment;
    $h     = Assessment::HEADINGS;
    $fill  = ['A' => '#3f8500', 'B' => '#0284c7', 'C' => '#f2b100', 'D' => '#e52020'];
    $ink   = ['C' => '#3d2b00'];
    // A4 landscape, 8 mm margins = 281 mm. Fixed widths in mm so mPDF never squeezes a column.
    $w     = ['no' => 8, 'nik' => 20, 'name' => 40, 'dept' => 22, 'crit' => 14.5, 'points' => 12, 'final' => 12, 'by' => 33];
    $notes = 281 - ($w['no'] + $w['nik'] + $w['name'] + $w['dept'] + $w['crit'] * count(Assessment::CRITERIA) + $w['points'] + $w['final'] + $w['by']);
@endphp
<html>
<head>
@include('exports.partials.pdf-style')
</head>
<body>
    {{-- summary --}}
    <table class="kpi">
        <tr>
            <td style="width: 32mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">Jumlah karyawan</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $rows->count() }}</span></td>
            @foreach ($counts as $letter => $n)
                <td style="width: 27mm; border-left: 3pt solid {{ $fill[$letter] }};"><span style="font-size: 6.5pt; color: #5e5e5e;">Nilai&nbsp;Akhir&nbsp;{{ $letter }}</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $n }}</span></td>
            @endforeach
            <td style="width: 32mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">Rata-rata nilai</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $rows->isEmpty() ? '-' : number_format($rows->avg('final_score'), 1) }}</span></td>
        </tr>
    </table>

    <table class="grid" repeat_header="1">
        <thead>
            <tr>
                <th rowspan="2" style="width: {{ $w['no'] }}mm;">No</th>
                <th rowspan="2" style="width: {{ $w['nik'] }}mm;">NIK</th>
                <th rowspan="2" style="width: {{ $w['name'] }}mm;">Nama</th>
                <th rowspan="2" style="width: {{ $w['dept'] }}mm;">Departemen /<br>Seksi</th>
                <th colspan="{{ count(Assessment::CRITERIA) }}">{{ $h['criteria'][0] }} {{ $h['criteria'][1] }} &nbsp;·&nbsp; {{ $h['weight'][0] }} {{ $h['weight'][1] }}</th>
                <th rowspan="2" style="width: {{ $w['points'] }}mm;">{{ $h['points'][0] }}<br><span class="sub">{{ $h['points'][1] }}</span></th>
                <th rowspan="2" style="width: {{ $w['final'] }}mm;">總分<br><span class="sub">Nilai Akhir</span></th>
                <th rowspan="2" style="width: {{ $w['by'] }}mm;">Penilai /<br>Disetujui oleh</th>
                <th rowspan="2" style="width: {{ $notes }}mm;">{{ $h['notes'][0] }}<br><span class="sub">{{ $h['notes'][1] }}</span></th>
            </tr>
            <tr>
                @foreach (Assessment::CRITERIA as $meta)
                    <th style="width: {{ $w['crit'] }}mm;">@if ($meta['zh']){{ $meta['zh'] }}<br>@endif{{ $meta['id'] }}<br><span class="sub">{{ $meta['weight'] }}%</span></th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td class="c">{{ $row['no'] }}</td>
                    <td>{{ $row['nik'] }}<br><span class="small">{{ $row['period'] }}</span></td>
                    <td><span class="b">{{ $row['name'] }}</span><br><span class="small">{{ $row['position'] }}</span></td>
                    <td>{{ $row['department'] }}@if ($row['section'] && $row['section'] !== $row['department'])<br><span class="small">{{ $row['section'] }}</span>@endif</td>
                    @foreach ($row['grades'] as $letter)
                        <td class="c b" style="font-size: 8.5pt;">{{ $letter }}</td>
                    @endforeach
                    <td class="r b" style="font-size: 8.5pt;">{{ number_format($row['final_score'], 1) }}</td>
                    <td class="grade" style="background: {{ $fill[$row['final_grade']] ?? '#9a9a9a' }}; color: {{ $ink[$row['final_grade']] ?? '#ffffff' }};">{{ $row['final_grade'] }}</td>
                    <td>{{ $row['penilai1'] }}<br><span class="small">{{ $row['approved_by'] }} · {{ $row['approved_at'] }}</span></td>
                    <td class="small">{!! nl2br(e(preg_replace('/^Overall:/m', 'Catatan umum:', $row['notes']))) !!}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 8 + count(Assessment::CRITERIA) }}" class="c">Belum ada penilaian yang disetujui untuk filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

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
