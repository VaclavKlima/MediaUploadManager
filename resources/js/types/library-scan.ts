export interface LibraryScanSummary {
    id: number;
    status: 'queued' | 'scanning' | 'completed' | 'failed';
    discovered_count: number;
    missing_count: number;
    error_detail: string | null;
    started_at: string | null;
    completed_at: string | null;
}

export type LibraryTaskType =
    | 'identify'
    | 'import'
    | 'restore'
    | 'retry_import'
    | 'retry_restore'
    | 'retry_delete'
    | 'missing';

export interface TrackedLibrarySource {
    finding_id: number;
    media_file_id: number;
    disk_id: string;
    relative_path: string;
    size_bytes: number | null;
}

export interface LibraryTask {
    id: number;
    task_type: LibraryTaskType;
    disk_id: string;
    relative_path: string;
    source_filename: string;
    size_bytes: number | null;
    status: string;
    tmdb_id: number | null;
    imdb_id: string | null;
    title: string | null;
    release_year: number | null;
    poster_url: string | null;
    destination_relative_path: string | null;
    error_detail: string | null;
    tracked_source: TrackedLibrarySource | null;
}

export interface LibraryScanProgress {
    completed: number;
    total: number;
}

export interface LibraryHistoryItem {
    id: number;
    name: string;
    outcome: string | null;
    completed_at: string | null;
}

export interface MaintenanceWarning {
    count: number;
    message: string;
}

export interface IdentityPreview {
    source: {
        disk_id: string;
        relative_path: string;
        filename: string;
        size_bytes: number | null;
    };
    destination: {
        disk_id: string;
        relative_path: string;
    };
    movie: {
        tmdb_id: number;
        imdb_id: string | null;
        title: string;
        release_year: number | null;
        poster_url: string | null;
    };
    can_import: boolean;
    operation: 'import' | 'restore';
    relocation: TrackedLibrarySource | null;
    blocker: { code: string; message: string } | null;
}

export interface IdentityPreviewResponse {
    data: IdentityPreview;
}

export interface UnavailableDisk {
    id: string;
    label: string;
    reasons: Array<{ code: string; message: string }>;
}
