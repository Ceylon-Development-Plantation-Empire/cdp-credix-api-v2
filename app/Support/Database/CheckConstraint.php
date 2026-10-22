<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints for the migrations.
 *
 * Laravel 12's schema builder has no CHECK support, so the constraint is added with one ALTER
 * statement per table. It runs on MySQL 8.0.16+ only (the production engine); the SQLite test
 * database skips it, which is safe because the services validate the same rules first. The
 * database constraint is the last line of defence when a bug or a manual SQL edit slips past them.
 */
final class CheckConstraint
{
    /**
     * Add named CHECK constraints to a table.
     *
     * @param  string  $table  Table name.
     * @param  array<string, string>  $checks  Constraint name => SQL boolean expression (column names only, no user input).
     */
    public static function add(string $table, array $checks): void
    {
        if (DB::getDriverName() !== 'mysql' || $checks === []) {
            return;
        }

        $clauses = [];
        foreach ($checks as $name => $expression) {
            $clauses[] = "ADD CONSTRAINT `{$name}` CHECK ({$expression})";
        }

        // Raw SQL is unavoidable here (no builder support); names and expressions are literals from migrations.
        DB::statement("ALTER TABLE `{$table}` ".implode(', ', $clauses));
    }
}
