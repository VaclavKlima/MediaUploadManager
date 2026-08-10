<?php

use App\Support\CanonicalJson;

it('compares JSON objects independently of key order while preserving value types and list order', function () {
    expect(CanonicalJson::equivalent(
        ['version' => 1, 'proof' => ['type' => 'inode', 'values' => [10, 20]]],
        ['proof' => ['values' => [10, 20], 'type' => 'inode'], 'version' => 1],
    ))->toBeTrue()
        ->and(CanonicalJson::equivalent(['version' => 1], ['version' => '1']))->toBeFalse()
        ->and(CanonicalJson::equivalent(['values' => [10, 20]], ['values' => [20, 10]]))->toBeFalse()
        ->and(CanonicalJson::equivalent(['value' => null], []))->toBeFalse();
});
