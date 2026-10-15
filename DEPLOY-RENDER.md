# Deploy ke Render.com

Aplikasi berjalan sebagai Docker container (lihat `Dockerfile`, `docker/`, `render.yaml`) dan memakai database
Supabase yang sudah ada. Container **tidak** menjalankan `migrate` atau `db:seed`.

## 1. Buat service

1. Render Dashboard → **New → Blueprint** → pilih repository ini. Render membaca `render.yaml`.
2. Render meminta nilai untuk variabel bertanda `sync: false`. Isi di dashboard (jangan pernah di GitHub):

| Variabel | Isi |
|---|---|
| `APP_KEY` | sama dengan `APP_KEY` di `.env` lokal (format `base64:...`) |
| `APP_URL` | URL Render, mis. `https://indosafety-penilaian.onrender.com` |
| `DB_HOST` | host pooler Supabase (lihat Supabase → Project Settings → Database → Connection pooling) |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | user pooler Supabase (lihat Supabase, format `postgres.<project-ref>`) |
| `DB_PASSWORD` | password database Supabase |
| `DEFAULT_ACCOUNT_PASSWORD` | password awal untuk akun baru (isian otomatis di form Tambah pengguna) |

Variabel lain (APP_ENV=production, APP_DEBUG=false, TRUSTED_PROXIES=*, DB_PORT=6543, DB_SSLMODE=require, ...)
sudah diisi oleh `render.yaml`.

## 2. Setelah deploy

- Buka `https://<service>.onrender.com/up` → harus "Application up".
- Login dengan akun yang sudah ada.
- Paket **free** Render tidur setelah ±15 menit tanpa akses; akses pertama setelahnya butuh ±30–60 detik.
- Session dan cache disimpan di disk container: hilang setiap deploy ulang (user cukup login lagi).

## Keamanan

- `.env`, `.env.*` (kecuali `.env.example`), file data karyawan (`database/seeders/*.csv`, `*.xls(x)`) diabaikan
  oleh `.gitignore` dan `.dockerignore`.
- `APP_DEBUG` harus `false` di Render: halaman error tidak menampilkan konfigurasi.
