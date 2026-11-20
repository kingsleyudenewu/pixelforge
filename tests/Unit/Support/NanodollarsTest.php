<?php

use App\Support\Nanodollars;

test('decimal dollar strings convert to exact nanodollars', function (string $decimal, int $nanos) {
    expect(Nanodollars::fromDecimal($decimal))->toBe($nanos);
})->with([
    'whole dollars' => ['5', 5_000_000_000],
    'per-million rate' => ['12.50', 12_500_000_000],
    'nine decimal places' => ['0.373875000', 373_875_000],
    'single nanodollar' => ['0.000000001', 1],
    'trailing zeros beyond nine places' => ['1.0000000000', 1_000_000_000],
    'negative amount' => ['-0.25', -250_000_000],
]);

test('nanodollars format as nine-decimal dollar strings', function (int $nanos, string $decimal) {
    expect(Nanodollars::toDecimal($nanos))->toBe($decimal);
})->with([
    'zero' => [0, '0.000000000'],
    'sub-cent' => [169_080_000, '0.169080000'],
    'dollars and fractions' => [10_467_343_750, '10.467343750'],
    'negative' => [-1, '-0.000000001'],
]);

test('amounts more precise than a nanodollar are rejected', function () {
    Nanodollars::fromDecimal('0.0000000001');
})->throws(InvalidArgumentException::class);

test('non-numeric amounts are rejected', function () {
    Nanodollars::fromDecimal('1,000.00');
})->throws(InvalidArgumentException::class);

test('display amounts round up so a quoted maximum is never understated', function (int $nanos, string $formatted) {
    expect(Nanodollars::formatUsd($nanos))->toBe($formatted);
})->with([
    'rounds a fraction up' => [467_343_750, '$0.4674'],
    'exact at four decimals' => [1_000_000_000, '$1.0000'],
]);
