<?php

namespace App\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;

/**
 * MySQL connection that builds schema changes with a grammar that works on
 * both MySQL and TiDB. Registered in AppServiceProvider::register().
 */
class MySqlConnection extends BaseMySqlConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new MySqlSchemaGrammar);
    }
}
