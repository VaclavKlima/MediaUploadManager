export type SeriesRoot = {
    id: string;
    label: string;
    kind: 'series';
    health: 'healthy' | 'unhealthy';
    eligible: boolean;
    usable_bytes: number | null;
    reasons: Array<{ code: string; message: string }>;
};

export type SeriesSearchResult = {
    tmdb_id: number;
    name: string;
    original_name: string | null;
    first_air_year: number | null;
    overview: string | null;
    poster_url: string | null;
};

export type ParsedSeriesSource = {
    title: string;
    year: number | null;
};

export type SeriesLookupResponse = {
    data: SeriesSearchResult[];
    meta: {
        source: 'filename' | 'text';
        parsed?: ParsedSeriesSource;
    };
};

export type SeriesDetailsResponse = {
    data: SeriesSearchResult;
};

export type SeriesConfirmationResponse = {
    data: ConfirmedSeries;
};

export type ConfirmedSeriesEpisode = {
    id: number;
    episode_number: number;
    identity: string;
    name: string;
    has_current_primary: boolean;
    can_replace_current_primary: boolean;
    current_primary: {
        id: number;
        relative_path: string;
        size_bytes: number;
    } | null;
};

export type ConfirmedSeriesSeason = {
    id: number;
    season_number: number;
    name: string;
    episodes: ConfirmedSeriesEpisode[];
};

export type AvailableSeriesSeason = {
    season_number: number;
    name: string;
    episode_count: number;
    hydrated: boolean;
};

export type ConfirmedSeries = {
    id: number;
    tmdb_id: number;
    name: string;
    original_name: string | null;
    first_air_year: number | null;
    overview: string | null;
    category: 'tv' | 'anime';
    poster_url: string | null;
    home_disk_id: string | null;
    episode_total: number;
    available_seasons: AvailableSeriesSeason[];
    seasons: ConfirmedSeriesSeason[];
};

export type SeriesBatchItem = {
    upload_uuid: string;
    position: number;
    source_identity: string;
    source_basename: string;
    last_modified_milliseconds: number | null;
    expires_at: string | null;
    expired_at: string | null;
    episode: {
        id: number;
        identity: string;
        title: string;
        season_number: number;
        episode_number: number;
    };
    destination: string;
    status: string;
    declared_bytes: number;
    confirmed_bytes: number;
    failure: {
        code: string | null;
        detail: string | null;
        can_retry: boolean;
        can_discard: boolean;
    } | null;
    replacement: {
        media_file_id: number;
        relative_path: string;
        size_bytes: number;
    } | null;
    actions: {
        authorize: boolean;
        pause: boolean;
        retry: boolean;
        cancel: boolean;
    };
    finalized: {
        relative_path: string;
        size_bytes: number;
        container: string;
        duration_milliseconds: number;
        finalized_at: string;
    } | null;
};

export type SeriesPreviewItemRequest = {
    source_identity: string;
    series_episode_id: number;
    declared_size: number;
    replaces_media_file_id: number | null;
    replacement_confirmed: boolean;
};

export type PreviewSeriesBatchRequest = {
    items: SeriesPreviewItemRequest[];
};

export type SeriesPreviewDisk = {
    id: string;
    label: string;
    status: 'clear' | 'unavailable';
    health: 'healthy' | 'unhealthy';
    total_bytes: number | null;
    free_bytes: number | null;
    safety_reserve_bytes: number;
    usable_bytes: number | null;
    active_reserved_bytes: number;
    projected_usable_bytes: number | null;
    eligible: boolean;
    reasons: Array<{ code: string; message: string }>;
};

export type SeriesPreviewItem = {
    source_basename: string;
    series_episode_id: number;
    episode_identity: string;
    episode_title: string;
    target_relative_path: string;
    declared_size: number;
    replacement: {
        media_file_id: number;
        relative_path: string;
        size_bytes: number;
    } | null;
};

export type SeriesBatchPreview = {
    series: { id: number; name: string; home_disk_id: string | null };
    declared_bytes: number;
    recommended_disk_id: string | null;
    can_start_batch: boolean;
    items: SeriesPreviewItem[];
    disks: SeriesPreviewDisk[];
};

export type SeriesBatchPreviewResponse = { data: SeriesBatchPreview };

export type SeriesBatchAdmissionRequest = {
    idempotency_key: string;
    disk_id: string;
    items: Array<
        SeriesPreviewItemRequest & {
            last_modified_milliseconds: number | null;
            fingerprint_first_sha256: string;
            fingerprint_last_sha256: string;
        }
    >;
};

export type SeriesBatch = {
    uuid: string;
    status: string;
    series: {
        id: number;
        tmdb_id: number;
        name: string;
        year: number | null;
        category: 'tv' | 'anime';
    };
    home_disk: { id: string; label: string | null };
    declared_bytes: number;
    confirmed_bytes: number;
    items: SeriesBatchItem[];
};

