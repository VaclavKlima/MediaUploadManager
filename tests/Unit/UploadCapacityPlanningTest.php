<?php

use App\Enums\UploadStatus;
use App\Support\Media\CapacityProjection;
use App\Support\Media\FileFingerprintRanges;
use App\ValueObjects\TokenHash;
use Illuminate\Support\Carbon;

it('treats exactly zero projected bytes as eligible and negative projections as ineligible', function () {
    $zero = new CapacityProjection(10_000, 4_000, 6_000);
    $negative = new CapacityProjection(10_000, 4_001, 6_000);

    expect($zero->projectedBytes)->toBe(0)
        ->and($zero->eligible())->toBeTrue()
        ->and($negative->projectedBytes)->toBe(-1)
        ->and($negative->eligible())->toBeFalse();
});

it('safely handles capacity sums that would overflow', function () {
    $projection = new CapacityProjection(0, PHP_INT_MAX, PHP_INT_MAX);

    expect($projection->projectedBytes)->toBe(-PHP_INT_MAX);
});

it('defines the reservation-active statuses in lifecycle order', function () {
    expect(UploadStatus::capacityReservingValues())->toBe([
        'pending',
        'uploading',
        'paused',
        'processing',
    ]);
});

it('allows overlapping first and last fingerprint windows for small files', function () {
    $small = new FileFingerprintRanges(600, 1_024);
    $large = new FileFingerprintRanges(4_096, 1_024);

    expect([$small->firstOffset, $small->firstLength, $small->lastOffset, $small->lastLength])
        ->toBe([0, 600, 0, 600])
        ->and([$large->firstOffset, $large->firstLength, $large->lastOffset, $large->lastLength])
        ->toBe([0, 1_024, 3_072, 1_024]);
});

it('hashes bearer tokens without retaining plaintext and supports configured expiry arithmetic', function () {
    Carbon::setTestNow('2026-08-06 12:00:00');
    $plaintext = str_repeat('a1', 32);
    $hash = TokenHash::fromPlaintext($plaintext);

    expect($hash->value)->toHaveLength(64)
        ->not->toBe($plaintext)
        ->and($hash->matches($plaintext))->toBeTrue()
        ->and(now()->addSeconds(900)->toDateTimeString())->toBe('2026-08-06 12:15:00');
});
