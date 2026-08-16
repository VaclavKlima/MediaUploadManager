import { Upload as TusUpload } from 'tus-js-client';
import type { DetailedError, HttpRequest } from 'tus-js-client';
import type {
    UploadFingerprint,
    UploadTransportSettings,
} from '@/types/upload-transport';

export async function fingerprintUploadFile(
    source: File,
    windowBytes: number,
): Promise<UploadFingerprint> {
    const firstEnd = Math.min(windowBytes, source.size);
    const lastStart = Math.max(0, source.size - windowBytes);
    const [firstSha256, lastSha256] = await Promise.all([
        sha256(source.slice(0, firstEnd)),
        sha256(source.slice(lastStart, source.size)),
    ]);

    return {
        filename: source.name,
        declared_size: source.size,
        last_modified_milliseconds:
            Number.isSafeInteger(source.lastModified) &&
            source.lastModified >= 0
                ? source.lastModified
                : null,
        fingerprint_first_sha256: firstSha256,
        fingerprint_last_sha256: lastSha256,
    };
}

type CreateUploadTransportOptions = {
    source: File;
    uploadUuid: string;
    endpoint: string;
    uploadUrl: string | null;
    authorizationToken: string;
    settings: UploadTransportSettings;
    refreshAuthorization: () => Promise<string>;
    onUploadUrlAvailable: (url: string | null) => void;
    onProgress: (bytesSent: number, bytesTotal: number) => void;
    onRetry: (error: DetailedError) => boolean;
    onError: (error: Error | DetailedError) => void;
    onSuccess: () => void;
};

export function createUploadTransport(
    options: CreateUploadTransportOptions,
): TusUpload {
    const upload = new TusUpload(options.source, {
        endpoint: options.endpoint,
        uploadUrl: options.uploadUrl,
        uploadSize: options.source.size,
        uploadDataDuringCreation: false,
        metadata: { upload_uuid: options.uploadUuid },
        headers: { Authorization: `Bearer ${options.authorizationToken}` },
        chunkSize: options.settings.chunk_size_bytes,
        retryDelays: options.settings.retry_delays_milliseconds,
        parallelUploads: 1,
        storeFingerprintForResuming: false,
        removeFingerprintOnSuccess: false,
        onBeforeRequest: async (request: HttpRequest) => {
            const token = await options.refreshAuthorization();

            if (!token) {
                throw new Error('Upload authorization is unavailable.');
            }

            request.setHeader('Authorization', `Bearer ${token}`);
        },
        onUploadUrlAvailable: () => options.onUploadUrlAvailable(upload.url),
        onProgress: options.onProgress,
        onShouldRetry: options.onRetry,
        onError: options.onError,
        onSuccess: options.onSuccess,
    });

    return upload;
}

async function sha256(blob: Blob): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await blob.arrayBuffer(),
    );

    return Array.from(new Uint8Array(digest), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}