export type SeriesBatchResponse = {
    data: SeriesBatch;
    idempotent_replay: boolean;
};

export type SeriesRecoveryItemRequest = {
    upload_uuid: string;
    source_identity: string;
    filename: string;
    declared_size: number;
    last_modified_milliseconds: number | null;
    fingerprint_first_sha256: string;
    fingerprint_last_sha256: string;
};

export type SeriesRecoveryRequest = { items: SeriesRecoveryItemRequest[] };

export type ResumableSeriesBatchesResponse = { data: SeriesBatch[] };

export type SeriesUploadStatus =
    | 'pending'
    | 'uploading'
    | 'paused'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'expired';

export type SeriesUploadSession = {
    uuid: string;
    series_episode_id: number;
    series_batch_uuid: string;
    batch_position: number;
    status: SeriesUploadStatus;
    original_filename: string;
    last_modified_milliseconds: number | null;
    disk: { id: string; label: string | null };
    target_relative_path: string;
    declared_bytes: number;
    confirmed_bytes: number;
    poll_interval_milliseconds: number;
    failure: {
        code: string | null;
        detail: string | null;
        can_retry: boolean;
        can_discard: boolean;
    } | null;
    actions: SeriesBatchItem['actions'];
    finalized?: SeriesBatchItem['finalized'];
};

export type AuthorizedSeriesUpload = SeriesUploadSession & {
    endpoint: string;
    resource_url: string | null;
    transport: UploadTransportSettings;
    authorization: UploadAuthorization;
};

export type SeriesUploadAuthorizationResponse = {
    data: AuthorizedSeriesUpload;
};

export type SeriesUploadSessionResponse = { data: SeriesUploadSession };

export type SeriesCatalogPaginator = {
    data: SeriesCatalogItem[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
};

export type SeriesCatalogFilters = {
    search: string | null;
    status: 'complete' | 'missing' | 'empty' | null;
    sort: 'recent' | 'title' | 'coverage';
};

export type SeriesCoverage = {
    seasons: { available: number; total: number };
    episodes: { available: number; total: number };
};

export type SeriesCatalogItem = {
    id: number;
    name: string;
    original_name: string | null;
    year: number | null;
    tmdb_id: number;
    category: 'tv' | 'anime';
    poster_url: string | null;
    state: 'complete' | 'missing' | 'empty' | 'in_progress';
    coverage: SeriesCoverage;
    home_disk: { id: string | null; label: string | null };
    latest_finalization: string | null;
    can_delete_show: boolean;
};

export type TechnicalTag = {
    kind: 'quality' | 'video' | 'audio' | 'duration';
    label: string;
};

export type SeriesEpisodeDetails = {
    id: number;
    episode_number: number;
    identity: string;
    name: string;
    tmdb_name: string;
    custom_name: string | null;
    overview: string | null;
    air_date: string | null;
    state: 'available' | 'missing' | 'upcoming' | 'unscheduled';
    current_file: {
        id: number;
        relative_path: string;
        size_bytes: number;
        technical_tags: TechnicalTag[];
        owner: { id: number; name: string } | null;
    } | null;
    actions: {
        can_rename: boolean;
        rename_blocker: string | null;
        can_delete_media: boolean;
        delete_media_blocker: string | null;
    };
};

export type SeriesSeasonDetails = {
    id: number;
    season_number: number;
    name: string;
    overview: string | null;
    episodes: SeriesEpisodeDetails[];
    actions: {
        can_delete_media: boolean;
        delete_media_blocker: string | null;
    };
};

export type SeriesShowDetails = {
    id: number;
    tmdb_id: number;
    name: string;
    original_name: string | null;
    year: number | null;
    overview: string | null;
    category: 'tv' | 'anime';
    poster_url: string | null;
    storage: {
        disk_id: string | null;
        disk_label: string | null;
        size_bytes: number;
    };
    coverage: SeriesCoverage;
    seasons: Array<{
        season_number: number;
        name: string;
        episode_count: number;
        hydrated: boolean;
    }>;
    selected_season_number: number;
    selected_season: SeriesSeasonDetails | null;
    selected_season_hydrated: boolean;
    actions: {
        can_delete_show: boolean;
        delete_show_blocker: string | null;
    };
};

export type EpisodeRenamePreview = {
    episode_id: number;
    tmdb_name: string;
    current_name: string;
    custom_name: string | null;
    has_current_file: boolean;
    source_relative_path: string | null;
    destination_relative_path: string | null;
    path_changes: boolean;
    can_rename: boolean;
    blocker: string | null;
};
import type {
    UploadAuthorization,
    UploadTransportSettings,
} from '@/types/upload-transport';
