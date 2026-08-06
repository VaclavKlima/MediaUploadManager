<?php

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

function uploadAtStatus(UploadStatus $status, ?User $owner = null): Upload
{
    $upload = Upload::factory()->for($owner ?? User::factory())->create();

    if ($status !== UploadStatus::Pending) {
        Upload::query()->whereKey($upload)->update(['status' => $status->value]);
        $upload->refresh();
    }

    return $upload;
}

function applySystemTransition(TransitionUploadStatus $action, Upload $upload, UploadStatus $target): Upload
{
    if ($target === UploadStatus::Failed) {
        return $action->failAsSystem($upload, 'transport_failed', 'Safe detail');
    }

    if ($upload->status === UploadStatus::Failed && $target === UploadStatus::Processing) {
        return $action->retryAsSystem($upload);
    }

    return $action->asSystem($upload, $target);
}

/** @return array<string, array{UploadStatus, UploadStatus}> */
function allowedUploadTransitions(): array
{
    return [
        'pending to uploading' => [UploadStatus::Pending, UploadStatus::Uploading],
        'pending to cancelled' => [UploadStatus::Pending, UploadStatus::Cancelled],
        'pending to expired' => [UploadStatus::Pending, UploadStatus::Expired],
        'uploading to paused' => [UploadStatus::Uploading, UploadStatus::Paused],
        'uploading to processing' => [UploadStatus::Uploading, UploadStatus::Processing],
        'uploading to cancelled' => [UploadStatus::Uploading, UploadStatus::Cancelled],
        'uploading to expired' => [UploadStatus::Uploading, UploadStatus::Expired],
        'uploading to failed' => [UploadStatus::Uploading, UploadStatus::Failed],
        'paused to uploading' => [UploadStatus::Paused, UploadStatus::Uploading],
        'paused to cancelled' => [UploadStatus::Paused, UploadStatus::Cancelled],
        'paused to expired' => [UploadStatus::Paused, UploadStatus::Expired],
        'paused to failed' => [UploadStatus::Paused, UploadStatus::Failed],
        'processing to completed' => [UploadStatus::Processing, UploadStatus::Completed],
        'processing to failed' => [UploadStatus::Processing, UploadStatus::Failed],
        'failed to processing by retry' => [UploadStatus::Failed, UploadStatus::Processing],
    ];
}

dataset('allowed upload transitions', allowedUploadTransitions());

dataset('forbidden upload transitions', function (): Generator {
    $allowedPairs = collect(allowedUploadTransitions());

    foreach (UploadStatus::cases() as $source) {
        foreach (UploadStatus::cases() as $target) {
            if ($source === $target) {
                continue;
            }

            $isAllowed = $allowedPairs->contains(
                fn (array $pair): bool => $pair[0] === $source && $pair[1] === $target,
            );

            if (! $isAllowed) {
                yield "{$source->value} to {$target->value}" => [$source, $target];
            }
        }
    }
});

it('applies every permitted system transition with compare-and-set', function (UploadStatus $source, UploadStatus $target) {
    Carbon::setTestNow('2026-08-06 12:00:00');
    $upload = uploadAtStatus($source);

    $transitioned = applySystemTransition(new TransitionUploadStatus, $upload, $target);

    expect($transitioned->status)->toBe($target)
        ->and($transitioned->last_activity_at?->toDateTimeString())->toBe('2026-08-06 12:00:00');
})->with('allowed upload transitions');

it('rejects every forbidden system transition', function (UploadStatus $source, UploadStatus $target) {
    $upload = uploadAtStatus($source);

    applySystemTransition(new TransitionUploadStatus, $upload, $target);
})->with('forbidden upload transitions')->throws(DomainException::class);

it('allows owners and administrators to pause or cancel permitted uploads', function (string $actorType, UploadStatus $source, UploadStatus $target) {
    $owner = User::factory()->create();
    $actor = $actorType === 'owner' ? $owner : User::factory()->administrator()->create();
    $upload = uploadAtStatus($source, $owner);

    $transitioned = (new TransitionUploadStatus)->asUser($upload, $target, $actor);

    expect($transitioned->status)->toBe($target);
})->with([
    'owner pauses' => ['owner', UploadStatus::Uploading, UploadStatus::Paused],
    'owner cancels' => ['owner', UploadStatus::Pending, UploadStatus::Cancelled],
    'administrator pauses' => ['administrator', UploadStatus::Uploading, UploadStatus::Paused],
    'administrator cancels' => ['administrator', UploadStatus::Paused, UploadStatus::Cancelled],
]);

