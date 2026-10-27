<?php

use App\Support\RowErrorKeys;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('it keys row errors by the stable uid instead of the payload index', function () {
    $items = [
        ['uid' => 7, 'part_id' => 1],
        ['uid' => 12, 'part_id' => null],
    ];

    $exception = ValidationException::withMessages([
        'items.1.part_id' => 'Part tidak boleh kosong.',
    ]);

    try {
        RowErrorKeys::remap($exception, $items);
        $this->fail('ValidationException should be rethrown.');
    } catch (ValidationException $rethrow) {
        expect(array_keys($rethrow->errors()))->toBe(['items.12.part_id']);
    }
});

test('it leaves keys without a matching uid untouched', function () {
    $items = [
        ['uid' => 7, 'part_id' => 1],
    ];

    $exception = ValidationException::withMessages([
        'items.0.part_id' => 'Part tidak boleh kosong.',
        'po_no' => 'Nomor PO wajib diisi.',
    ]);

    try {
        RowErrorKeys::remap($exception, $items);
        $this->fail('ValidationException should be rethrown.');
    } catch (ValidationException $rethrow) {
        expect(array_keys($rethrow->errors()))->toBe(['items.7.part_id', 'po_no']);
    }
});

test('it rethrows the original errors when no row carries a uid', function () {
    $exception = ValidationException::withMessages([
        'items.0.part_id' => 'Part tidak boleh kosong.',
    ]);

    try {
        RowErrorKeys::remap($exception, [['part_id' => 1]]);
        $this->fail('ValidationException should be rethrown.');
    } catch (ValidationException $rethrow) {
        expect(array_keys($rethrow->errors()))->toBe(['items.0.part_id']);
    }
});
