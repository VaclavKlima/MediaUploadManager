export type MovieLibraryState =
    'available' | 'in_progress' | 'failed' | 'orphaned' | 'deleting';

export type MovieLibraryFile = {
    id: number;
    disk: {
        id: string;
        label: string | null;
    };
    relative_path: string;
    size_bytes: number;
    finalized_at: string;
    owner: {
        id: number;
        name: string;
    } | null;
};

export type MovieLibraryItem = {
    id: number;
    title: string;
    original_title: string | null;
    release_year: number | null;
    tmdb_id: number;
    imdb_id: string | null;
    poster_url: string | null;
    state: MovieLibraryState;
    current_file: MovieLibraryFile | null;
    can_delete: boolean;
    deletion_blocker: string | null;
    can_reidentify: boolean;
    reidentification_blocker: string | null;
    reidentification: {
        id: number;
        status: 'pending' | 'failed' | 'completed';
        error_code: string | null;
        error_detail: string | null;
        completed_at: string | null;
    } | null;
};

export type MovieIdentity = {
    tmdb_id: number;
    imdb_id: string | null;
    title: string;
    original_title: string | null;
    release_year: number | null;
};

export type MovieReidentificationPreview = {
    current_identity: MovieIdentity;
    proposed_identity: MovieIdentity;
    current_relative_path: string | null;
    proposed_relative_path: string | null;
    disk: {
        id: string;
        label: string | null;
    } | null;
    size_bytes: number | null;
    eligible: boolean;
    blocker: {
        code: string;
        message: string;
    } | null;
    retry: {
        operation_id: number;
        status: string;
        error_code: string | null;
        error_detail: string | null;
    } | null;
};

export type MovieLibraryPaginator = {
    data: MovieLibraryItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type MovieLibraryFilters = {
    search: string | null;
    status: MovieLibraryState | null;
    sort: 'newest' | 'title';
};