it('rejects another owner and user attempts at system-only transitions', function (string $case) {
    $owner = User::factory()->create();
    $actor = $case === 'wrong owner' ? User::factory()->create() : $owner;
    $upload = uploadAtStatus(UploadStatus::Pending, $owner);
    $target = $case === 'wrong owner' ? UploadStatus::Cancelled : UploadStatus::Uploading;

    (new TransitionUploadStatus)->asUser($upload, $target, $actor);
})->with(['wrong owner', 'system transition'])->throws(AuthorizationException::class);

it('treats a repeated transition and a stale target-winning race as idempotent', function () {
    $action = new TransitionUploadStatus;
    $upload = Upload::factory()->create();
    $staleUpload = Upload::query()->findOrFail($upload->id);

    $firstResult = $action->asSystem($upload, UploadStatus::Uploading);
    $repeatedResult = $action->asSystem($firstResult, UploadStatus::Uploading);
    $staleResult = $action->asSystem($staleUpload, UploadStatus::Uploading);

    expect($repeatedResult->status)->toBe(UploadStatus::Uploading)
        ->and($staleResult->status)->toBe(UploadStatus::Uploading);
});

it('rejects a stale compare-and-set when a different target won', function () {
    $action = new TransitionUploadStatus;
    $upload = Upload::factory()->create();
    $staleUpload = Upload::query()->findOrFail($upload->id);

    $action->asSystem($upload, UploadStatus::Cancelled);
    $action->asSystem($staleUpload, UploadStatus::Uploading);
})->throws(DomainException::class);

it('normalizes safe failure data and isolates retry behavior', function () {
    Carbon::setTestNow('2026-08-06 12:00:00');
    $action = new TransitionUploadStatus;
    $upload = uploadAtStatus(UploadStatus::Processing);

    $failed = $action->failAsSystem(
        $upload,
        ' FFPROBE Timeout ',
        "  probe\nfailed  ".str_repeat('x', 600),
    );
    $firstFailedAt = $failed->failed_at?->toDateTimeString();
    $failureCode = $failed->error_code;
    $failureDetail = $failed->error_detail;

    Carbon::setTestNow('2026-08-06 13:00:00');
    $retried = $action->retryAsSystem($failed);

    expect($failureCode)->toBe('ffprobe_timeout')
        ->and($failureDetail)->not->toContain("\n")
        ->and(mb_strlen((string) $failureDetail))->toBeLessThanOrEqual(500)
        ->and($retried->status)->toBe(UploadStatus::Processing)
        ->and($retried->error_code)->toBeNull()
        ->and($retried->error_detail)->toBeNull()
        ->and($retried->failed_at?->toDateTimeString())->toBe($firstFailedAt)
        ->and($retried->processing_at?->toDateTimeString())->toBe('2026-08-06 13:00:00');
});

it('requires the dedicated retry and failure methods', function (string $case) {
    $action = new TransitionUploadStatus;

    if ($case === 'retry') {
        $action->asSystem(uploadAtStatus(UploadStatus::Failed), UploadStatus::Processing);

        return;
    }

    $action->asSystem(uploadAtStatus(UploadStatus::Processing), UploadStatus::Failed);
})->with(['retry', 'failure'])->throws(DomainException::class);

it('prevents direct model status changes', function () {
    $upload = Upload::factory()->create();
    $upload->status = UploadStatus::Uploading;
    $upload->save();
})->throws(DomainException::class);

it('reserves only remaining bytes for active statuses', function (UploadStatus $status, int $expected) {
    $upload = uploadAtStatus($status);
    Upload::query()->whereKey($upload)->update([
        'declared_size' => 10_000,
        'confirmed_offset' => 4_000,
    ]);
    $upload->refresh();

    expect($upload->reservesCapacity())->toBe($status->reservesCapacity())
        ->and($upload->reservedBytes()->value)->toBe($expected);
})->with([
    'pending' => [UploadStatus::Pending, 6_000],
    'uploading' => [UploadStatus::Uploading, 6_000],
    'paused' => [UploadStatus::Paused, 6_000],
    'processing' => [UploadStatus::Processing, 6_000],
    'completed' => [UploadStatus::Completed, 0],
    'failed' => [UploadStatus::Failed, 0],
    'cancelled' => [UploadStatus::Cancelled, 0],
    'expired' => [UploadStatus::Expired, 0],
]);
