<?php

namespace App\Actions;

use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\User;
use App\Support\Media\FolderCleanupProcessor;
use JsonException;
use RuntimeException;

final readonly class PreviewFolderCleanup
{
    public function __construct(private FolderCleanupProcessor $processor) {}

    /** @throws JsonException|RuntimeException */
    public function execute(LibraryFinding $finding, User $actor): FolderCleanup
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may preview folder cleanup.');
        }

        if ($finding->resolved_at === null || ! in_array($finding->resolution, ['imported', 'deleted', 'relocated'], true)) {
            throw new RuntimeException('Resolve the discovered file before cleaning its old folder.');
        }

        $preview = $this->processor->preview($finding->disk_id, $finding->source_folder);
        $encoded = json_encode($preview['entries'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return FolderCleanup::query()->create([
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'disk_id' => $finding->disk_id,
            'relative_folder' => $preview['relative_folder'],
            'status' => 'previewed',
            'manifest' => $preview['entries'],
            'manifest_hash' => hash('sha256', $encoded),
            'file_count' => $preview['file_count'],
            'total_size_bytes' => $preview['total_size_bytes'],
        ]);
    }
}
