<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;

/**
 * MySQL schema grammar that also works on TiDB.
 *
 * Laravel adds all new columns of a Schema::table() call in one
 * "ALTER TABLE ... ADD a ..., ADD b ... AFTER a" statement. MySQL accepts an
 * AFTER that names a column added earlier in the same statement; TiDB does not
 * ("1054 Unknown column"). Sending one ALTER TABLE per column works on both
 * and keeps the same column order.
 */
class MySqlSchemaGrammar extends MySqlGrammar
{
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $statements = array_map(
            fn (string $column) => 'alter table '.$table.' '.$column,
            $this->prefixArray('add', $this->getColumns($blueprint))
        );

        return array_values(array_merge(
            $statements,
            $this->compileAutoIncrementStartingValues($blueprint)
        ));
    }
}
