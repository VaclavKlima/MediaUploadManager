import { useHttp } from '@inertiajs/vue3';
import { Upload as TusUpload } from 'tus-js-client';
import type { HttpRequest, DetailedError } from 'tus-js-client';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import MoviePathPreviewController from '@/actions/App/Http/Controllers/MoviePathPreviewController';
import MovieUploadController from '@/actions/App/Http/Controllers/MovieUploadController';
import UploadAuthorizationController from '@/actions/App/Http/Controllers/UploadAuthorizationController';
import UploadController from '@/actions/App/Http/Controllers/UploadController';
import UploadPauseController from '@/actions/App/Http/Controllers/UploadPauseController';
import type {
    AuthorizedUploadSession,
    ConfirmationResponse,
    DetailsResponse,
    MovieDetails,
    MovieSummary,
    ParsedFilename,
    PathPreview,
    PathPreviewResponse,
    SearchResponse,
    UploadAuthorizationResponse,
    UploadCancellationResponse,
    UploadConnectionState,
    UploadReservation,
    UploadReservationResponse,
    UploadSession,
    UploadSessionResponse,
    UploadSessionsResponse,
    UploadWizardStep,
} from '@/types/movie-upload';

interface ReservationPayload {
    idempotency_key: string;
    filename: string;
    declared_size: number;
    last_modified_milliseconds: number | null;
    fingerprint_first_sha256: string;
    fingerprint_last_sha256: string;
    disk_id: string;
    replaces_media_file_id: number | null;
    replacement_confirmed: boolean | null;
}

