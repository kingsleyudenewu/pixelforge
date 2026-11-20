<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic in integer nanodollars (1e-9 USD).
 *
 * Pixelforge prices in fractions of a cent and reports usage with 9 decimal places, so storing
 * whole nanodollars lets every amount and every daily total match theirs exactly, with no floats.
 */
final class Nanodollars
{
    public const int PER_DOLLAR = 1_000_000_000;

    private const int SCALE = 9;

    /**
     * Parse a decimal dollar string such as "12.50" or "0.373875000" into nanodollars.
     */
    public static function fromDecimal(string $amount): int
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', trim($amount), $matches) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a decimal amount.");
        }

        $fraction = rtrim($matches[3] ?? '', '0');

        if (strlen($fraction) > self::SCALE) {
            throw new InvalidArgumentException("[{$amount}] is more precise than a nanodollar.");
        }

        $nanos = (int) $matches[2] * self::PER_DOLLAR + (int) str_pad($fraction, self::SCALE, '0');

        return $matches[1] === '-' ? -$nanos : $nanos;
    }

    /**
     * Format nanodollars as a decimal dollar string with 9 decimal places, e.g. "0.373875000".
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $absolute = abs($nanos);

        return $sign.intdiv($absolute, self::PER_DOLLAR).'.'.str_pad((string) ($absolute % self::PER_DOLLAR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Format for people, e.g. "$0.4674". Rounds up so a quoted maximum is never understated.
     */
    public static function formatUsd(int $nanos, int $decimals = 4): string
    {
        $unit = 10 ** (self::SCALE - $decimals);
        $rounded = (int) ceil($nanos / $unit) * $unit;

        return '$'.substr(self::toDecimal($rounded), 0, -(self::SCALE - $decimals));
    }
}
