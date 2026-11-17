<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Full backup of the `public` schema as ONE .sql file: structure (read from the Postgres catalog, so it matches the
 * live database exactly) + data + sequence positions. Restore = paste the file in the Supabase SQL Editor and Run.
 *
 * The file runs in one transaction: it drops the app's tables, recreates them and refills them, so a restore either
 * fully succeeds or changes nothing. Tables are created unqualified under `SET LOCAL search_path`, so the same
 * file can be restored into another schema for testing (see restoreSearchPath()).
 */
class DatabaseBackup
{
    /** Short-lived system tables: structure is kept, their rows are not worth restoring. */
    public const SKIP_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public const LABELS = [
        'employees'             => 'Data karyawan',
        'users'                 => 'Akun pengguna',
        'assessments'           => 'Penilaian Assessment 1',
        'hrd_sanctions'         => 'Sanksi HRD',
        'criteria_descriptions' => 'Panduan kriteria',
        'a1_routes'             => 'Rute khusus Assessment 1',
        'a2_units'              => 'Unit Assessment 2',
        'a2_criteria'           => 'Kriteria Assessment 2',
        'a2_participants'       => 'Peserta Assessment 2',
        'a2_assessments'        => 'Penilaian Assessment 2',
        'app_settings'          => 'Pengaturan aplikasi',
        'assessment_attachments' => 'Daftar lampiran penilaian (filenya tidak ikut)',
        'migrations'            => 'Riwayat versi struktur database',
    ];

