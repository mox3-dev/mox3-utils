<?php

namespace Mox3\Utils\DbSync;

use InvalidArgumentException;

final class DatabaseTargets
{
    /**
     * Parse the repeatable/comma-separated `--databases` values into ordered
     * [sourceDb, targetDb] pairs. Each entry is either "db" (target takes the
     * same name) or "source:target" (rename the target). Blank entries are
     * skipped; an entry with an empty source name is rejected. Returns [] when
     * nothing was provided so the caller can fall back to single-DB behavior.
     *
     * @param  array<int, string|null>  $rawEntries
     * @return list<array{0:string, 1:string}>
     */
    public static function parse(array $rawEntries): array
    {
        $pairs = [];

        foreach ($rawEntries as $raw) {
            foreach (explode(',', (string) $raw) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }

                [$src, $tgt] = array_pad(explode(':', $entry, 2), 2, null);
                $src = trim((string) $src);

                if ($src === '') {
                    throw new InvalidArgumentException(
                        "Invalid --databases entry '{$entry}': source database name is empty."
                    );
                }

                $tgt = ($tgt !== null && trim($tgt) !== '') ? trim($tgt) : $src;
                $pairs[] = [$src, $tgt];
            }
        }

        return $pairs;
    }
}
