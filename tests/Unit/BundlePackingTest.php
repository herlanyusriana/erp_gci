<?php

use App\Support\BundlePacking;

it('derives packing qty by splitting the received qty evenly across bundles', function () {
    expect(BundlePacking::packingQty(20000, 10))->toBe(2000.0)
        ->and(BundlePacking::packingQty(1000, 3))->toBe(333.3333)
        ->and(BundlePacking::packingQty(1000, 0))->toBe(0.0)
        ->and(BundlePacking::packingQty(0, 5))->toBe(0.0);
});

it('numbers every bundle from one and gives each the same qty', function () {
    $bundles = BundlePacking::bundles(20000, 3);

    expect($bundles)->toHaveCount(3)
        ->and(array_column($bundles, 'bundle_no'))->toBe([1, 2, 3])
        ->and(array_column($bundles, 'qty'))->toBe([6666.6666, 6666.6666, 6666.6666]);
});

it('never returns a negative net requirement', function () {
    expect(BundlePacking::netRequirement(16000, 3000))->toBe(13000.0)
        ->and(BundlePacking::netRequirement(1000, 1000))->toBe(0.0)
        ->and(BundlePacking::netRequirement(1000, 4000))->toBe(0.0);
});

it('checks bundles with floor of net requirement divided by packing qty', function () {
    // Contoh acuan: kebutuhan bersih 13.000, packing 2.000 → 6 bundle.
    expect(BundlePacking::autoCheckCount(13000, 2000, 10))->toBe(6)
        ->and(BundlePacking::autoCheckCount(13000, 2000, 3))->toBe(3)
        ->and(BundlePacking::autoCheckCount(1999, 2000, 10))->toBe(0)
        ->and(BundlePacking::autoCheckCount(0, 2000, 10))->toBe(0)
        ->and(BundlePacking::autoCheckCount(-500, 2000, 10))->toBe(0);
});

it('plans the reference scenario with six bundles checked and manual qty added', function () {
    $plan = BundlePacking::plan(16000, 3000, 20000, 10, 500);

    expect($plan['net_requirement'])->toBe(13000.0)
        ->and($plan['packing_qty'])->toBe(2000.0)
        ->and($plan['bundle_qty'])->toBe(12000.0)
        ->and($plan['total_qty'])->toBe(12500.0)
        ->and($plan['exceeds'])->toBeFalse();

    $checked = array_values(array_filter($plan['bundles'], fn ($b) => $b['checked']));
    expect(array_column($checked, 'bundle_no'))->toBe([1, 2, 3, 4, 5, 6]);
});

it('flags a total issue above the net requirement and checks nothing when there is no need', function () {
    $over = BundlePacking::plan(16000, 3000, 20000, 10, 1500);
    expect($over['total_qty'])->toBe(13500.0)
        ->and($over['exceeds'])->toBeTrue();

    $none = BundlePacking::plan(16000, 16000, 20000, 10, 0);
    expect($none['net_requirement'])->toBe(0.0)
        ->and($none['bundle_qty'])->toBe(0.0)
        ->and(array_filter($none['bundles'], fn ($b) => $b['checked']))->toBe([]);
});
