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
            return [$parts[0], $count === 2 ? (strtoupper($parts[1]) ?: '(other)') : '(other)'];
        }

        $token = strtoupper($parts[$count - 2]);
        $prefix = implode('-', array_slice($parts, 0, $count - 2));

        return [$prefix, $token ?: '(other)'];
    }

    /** The trailing portion segment (e.g. "P3SP3"), or null when absent. */
    public static function portion(string $account): ?string
    {
        $parts = array_map('trim', explode('-', $account));

        return count($parts) >= 3 ? (end($parts) ?: null) : null;
    }
}
