<?php

use App\Support\Tail;

it('returns the last lines and how many there were', function () {
    $text = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 10)));

    $tail = Tail::lines($text, 3, 10_000);

    expect($tail['text'])->toBe("line 8\nline 9\nline 10")
        ->and($tail['total_lines'])->toBe(10)
        ->and($tail['returned_lines'])->toBe(3)
        ->and($tail['truncated'])->toBeTrue();
});

it('returns everything when the log is short', function () {
    $tail = Tail::lines("a\nb\nc\n", 100, 10_000);

    expect($tail)->toBe(['text' => "a\nb\nc", 'total_lines' => 3, 'returned_lines' => 3, 'truncated' => false]);
});

it('handles an empty log', function () {
    expect(Tail::lines('', 10, 100))->toBe(['text' => '', 'total_lines' => 0, 'returned_lines' => 0, 'truncated' => false])
        ->and(Tail::lines("\n\n", 10, 100)['total_lines'])->toBe(0);
});

it('cuts by size at a whole line, keeping the newest lines', function () {
    $text = implode("\n", array_map(fn ($i) => str_pad((string) $i, 20, '.'), range(1, 100)));

    $tail = Tail::lines($text, 100, 100);

    expect(strlen($tail['text']))->toBeLessThanOrEqual(100)
        ->and($tail['truncated'])->toBeTrue()
        ->and($tail['text'])->toEndWith(str_pad('100', 20, '.'))
        ->and($tail['text'])->toStartWith(str_pad((string) (100 - $tail['returned_lines'] + 1), 20, '.'));
});

it('always returns at least one line when asked for fewer', function () {
    expect(Tail::lines("a\nb", 0, 100)['returned_lines'])->toBe(1);
});

it('copes with a very large log without trouble', function () {
    $big = str_repeat("some log line with a fair amount of text in it\n", 70_000);

    $tail = Tail::lines($big, 100, 20_000);

    expect($tail['total_lines'])->toBe(70_000)->and($tail['returned_lines'])->toBe(100)->and(strlen($tail['text']))->toBeLessThanOrEqual(20_000);
});
