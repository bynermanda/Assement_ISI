{{-- Shared PDF styles (mPDF): black header row, 0.5pt hairlines, zebra rows, fixed widths set per column in mm. --}}
<style>
    body { font-family: dejavusans; font-size: 7.5pt; color: #1a1a1a; }
    h2 { font-size: 10.5pt; margin: 0 0 1.5mm 0; color: #000000; }
    .muted { color: #5e5e5e; }
    table.grid { border-collapse: collapse; width: 100%; }
    table.grid th, table.grid td { border: 0.5pt solid #9a9a9a; padding: 1.6mm 1.4mm; vertical-align: middle; }
    table.grid thead th { background: #1a1a1a; color: #ffffff; text-align: center; font-weight: bold; font-size: 7pt; line-height: 1.25; }
    table.grid thead th .sub { color: #cfcfcf; font-weight: normal; font-size: 6.5pt; }
    table.grid tbody tr.even td { background: #f4f4f4; }
    .c { text-align: center; }
    .r { text-align: right; }
    .b { font-weight: bold; }
    .small { font-size: 6.5pt; color: #5e5e5e; }
    .grade { font-weight: bold; font-size: 9pt; color: #ffffff; text-align: center; }

    /* summary boxes */
    table.kpi { border-collapse: separate; border-spacing: 1.5mm 0; margin: 0 -1.5mm 4mm -1.5mm; }
    table.kpi td { border: 0.5pt solid #9a9a9a; padding: 1.5mm 2.5mm; vertical-align: top; }
    table.kpi .label { font-size: 6.5pt; color: #5e5e5e; }
    table.kpi .value { font-size: 13pt; font-weight: bold; color: #000000; }

    /* final grade scale */
    table.scale { border-collapse: collapse; margin-top: 4mm; }
    table.scale td, table.scale th { border: 0.5pt solid #9a9a9a; padding: 1.2mm 2.5mm; font-size: 7pt; }
    table.scale th { background: #1a1a1a; color: #ffffff; text-align: left; }
</style>
