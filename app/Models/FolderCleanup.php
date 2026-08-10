<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\FolderCleanupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $library_finding_id
 * @property string $disk_id
 * @property string $relative_folder
 * @property string $status
 * @property list<array<string, mixed>> $manifest
 * @property string $manifest_hash
 * @property int $file_count
 * @property int $total_size_bytes
 * @property string|null $error_detail
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable([
    'user_id',
    'library_finding_id',
    'disk_id',
    'relative_folder',
    'status',
    'manifest',
    'manifest_hash',
    'file_count',
    'total_size_bytes',
    'error_detail',
    'confirmed_at',
    'completed_at',
])]
class FolderCleanup extends Model
{
    /** @use HasFactory<FolderCleanupFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LibraryFinding, $this> */
    public function libraryFinding(): BelongsTo
    {
        return $this->belongsTo(LibraryFinding::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
