/**
 * Display helpers for money. The server sends exact decimal dollar strings (9 decimal places)
 * and does all the arithmetic that matters; these only parse and format for people.
 */
const NANOS_PER_DOLLAR = 1_000_000_000;

export type Rounding = 'up' | 'down' | 'nearest';

/** Parse a decimal dollar string such as "0.078512500" into whole nanodollars. */
export function toNanos(amount: string): number {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = (negative ? amount.slice(1) : amount).split(
        '.',
    );
    const nanos =
        Number(whole) * NANOS_PER_DOLLAR +
        Number(fraction.padEnd(9, '0').slice(0, 9));

    return negative ? -nanos : nanos;
}

/**
 * Format nanodollars as US dollars, e.g. "$0.0785". Round a quoted maximum up and a balance
 * down, so neither is ever overstated in the user's favour.
 */
export function formatUsd(
    nanos: number,
    {
        decimals = 4,
        rounding = 'nearest',
    }: { decimals?: number; rounding?: Rounding } = {},
): string {
    const unit = 10 ** (9 - decimals);
    const round =
        rounding === 'up'
            ? Math.ceil
            : rounding === 'down'
              ? Math.floor
              : Math.round;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format((round(nanos / unit) * unit) / NANOS_PER_DOLLAR);
}

/** The full-precision amount without trailing zeros, e.g. "$0.0785125". */
export function formatUsdExact(amount: string): string {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = (negative ? amount.slice(1) : amount).split(
        '.',
    );
    const trimmedFraction = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `${negative ? '-' : ''}$${Number(whole).toLocaleString('en-US')}.${trimmedFraction}`;
}
