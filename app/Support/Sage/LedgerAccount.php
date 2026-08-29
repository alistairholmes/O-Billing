<?php

declare(strict_types=1);

namespace App\Support\Sage;

/**
 * Splits a Sage debtor-ledger account code `{STAND}-{TYPE}-{portion}` into its
 * parts. The stand itself may contain hyphens (e.g. `BGATWN-345`, `MK010-W24`),
 * so the service token is the SECOND-TO-LAST segment and the stand is
 * everything before it; the last segment is the portion. Shared by the
 * importers and the Sage posting writers so every code splits identically.
 */
final class LedgerAccount
{
    /**
     * @return array{0: string, 1: string} [stand, token] — token is "(other)"
     *                                     when the code has no recognisable one
     */
    public static function split(string $account): array
    {
        $parts = array_map('trim', explode('-', $account));
        $count = count($parts);

        if ($count < 3) {
            // No portion suffix: best effort {stand}-{token}, or just {stand}.
            // A two-segment code is often {stand}-{portion} with no service at
            // all (Zaka has ~2,000 of these), and reading the portion as a
            // service invents ledger service types like "LEDGER-P3SP3".
            $token = $count === 2 ? strtoupper($parts[1]) : '';

            return [$parts[0], self::isPortion($token) ? '(other)' : ($token ?: '(other)')];
        }

        $token = strtoupper($parts[$count - 2]);
        $prefix = implode('-', array_slice($parts, 0, $count - 2));

        return [$prefix, self::isPortion($token) ? '(other)' : ($token ?: '(other)')];
    }

    /** Is this segment a project/portion marker (P3SP3, (P6SP1)) rather than a service? */
    private static function isPortion(string $segment): bool
    {
        return preg_match('/^\(?P\d+(SP\d+)?\)?$/i', $segment) === 1;
    }

    /** The trailing portion segment (e.g. "P3SP3"), or null when absent. */
    public static function portion(string $account): ?string
    {
        $parts = array_map('trim', explode('-', $account));

        return count($parts) >= 3 ? (end($parts) ?: null) : null;
    }
}
