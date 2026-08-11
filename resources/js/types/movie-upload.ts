export interface MovieSummary {
    tmdb_id: number;
    title: string;
    original_title: string | null;
    release_date: string | null;
    release_year: number | null;
    overview: string | null;
    poster_path: string | null;
    poster_url: string | null;
    original_language: string | null;
}

export interface Genre {
    id: number;
    name: string;
}

export interface MovieDetails extends MovieSummary {
    imdb_id: string | null;
    runtime: number | null;
    status: string | null;
    tagline: string | null;
    vote_average: number | null;
    vote_count: number | null;
    genres: Genre[];
}

export interface ParsedFilename {
    filename: string;
    title: string;
    year: number | null;
}

export interface SearchResponse {
    data: MovieSummary[];
    meta: {
        source: 'text' | 'filename';
        parsed?: ParsedFilename;
    };
}

export interface DetailsResponse {
    data: MovieDetails;
}

export interface ConfirmationResponse extends DetailsResponse {
    media_item_id: number;
    reused: boolean;
    has_current_primary: boolean;
}

export interface DiskIdentity {
    id: string;
    label: string | null;
}

export interface PreviewReason {
    code: string;
    message: string;
}

export interface PreviewBlocker extends PreviewReason {
    disk: DiskIdentity | null;
}

export interface DiskTarget {
    id: string;
    label: string;
    status: 'clear' | 'replaceable' | 'conflict' | 'unavailable';
    health: 'healthy' | 'unhealthy';
    total_bytes: number | null;
    free_bytes: number | null;
    safety_reserve_bytes: number;
    usable_bytes: number | null;
    active_reserved_bytes: number;
    projected_usable_bytes: number | null;
    eligible: boolean;
    replacement_method: 'atomic_same_path_swap' | 'finalize_then_delete' | null;
    reasons: PreviewReason[];
}

export interface ReplaceableMediaFile {
    id: number;
    disk: DiskIdentity;
    relative_path: string;
    size_bytes: number;
    finalized_at: string;
}

export interface PathPreview {
    directory: string;
    filename: string;
    relative_path: string;
    extension: string;
    declared_size: number;
    can_start_new_upload: boolean;
    can_replace_current_primary: boolean;
    replaceable: ReplaceableMediaFile | null;
    recommended_disk_id: string | null;
    fingerprint_window_bytes: number;
    blockers: PreviewBlocker[];
    disks: DiskTarget[];
}

export interface PathPreviewResponse {
    data: PathPreview;
}

export interface ReservationAuthorization {
    token: string;
    abilities: string[];
    expires_at: string;
}

export interface UploadTransportSettings {
    chunk_size_bytes: number;
    retry_delays_milliseconds: number[];
    token_refresh_leeway_seconds: number;
    fingerprint_window_bytes: number;
}

export type UploadSessionStatus =
    | 'pending'
    | 'uploading'
    | 'paused'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'cancelled';

export interface UploadProcessingFailure {
    code: string | null;
    detail: string | null;
    can_retry: boolean;
    can_discard: boolean;
}

export interface FinalizedMedia {
    disk: DiskIdentity;
    relative_path: string;
    size_bytes: number;
    container: string;
    duration_milliseconds: number;
    video: Array<{
        index: number;
        codec: string;
        width: number;
        height: number;
        language: string | null;
        disposition: Record<string, boolean>;
    }>;
    audio: Array<{
        index: number;
        codec: string;
        channels: number | null;
        channel_layout: string | null;
        sample_rate: number | null;
        language: string | null;
        disposition: Record<string, boolean>;
    }>;
    finalized_at: string;
}

export interface UploadReplacement {
    media_file_id: number;
    disk: DiskIdentity;
    relative_path: string;
    size_bytes: number;
    confirmed_at: string;
    method: 'atomic_same_path_swap' | 'finalize_then_delete';
}

export interface UploadSession {
    uuid: string;
    media_item_id: number;
    status: UploadSessionStatus;
    original_filename: string;
    last_modified_milliseconds: number | null;
    disk: DiskIdentity;
    target_relative_path: string;
    staging_relative_path: string;
    declared_bytes: number;
    confirmed_bytes: number;
    expires_at: string | null;
    uploading_at?: string | null;
    paused_at?: string | null;
    processing_at?: string | null;
    completed_at?: string | null;
    failed_at?: string | null;
    cancelled_at?: string | null;
    poll_interval_milliseconds: number;
    failure: UploadProcessingFailure | null;
    replacement: UploadReplacement | null;
    finalized: FinalizedMedia | null;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface UploadReservation extends UploadSession {
    tus_endpoint: string;
    tus_resource_url: string | null;
    transport: UploadTransportSettings;
    authorization: ReservationAuthorization;
    idempotent_replay: boolean;
}

export interface AuthorizedUploadSession extends UploadSession {
    endpoint: string;
    resource_url: string | null;
    transport: UploadTransportSettings;
    authorization: ReservationAuthorization;
}

export interface UploadReservationResponse {
    data: UploadReservation;
}

export interface UploadCancellationResponse {
    data: UploadSession;
}

export interface UploadSessionResponse {
    data: UploadSession;
}

export interface UploadSessionsResponse {
    data: UploadSession[];
    meta: {
        fingerprint_window_bytes: number;
    };
}

export interface UploadAuthorizationResponse {
    data: AuthorizedUploadSession;
}

export type UploadConnectionState =
    | 'ready'
    | 'authorizing'
    | 'uploading'
    | 'retrying'
    | 'offline'
    | 'paused'
    | 'error'
    | 'received'
    | 'cancelled';

export type UploadWizardStep = 1 | 2 | 3 | 4 | 5;
