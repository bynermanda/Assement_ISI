<?php

namespace App\Http\Requests;

use App\Support\AverageExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Rekap rata-rata (Excel / PDF): checks the filters and the length of the range BEFORE anything heavy runs.
 * PDF max AverageExport::MAX_PDF_MONTHS months, Excel max MAX_XLSX_MONTHS ("Semua periode" counts too).
 * The built export is kept on the request: export() gives it to the controller without a second query.
 */
class AverageExportRequest extends FormRequest
{
    private ?AverageExport $export = null;

    public function authorize(): bool
    {
        return true; // the route is already admin-only
    }

    public function rules(): array
    {
        return [
            'type'       => ['nullable', 'in:a1,a2'],
            'all'        => ['nullable', 'boolean'],
            'from'       => ['nullable', 'date_format:Y-m'],
            'to'         => ['nullable', 'date_format:Y-m'],
            'department' => ['nullable', 'string', 'max:120'],
            'section'    => ['nullable', 'string', 'max:120'],
            'unit'       => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['from' => 'Dari periode', 'to' => 'Sampai periode', 'type' => 'jenis rekap'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $export = AverageExport::fromRequest($this);
            $months = count($export->periods);
            $pdf    = $this->routeIs('exports.average.pdf');
            $max    = $pdf ? AverageExport::MAX_PDF_MONTHS : AverageExport::MAX_XLSX_MONTHS;
            if ($months > $max) {
                $validator->errors()->add('range', sprintf(
                    'Rentang %s s/d %s = %d bulan. Rekap %s maksimal %d bulan%s.',
                    AverageExport::monthLabel($export->filters['from']), AverageExport::monthLabel($export->filters['to']),
                    $months, $pdf ? 'PDF' : 'Excel', $max,
                    $pdf ? '; perkecil rentangnya atau pakai Rekap Excel (maks. ' . AverageExport::MAX_XLSX_MONTHS . ' bulan)' : ''
                ));
                return;
            }
            $this->export = $export;
        }];
    }

    public function export(): AverageExport
    {
        return $this->export ??= AverageExport::fromRequest($this);
    }
}
