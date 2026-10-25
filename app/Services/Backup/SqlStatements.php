<?php

namespace App\Services\Backup;

use Generator;
use RuntimeException;

/**
 * A SQL dump read a statement at a time from a place in it — the app's own backups, and those of phpMyAdmin or
 * mysqldump: a statement ends at a ";" that is not inside quotes; "--" and "#" lines between statements are
 * comments. Each comes with where the file goes on after it, to go on from there in the next step.
 */
class SqlStatements
{
    /**
     * @param  resource  $file  opened for reading
     * @return Generator<int, array{sql: string, next: int}>
     */
    public static function from($file, int $offset): Generator
    {
        fseek($file, $offset);
        $buffer = '';
        $quote = null;
        $escaped = false;
        $position = $offset;

        while (($line = fgets($file)) !== false) {
            $start = $position;
            $position += strlen($line);

            // Between statements: a comment line, or nothing.
            if ($buffer === '' && $quote === null) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (stripos($trimmed, 'DELIMITER ') === 0) {
                    throw new RuntimeException(__('This file defines stored procedures or triggers (DELIMITER), which cannot be restored here.'));
                }
            }

            $length = strlen($line);
            $from = 0;

            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];

                if ($quote !== null) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\' && $quote !== '`') {
                        $escaped = true;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                } elseif ($char === ';') {
                    $sql = trim($buffer.substr($line, $from, $i - $from));
                    $buffer = '';
                    $from = $i + 1;

                    if ($sql !== '') {
                        yield ['sql' => $sql, 'next' => $start + $i + 1];
                    }
                }
            }

            $buffer .= substr($line, $from);

            // Only the end of a line left over: nothing begun.
            if ($quote === null && trim($buffer) === '') {
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            yield ['sql' => trim($buffer), 'next' => $position];
        }
    }
}
