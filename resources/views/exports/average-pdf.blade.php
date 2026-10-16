@php
    use App\Models\Assessment;
    use App\Support\ApprovedExport;
    use App\Support\AverageExport;
    $h      = Assessment::HEADINGS;
    $a2     = $export->filters['type'] === 'a2';
    $fill   = ['A' => '#3f8500', 'B' => '#0284c7', 'C' => '#f2b100', 'D' => '#e52020'];
    $ink    = ['C' => '#3d2b00'];
    $months = count($export->periods);
    // A4 landscape = 281 mm: fixed columns, the months share the rest
    $w      = ['no' => 8, 'nik' => 18, 'name' => 38, 'unit' => 28, 'count' => 12, 'avg' => 14, 'grade' => 12];
    $mw     = max(6, round((281 - array_sum($w)) / max(1, $months), 1));
    $mfont  = $months > 18 ? '6pt' : ($months > 12 ? '6.8pt' : '7.5pt');
@endphp
<html>
<head>
@include('exports.partials.pdf-style')
</head>
<body>
    <table class="kpi">
        <tr>
            <td style="width: 32mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">{{ $a2 ? 'Jumlah pimpinan' : 'Jumlah karyawan' }}</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $rows->count() }}</span></td>
            @foreach ($counts as $letter => $n)
                <td style="width: 27mm; border-left: 3pt solid {{ $fill[$letter] }};"><span style="font-size: 6.5pt; color: #5e5e5e;">Rata-rata&nbsp;{{ $letter }}</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $n }}</span></td>
            @endforeach
            <td style="width: 34mm;"><span style="font-size: 6.5pt; color: #5e5e5e;">Rata-rata keseluruhan</span><br><span style="font-size: 14pt; font-weight: bold; color: #000000;">{{ $rows->isEmpty() ? '-' : number_format($rows->avg('average'), 1) }}</span></td>
            <td style="width: 60mm; border: none; font-size: 6.5pt; color: #5e5e5e; vertical-align: middle;">Rata-rata = jumlah nilai akhir ÷ jumlah bulan yang ada nilainya (hanya Disetujui). Huruf memakai skala {{ ApprovedExport::labelFor($export->filters['to']) }}.</td>
        </tr>
    </table>

    <table class="grid" repeat_header="1">
        <thead>
            <tr>
                <th rowspan="2" style="width: {{ $w['no'] }}mm;">No</th>
                <th rowspan="2" style="width: {{ $w['nik'] }}mm;">NIK</th>
                <th rowspan="2" style="width: {{ $w['name'] }}mm;">Nama</th>
                <th rowspan="2" style="width: {{ $w['unit'] }}mm;">{!! $a2 ? 'Unit' : 'Departemen /<br>Seksi' !!}</th>
                <th colspan="{{ $months }}">{{ $h['points'][0] }} {{ $h['points'][1] }} per bulan</th>
                <th rowspan="2" style="width: {{ $w['count'] }}mm;">Jumlah<br><span class="sub">bulan</span></th>
                <th rowspan="2" style="width: {{ $w['avg'] }}mm;">Rata-<br>rata</th>
                <th rowspan="2" style="width: {{ $w['grade'] }}mm;">總分<br><span class="sub">Nilai Akhir</span></th>
            </tr>
            <tr>
                @foreach ($export->periods as $p)
                    <th style="width: {{ $mw }}mm; font-size: {{ $mfont }};">{{ AverageExport::monthLabel($p) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td class="c">{{ $row['no'] }}</td>
                    <td>{{ $row['nik'] }}</td>
                    <td><span class="b">{{ $row['name'] }}</span><br><span class="small">{{ $row['position'] }}</span></td>
                    <td>@if ($a2){{ $row['unit'] }}@else{{ $row['department'] }}@if ($row['section'] && $row['section'] !== $row['department'])<br><span class="small">{{ $row['section'] }}</span>@endif @endif</td>
                    @foreach ($row['scores'] as $score)
                        <td class="c" style="font-size: {{ $mfont }};">{!! $score === null ? '<span class="small">–</span>' : number_format($score, 1) !!}</td>
                    @endforeach
                    <td class="c">{{ $row['months'] }}</td>
                    <td class="r b" style="font-size: 8.5pt;">{{ number_format($row['average'], 1) }}</td>
                    <td class="grade" style="background: {{ $fill[$row['grade']] ?? '#9a9a9a' }}; color: {{ $ink[$row['grade']] ?? '#ffffff' }};">{{ $row['grade'] }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 7 + $months }}" class="c">Belum ada penilaian yang disetujui untuk rentang dan filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="scale">
        <tr><th colspan="3">{{ $h['scale'][0] }} {{ $h['scale'][1] }} · skala {{ ApprovedExport::labelFor($export->filters['to']) }}</th></tr>
        @foreach (array_reverse(Assessment::finalGradeRanges($export->filters['to']), true) as $letter => [$min, $max])
            <tr>
                <td style="width: 8mm; background: {{ $fill[$letter] }}; color: {{ $ink[$letter] ?? '#ffffff' }}; text-align: center; font-weight: bold;">{{ $letter }}</td>
                <td style="width: 28mm;">{{ $letter }}級 ({{ $min }}~{{ $max }})</td>
                <td style="width: 32mm;">Nilai {{ $letter }} ({{ $min }}~{{ $max }})</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
