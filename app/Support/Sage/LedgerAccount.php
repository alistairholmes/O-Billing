<?php

declare(strict_types=1);

namespace App\Support\Sage;

/**
 * Splits a Sage debtor-ledger account code `{STAND}-{TYPE}-{portion}` into its
 * parts. The stand itself may contain hyphens (e.g. `BGATWN-345`, `MK010-W24`),
 * so the service token is the SECOND-TO-LAST segment and the stand is
 * everything before it; the last segment is the portion. Shared by the
 * importers and the Sage posting writers so every code splits identically.
 *
 * Codes are typed by hand into Sage, so a handful in every council are
 * malformed. Three defects are common enough to recover from rather than
 * discard, because each still names its service unambiguously:
 *
 *   DWA026-DEV-A026-      a trailing separator, shifting every segment along
 *   ESTV006-DEV=P3SP3     "=" typed instead of "-" before the portion
 *   MNH170-S/EXT/P6SP1    "/" typed instead of "-" before the portion
 *
 * What cannot be recovered is a code whose service segment holds a number
 * (`BAN-013-P3SP3`) — there is no service named "013", and guessing one would
 * invent a billable. Those become "(other)", the same bucket as a code with no
 * service segment at all.
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

        // A trailing separator leaves an empty segment that would otherwise be
        // read as the portion, pushing the real service out of position.
        while (count($parts) > 1 && end($parts) === '') {
            array_pop($parts);
        }

        $count = count($parts);

        if ($count < 3) {
            // No portion suffix: best effort {stand}-{token}, or just {stand}.
            // A two-segment code is often {stand}-{portion} with no service at
            // all (Zaka has ~2,000 of these), and reading the portion as a
            // service invents ledger service types like "LEDGER-P3SP3".
            $token = $count === 2 ? strtoupper($parts[1]) : '';

            return [$parts[0], self::normalise($token)];
        }

        $token = strtoupper($parts[$count - 2]);
        $prefix = implode('-', array_slice($parts, 0, $count - 2));

        return [$prefix, self::normalise($token)];
    }

    /**
     * Reduce a raw segment to a service token, or "(other)" when it names no
     * service. Runs after the segment has been picked, so both the two- and
     * three-segment paths get the same treatment.
     */
    private static function normalise(string $token): string
    {
        // "DEV=P3SP3" / "S/EXT/P6SP1" — the portion ran into the service because
        // the separator before it was mistyped. The service is what precedes it.
        $token = (string) preg_replace('#[=/]\(?P\d+(SP\d+)?\)?$#i', '', $token);

        if ($token === '' || self::isPortion($token)) {
            return '(other)';
        }

        // A service token names a service, so it has to contain a word. Purely
        // numeric segments ("013", "807") are stand sub-numbers that landed in
        // the service position; "A026" is an account fragment. Requiring two
        // consecutive letters keeps every real token — DEV, LEA, S/EXT, AS —
        // and admits none of these.
        if (preg_match('/[A-Z]{2}/', $token) !== 1) {
            return '(other)';
        }

        return $token;
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
