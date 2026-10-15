<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Support\A2Export;
use App\Support\ApprovedExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin: export of APPROVED assessments only (page preview, Excel, PDF). */
class ExportController extends Controller
{
    private const FINAL_FILL = ['A' => '059669', 'B' => '0284C7', 'C' => 'FBBF24', 'D' => 'E11D48'];

    public function xlsx(Request $request): StreamedResponse
    {
        set_time_limit(300);

        $export = ApprovedExport::fromRequest($request);
        $rows   = $export->rows();

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('PT Indosafety Sentosa')->setTitle('Penilaian Approved');
        $this->fillAssessmentSheet($book->getActiveSheet(), $export, $rows);
        $this->fillSummarySheet($book->createSheet(), $export, $rows);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $export->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf(Request $request): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $export = ApprovedExport::fromRequest($request);
        $rows   = $export->rows();

        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Hasil Penilaian Karyawan · ' . $export->pdfDescription());
        $this->pdfChrome($mpdf, 'Hasil Penilaian Karyawan (Assessment 1)', $export->pdfDescription());
        $mpdf->WriteHTML(view('exports.pdf', [
            'export' => $export,
            'rows'   => $rows,
            'counts' => ApprovedExport::finalCounts($rows),
        ])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $export->filename('pdf') . '"',
        ]);
    }

    /* ------------------------------------------------------------------ Assessment 2 */

    public function a2Xlsx(Request $request): StreamedResponse
    {
        set_time_limit(300);

        $export = A2Export::fromRequest($request);
        $groups = $export->groups();

        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $book->getProperties()->setCreator('PT Indosafety Sentosa')->setTitle('Assessment 2 Approved');
        $this->fillA2Summary($book->getActiveSheet(), $export, $groups);

        $used = ['Ringkasan' => true];
        foreach ($groups as $g) {
            $title = mb_substr(trim(preg_replace('/[\\\\\/\?\*\[\]:]+/', '-', $g['unit']->name)), 0, 28);
            for ($i = 2; isset($used[$title]); $i++) { $title = mb_substr($title, 0, 26) . " {$i}"; }
            $used[$title] = true;
            $this->fillA2Unit($book->createSheet()->setTitle($title), $export, $g);
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $export->filename('xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function a2Pdf(Request $request): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $export = A2Export::fromRequest($request);
        $groups = $export->groups();

        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Hasil Assessment 2 · ' . $export->pdfDescription());
        $this->pdfChrome($mpdf, 'Hasil Penilaian Pimpinan (Assessment 2)', $export->pdfDescription());
        $mpdf->WriteHTML(view('exports.a2-pdf', ['export' => $export, 'groups' => $groups, 'counts' => A2Export::finalCounts($groups)])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $export->filename('pdf') . '"',
        ]);
    }

    private function fillA2Summary(Worksheet $sheet, A2Export $export, Collection $groups): void
    {
        $sheet->setTitle('Ringkasan');
        $h = Assessment::HEADINGS;
        $sheet->setCellValue('A1', 'Hasil Assessment 2 (Approved) · PT Indosafety Sentosa');
        $sheet->setCellValue('A2', $export->description() . ' · ' . $groups->sum(fn ($g) => $g['rows']->count()) . ' orang · exported ' . now()->format('d M Y H:i'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('475569');

        $sheet->fromArray(['No', 'Periode', 'NIK', 'Nama', 'Unit', 'Penilai', 'Approved by', 'Approved on', $h['points'][0] . ' ' . $h['points'][1], 'Nilai Akhir'], null, 'A4');
        $this->styleHeader($sheet, 'A4:J4');
        $r = 5; $no = 1;
        foreach ($groups as $g) {
            foreach ($g['rows'] as $row) {
                $sheet->fromArray([$no++, $row['period']], null, "A{$r}");
                $sheet->setCellValueExplicit("C{$r}", $row['nik'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $this->putRow($sheet, "D{$r}", [$row['name'], $g['unit']->name, $row['penilai'], $row['approved_by'], $row['approved_at'], $row['final_score'], $row['final_grade']]);
                $this->paintFinal($sheet, "J{$r}", $row['final_grade']);
                $r++;
            }
        }
        if ($r === 5) {
            $sheet->setCellValue('A5', 'No approved Assessment 2 for these filters.');
            $r = 6;
        }
        $sheet->getStyle('A4:J' . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle('I5:I' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle('I5:J' . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $r += 1;
        $sheet->setCellValue("A{$r}", 'Nilai Akhir')->getStyle("A{$r}")->getFont()->setBold(true);
        foreach (A2Export::finalCounts($groups) as $letter => $n) {
            $r++;
            $sheet->fromArray([$letter, $n], null, "A{$r}", true);
            $this->paintFinal($sheet, "A{$r}", $letter);
        }
        $r += 2;
        $sheet->setCellValue("A{$r}", $h['scale'][0] . ' ' . $h['scale'][1])->getStyle("A{$r}")->getFont()->setBold(true);
        foreach (array_reverse(Assessment::finalGradeRanges(), true) as $letter => [$min, $max]) {
            $r++;
            $sheet->fromArray(["{$letter}級 ({$min}~{$max})", "Nilai {$letter} ({$min}~{$max})"], null, "A{$r}");
        }

        foreach (['A' => 6, 'B' => 10, 'C' => 13, 'D' => 28, 'E' => 24, 'F' => 30, 'G' => 24, 'H' => 13, 'I' => 11, 'J' => 11] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $sheet->freezePane('A5');
    }

    /** One unit: its own criteria as columns (group heading merged over its criteria, like the assessment table). */
    private function fillA2Unit(Worksheet $sheet, A2Export $export, array $g): void
    {
        $h        = Assessment::HEADINGS;
        $criteria = $g['criteria'];
        $layout   = A2Export::layout($criteria);
        $col      = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $before   = ['No', 'Periode', 'NIK', 'Nama', 'Grade · Jabatan'];
        $first    = count($before) + 1;
        $lastCrit = $first + $criteria->count() - 1;
        $after    = [$h['points'][0] . "\n" . $h['points'][1], 'Nilai Akhir', 'Penilai', 'Approved by', $h['notes'][0] . "\n" . $h['notes'][1]];
        $lastCol  = $lastCrit + count($after);

        $sheet->setCellValue('A1', 'Assessment 2 · ' . $g['unit']->name);
        $sheet->setCellValue('A2', $export->description() . ' · ' . $g['rows']->count() . ' orang · total bobot ' . rtrim(rtrim(number_format($criteria->sum('weight'), 2), '0'), '.') . '%');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('475569');

        foreach ($before as $i => $label) {
            $c = $col($i + 1);
            $sheet->setCellValue("{$c}4", $label)->mergeCells("{$c}4:{$c}6");
        }
        $sheet->setCellValue($col($first) . '4', $h['criteria'][0] . ' ' . $h['criteria'][1] . '  ·  ' . $h['weight'][0] . ' ' . $h['weight'][1]);
        if ($criteria->count() > 1) {
            $sheet->mergeCells($col($first) . '4:' . $col($lastCrit) . '4');
        }
        $i = $first;
        foreach ($criteria as $c) {
            [$group, $firstOfGroup, $size] = $layout[$c->id];
            $label = trim(trim((string) $c->name_zh) . "\n" . preg_replace('/\s+/', ' ', $c->name_id)) . "\n(" . rtrim(rtrim(number_format($c->weight, 2), '0'), '.') . '%)';
            $cell  = $col($i);
            if ($group === null) {
                $sheet->setCellValue("{$cell}5", $label)->mergeCells("{$cell}5:{$cell}6");
            } else {
                if ($firstOfGroup) {
                    $sheet->setCellValue("{$cell}5", trim($c->group_zh . "\n" . $c->group_id));
                    if ($size > 1) { $sheet->mergeCells("{$cell}5:" . $col($i + $size - 1) . '5'); }
                }
                $sheet->setCellValue("{$cell}6", $label);
            }
            $i++;
        }
        foreach ($after as $j => $label) {
            $c = $col($lastCrit + 1 + $j);
            $sheet->setCellValue("{$c}4", $label)->mergeCells("{$c}4:{$c}6");
        }
        $this->styleHeader($sheet, 'A4:' . $col($lastCol) . '6');
        $sheet->getRowDimension(5)->setRowHeight(30);
        $sheet->getRowDimension(6)->setRowHeight(75);

        $r = 7;
        foreach ($g['rows'] as $row) {
            $sheet->fromArray([$row['no'], $row['period']], null, "A{$r}");
            $sheet->setCellValueExplicit("C{$r}", $row['nik'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $this->putRow($sheet, "D{$r}", [$row['name'], $row['position'], ...array_values($row['grades']),
                $row['final_score'], $row['final_grade'], $row['penilai'], $row['approved_by'] . ' · ' . $row['approved_at'], $row['notes']]);
            $this->paintFinal($sheet, $col($lastCrit + 2) . $r, $row['final_grade']);
            $r++;
        }
        $last = max(7, $r - 1);
        $sheet->getStyle("A7:{$col($lastCol)}{$last}")->applyFromArray([
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getStyle($col($first) . "7:" . $col($lastCrit + 2) . $last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($col($lastCrit + 1) . "7:" . $col($lastCrit + 1) . $last)->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle($col($lastCol) . "7:" . $col($lastCol) . $last)->getAlignment()->setWrapText(true);

        foreach (['A' => 5, 'B' => 9, 'C' => 12, 'D' => 26, 'E' => 22] as $c => $w) { $sheet->getColumnDimension($c)->setWidth($w); }
        for ($k = $first; $k <= $lastCrit; $k++) { $sheet->getColumnDimension($col($k))->setWidth(15); }
        foreach ([9, 9, 24, 26, 45] as $j => $w) { $sheet->getColumnDimension($col($lastCrit + 1 + $j))->setWidth($w); }
        $sheet->freezePane('E7');
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    }

    /**
     * Writes a row of DATA. Text always goes in as text: a name or note starting with "=", "+", "-" or "@" would
     * otherwise become an Excel formula (formula / CSV injection) when HR opens the file. Numbers stay numbers.
     */
    private function putRow(Worksheet $sheet, string $start, array $values): void
    {
        [$col, $row] = Coordinate::coordinateFromString($start);
        $index = Coordinate::columnIndexFromString($col);

        foreach (array_values($values) as $i => $value) {
            $cell = Coordinate::stringFromColumnIndex($index + $i) . $row;
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            } elseif ($value !== null && $value !== '') {
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => '0F172A']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
        ]);
    }

    private function paintFinal(Worksheet $sheet, string $cell, string $letter): void
    {
        if (isset(self::FINAL_FILL[$letter])) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $letter === 'C' ? '451A03' : 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::FINAL_FILL[$letter]]],
            ]);
        }
    }

    /** A4 landscape; autoScriptToLang / autoLangToFont give the Chinese headings a CJK font (Sun-ExtA). */
    /** Same page header (company logo, title, filters) and footer on every PDF page. */
    private function pdfChrome(Mpdf $mpdf, string $title, string $subtitle): void
    {
        $mpdf->SetHTMLHeader(view('exports.partials.pdf-header', ['title' => $title, 'subtitle' => $subtitle])->render());
        $mpdf->SetHTMLFooter('<table width="100%" style="font-family: dejavusans; font-size: 7pt; color: #5e5e5e; border-top: 0.5pt solid #9a9a9a;"><tr>'
            . '<td style="padding-top: 1.5mm;">PT Indosafety Sentosa · ' . e($title) . ' · ' . e($subtitle) . '</td>'
            . '<td style="padding-top: 1.5mm; text-align: right;">Halaman {PAGENO} / {nbpg}</td></tr></table>');
    }

    private function mpdf(): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 30, 'margin_bottom' => 13,
            'margin_header' => 7, 'margin_footer' => 6,
            'tempDir' => $tempDir, 'autoScriptToLang' => true, 'autoLangToFont' => true, 'default_font' => 'dejavusans',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Sheet 1: one row per approved assessment, headings like the assessment table:
     * 考核項目 Item Evaluasi over the criteria, 出勤表現 Performa Kehadiran over its two items.
     */
    private function fillAssessmentSheet(Worksheet $sheet, ApprovedExport $export, Collection $rows): void
    {
        $sheet->setTitle('Penilaian');

        $criteria = Assessment::CRITERIA;
        $layout   = Assessment::criteriaLayout();
        $h        = Assessment::HEADINGS;
        $before   = ['No', 'Periode', 'NIK', 'Nama', 'Department', 'Section', 'Grade · Jabatan'];
        $after    = [$h['points'][0] . "\n" . $h['points'][1], 'Nilai Akhir', 'Penilai 1', 'Approved by', 'Approved on', $h['notes'][0] . "\n" . $h['notes'][1]];
        $first    = count($before) + 1;                       // first criterion column
        $lastCrit = $first + count($criteria) - 1;
        $lastCol  = $lastCrit + count($after);
        $col      = fn (int $i) => Coordinate::stringFromColumnIndex($i);

        // Title
        $sheet->setCellValue('A1', 'Hasil Penilaian Karyawan (Approved) · PT Indosafety Sentosa');
        $sheet->setCellValue('A2', $export->description() . ' · ' . $rows->count() . ' karyawan · exported ' . now()->format('d M Y H:i'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('475569');

        // Header rows 4-6
        foreach ($before as $i => $label) {
            $c = $col($i + 1);
            $sheet->setCellValue("{$c}4", $label)->mergeCells("{$c}4:{$c}6");
        }
        $sheet->setCellValue($col($first) . '4', $h['criteria'][0] . ' ' . $h['criteria'][1] . '  ·  ' . $h['weight'][0] . ' ' . $h['weight'][1]);
        $sheet->mergeCells($col($first) . '4:' . $col($lastCrit) . '4');

        $i = $first;
        foreach ($criteria as $column => $meta) {
            [$group, $firstOfGroup, $size] = $layout[$column];
            $label = trim($meta['zh'] . "\n" . $meta['id']) . "\n(" . $meta['weight'] . '%)';
            $c     = $col($i);

            if ($group === null) {
                $sheet->setCellValue("{$c}5", $label)->mergeCells("{$c}5:{$c}6");
            } else {
                if ($firstOfGroup) {
                    $g = Assessment::CRITERIA_GROUPS[$group];
                    $sheet->setCellValue("{$c}5", $g['zh'] . "\n" . $g['id'])->mergeCells("{$c}5:" . $col($i + $size - 1) . '5');
                }
                $sheet->setCellValue("{$c}6", $label);
            }
            $i++;
        }
        foreach ($after as $j => $label) {
            $c = $col($lastCrit + 1 + $j);
            $sheet->setCellValue("{$c}4", $label)->mergeCells("{$c}4:{$c}6");
        }

        $header = 'A4:' . $col($lastCol) . '6';
        $sheet->getStyle($header)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => '0F172A']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(32);
        $sheet->getRowDimension(6)->setRowHeight(48);

        // Data from row 7
        $r = 7;
        foreach ($rows as $row) {
            $values = [$row['no'], $row['period'], $row['nik'], $row['name'], $row['department'], $row['section'], $row['position'],
                ...array_values($row['grades']),
                $row['final_score'], $row['final_grade'], $row['penilai1'], $row['approved_by'], $row['approved_at'], $row['notes']];

            foreach ($values as $k => $value) {
                $sheet->setCellValueExplicit($col($k + 1) . $r, $value, is_float($value) || is_int($value)
                    ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                    : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);   // NIK stays text ("01.151002")
            }

            $finalCell = $col($lastCrit + 2) . $r;
            if (isset(self::FINAL_FILL[$row['final_grade']])) {
                $sheet->getStyle($finalCell)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $row['final_grade'] === 'C' ? '451A03' : 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::FINAL_FILL[$row['final_grade']]]],
                ]);
            }
            $r++;
        }

        $lastRow = max(7, $r - 1);
        if ($rows->isNotEmpty()) {
            $sheet->getStyle("A7:{$col($lastCol)}{$lastRow}")->applyFromArray([
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
            $sheet->getStyle($col($first) . "7:" . $col($lastCrit + 2) . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($col($lastCrit + 1) . "7:" . $col($lastCrit + 1) . $lastRow)->getNumberFormat()->setFormatCode('0.0');
            $sheet->getStyle($col($lastCol) . "7:" . $col($lastCol) . $lastRow)->getAlignment()->setWrapText(true);
            $sheet->setAutoFilter('A6:' . $col($lastCol) . $lastRow);
        } else {
            $sheet->setCellValue('A7', 'No approved assessments for these filters.');
        }

        // Widths, freeze, print
        $widths = [5, 9, 13, 28, 18, 18, 22];
        foreach ($widths as $k => $w) {
            $sheet->getColumnDimension($col($k + 1))->setWidth($w);
        }
        for ($k = $first; $k <= $lastCrit; $k++) {
            $sheet->getColumnDimension($col($k))->setWidth(13);
        }
        foreach ([9, 9, 22, 22, 13, 45] as $j => $w) {
            $sheet->getColumnDimension($col($lastCrit + 1 + $j))->setWidth($w);
        }
        $sheet->freezePane('E7');
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(4, 6);
    }

    /** Sheet 2: final grades per department, and the 等計分數 Perhitungan Nilai scale. */
    private function fillSummarySheet(Worksheet $sheet, ApprovedExport $export, Collection $rows): void
    {
        $sheet->setTitle('Ringkasan');
        $letters = array_keys(Assessment::FINAL_GRADES);

        $sheet->setCellValue('A1', 'Ringkasan Nilai Akhir (Approved)');
        $sheet->setCellValue('A2', $export->description());
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray(['Department', ...$letters, 'Total', 'Rata-rata'], null, 'A4');
        $r = 5;
        foreach ($rows->groupBy('department')->sortKeys() as $department => $group) {
            $counts = ApprovedExport::finalCounts($group);
            $this->putRow($sheet, "A{$r}", [$department, ...array_values($counts), $group->count(), round($group->avg('final_score'), 1)]);
            $r++;
        }
        $all = ApprovedExport::finalCounts($rows);
        $sheet->fromArray(['TOTAL', ...array_values($all), $rows->count(), $rows->isEmpty() ? 0 : round($rows->avg('final_score'), 1)], null, "A{$r}");
        $sheet->getStyle("A4:H4")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:H{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A4:H{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A4:H4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');

        $s = $r + 3;
        $sheet->setCellValue("A{$s}", Assessment::HEADINGS['scale'][0] . ' ' . Assessment::HEADINGS['scale'][1]);
        $sheet->getStyle("A{$s}")->getFont()->setBold(true);
        foreach (array_reverse(Assessment::finalGradeRanges(), true) as $letter => [$min, $max]) {
            $s++;
            $sheet->fromArray(["{$letter}級 ({$min}~{$max})", "Nilai {$letter} ({$min}~{$max})"], null, "A{$s}");
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(18);
        foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(11);
        }
    }
}
