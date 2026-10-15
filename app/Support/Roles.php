<?php

namespace App\Support;

/**
 * Only two kinds of accounts now:
 *  - admin    : HRD (master data, accounts, guidelines, manual assessments)
 *  - reviewer : a real person with a grade. What they may do (Penilai 1 and/or Penilai 2)
 *               follows from their grade, see App\Support\ReviewRules.
 */
final class Roles
{
    public const ADMIN    = 'admin';
    public const REVIEWER = 'reviewer';
    /** Assessment 2: one account per person (employee, TKA or outsider) who assesses or approves leaders. */
    public const ASSESSOR = 'assessor';

    /** Old values, kept only so old accounts can be recognised and removed. */
    public const LEGACY = ['assistant', 'manager', 'approver'];

    public const LABELS = [
        self::ADMIN    => 'Admin (HRD)',
        self::REVIEWER => 'Akun departemen (A1)',
        self::ASSESSOR => 'Akun pribadi (A2)',
    ];

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $role): string
    {
        return self::LABELS[$role] ?? ucfirst((string) $role);
    }
}
