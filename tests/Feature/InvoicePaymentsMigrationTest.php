<?php

use Illuminate\Support\Facades\Schema;

/**
 * Executes the shipped `create_laravel_crm_invoice_payments_table` stub the
 * way a host's `migrate` does — against a database that does *not* already
 * have the table.
 *
 * The install/update command tests only assert the stub is published and that
 * `migrate` exits zero; they run against a harness schema where
 * `crm_invoice_payments` already exists, so the stub's hasTable() guard
 * returns before a single line of the CREATE ever runs. That let a fatal
 * "Undefined variable $prefix" inside the Schema::create closure ship.
 */
function invoicePaymentsMigration(): object
{
    return require __DIR__ . '/../../database/migrations/create_laravel_crm_invoice_payments_table.php.stub';
}

it('creates the invoice payments table from the published stub', function () {
    $prefix = config('laravel-crm.db_table_prefix');
    $table = $prefix . 'invoice_payments';

    Schema::dropIfExists($table);
    expect(Schema::hasTable($table))->toBeFalse();

    invoicePaymentsMigration()->up();

    expect(Schema::hasTable($table))->toBeTrue();
    expect(Schema::hasColumns($table, [
        'id',
        'external_id',
        'team_id',
        'invoice_id',
        'amount',
        'paid_at',
        'user_created_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});

it('is a no-op when the invoice payments table already exists', function () {
    $table = config('laravel-crm.db_table_prefix') . 'invoice_payments';

    expect(Schema::hasTable($table))->toBeTrue();

    invoicePaymentsMigration()->up();

    expect(Schema::hasTable($table))->toBeTrue();
});

it('drops the invoice payments table on rollback', function () {
    $table = config('laravel-crm.db_table_prefix') . 'invoice_payments';

    invoicePaymentsMigration()->down();

    expect(Schema::hasTable($table))->toBeFalse();
});
