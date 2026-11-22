<?php

namespace App\Support;

/** The end of a long text: the last lines, capped in size. A system log can be megabytes. */
final class Tail
{
    /**
     * @return array{text: string, total_lines: int, returned_lines: int, truncated: bool}
     */
    public static function lines(string $text, int $lines, int $maxBytes): array
    {
        $text = rtrim($text, "\r\n");

        if ($text === '') {
            return ['text' => '', 'total_lines' => 0, 'returned_lines' => 0, 'truncated' => false];
        }

        $all = explode("\n", $text);
        $total = count($all);
        $kept = array_slice($all, -max(1, $lines));
        $out = implode("\n", $kept);
        $cutBySize = false;

        if (strlen($out) > $maxBytes) {
            $out = substr($out, -$maxBytes);
            // Start at a whole line, not in the middle of one.
            $firstBreak = strpos($out, "\n");
            $out = $firstBreak === false ? $out : substr($out, $firstBreak + 1);
            $cutBySize = true;
        }

        return [
            'text' => $out,
            'total_lines' => $total,
            'returned_lines' => $out === '' ? 0 : substr_count($out, "\n") + 1,
            'truncated' => $cutBySize || count($kept) < $total,
        ];
    }
}
