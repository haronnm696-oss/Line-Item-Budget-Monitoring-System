<?php

namespace Tests\Feature;

use App\Database\MySqlSchemaGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Tests\TestCase;

/**
 * TiDB rejects an ALTER TABLE whose AFTER names a column added in the same
 * statement, so MySQL connections add one column per statement.
 */
class TidbSchemaGrammarTest extends TestCase
{
    public function test_each_added_column_gets_its_own_alter_statement(): void
    {
        $blueprint = new Blueprint('projects', function (Blueprint $table) {
            $table->decimal('first_share', 15, 2)->default(0)->after('total_approved_budget');
            $table->decimal('second_share', 15, 2)->default(0)->after('first_share');
        });

        $statements = (new MySqlSchemaGrammar)->compileAdd($blueprint, new Fluent());

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('alter table `projects` add `first_share`', $statements[0]);
        $this->assertStringContainsString('after `total_approved_budget`', $statements[0]);
        $this->assertStringStartsWith('alter table `projects` add `second_share`', $statements[1]);
        $this->assertStringContainsString('after `first_share`', $statements[1]);
    }

    public function test_mysql_connections_use_the_tidb_safe_grammar(): void
    {
        // Building the connection does not open a socket (PDO is lazy).
        $connection = DB::connection('mysql');

        $this->assertInstanceOf(\App\Database\MySqlConnection::class, $connection);
        $this->assertInstanceOf(MySqlSchemaGrammar::class, $connection->getSchemaBuilder()->getConnection()->getSchemaGrammar());

        DB::purge('mysql');
    }
}