    private const ROWS_PER_INSERT = 100;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::connection()->getPdo();
    }

    public static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public static function filename(): string
    {
        return 'backup-penilaian-' . now('Asia/Jakarta')->format('Y-m-d-Hi') . '.sql';
    }

    /** @return array<int, array{name: string, label: string, rows: int, data: bool}> in restore order */
    public function tables(): array
    {
        return array_map(fn (string $t) => [
            'name'  => $t,
            'label' => self::LABELS[$t] ?? 'Data sistem',
            'rows'  => (int) $this->pdo->query('select count(*) from public.' . $this->ident($t))->fetchColumn(),
            'data'  => ! in_array($t, self::SKIP_DATA, true),
        ], $this->ordered());
    }

    /** Writes the whole file through $out (called many times with pieces of text). */
    public function write(callable $out, string $by): void
    {
        $tables = $this->ordered();
        $rows = 0;

        $out($this->header($by, $tables));
        $out("BEGIN;\nSET LOCAL search_path TO public;\n\n");

        // 1. remove the current tables (CASCADE: foreign keys between them)
        $out('DROP TABLE IF EXISTS ' . implode(', ', array_map(fn ($t) => $this->ident($t), $tables)) . " CASCADE;\n");
        foreach ($this->column("select relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = 'public' and c.relkind = 'S' order by 1") as $seq) {
            $out('DROP SEQUENCE IF EXISTS ' . $this->ident($seq) . ";\n");
        }

        // 2. structure
        $out("\n-- ===== STRUKTUR =====\n");
        foreach ($this->structure() as $statement) {
            $out($statement . "\n");
        }

        // 3. data, parents before children
        $out("\n-- ===== DATA =====\n");
        foreach ($tables as $table) {
            if (in_array($table, self::SKIP_DATA, true)) {
                continue;
            }
            $rows += $this->writeData($table, $out);
        }

        // 4. ids continue after the highest restored id
        $out("\n-- ===== URUTAN ID =====\n");
        foreach ($this->pdo->query("select c.relname seq, t.relname tbl, a.attname col from pg_class c
                join pg_namespace n on n.oid = c.relnamespace join pg_depend d on d.objid = c.oid and d.deptype = 'a'
                join pg_class t on t.oid = d.refobjid join pg_attribute a on a.attrelid = t.oid and a.attnum = d.refobjsubid
                where n.nspname = 'public' and c.relkind = 'S' order by 1")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $out(sprintf("SELECT setval(%s, COALESCE((SELECT max(%s) FROM %s), 0) + 1, false);\n",
                $this->pdo->quote($s['seq']), $this->ident($s['col']), $this->ident($s['tbl'])));
        }

        $out("\nCOMMIT;\n\n-- Selesai: " . count($tables) . " tabel, {$rows} baris data.\n");
    }

    /** The SET line a restore runs under; a test replaces it to restore into another schema. */
    public static function restoreSearchPath(): string
    {
        return 'SET LOCAL search_path TO public;';
    }

    private function header(string $by, array $tables): string
    {
        $lines = [
            'BACKUP DATABASE - Aplikasi Penilaian Kinerja Karyawan, PT Indosafety Sentosa',
            'Dibuat  : ' . now('Asia/Jakarta')->format('d-m-Y H:i') . ' WIB, oleh ' . $by,
            'Isi     : struktur + data ' . count($tables) . ' tabel (data karyawan, akun, penilaian, sanksi, Assessment 2, pengaturan)',
            '',
            'RAHASIA: berisi data pribadi karyawan. Simpan hanya di folder perusahaan yang aksesnya terbatas.',
            'Jangan dikirim lewat chat / email pribadi, jangan diunggah ke GitHub.',
            '',
            'CARA MEMULIHKAN: Supabase > SQL Editor > New query > tempel seluruh isi file ini > Run.',
            'PERHATIAN: memulihkan MENGGANTI SEMUA data aplikasi saat ini dengan isi backup ini.',
            'Bila ada yang gagal, tidak ada yang berubah (semua dalam satu transaksi).',
        ];

        return implode("\n", array_map(fn ($l) => rtrim('-- ' . $l), $lines)) . "\n\n";
    }

    /** CREATE statements for sequences, tables, keys, indexes, foreign keys and RLS, as in the live database. */
    private function structure(): array
    {
        $sql = [];
        $tables = $this->pdo->query("select c.oid, c.relname, c.relrowsecurity from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r' order by c.relname")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($this->pdo->query("select c.relname, format_type(s.seqtypid, null) typ, s.seqstart, s.seqincrement, s.seqmin, s.seqmax, s.seqcache, s.seqcycle
                from pg_sequence s join pg_class c on c.oid = s.seqrelid join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = 'public' order by 1")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sql[] = sprintf('CREATE SEQUENCE %s AS %s INCREMENT BY %s MINVALUE %s MAXVALUE %s START WITH %s CACHE %s%s;',
                $this->ident($s['relname']), $s['typ'], $s['seqincrement'], $s['seqmin'], $s['seqmax'], $s['seqstart'], $s['seqcache'], $s['seqcycle'] ? ' CYCLE' : '');
        }

        $columns = $this->pdo->prepare("select quote_ident(a.attname) q, format_type(a.atttypid, a.atttypmod) typ, a.attnotnull nn,
                pg_get_expr(d.adbin, d.adrelid) def,
                case when a.attcollation <> 0 and a.attcollation <> ty.typcollation then quote_ident(co.collname) end coll
            from pg_attribute a join pg_type ty on ty.oid = a.atttypid
            left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
            left join pg_collation co on co.oid = a.attcollation
            where a.attrelid = ? and a.attnum > 0 and not a.attisdropped order by a.attnum");
        foreach ($tables as $t) {
            $columns->execute([$t['oid']]);
            $cols = array_map(fn ($c) => "    {$c['q']} {$c['typ']}" . ($c['coll'] ? " COLLATE {$c['coll']}" : '')
                . ($c['def'] !== null ? " DEFAULT {$c['def']}" : '') . ($c['nn'] ? ' NOT NULL' : ''), $columns->fetchAll(PDO::FETCH_ASSOC));
            $sql[] = 'CREATE TABLE ' . $this->ident($t['relname']) . " (\n" . implode(",\n", $cols) . "\n);";
        }

        foreach ($this->pdo->query("select c.relname seq, t.relname tbl, a.attname col from pg_class c
                join pg_namespace n on n.oid = c.relnamespace join pg_depend d on d.objid = c.oid and d.deptype = 'a'
                join pg_class t on t.oid = d.refobjid join pg_attribute a on a.attrelid = t.oid and a.attnum = d.refobjsubid
                where n.nspname = 'public' and c.relkind = 'S' order by 1")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $sql[] = 'ALTER SEQUENCE ' . $this->ident($o['seq']) . ' OWNED BY ' . $this->ident($o['tbl']) . '.' . $this->ident($o['col']) . ';';
        }

        $constraints = fn (string $types) => $this->pdo->query("select c.relname tbl, x.conname, pg_get_constraintdef(x.oid) def
            from pg_constraint x join pg_class c on c.oid = x.conrelid join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and x.contype in ({$types}) order by x.contype desc, 1, 2")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($constraints("'p','u','c','x'") as $x) {
            $sql[] = 'ALTER TABLE ' . $this->ident($x['tbl']) . ' ADD CONSTRAINT ' . $this->ident($x['conname']) . " {$x['def']};";
        }
        foreach ($this->column("select pg_get_indexdef(i.indexrelid) from pg_index i join pg_class c on c.oid = i.indrelid
                join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = 'public' and not exists (select 1 from pg_constraint x where x.conindid = i.indexrelid) order by 1") as $def) {
            $sql[] = str_replace(' ON public.', ' ON ', $def) . ';';   // unqualified: follows search_path
        }
        foreach ($constraints("'f'") as $x) {
            $sql[] = 'ALTER TABLE ' . $this->ident($x['tbl']) . ' ADD CONSTRAINT ' . $this->ident($x['conname']) . " {$x['def']};";
        }
        foreach ($tables as $t) {
            if ($t['relrowsecurity']) {
                $sql[] = 'ALTER TABLE ' . $this->ident($t['relname']) . ' ENABLE ROW LEVEL SECURITY;';
            }
        }

        return $sql;
    }

    private function writeData(string $table, callable $out): int
    {
        $cols = $this->column("select attname from pg_attribute where attrelid = 'public." . $this->ident($table) . "'::regclass
            and attnum > 0 and not attisdropped order by attnum");
        $colList = implode(', ', array_map(fn ($c) => $this->ident($c), $cols));
        $rows = $this->pdo->query("select {$colList} from public." . $this->ident($table) . ' order by 1')->fetchAll(PDO::FETCH_ASSOC);

        $out("\n-- " . (self::LABELS[$table] ?? $table) . ': ' . count($rows) . " baris\n");
        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            $values = array_map(fn ($row) => '(' . implode(', ', array_map(fn ($c) => $this->literal($row[$c]), $cols)) . ')', $chunk);
            $out('INSERT INTO ' . $this->ident($table) . " ({$colList}) VALUES\n" . implode(",\n", $values) . ";\n");
        }

        return count($rows);
    }

    /** Tables ordered so a referenced (parent) table comes before the tables that point to it. */
    private function ordered(): array
    {
        $tables = $this->column("select relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r' order by 1");
        $parents = array_fill_keys($tables, []);
        foreach ($this->pdo->query("select c.relname child, p.relname parent from pg_constraint x
                join pg_class c on c.oid = x.conrelid join pg_class p on p.oid = x.confrelid
                join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and x.contype = 'f'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['child'] !== $r['parent'] && isset($parents[$r['child']])) {
                $parents[$r['child']][] = $r['parent'];
            }
        }

        $order = [];
        $visit = function (string $t) use (&$visit, &$order, $parents) {
            if (in_array($t, $order, true)) {
                return;
            }
            foreach ($parents[$t] as $p) {
                $visit($p);
            }
            $order[] = $t;
        };
        foreach ($tables as $t) {
            $visit($t);
        }

        return $order;
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null     => 'NULL',
            is_bool($value)     => $value ? 'true' : 'false',
            is_int($value)      => (string) $value,
            is_resource($value) => $this->pdo->quote((string) stream_get_contents($value)),
            default             => $this->pdo->quote((string) $value),
        };
    }

    private function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function column(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}
