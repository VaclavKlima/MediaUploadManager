export type UploadFingerprint = {
    filename: string;
    declared_size: number;
    last_modified_milliseconds: number | null;
    fingerprint_first_sha256: string;
    fingerprint_last_sha256: string;
};

export type UploadAuthorization = {
    token: string;
    abilities: string[];
    expires_at: string;
};

export type UploadTransportSettings = {
    chunk_size_bytes: number;
    retry_delays_milliseconds: number[];
    token_refresh_leeway_seconds: number;
    fingerprint_window_bytes: number;
};

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
