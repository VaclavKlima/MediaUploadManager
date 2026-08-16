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

interface LibraryTaskBase {
    id: number;
    task_type: LibraryTaskType;
    disk_id: string;
    relative_path: string;
    source_folder: string;
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

export interface MovieLibraryTask extends LibraryTaskBase {
    media_type: 'movie';
    root_kind: 'movies';
    movie: {
        tmdb_id: number | null;
        imdb_id: string | null;
        title: string | null;
        release_year: number | null;
    };
    show: null;
}

export interface ShowLibraryTask extends LibraryTaskBase {
    media_type: 'show';
    root_kind: 'series';
    movie: null;
    show: {
        tmdb_id: number | null;
        name: string | null;
        first_air_year: number | null;
        category: 'tv' | 'anime' | null;
        category_required: boolean;
        season_number: number | null;
        episode_number: number | null;
        episode_name: string | null;
        series_episode_id: number | null;
        parse_error: string | null;
        search_query: string;
    };
}

export type LibraryTask = MovieLibraryTask | ShowLibraryTask;

export interface LibraryScanProgress {
    completed: number;
    total: number;
}

export interface LibraryHistoryItem {
    id: number;
    media_type: 'movie' | 'show';
    name: string;
    outcome: string | null;
    completed_at: string | null;
}

export interface MaintenanceWarning {
    count: number;
    message: string;
}

interface IdentityPreviewBase {
    source: {
        disk_id: string;
        relative_path: string;
        filename: string;
        size_bytes: number | null;
    };
    destination: { disk_id: string; relative_path: string };
    can_import: boolean;
    operation: 'import' | 'restore';
    relocation: TrackedLibrarySource | null;
    blocker: { code: string; message: string } | null;
}

export interface MovieIdentityPreview extends IdentityPreviewBase {
    media_type?: 'movie';
    movie: {
        tmdb_id: number;
        imdb_id: string | null;
        title: string;
        release_year: number | null;
        poster_url: string | null;
    };
}

export interface ShowIdentityPreview extends IdentityPreviewBase {
    media_type: 'show';
    show: {
        tmdb_id: number;
        name: string;
        first_air_year: number | null;
        poster_url: string | null;
        category: 'tv' | 'anime';
        season_number: number;
        episode_number: number;
        episode_name: string;
        existing_series_id: number | null;
        existing_episode_id: number | null;
    };
}

export type IdentityPreview = MovieIdentityPreview | ShowIdentityPreview;
export interface IdentityPreviewResponse {
    data: IdentityPreview;
}

export interface UnavailableDisk {
    id: string;
    label: string;
    root_kind: 'movies' | 'series';
    reasons: Array<{ code: string; message: string }>;
}

export interface TmdbShowSeasonResponse {
    data: {
        tmdb_id: number;
        season_number: number;
        name: string;
        episodes: Array<{
            tmdb_id: number;
            season_number: number;
            episode_number: number;
            name: string;
        }>;
    };
}
