<?php

use App\Services\ServerAvatar\TokenNormalizer;

it('strips the Bearer prefix, quotes and whitespace that people paste along with a token', function (string $pasted) {
    expect(TokenNormalizer::normalize($pasted))->toBe('abc123DEF456');
})->with([
    'plain' => ['abc123DEF456'],
    'bearer' => ['Bearer abc123DEF456'],
    'lowercase bearer' => ['bearer abc123DEF456'],
    'extra spaces' => ['  Bearer    abc123DEF456  '],
    'newline' => ["Bearer abc123DEF456\n"],
    'double quotes' => ['"abc123DEF456"'],
    'quotes and bearer' => ['"Bearer abc123DEF456"'],
    'backticks' => ['`abc123DEF456`'],
]);

it('leaves a token that merely contains the word alone', function () {
    expect(TokenNormalizer::normalize('bearerlike123'))->toBe('bearerlike123');
});

it('returns an empty string for blank input', function (string $blank) {
    expect(TokenNormalizer::normalize($blank))->toBe('');
})->with(['', '   ', "\n\t", 'Bearer ', '""']);
