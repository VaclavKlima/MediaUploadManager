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
