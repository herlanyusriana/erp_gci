<?php

use App\Support\DocumentNumber;
use Illuminate\Database\QueryException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Tiru QueryException PostgreSQL untuk pelanggaran unique index.
 */
function documentNumberUniqueViolation(string $constraint): QueryException
{
    $previous = new PDOException(sprintf(
        'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "%s"',
        $constraint,
    ), 23505);

    return new QueryException('pgsql', 'insert into "documents" ("no") values (?)', ['X'], $previous);
}

test('it returns the callback result without retrying when the insert succeeds', function () {
    $calls = 0;

    $result = DocumentNumber::retry(function () use (&$calls): string {
        $calls++;

        return 'WO-2601-0001';
    }, 'work_orders_wo_no_unique');

    expect($result)->toBe('WO-2601-0001')
        ->and($calls)->toBe(1);
});

test('it retries when the document number constraint is violated', function () {
    $calls = 0;

    $result = DocumentNumber::retry(function () use (&$calls): string {
        $calls++;

        if ($calls < 3) {
            throw documentNumberUniqueViolation('work_orders_wo_no_unique');
        }

        return 'WO-2601-0003';
    }, 'work_orders_wo_no_unique');

    expect($result)->toBe('WO-2601-0003')
        ->and($calls)->toBe(3);
});

test('it stops after three attempts and rethrows the last violation', function () {
    $calls = 0;

    $run = function () use (&$calls): void {
        DocumentNumber::retry(function () use (&$calls): never {
            $calls++;

            throw documentNumberUniqueViolation('work_orders_wo_no_unique');
        }, 'work_orders_wo_no_unique');
    };

    expect($run)->toThrow(QueryException::class)
        ->and($calls)->toBe(3);
});

test('it rethrows a unique violation on another constraint without retrying', function () {
    $calls = 0;

    $run = function () use (&$calls): void {
        DocumentNumber::retry(function () use (&$calls): never {
            $calls++;

            throw documentNumberUniqueViolation('material_issues_idempotency_key_unique');
        }, 'material_issues_issue_no_unique');
    };

    expect($run)->toThrow(QueryException::class)
        ->and($calls)->toBe(1);
});

test('it rethrows a non unique database error without retrying', function () {
    $calls = 0;

    $run = function () use (&$calls): void {
        DocumentNumber::retry(function () use (&$calls): never {
            $calls++;

            $previous = new PDOException('SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update violates foreign key constraint "documents_part_id_foreign"', 23503);

            throw new QueryException('pgsql', 'insert into "documents" ("part_id") values (?)', [99], $previous);
        }, 'work_orders_wo_no_unique');
    };

    expect($run)->toThrow(QueryException::class)
        ->and($calls)->toBe(1);
});
