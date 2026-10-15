{{-- Page header of every PDF page (mPDF SetHTMLHeader): company logo, title, filters, print date. Inline styles only. --}}
<table width="100%" style="border-collapse: collapse; border-bottom: 1pt solid #000000; font-family: dejavusans;">
    <tr>
        <td style="width: 46mm; padding: 0 0 2mm 0; vertical-align: middle;">
            <img src="{{ public_path('images/logo.png') }}" style="height: 13mm;">
        </td>
        <td style="padding: 0 0 2mm 4mm; vertical-align: middle; border-left: 3pt solid #76b900;">
            <div style="font-size: 13pt; font-weight: bold; color: #000000;">{{ $title }}</div>
            <div style="font-size: 8pt; color: #1a1a1a; margin-top: 1mm;">PT Indosafety Sentosa · {{ $subtitle }}</div>
        </td>
        <td style="width: 55mm; padding: 0 0 2mm 0; vertical-align: middle; text-align: right; font-size: 7.5pt; color: #5e5e5e;">
            Dicetak {{ now()->locale('id')->translatedFormat('d F Y H:i') }}<br>
            Hanya penilaian berstatus Disetujui
        </td>
    </tr>
</table>
