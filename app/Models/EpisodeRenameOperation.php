<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $series_episode_id
 * @property int $actor_user_id
 * @property int|null $source_media_file_id
 * @property int|null $source_upload_id
 * @property string|null $old_custom_name
 * @property string|null $new_custom_name
 * @property string|null $disk_id
 * @property string|null $source_relative_path
 * @property string|null $destination_relative_path
 * @property int|null $size_bytes
 * @property int|null $device_id
 * @property int|null $inode_id
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property CarbonInterface $claimed_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 */
#[Fillable([
    'series_episode_id', 'actor_user_id', 'source_media_file_id', 'source_upload_id',
    'old_custom_name', 'new_custom_name', 'disk_id', 'source_relative_path',
    'destination_relative_path', 'size_bytes', 'device_id', 'inode_id', 'status',
    'error_code', 'error_detail', 'claimed_at', 'completed_at', 'failed_at',
])]
class EpisodeRenameOperation extends Model
{
    /** @return BelongsTo<SeriesEpisode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(SeriesEpisode::class, 'series_episode_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function sourceMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'source_media_file_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
