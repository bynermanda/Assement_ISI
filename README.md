# Penilaian Kinerja Karyawan · PT Indosafety Sentosa

Aplikasi web penilaian kinerja bulanan karyawan: penilai mengisi nilai, atasan menyetujui, HRD mengelola data dan
mengekspor hasil yang sudah disetujui ke Excel / PDF.

Dibangun dengan **Laravel 12** (PHP ≥ 8.2), database **PostgreSQL di Supabase**, tampilan Blade + Tailwind + Alpine.js,
dan dideploy sebagai container Docker di **Render**.

---

## Fitur

| Area | Isi |
|---|---|
| **Assessment 1** (per departemen) | Form penilaian per karyawan dan *Assessment Massal* (grid) untuk departemen besar; Penilai 1 menilai, Penilai 2 menyetujui / menolak. Prinsip empat mata: penilai tidak bisa menyetujui penilaiannya sendiri. |
| **Assessment 2** (per unit) | Kriteria berbobot per unit; penilai dan approver ditentukan per peserta. |
| **Impor HRD** | Kehadiran (Poin 1 & 2) dan sanksi (Poin 6 Kepatuhan Keselamatan) dari file Excel; dipratinjau di browser, divalidasi ulang di server, nilai terkunci. |
| **Panduan** | Deskripsi kriteria per departemen / seksi, Jalur Khusus A1, unit Assessment 2, Skala Nilai Akhir (berlaku per periode). |
| **Export** | Hasil yang sudah disetujui: Excel dan PDF, termasuk rekap rata-rata beberapa periode. |
| **Pengguna** | Akun departemen (Penilai 1 / 2), akun pribadi Assessment 2, admin HRD; generate akun per departemen, reset password. |
| **Lampiran** | Penilai bisa melampirkan bukti (PDF / JPG / PNG, maks. 3 file × 2 MB) saat penilaian masih Draf / Dikembalikan; approver dan admin hanya melihat. Dihapus otomatis 6 bulan setelah di-approve (lihat [Lampiran](#lampiran-penilaian)). |
| **Backup** | Admin mengunduh seluruh database sebagai satu file `.sql` (lihat [Backup](#backup--pemulihan)). |
| **Bahasa** | Indonesia; akun Assessment 2 (`a2.*`) juga melihat teks 繁體中文 (`lang/zh_TW.json`). |

### Peran

| Peran | Kode | Akses |
|---|---|---|
| Admin (HRD) | `admin` | Semua data, pengguna, impor, panduan, export, backup |
| Akun departemen (A1) | `reviewer` | Penilai 1 (menilai) / Penilai 2 (menyetujui) untuk seksi yang dipegang |
| Akun pribadi (A2) | `assessor` | Menilai / menyetujui peserta Assessment 2 yang ditugaskan |

---

## Menjalankan di komputer lokal

Kebutuhan: **PHP 8.2.35+ / 8.3.36+** (versi lebih lama rentan PHP2Shell) dengan ekstensi `pdo_pgsql`, `pgsql`,
`mbstring`, `gd`, `zip`, `fileinfo`, `curl`, `openssl`; **Composer 2**; akses ke database PostgreSQL (Supabase).

```bash
git clone <url-repo> indosafety-penilaian
cd indosafety-penilaian
composer install

# buat file .env (lihat tabel variabel di bawah), lalu:
php artisan key:generate
php artisan migrate        # HANYA untuk database baru yang masih kosong
php artisan serve          # http://127.0.0.1:8000
```

Database yang sudah berisi data **tidak perlu** `migrate` ulang. Jangan pernah menjalankan `migrate:fresh` atau
`db:wipe` ke database produksi: semua data akan terhapus.

### Variabel lingkungan (`.env`)

File `.env` **tidak** disimpan di repo. Buat sendiri di root proyek, atau isi di dashboard Render untuk produksi.

| Variabel | Contoh / keterangan |
|---|---|
| `APP_NAME` | `Penilaian Kinerja Karyawan` |
| `APP_ENV` | `local` di laptop, `production` di server |
| `APP_DEBUG` | `true` di laptop, **wajib `false`** di server |
| `APP_KEY` | dibuat dengan `php artisan key:generate` (lokal) atau `php artisan key:generate --show` (untuk Render) |
| `APP_URL` | alamat aplikasi, persis seperti di browser, tanpa `/` di akhir |
| `APP_LOCALE` | `id` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | host *Transaction pooler* dari Supabase → Connect |
| `DB_PORT` | `6543` (Transaction pooler) |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | `postgres.<project-ref>` dari Supabase |
| `DB_PASSWORD` | password database Supabase |
| `DB_SSLMODE` | `require` |
| `DB_EMULATE_PREPARES` | `true` (wajib untuk Transaction pooler) |
| `DB_CONNECT_TIMEOUT` | `10` |
| `SESSION_DRIVER` / `CACHE_STORE` | `file` |
| `SESSION_SECURE_COOKIE` | `true` di server (HTTPS) |
| `TRUSTED_PROXIES` | `*` di Render; IP reverse proxy di server internal |
| `DEFAULT_ACCOUNT_PASSWORD` | password awal untuk akun baru (min. 8 karakter, huruf + angka) |
| `FORCE_PASSWORD_CHANGE` | `true`: akun baru / di-reset wajib ganti password saat login pertama |
| `SUPABASE_S3_ENDPOINT` | `https://<project-ref>.storage.supabase.co/storage/v1/s3` (Supabase → Storage → S3 Connection) |
| `SUPABASE_S3_REGION` | region proyek Supabase, mis. `ap-southeast-1` |
| `SUPABASE_S3_BUCKET` | `lampiran-penilaian` |
| `SUPABASE_S3_KEY` / `SUPABASE_S3_SECRET` | *access key* S3 dari Supabase (rahasia). Kosong = di laptop lampiran disimpan di `storage/app/private/lampiran`; di server unggah lampiran nonaktif |

---

## Deploy (Render + Supabase)

Panduan lengkap: [DEPLOY-RENDER.md](DEPLOY-RENDER.md). Ringkasnya:

1. Render → **New → Blueprint** → pilih repo ini; Render membaca [`render.yaml`](render.yaml).
2. Isi variabel bertanda `sync: false` (APP_KEY, APP_URL, DB_*, DEFAULT_ACCOUNT_PASSWORD) di dashboard, **bukan** di repo.
3. Tambahkan `SESSION_SECURE_COOKIE=true` dan `FORCE_PASSWORD_CHANGE=true`.
4. Setiap `git push` ke `main` otomatis dideploy ulang. Cek `https://<alamat-aplikasi>/up` → *Application up*.

Image Docker: `php:8.3.36-apache` (versi persis yang sudah menambal PHP2Shell, jangan diturunkan). Container
**tidak** menjalankan migrasi otomatis.

---

## Lampiran penilaian

- **Siapa:** penilai mengunggah / menghapus lampiran di Form Assessment (A1) atau form Assessment 2 selama penilaian
  berstatus Draf atau Dikembalikan; setelah dikirim terkunci. Approver dan admin hanya melihat (pratinjau PDF / foto).
  Pengguna Assessment Massal: simpan draf, lalu tambahkan lampiran lewat Form Assessment.
- **Batas:** PDF, JPG, PNG; maks. 3 file per penilaian, 2 MB per file. Foto dikecilkan otomatis di browser.
- **Keamanan:** file disimpan di bucket **privat** Supabase Storage `lampiran-penilaian` (dibuat sekali; batas jenis &
  ukuran juga dipasang di bucket) dengan nama acak. Setiap tampilan lewat `/lampiran/{id}` setelah cek hak akses
  (penilai / approver karyawan tersebut / admin). Gambar di-encode ulang (metadata & muatan tersembunyi dibuang),
  PDF dicek tanda tangannya. Kode: `app/Support/Attachments.php`.
- **Retensi:** `php artisan lampiran:prune` menghapus lampiran 6 bulan setelah penilaian di-approve (dan lampiran
  yang penilaiannya sudah terhapus). Berjalan otomatis sekali sehari (request pertama hari itu; di server dengan cron
  juga lewat `schedule:run`). Nilai dan catatan penilaian tidak ikut terhapus.
- File lampiran **tidak** termasuk di backup `.sql`; pemakaiannya terlihat di menu **Backup**.

---

## Database

- Struktur dibuat oleh migrasi di `database/migrations`. `php artisan migrate` pada database kosong menghasilkan
  struktur yang identik dengan produksi.
- [`database/schema/schema-public.sql`](database/schema/schema-public.sql): acuan struktur 20 tabel (tanpa data).
  Tidak dimuat otomatis; perbarui bila ada migrasi baru.
- RLS (Row Level Security) aktif di semua tabel tanpa policy: tabel tidak bisa dibaca lewat API Supabase, aplikasi
  tetap bisa karena terhubung sebagai pemilik database.

---

## Backup & pemulihan

**Membuat backup (disarankan setiap bulan, setelah penilaian di-approve):** login sebagai admin → menu **Backup** →
**Unduh Backup**. File `backup-penilaian-<tanggal>.sql` berisi struktur + seluruh data. Simpan di folder perusahaan
yang aksesnya terbatas: file ini berisi data pribadi karyawan, jangan dikirim lewat chat / email pribadi dan jangan
diunggah ke GitHub.

**Memulihkan:** Supabase → **SQL Editor** → **New query** → tempel seluruh isi file → **Run**.
Pemulihan **mengganti semua data saat ini**; bila terjadi error, tidak ada yang berubah (satu transaksi).

---

## Perintah artisan khusus

| Perintah | Fungsi |
|---|---|
| `php artisan a2:import <file.xlsx> [--dry-run] [--password=]` | Impor kriteria, peserta dan akun Assessment 2 dari workbook HRD |
| `php artisan trial:assessments status\|seed\|purge` | Data penilaian uji coba untuk mencoba export (jangan di produksi) |

---

## Keamanan

- **Jangan pernah meng-commit** `.env`, salinan `.env`, file data karyawan (`*.csv`, `*.xlsx`) atau file backup
  `.sql`; sebagian besar sudah diabaikan oleh `.gitignore` dan `.dockerignore`.
- Rahasia produksi hanya disimpan di dashboard Render dan password manager perusahaan.
- Header keamanan (CSP, HSTS, X-Frame-Options), batas percobaan login dan batas jumlah aksi per menit aktif
  (`app/Http/Middleware/SecurityHeaders.php`, `app/Providers/AppServiceProvider.php`).
- PHP minimal 8.2.35 / 8.3.36 (PHP2Shell, CVE-2026-109618 / CVE-2026-109619); `session.upload_progress` dimatikan
  di `docker/php.ini`.

### Pemeliharaan rutin

| Kapan | Apa |
|---|---|
| Setiap bulan | Unduh backup; jalankan `composer audit`; periksa log error di Render |
| Saat ada rilis keamanan PHP / Laravel | Naikkan versi di `Dockerfile` / `composer.lock`, uji export PDF & Excel, push |
| Saat penilai keluar / pindah | Reset password atau hapus akunnya di menu **Pengguna**, lalu alihkan tugas penilaiannya |
| Setiap 3 bulan | Uji pemulihan backup ke proyek Supabase uji |
