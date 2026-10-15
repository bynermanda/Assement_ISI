<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection;
use PDO;

/**
 * PostgreSQL behind the Supabase pooler (transaction mode) with emulated prepares (config/database.php).
 *
 * With emulation the values are written into the SQL text. Laravel turns booleans into 1/0, which a boolean
 * column refuses ("boolean but expression is of type integer"), and an unquoted integer compared with a text
 * column (NIK) fails too. Sending both as quoted text lets PostgreSQL pick the type, as with real prepares.
 */
class PooledPostgresConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings)
    {
        $emulated = (bool) ($this->getConfig('options')[PDO::ATTR_EMULATE_PREPARES] ?? false);

        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            } elseif ($emulated && is_int($value)) {
                $bindings[$key] = (string) $value;
            }
        }

        return parent::prepareBindings($bindings);
    }
}
