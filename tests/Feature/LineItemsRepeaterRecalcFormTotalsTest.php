<?php

use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

function recalcFormTotalsInvoke(array $rows): array
{
    $form = ['sub_total' => null, 'tax' => null, 'total' => null];

    $get = function (string $path) use ($rows) {
        if ($path === '../') {
            return $rows;
        }

        return null;
    };

    $set = function (string $path, $value) use (&$form): void {
        $map = [
            '../../sub_total' => 'sub_total',
            '../../tax' => 'tax',
            '../../total' => 'total',
        ];

        if (isset($map[$path])) {
            $form[$map[$path]] = $value;
        }
    };

    $ref = new ReflectionMethod(LineItemsRepeater::class, 'recalcFormTotals');
    $ref->setAccessible(true);
    $ref->invokeArgs(null, [$get, $set]);

    return $form;
}

it('declares recalcFormTotals as a private static helper accepting get/set', function () {
    $ref = new ReflectionMethod(LineItemsRepeater::class, 'recalcFormTotals');

    expect($ref->isStatic())->toBeTrue();
    expect($ref->isPrivate())->toBeTrue();

    $params = $ref->getParameters();
    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('get');
    expect($params[1]->getName())->toBe('set');
});

it('sums each row amount into sub_total, each tax_amount into tax, and writes total = sub_total + tax', function () {
    $form = recalcFormTotalsInvoke([
        'uuid-1' => ['amount' => 100, 'tax_amount' => 10],
        'uuid-2' => ['amount' => 250, 'tax_amount' => 25],
        'uuid-3' => ['amount' => 50, 'tax_amount' => 5],
    ]);

    expect($form['sub_total'])->toBe(400.0);
    expect($form['tax'])->toBe(40.0);
    expect($form['total'])->toBe(440.0);
});

it('writes zeros to all three form fields when the rows array is empty', function () {
    $form = recalcFormTotalsInvoke([]);

    expect($form['sub_total'])->toBe(0.0);
    expect($form['tax'])->toBe(0.0);
    expect($form['total'])->toBe(0.0);
});

it('treats missing tax_amount keys as zero (Deal rows have no tax_amount column)', function () {
    $form = recalcFormTotalsInvoke([
        'uuid-1' => ['amount' => 75],
        'uuid-2' => ['amount' => 125],
    ]);

    expect($form['sub_total'])->toBe(200.0);
    expect($form['tax'])->toBe(0.0);
    expect($form['total'])->toBe(200.0);
});

it('casts row values to float defensively (handles numeric strings and nulls)', function () {
    $form = recalcFormTotalsInvoke([
        'uuid-1' => ['amount' => '100.50', 'tax_amount' => '10.05'],
        'uuid-2' => ['amount' => null, 'tax_amount' => null],
        'uuid-3' => ['amount' => 49.5, 'tax_amount' => 4.95],
    ]);

    expect($form['sub_total'])->toBe(150.0);
    expect($form['tax'])->toBe(15.0);
    expect($form['total'])->toBe(165.0);
});

it('does NOT include discount or adjustment in the total (matches core LiveQuoteItems:155-161)', function () {
    $form = recalcFormTotalsInvoke([
        'uuid-1' => ['amount' => 100, 'tax_amount' => 10, 'discount' => 50, 'adjustment' => 20],
    ]);

    // Discount/adjustment in the row dict are intentionally ignored — they
    // do not exist as per-row fields anyway; they are applied server-side at
    // save by the various *Service::create/update methods.
    expect($form['sub_total'])->toBe(100.0);
    expect($form['tax'])->toBe(10.0);
    expect($form['total'])->toBe(110.0);
});

it('handles a null result from $get gracefully (no rows yet hydrated)', function () {
    $form = ['sub_total' => null, 'tax' => null, 'total' => null];

    $get = fn (string $path) => null;
    $set = function (string $path, $value) use (&$form): void {
        $map = [
            '../../sub_total' => 'sub_total',
            '../../tax' => 'tax',
            '../../total' => 'total',
        ];
        if (isset($map[$path])) {
            $form[$map[$path]] = $value;
        }
    };

    $ref = new ReflectionMethod(LineItemsRepeater::class, 'recalcFormTotals');
    $ref->setAccessible(true);
    $ref->invokeArgs(null, [$get, $set]);

    expect($form['sub_total'])->toBe(0.0);
    expect($form['tax'])->toBe(0.0);
    expect($form['total'])->toBe(0.0);
});
