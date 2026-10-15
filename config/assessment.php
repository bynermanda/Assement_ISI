<?php

/*
|--------------------------------------------------------------------------
| Assessment 1 settings
|--------------------------------------------------------------------------
| assessed_max_grade : Assessment 1 covers employees of grade 1 up to this grade.
|                      Higher grades go to Assessment 2.
|
| The working unit is the section of a department ("APQP (QA)", "PQC (QC)");
| when the section is empty or the same as the department it is just the department ("PRESS").
| Every department gets one Penilai 1 account (assesses) and one Penilai 2 account (approves and may edit).
|
| Assessment 1 or 2 per employee: the admin sets it on the Employees page (employees.assessment_level).
| Left on "Auto", it follows the grade: 1 up to assessed_max_grade -> Assessment 1, higher -> Assessment 2.
| Approval persons (Penilai 2) are set to Assessment 2 there, see migration 2026_10_12_000001.
*/
return [
    'assessed_max_grade' => 5,

    // Assessment Massal (bulk grid) only for accounts with MORE than this many employees to assess;
    // smaller departments use the manual Assessment Form.
    'bulk_min_employees' => 25,

    // In those Assessment Massal departments only these job titles (jabatan, employees.grade) stay with Penilai 1 and
    // Assessment Massal. Every other title (Teknisi 1, 2, 3 ..., Kepala Line, Kepala Regu, Staff ...) is assessed by
    // the department's Penilai 2 and approved by the superior of its holder. See ReviewRules::routeMap().
    'bulk_jabatans' => ['Operator', 'Asst. Kepala Line', 'Teknisi', 'Driver'],

    // Trial phase: everyone keeps the shared password and is not forced to change it.
    // At go-live set FORCE_PASSWORD_CHANGE=true in .env: new / generated / reset accounts must then change it at first login.
    'force_password_change' => (bool) env('FORCE_PASSWORD_CHANGE', false),

    // Pre-filled password for new / split accounts (and the default of a2:import). Kept out of the code on purpose:
    // set DEFAULT_ACCOUNT_PASSWORD in .env / on the server. Empty = the admin types one each time.
    'default_password' => (string) env('DEFAULT_ACCOUNT_PASSWORD', ''),
];