interface FingerprintPayload {
    filename: string;
    declared_size: number;
    last_modified_milliseconds: number | null;
    fingerprint_first_sha256: string;
    fingerprint_last_sha256: string;
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

export function useMovieUploadWizard() {
    const currentStep = ref<UploadWizardStep>(1);
    const sourceFile = ref<File | null>(null);
    const searchInput = ref('');
    const results = ref<MovieSummary[]>([]);
    const parsedFilename = ref<ParsedFilename | null>(null);
    const selectedMovie = ref<MovieSummary | null>(null);
    const confirmedMovie = ref<ConfirmationResponse | null>(null);
    const pathPreview = ref<PathPreview | null>(null);
    const selectedDiskId = ref('');
    const replacementConfirmed = ref(false);
    const reservation = ref<UploadReservation | null>(null);
    const idempotencyKey = ref(crypto.randomUUID());
    const lookupError = ref('');
    const previewError = ref('');
    const reservationError = ref('');
    const recoveryError = ref('');
    const uploadError = ref('');
    const isHashing = ref(false);
    const statusMessage = ref('Select a source file to begin.');
    const lookupCompleted = ref(false);
    const resumableSessions = ref<UploadSession[]>([]);
    const recoveryFingerprintWindowBytes = ref(0);
    const fileFingerprint = ref<FingerprintPayload | null>(null);
    const connectionState = ref<UploadConnectionState>('ready');
    const transferredBytes = ref(0);
    const speedBytesPerSecond = ref(0);
    const etaSeconds = ref<number | null>(null);

    let lookupRequestId = 0;
    let confirmationRequestId = 0;
    let previewRequestId = 0;
    let reservationRequestId = 0;
    let cancellationRequestId = 0;
    let recoveryRequestId = 0;
    let activeTusUpload: TusUpload | null = null;
    let authorizationPromise: Promise<void> | null = null;
    let lastProgressAt = 0;
    let lastProgressBytes = 0;
    let processingPollTimer: number | null = null;

    const textLookup = useHttp<{ query: string; year: string }, SearchResponse>(
        {
            query: '',
            year: '',
        },
    );
    const filenameLookup = useHttp<{ filename: string }, SearchResponse>({
        filename: '',
    });
    const detailsLookup = useHttp<Record<string, never>, DetailsResponse>({});
    const confirmation = useHttp<{ tmdb_id: number }, ConfirmationResponse>({
        tmdb_id: 0,
    });
    const previewLookup = useHttp<
        { filename: string; declared_size: number },
        PathPreviewResponse
    >({ filename: '', declared_size: 0 });
    const reservationRequest = useHttp<
        ReservationPayload,
        UploadReservationResponse
    >({
        idempotency_key: '',
        filename: '',
        declared_size: 0,
        last_modified_milliseconds: null,
        fingerprint_first_sha256: '',
        fingerprint_last_sha256: '',
        disk_id: '',
        replaces_media_file_id: null,
        replacement_confirmed: null,
    });
    const cancellationRequest = useHttp<
        Record<string, never>,
        UploadCancellationResponse
    >({});
    const sessionsRequest = useHttp<
        Record<string, never>,
        UploadSessionsResponse
    >({});
    const authorizationRequest = useHttp<
        FingerprintPayload,
        UploadAuthorizationResponse
    >({
        filename: '',
        declared_size: 0,
        last_modified_milliseconds: null,
        fingerprint_first_sha256: '',
        fingerprint_last_sha256: '',
    });
    const pauseRequest = useHttp<Record<string, never>, UploadSessionResponse>(
        {},
    );
    const statusRequest = useHttp<Record<string, never>, UploadSessionResponse>(
        {},
    );
    const processingRetryRequest = useHttp<
        Record<string, never>,
        UploadSessionResponse
    >({});

    const sourceFilename = computed(() => sourceFile.value?.name ?? '');
    const isLookingUp = computed(
        () =>
            textLookup.processing ||
            filenameLookup.processing ||
            detailsLookup.processing,
    );
    const isReserving = computed(() => reservationRequest.processing);
    const isCancelling = computed(() => cancellationRequest.processing);
    const isLoadingSessions = computed(() => sessionsRequest.processing);
    const isUploadBusy = computed(
        () =>
            connectionState.value === 'authorizing' ||
            connectionState.value === 'uploading' ||
            connectionState.value === 'retrying' ||
            connectionState.value === 'offline',
    );
    const isAdmissionBusy = computed(
        () =>
            isHashing.value ||
            isReserving.value ||
            isCancelling.value ||
            (currentStep.value === 3 && previewLookup.processing),
    );

    function readError(data: string | undefined, fallback: string): string {
        if (!data) {
            return fallback;
        }

        try {
            const payload = JSON.parse(data) as { message?: string };

            return payload.message ?? fallback;
        } catch {
            return fallback;
        }
    }

    async function loadResumableSessions(): Promise<void> {
        recoveryError.value = '';

        try {
            const response = await sessionsRequest.get(
                UploadController.index.url(),
                {
                    onHttpException: (exception) => {
                        recoveryError.value = readError(
                            exception.data,
                            'Active upload sessions could not be loaded.',
                        );
                    },
                },
            );

            resumableSessions.value = response.data;
            recoveryFingerprintWindowBytes.value =
                response.meta.fingerprint_window_bytes;
        } catch {
            // The safe server message is displayed above.
        }
    }

    async function fingerprintFile(
        source: File,
        windowBytes: number,
    ): Promise<FingerprintPayload> {
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

    function normalizeAuthorization(
        session: AuthorizedUploadSession,
    ): UploadReservation {
        return {
            ...session,
            tus_endpoint: session.endpoint,
            tus_resource_url: session.resource_url,
            idempotent_replay: false,
        };
    }

    function retainedReservation(session: UploadSession): UploadReservation {
        return {
            ...session,
            tus_endpoint: '',
            tus_resource_url: null,
            transport: {
                chunk_size_bytes: 0,
                retry_delays_milliseconds: [],
                token_refresh_leeway_seconds: 0,
                fingerprint_window_bytes: 0,
            },
            authorization: {
                token: '',
                abilities: [],
                expires_at: new Date(0).toISOString(),
            },
            idempotent_replay: false,
        };
    }

    function clearProcessingPoll(): void {
        if (processingPollTimer !== null) {
            window.clearTimeout(processingPollTimer);
            processingPollTimer = null;
        }
    }

    function cancelLookups(): number {
        lookupRequestId += 1;
        textLookup.cancel();
        filenameLookup.cancel();
        detailsLookup.cancel();

        return lookupRequestId;
    }

    function cancelConfirmation(): void {
        confirmationRequestId += 1;
        confirmation.cancel();
    }

    function cancelPreview(): number {
        previewRequestId += 1;
        previewLookup.cancel();

        return previewRequestId;
    }

    function cancelReservationRequests(): number {
        reservationRequestId += 1;
        reservationRequest.cancel();

        return reservationRequestId;
    }

    function resetReservationDraft(cancelActiveCancellation = true): void {
        clearProcessingPoll();
        cancelReservationRequests();
        cancellationRequestId += 1;

        if (cancelActiveCancellation) {
            cancellationRequest.cancel();
        }

        reservation.value = null;
        fileFingerprint.value = null;
        reservationError.value = '';
        uploadError.value = '';
        connectionState.value = 'ready';
        transferredBytes.value = 0;
        speedBytesPerSecond.value = 0;
        etaSeconds.value = null;
        selectedDiskId.value = '';
        replacementConfirmed.value = false;
        idempotencyKey.value = crypto.randomUUID();
    }

    async function selectSource(file: File): Promise<void> {
        if (reservation.value || isAdmissionBusy.value) {
            return;
        }

        cancelLookups();
        cancelConfirmation();
        cancelPreview();
        sourceFile.value = file;
        lookupError.value = '';
        previewError.value = '';
        pathPreview.value = null;
        resetReservationDraft();
        selectedMovie.value = null;
        confirmedMovie.value = null;
        currentStep.value = 2;
        statusMessage.value =
            'Source selected. Looking for matches from the filename.';
        await loadFilenameSuggestions(file);
    }

    async function loadFilenameSuggestions(file: File): Promise<void> {
        const requestId = cancelLookups();

        lookupError.value = '';
        lookupCompleted.value = false;
        results.value = [];
        parsedFilename.value = null;
        selectedMovie.value = null;
        filenameLookup.filename = file.name;

        try {
            const response = await filenameLookup.get(
                MovieController.suggestions.url(),
                {
                    onHttpException: (exception) => {
                        if (requestId === lookupRequestId) {
                            lookupError.value = readError(
                                exception.data,
                                'Movie lookup failed. Please try again.',
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === lookupRequestId) {
                            lookupError.value =
                                'Movie lookup failed. Check your connection and try again.';
                        }
                    },
                },
            );

            if (requestId !== lookupRequestId || sourceFile.value !== file) {
                return;
            }

            results.value = response.data;
            parsedFilename.value = response.meta.parsed ?? null;
            searchInput.value = parsedFilename.value?.title ?? file.name;
            lookupCompleted.value = true;
            statusMessage.value = response.data.length
                ? `${response.data.length} movie matches found.`
                : 'No movie matches found.';
        } catch {
            // Cancelled and handled HTTP failures leave the current pane intact.
        }
    }

    async function runSmartSearch(): Promise<void> {
        const query = searchInput.value.normalize('NFC').trim();

        if (!query) {
            lookupError.value = 'Enter a title, TMDB ID, or IMDb ID.';

            return;
        }

        const requestId = cancelLookups();

        searchInput.value = query;
        lookupError.value = '';
        lookupCompleted.value = false;
        results.value = [];
        parsedFilename.value = null;
        selectedMovie.value = null;

        try {
            if (/^tt\d{7,12}$/i.test(query)) {
                const response = await detailsLookup.get(
                    MovieController.showImdb.url(query.toLowerCase()),
                    lookupExceptionOptions(requestId),
                );

                if (requestId === lookupRequestId) {
                    showExactResult(response.data);
                }

                return;
            }

            if (/^\d+$/.test(query)) {
                const response = await detailsLookup.get(
                    MovieController.showTmdb.url(Number(query)),
                    lookupExceptionOptions(requestId),
                );

                if (requestId === lookupRequestId) {
                    showExactResult(response.data);
                }

                return;
            }

            textLookup.query = query;
            textLookup.year = '';
            const response = await textLookup.get(
                MovieController.search.url(),
                lookupExceptionOptions(requestId),
            );

            if (requestId !== lookupRequestId) {
                return;
            }

            results.value = response.data;
            lookupCompleted.value = true;
            statusMessage.value = response.data.length
                ? `${response.data.length} movie matches found.`
                : 'No movie matches found.';
        } catch {
            // Cancelled and handled HTTP failures leave the current pane intact.
        }
    }

    function lookupExceptionOptions(requestId: number) {
        return {
            onHttpException: (exception: { data?: string }) => {
                if (requestId === lookupRequestId) {
                    lookupError.value = readError(
                        exception.data,
                        'Movie lookup failed. Please try again.',
                    );
                }
            },
        };
    }

    function showExactResult(movie: MovieDetails): void {
        results.value = [movie];
        lookupCompleted.value = true;
        selectedMovie.value = null;
        statusMessage.value = `Exact match found for ${movie.title}.`;
    }

    function selectMovie(movie: MovieSummary): void {
        if (isLookingUp.value || confirmation.processing) {
            return;
        }

        selectedMovie.value = movie;
        statusMessage.value = `${movie.title} selected. Choose Select to continue.`;
    }

    async function confirmMovie(): Promise<void> {
        const movie = selectedMovie.value;

        if (!movie || !sourceFile.value) {
            return;
        }

        cancelConfirmation();
        cancelPreview();
        const requestId = confirmationRequestId;
        const source = sourceFile.value;

        lookupError.value = '';
        previewError.value = '';
        pathPreview.value = null;
        confirmation.tmdb_id = movie.tmdb_id;

        try {
            const response = await confirmation.post(
                MovieController.confirm.url(),
                {
                    onHttpException: (exception) => {
                        if (requestId === confirmationRequestId) {
                            lookupError.value = readError(
                                exception.data,
                                'Movie confirmation failed. Please try again.',
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === confirmationRequestId) {
                            lookupError.value =
                                'Movie confirmation failed. Check your connection and try again.';
                        }
                    },
                },
            );

            if (
                requestId !== confirmationRequestId ||
                selectedMovie.value?.tmdb_id !== movie.tmdb_id ||
                sourceFile.value !== source
            ) {
                return;
            }

            confirmedMovie.value = response;
            currentStep.value = 3;
            statusMessage.value = 'Movie selected. Loading available storage.';
            await requestPathPreview();
        } catch {
            // The safe server message is announced above.
        }
    }

    async function requestPathPreview(
        preserveExistingPreview = false,
    ): Promise<void> {
        const movie = confirmedMovie.value;
        const source = sourceFile.value;
        const requestId = cancelPreview();

        if (!preserveExistingPreview) {
            pathPreview.value = null;
        }

        previewError.value = '';

        if (!movie || !source) {
            return;
        }

        previewLookup.filename = source.name;
        previewLookup.declared_size = source.size;

        try {
            const response = await previewLookup.get(
                MoviePathPreviewController.url(movie.media_item_id),
                {
                    onHttpException: (exception) => {
                        if (requestId === previewRequestId) {
                            const message = readError(
                                exception.data,
                                'Destination preview failed. Please try again.',
                            );

                            if (preserveExistingPreview) {
                                reservationError.value = message;
                            } else {
                                previewError.value = message;
                            }
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === previewRequestId) {
                            const message =
                                'Storage could not be loaded. Check your connection and try again.';

                            if (preserveExistingPreview) {
                                reservationError.value = message;
                            } else {
                                previewError.value = message;
                            }
                        }
                    },
                },
            );

            if (
                requestId !== previewRequestId ||
                confirmedMovie.value?.media_item_id !== movie.media_item_id ||
                sourceFile.value !== source
            ) {
                return;
            }

            pathPreview.value = response.data;
            replacementConfirmed.value = false;
            reservationError.value = '';
            selectedDiskId.value = '';
            statusMessage.value = response.data.can_start_new_upload
                ? 'Storage options ready. Choose a disk to start uploading.'
                : response.data.can_replace_current_primary
                  ? 'Storage options ready. Confirm the irreversible replacement before choosing a disk.'
                  : 'Storage options loaded, but the upload is currently blocked.';
        } catch {
            // The safe server message is announced above.
        }
    }

    function goToSource(): void {
        if (reservation.value || isAdmissionBusy.value) {
            return;
        }

        currentStep.value = 1;
        statusMessage.value = 'Source file step opened.';
    }

    function goToIdentify(): void {
        if (reservation.value || isAdmissionBusy.value) {
            return;
        }

        if (!sourceFile.value) {
            return;
        }

        currentStep.value = 2;
        statusMessage.value = 'Movie identification step opened.';
    }

    function changeMovie(): void {
        if (reservation.value || isAdmissionBusy.value) {
            return;
        }

        cancelLookups();
        cancelConfirmation();
        cancelPreview();
        selectedMovie.value = null;
        confirmedMovie.value = null;
        pathPreview.value = null;
        resetReservationDraft();
        previewError.value = '';
        currentStep.value = 2;
        statusMessage.value =
            'Movie confirmation cleared. Choose another match.';
    }

    function goToStorage(): void {
        if (reservation.value || isAdmissionBusy.value) {
            return;
        }

        currentStep.value = 3;
        statusMessage.value = 'Storage selection reopened.';
    }

    async function selectStorageAndStart(diskId: string): Promise<void> {
        const source = sourceFile.value;
        const movie = confirmedMovie.value;
        const preview = pathPreview.value;

        if (
            !source ||
            !movie ||
            !preview ||
            reservation.value ||
            isAdmissionBusy.value ||
            !preview.disks.some(
                (disk) => disk.id === diskId && disk.eligible,
            ) ||
            (preview.can_replace_current_primary && !replacementConfirmed.value)
        ) {
            return;
        }

        if (selectedDiskId.value && selectedDiskId.value !== diskId) {
            idempotencyKey.value = crypto.randomUUID();
        }

        selectedDiskId.value = diskId;
        const requestId = cancelReservationRequests();

        reservationError.value = '';
        isHashing.value = true;
        statusMessage.value = 'Computing first and last file fingerprints.';

        try {
            const fingerprint = await fingerprintFile(
                source,
                preview.fingerprint_window_bytes,
            );

            if (
                requestId !== reservationRequestId ||
                sourceFile.value !== source ||
                confirmedMovie.value?.media_item_id !== movie.media_item_id ||
                selectedDiskId.value !== diskId
            ) {
                return;
            }

            isHashing.value = false;
            statusMessage.value = 'Fingerprints ready. Reserving capacity.';
            fileFingerprint.value = fingerprint;
            reservationRequest.idempotency_key = idempotencyKey.value;
            reservationRequest.filename = fingerprint.filename;
            reservationRequest.declared_size = fingerprint.declared_size;
            reservationRequest.last_modified_milliseconds =
                fingerprint.last_modified_milliseconds;
            reservationRequest.fingerprint_first_sha256 =
                fingerprint.fingerprint_first_sha256;
            reservationRequest.fingerprint_last_sha256 =
                fingerprint.fingerprint_last_sha256;
            reservationRequest.disk_id = diskId;
            reservationRequest.replaces_media_file_id =
                preview.can_replace_current_primary && preview.replaceable
                    ? preview.replaceable.id
                    : null;
            reservationRequest.replacement_confirmed =
                preview.can_replace_current_primary ? true : null;

            const response = await reservationRequest.post(
                MovieUploadController.store.url(movie.media_item_id),
                {
                    onHttpException: (exception) => {
                        if (requestId === reservationRequestId) {
                            reservationError.value = readError(
                                exception.data,
                                'Capacity could not be reserved. Refresh the destination and try again.',
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === reservationRequestId) {
                            reservationError.value =
                                'Capacity could not be reserved. Check your connection and choose the disk again.';
                        }
                    },
                },
            );

            if (
                requestId !== reservationRequestId ||
                sourceFile.value !== source ||
                confirmedMovie.value?.media_item_id !== movie.media_item_id ||
                selectedDiskId.value !== diskId
            ) {
                return;
            }

            reservation.value = response.data;
            transferredBytes.value = response.data.confirmed_bytes;
            connectionState.value = 'ready';
            currentStep.value = 4;
            statusMessage.value = `Capacity reserved on ${response.data.disk.label || response.data.disk.id}. Starting upload.`;
            await startUpload();
        } catch {
            if (requestId === reservationRequestId && !reservationError.value) {
                reservationError.value = isHashing.value
                    ? 'The file could not be fingerprinted. Keep it available and choose the disk again.'
                    : 'Capacity could not be reserved. Choose the disk again.';
            }
        } finally {
            if (requestId === reservationRequestId) {
                isHashing.value = false;
            }
        }
    }

    async function recoverSession(
        session: UploadSession,
        source: File,
    ): Promise<void> {
        if (isUploadBusy.value || isHashing.value) {
            return;
        }

        recoveryRequestId += 1;
        const requestId = recoveryRequestId;
        recoveryError.value = '';

        if (
            source.name !== session.original_filename ||
            source.size !== session.declared_bytes ||
            (Number.isSafeInteger(source.lastModified)
                ? source.lastModified
                : null) !== session.last_modified_milliseconds
        ) {
            recoveryError.value =
                'The selected file name, size, or modification time does not match this session.';

            return;
        }

        isHashing.value = true;
        statusMessage.value = 'Checking the selected file fingerprint.';

        try {
            const fingerprint = await fingerprintFile(
                source,
                recoveryFingerprintWindowBytes.value,
            );

            if (requestId !== recoveryRequestId) {
                return;
            }

            const authorization = await requestFreshAuthorization(
                session.uuid,
                fingerprint,
            );

            if (requestId !== recoveryRequestId) {
                return;
            }

            sourceFile.value = source;
            fileFingerprint.value = fingerprint;
            reservation.value = normalizeAuthorization(authorization);
            transferredBytes.value = authorization.confirmed_bytes;
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            connectionState.value =
                authorization.status === 'paused' ? 'paused' : 'ready';
            currentStep.value = 4;
            resumableSessions.value = resumableSessions.value.filter(
                (candidate) => candidate.uuid !== session.uuid,
            );
            statusMessage.value = `Exact file match confirmed at ${authorization.confirmed_bytes} bytes. Ready to resume.`;
        } catch {
            if (!recoveryError.value) {
                recoveryError.value =
                    'This file could not be authorized for the selected session.';
            }
        } finally {
            if (requestId === recoveryRequestId) {
                isHashing.value = false;
            }
        }
    }

    function openRetainedSession(session: UploadSession): void {
        if (!['processing', 'failed', 'completed'].includes(session.status)) {
            return;
        }

        clearProcessingPoll();
        sourceFile.value = null;
        fileFingerprint.value = null;
        reservation.value = retainedReservation(session);
        transferredBytes.value = session.confirmed_bytes;
        speedBytesPerSecond.value = 0;
        etaSeconds.value = null;
        currentStep.value = 4;
        resumableSessions.value = resumableSessions.value.filter(
            (candidate) => candidate.uuid !== session.uuid,
        );
        applyTerminalOrProcessingState(session);
    }

    async function requestFreshAuthorization(
        uuid: string,
        fingerprint: FingerprintPayload,
    ): Promise<AuthorizedUploadSession> {
        authorizationRequest.filename = fingerprint.filename;
        authorizationRequest.declared_size = fingerprint.declared_size;
        authorizationRequest.last_modified_milliseconds =
            fingerprint.last_modified_milliseconds;
        authorizationRequest.fingerprint_first_sha256 =
            fingerprint.fingerprint_first_sha256;
        authorizationRequest.fingerprint_last_sha256 =
            fingerprint.fingerprint_last_sha256;

        try {
            const response = await authorizationRequest.post(
                UploadAuthorizationController.url(uuid),
                {
                    onHttpException: (exception) => {
                        const message = readError(
                            exception.data,
                            'Upload authorization could not be refreshed.',
                        );
                        uploadError.value = message;
                        recoveryError.value = message;
                    },
                },
            );

            return response.data;
        } catch (error) {
            throw error;
        }
    }

    async function refreshAuthorization(force = false): Promise<void> {
        const activeReservation = reservation.value;
        const fingerprint = fileFingerprint.value;

        if (!activeReservation || !fingerprint) {
            throw new Error('The upload authorization context is unavailable.');
        }

        const expiresAt = Date.parse(
            activeReservation.authorization.expires_at,
        );
        const refreshAt =
            expiresAt -
            activeReservation.transport.token_refresh_leeway_seconds * 1000;

        if (!force && Number.isFinite(expiresAt) && Date.now() < refreshAt) {
            return;
        }

        if (!authorizationPromise) {
            authorizationPromise = (async () => {
                connectionState.value = 'authorizing';
                const authorization = await requestFreshAuthorization(
                    activeReservation.uuid,
                    fingerprint,
                );
                reservation.value = normalizeAuthorization(authorization);
                transferredBytes.value = authorization.confirmed_bytes;
            })().finally(() => {
                authorizationPromise = null;
            });
        }

        await authorizationPromise;
    }

    async function startUpload(forceAuthorization = false): Promise<void> {
        const source = sourceFile.value;

        if (
            !source ||
            !reservation.value ||
            isUploadBusy.value ||
            reservation.value.status === 'processing' ||
            reservation.value.status === 'cancelled'
        ) {
            return;
        }

        uploadError.value = '';

        try {
            if (forceAuthorization) {
                await refreshAuthorization(true);
            }

            const activeReservation = reservation.value;

            if (!activeReservation) {
                return;
            }

            lastProgressAt = performance.now();
            lastProgressBytes = activeReservation.confirmed_bytes;
            transferredBytes.value = activeReservation.confirmed_bytes;
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            connectionState.value = 'uploading';

            activeTusUpload = new TusUpload(source, {
                endpoint: activeReservation.tus_endpoint,
                uploadUrl: activeReservation.tus_resource_url,
                uploadSize: source.size,
                uploadDataDuringCreation: false,
                metadata: {
                    upload_uuid: activeReservation.uuid,
                },
                headers: {
                    Authorization: `Bearer ${activeReservation.authorization.token}`,
                },
                chunkSize: activeReservation.transport.chunk_size_bytes,
                retryDelays:
                    activeReservation.transport.retry_delays_milliseconds,
                parallelUploads: 1,
                storeFingerprintForResuming: false,
                removeFingerprintOnSuccess: false,
                onBeforeRequest: async (request: HttpRequest) => {
                    await refreshAuthorization();
                    const token = reservation.value?.authorization.token;

                    if (!token) {
                        throw new Error('Upload authorization is unavailable.');
                    }

                    request.setHeader('Authorization', `Bearer ${token}`);
                },
                onUploadUrlAvailable: () => {
                    if (reservation.value && activeTusUpload?.url) {
                        reservation.value.tus_resource_url =
                            activeTusUpload.url;
                    }
                },
                onProgress: updateProgress,
                onShouldRetry: (error: DetailedError) => {
                    connectionState.value = navigator.onLine
                        ? 'retrying'
                        : 'offline';
                    statusMessage.value = navigator.onLine
                        ? 'Connection interrupted. Retrying from the confirmed offset.'
                        : 'Connection lost. The upload will retry after reconnecting.';
                    const status = error.originalResponse?.getStatus() ?? null;

                    return status === null || status === 409 || status >= 500;
                },
                onError: () => {
                    activeTusUpload = null;
                    speedBytesPerSecond.value = 0;
                    etaSeconds.value = null;
                    connectionState.value = navigator.onLine
                        ? 'error'
                        : 'offline';
                    uploadError.value = navigator.onLine
                        ? 'The transfer stopped safely. Retry to reconcile the server offset and continue.'
                        : 'The browser is offline. Reconnect, then retry the upload.';
                    statusMessage.value = uploadError.value;
                },
                onSuccess: () => {
                    activeTusUpload = null;
                    transferredBytes.value = source.size;
                    speedBytesPerSecond.value = 0;
                    etaSeconds.value = 0;
                    connectionState.value = 'received';
                    statusMessage.value =
                        'Upload received. Confirming the protected staging state.';
                    void waitForProcessing();
                },
            });

            activeTusUpload.start();
            statusMessage.value = 'Protected resumable upload started.';
        } catch {
            connectionState.value = 'error';
            uploadError.value ||= 'The upload could not be started safely.';
            statusMessage.value = uploadError.value;
        }
    }

    function updateProgress(bytesSent: number, bytesTotal: number): void {
        const now = performance.now();
        const elapsedSeconds = (now - lastProgressAt) / 1000;
        const acceptedBytes = bytesSent - lastProgressBytes;

        if (elapsedSeconds >= 0.25 && acceptedBytes >= 0) {
            const instantSpeed = acceptedBytes / elapsedSeconds;
            speedBytesPerSecond.value = speedBytesPerSecond.value
                ? speedBytesPerSecond.value * 0.7 + instantSpeed * 0.3
                : instantSpeed;
            lastProgressAt = now;
            lastProgressBytes = bytesSent;
        }

        transferredBytes.value = bytesSent;
        etaSeconds.value =
            speedBytesPerSecond.value > 0
                ? Math.max(bytesTotal - bytesSent, 0) /
                  speedBytesPerSecond.value
                : null;
        connectionState.value = 'uploading';
        statusMessage.value = `${Math.floor((bytesSent / bytesTotal) * 100)} percent uploaded.`;
    }

    async function waitForProcessing(): Promise<void> {
        const uuid = reservation.value?.uuid;

        if (!uuid) {
            return;
        }

        for (let attempt = 0; attempt < 10; attempt += 1) {
            try {
                const response = await statusRequest.get(
                    UploadController.show.url(uuid),
                );

                if (reservation.value?.uuid !== uuid) {
                    return;
                }

                reservation.value = {
                    ...reservation.value,
                    ...response.data,
                };

                if (response.data.status !== 'uploading') {
                    applyTerminalOrProcessingState(response.data);

                    return;
                }
            } catch {
                // The transfer is complete; a later detail refresh can reconcile it.
            }

            await new Promise((resolve) => window.setTimeout(resolve, 500));
        }
    }

    function applyTerminalOrProcessingState(session: UploadSession): void {
        if (session.status === 'processing') {
            connectionState.value = 'received';
            uploadError.value = '';
            statusMessage.value = 'Validating media.';
            scheduleProcessingPoll(
                session.uuid,
                session.poll_interval_milliseconds,
            );

            return;
        }

        clearProcessingPoll();

        if (session.status === 'completed') {
            connectionState.value = 'received';
            uploadError.value = '';
            currentStep.value = 5;
            statusMessage.value =
                'Movie validated and placed in the Jellyfin library.';

            return;
        }

        if (session.status === 'failed') {
            connectionState.value = 'error';
            uploadError.value =
                session.failure?.detail ??
                'Media validation failed safely. The staged file was retained.';
            statusMessage.value = uploadError.value;
        }
    }

    function scheduleProcessingPoll(uuid: string, interval: number): void {
        clearProcessingPoll();
        const boundedInterval = Math.min(Math.max(interval, 500), 10_000);
        processingPollTimer = window.setTimeout(() => {
            void pollProcessingStatus(uuid);
        }, boundedInterval);
    }

    async function pollProcessingStatus(uuid: string): Promise<void> {
        processingPollTimer = null;

        if (
            reservation.value?.uuid !== uuid ||
            reservation.value.status !== 'processing'
        ) {
            return;
        }

        try {
            const response = await statusRequest.get(
                UploadController.show.url(uuid),
            );

            if (reservation.value?.uuid !== uuid) {
                return;
            }

            reservation.value = {
                ...reservation.value,
                ...response.data,
            };
            applyTerminalOrProcessingState(response.data);
        } catch {
            if (reservation.value?.uuid === uuid) {
                scheduleProcessingPoll(
                    uuid,
                    reservation.value.poll_interval_milliseconds,
                );
            }
        }
    }

    async function retryProcessing(): Promise<void> {
        const activeReservation = reservation.value;

        if (
            !activeReservation ||
            activeReservation.status !== 'failed' ||
            !activeReservation.failure?.can_retry ||
            processingRetryRequest.processing
        ) {
            return;
        }

        uploadError.value = '';

        try {
            const response = await processingRetryRequest.post(
                UploadController.retry.url(activeReservation.uuid),
                {
                    onHttpException: (exception) => {
                        uploadError.value = readError(
                            exception.data,
                            'Media validation could not be retried safely.',
                        );
                    },
                },
            );

            if (reservation.value?.uuid !== activeReservation.uuid) {
                return;
            }

            reservation.value = {
                ...activeReservation,
                ...response.data,
            };
            applyTerminalOrProcessingState(response.data);
        } catch {
            // The safe server message is displayed above.
        }
    }

    async function pauseUpload(): Promise<void> {
        const activeReservation = reservation.value;

        if (!activeReservation || !activeTusUpload || !isUploadBusy.value) {
            return;
        }

        await activeTusUpload.abort(false);
        activeTusUpload = null;

        try {
            const response = await pauseRequest.post(
                UploadPauseController.url(activeReservation.uuid),
            );

            if (reservation.value?.uuid !== activeReservation.uuid) {
                return;
            }

            reservation.value = {
                ...reservation.value,
                ...response.data,
                authorization: {
                    token: '',
                    abilities: [],
                    expires_at: new Date(0).toISOString(),
                },
            };
            connectionState.value = 'paused';
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            statusMessage.value =
                'Upload paused at the confirmed server offset.';
        } catch {
            connectionState.value = 'error';
            uploadError.value =
                'The upload stopped, but its paused state could not be confirmed.';
        }
    }

    function retryUpload(): void {
        void startUpload(true);
    }

    async function cancelReservation(): Promise<void> {
        const activeReservation = reservation.value;
        const discardingFailure = activeReservation?.status === 'failed';

        if (!activeReservation || isCancelling.value) {
            return;
        }

        if (activeTusUpload) {
            await activeTusUpload.abort(false);
            activeTusUpload = null;
        }

        cancellationRequestId += 1;
        const requestId = cancellationRequestId;

        reservationError.value = '';
        uploadError.value = '';

        const fallback = discardingFailure
            ? 'The retained failed upload could not be discarded safely.'
            : 'The reservation could not be cancelled.';
        const showCancellationError = (message: string): void => {
            if (discardingFailure) {
                uploadError.value = message;
            } else {
                reservationError.value = message;
            }
        };

        try {
            const response = await cancellationRequest.delete(
                UploadController.destroy.url(activeReservation.uuid),
                {
                    onHttpException: (exception) => {
                        if (requestId === cancellationRequestId) {
                            showCancellationError(
                                readError(exception.data, fallback),
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === cancellationRequestId) {
                            showCancellationError(fallback);
                        }
                    },
                },
            );

            if (
                requestId !== cancellationRequestId ||
                reservation.value?.uuid !== activeReservation.uuid
            ) {
                return;
            }

            if (discardingFailure) {
                resetWizardForNewUpload(
                    'Failed upload discarded. Select a source file to begin.',
                    false,
                );

                return;
            }

            reservation.value = {
                ...activeReservation,
                ...response.data,
                status: 'cancelled',
                authorization: {
                    token: '',
                    abilities: [],
                    expires_at: new Date(0).toISOString(),
                },
            };
            connectionState.value = 'cancelled';
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            uploadError.value = '';
            statusMessage.value =
                'Upload cancelled. The partial object and authorization were removed.';
        } catch {
            if (
                requestId === cancellationRequestId &&
                reservation.value?.uuid === activeReservation.uuid &&
                !(discardingFailure
                    ? uploadError.value
                    : reservationError.value)
            ) {
                showCancellationError(fallback);
            }
        }
    }

    function resetWizardForNewUpload(
        message = 'Select a source file to begin.',
        cancelActiveCancellation = true,
    ): void {
        cancelLookups();
        cancelConfirmation();
        cancelPreview();
        sourceFile.value = null;
        selectedMovie.value = null;
        confirmedMovie.value = null;
        pathPreview.value = null;
        resetReservationDraft(cancelActiveCancellation);
        currentStep.value = 1;
        statusMessage.value = message;
        void loadResumableSessions();
    }

    function beginNewUpload(): void {
        if (activeTusUpload || isUploadBusy.value || isCancelling.value) {
            return;
        }

        resetWizardForNewUpload();
    }

    function handleOffline(): void {
        if (activeTusUpload) {
            connectionState.value = 'offline';
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            statusMessage.value =
                'Connection lost. Uploaded chunks remain safely staged.';
        }
    }

    function handleOnline(): void {
        if (activeTusUpload && connectionState.value === 'offline') {
            connectionState.value = 'retrying';
            statusMessage.value =
                'Connection restored. Retrying from the server offset.';
        }
    }

    function interruptTransfer(): void {
        if (activeTusUpload) {
            void activeTusUpload.abort(false);
        }
    }

    onMounted(() => {
        void loadResumableSessions();
        window.addEventListener('offline', handleOffline);
        window.addEventListener('online', handleOnline);
        window.addEventListener('beforeunload', interruptTransfer);
    });

    onBeforeUnmount(() => {
        interruptTransfer();
        activeTusUpload = null;
        cancelLookups();
        cancelConfirmation();
        cancelPreview();
        cancelReservationRequests();
        cancellationRequestId += 1;
        cancellationRequest.cancel();
        recoveryRequestId += 1;
        sessionsRequest.cancel();
        authorizationRequest.cancel();
        pauseRequest.cancel();
        statusRequest.cancel();
        processingRetryRequest.cancel();
        clearProcessingPoll();
        window.removeEventListener('offline', handleOffline);
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('beforeunload', interruptTransfer);
    });

    return {
        currentStep,
        sourceFile,
        sourceFilename,
        searchInput,
        results,
        parsedFilename,
        selectedMovie,
        confirmedMovie,
        pathPreview,
        selectedDiskId,
        replacementConfirmed,
        reservation,
        lookupError,
        previewError,
        reservationError,
        recoveryError,
        uploadError,
        statusMessage,
        lookupCompleted,
        isLookingUp,
        isConfirming: computed(() => confirmation.processing),
        isCheckingDestination: computed(() => previewLookup.processing),
        isHashing,
        isReserving,
        isCancelling,
        isLoadingSessions,
        isUploadBusy,
        isPausing: computed(() => pauseRequest.processing),
        isRetryingProcessing: computed(() => processingRetryRequest.processing),
        isAdmissionBusy,
        resumableSessions,
        connectionState,
        transferredBytes,
        speedBytesPerSecond,
        etaSeconds,
        selectSource,
        recoverSession,
        openRetainedSession,
        runSmartSearch,
        selectMovie,
        confirmMovie,
        requestPathPreview,
        goToSource,
        goToIdentify,
        changeMovie,
        goToStorage,
        selectStorageAndStart,
        startUpload,
        pauseUpload,
        retryUpload,
        retryProcessing,
        cancelReservation,
        beginNewUpload,
    };
}
