export interface UploadOverviewCounts {
    active: number;
    paused: number;
    processing: number;
    failed: number;
    expiring: number;
}

export interface UploadWarning {
    uuid: string;
    original_filename: string;
    status: string;
    owner_name?: string;
    can_open_recovery: boolean;
}

export interface FailedUploadWarning extends UploadWarning {
    failure_detail: string | null;
}

export interface ExpiringUploadWarning extends UploadWarning {
    confirmed_bytes: number;
    declared_bytes: number;
    progress_percentage: number;
    expires_at: string;
}

export interface UploadOverview {
    scope: 'personal' | 'installation';
    generated_at: string;
    expiry_warning_cutoff: string;
    counts: UploadOverviewCounts;
    warnings: {
        failed: FailedUploadWarning[];
        expiring: ExpiringUploadWarning[];
    };
}

export interface DiskHealthReason {
    code: string;
    message: string;
}

export interface DashboardDiskHealth {
    id: string;
    label: string;
    health: 'healthy' | 'unhealthy';
    eligible: boolean;
    total_bytes: number | null;
    free_bytes: number | null;
    safety_reserve_bytes: number;
    usable_bytes: number | null;
    reasons: DiskHealthReason[];
}

export interface DiskOverview {
    status: 'available' | 'unavailable';
    checked_at: string;
    message: string | null;
    disks: DashboardDiskHealth[];
}
