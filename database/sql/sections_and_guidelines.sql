-- Jalankan di Supabase SQL Editor SETELAH "php artisan migrate" dan import karyawan.

-- 1. Departemen / section yang BELUM punya baris guideline di criteria_descriptions
select distinct e.department, e.section
from public.employees e
where e.status = 'Active'
  and not exists (
        select 1
        from public.criteria_descriptions c
        where lower(trim(c.department)) = lower(trim(e.department))
          and (c.section is null
               or trim(c.section) = ''
               or lower(trim(c.section)) = lower(trim(coalesce(e.section, ''))))
  )
order by 1, 2;

-- 2. Contoh: QC punya beberapa section. Salin teks dari satu departemen sebagai bahan awal,
--    lalu edit teksnya di Table Editor. Ganti 'maintenance' dengan departemen yang teksnya ingin disalin.
insert into public.criteria_descriptions (department, section, desc1, desc2, desc3, desc4, desc5, desc6, created_at, updated_at)
select 'QC', s.section, c.desc1, c.desc2, c.desc3, c.desc4, c.desc5, c.desc6, now(), now()
from public.criteria_descriptions c
cross join (values ('QC'), ('IQC'), ('PQC'), ('TOTAL CHECK')) as s(section)
where lower(trim(c.department)) = 'maintenance'
  and (c.section is null or trim(c.section) = '');

-- Catatan:
--  * Jika muncul error pada kolom created_at / updated_at, hapus kedua kolom itu dari INSERT dan SELECT.
--  * Jika muncul error pada id_desc (tidak otomatis terisi), beri tahu saya; query-nya perlu disesuaikan.
--  * Baris tanpa section tetap berlaku sebagai guideline untuk seluruh departemen.
