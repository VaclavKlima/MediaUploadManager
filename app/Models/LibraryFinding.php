<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LibraryFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $library_scan_id
 * @property int|null $media_item_id
 * @property int|null $media_file_id
 * @property int|null $paired_missing_finding_id
 * @property string $disk_id
 * @property string $relative_path
 * @property string $path_key
 * @property string $source_folder
 * @property string $source_filename
 * @property int|null $size_bytes
 * @property int|null $device_id
 * @property int|null $inode_id
 * @property string $kind
 * @property string $status
 * @property string|null $identity_source
 * @property array<string, mixed>|null $identity_snapshot
 * @property int|null $tmdb_id
 * @property string|null $imdb_id
 * @property string|null $destination_relative_path
 * @property array<string, mixed>|null $operation_claim
 * @property string|null $error_detail
 * @property string|null $resolution
 * @property CarbonInterface|null $resolved_at
 */
#[Fillable([
    'library_scan_id',
    'media_item_id',
    'media_file_id',
    'paired_missing_finding_id',
    'disk_id',
    'relative_path',
    'source_folder',
    'source_filename',
    'size_bytes',
    'device_id',
    'inode_id',
    'kind',
    'status',
    'identity_source',
    'identity_snapshot',
    'tmdb_id',
    'imdb_id',
    'destination_relative_path',
    'operation_claim',
    'error_detail',
    'resolution',
    'resolved_at',
])]
class LibraryFinding extends Model
{
    /** @use HasFactory<LibraryFindingFactory> */
    use HasFactory;

    public static function pathKey(string $diskId, string $relativePath): string
    {
        return hash('sha256', $diskId."\0".$relativePath);
    }

    /** @return BelongsTo<LibraryScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(LibraryScan::class, 'library_scan_id');
    }

    /** @return BelongsTo<MediaItem, $this> */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return BelongsTo<LibraryFinding, $this> */
    public function pairedMissingFinding(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_missing_finding_id');
    }

    /** @return HasMany<FolderCleanup, $this> */
    public function folderCleanups(): HasMany
    {
        return $this->hasMany(FolderCleanup::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $finding): void {
            $finding->path_key = self::pathKey($finding->disk_id, $finding->relative_path);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_claim' => 'array',
            'identity_snapshot' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
