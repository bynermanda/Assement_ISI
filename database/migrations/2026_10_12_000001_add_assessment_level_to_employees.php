<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval persons (Penilai 2) at the time of this migration. They move to Assessment 2;
     * from now on the admin moves people on the Employees page.
     */
    private const APPROVERS = [
        '11.170.602', // DEDI KURNIAWAN          ASSY 1
        '16.131.035', // RIYANTO                 ASSY 2
        '16.131.047', // SUROTO                  ASSY 3
        '11.131.007', // DIAN HERIYANTO          P.E / IMPROVEMENT
        '1.151.002',  // FERNANDO WANGSADI       PURCHASING
        '2.190.704',  // ROBBY ZULKARNAIN        EXIM
        '4.180.701',  // JOHAN EKO PRABOWO       FIN/ACC
        '5.160.901',  // KUSNIANJANA WIJA PARAMA HRD
        '9.190.102',  // RICKY KURNIATAMA        MARKETING
        '11.230.703', // PRIYANTO                MAINTENANCE
        '13.240.802', // AGUS SUPIANA            PRESS
        '13.131.027', // RIXON TUA SIANIPAR      PRESS
        '10.180.301', // AGUS TONY               PRODUKSI
        '16.230.115', // IMAM SURIAMSYAH         PRODUKSI
        '8.131.059',  // SURAHMAN                PC/MPC, PPC/WH2
        '6.131.025',  // PIRDAUS                 QA
        '6.130.107',  // FIONA KUSUMA            QA
        '6.140.903',  // CECE SUHARJANA          QA
        '6.131.020',  // JIHAR SAH               QC
        '6.240.205',  // HERU BUDIYANTO          QC
        '9.251.113',  // ARIKA WAHYU PARMAYANTO  R&D
        '16.121.254', // USMAN                   R&D
        '10.171.217', // IFFAN GITA SAPUTRA      DIES
        // IT: ALTIN WIDHI SUCIPTO stays in Assessment 1 on purpose.
    ];

    public function up(): void
    {
        // NULL = automatic by grade (1-5 -> Assessment 1, 6+ -> Assessment 2); 1 or 2 = set by the admin
        if (! Schema::hasColumn('employees', 'assessment_level')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedSmallInteger('assessment_level')->nullable()->after('status');
            });
        }

        $normalize = fn (?string $nik) => strtoupper(ltrim((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $nik), '0'));
        $wanted    = array_flip(array_map($normalize, self::APPROVERS));

        $ids = DB::table('employees')->pluck('id_employee')
            ->filter(fn ($id) => isset($wanted[$normalize($id)]))
            ->all();

        if ($ids !== []) {
            DB::table('employees')->whereIn('id_employee', $ids)->update(['assessment_level' => 2]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('assessment_level'));
    }
};
