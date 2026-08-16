<script setup lang="ts">
import { Deferred, Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CircleCheck,
    CirclePause,
    CircleX,
    ClockAlert,
    ExternalLink,
    FileVideo2,
    Film,
    HardDrive,
    LoaderCircle,
    RotateCcw,
    ServerCog,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import UploadResultNotificationControl from '@/components/UploadResultNotificationControl.vue';
import { useUploadResultNotifications } from '@/composables/useUploadResultNotifications';
import { dashboard, operations } from '@/routes';
import { upload as movieUpload } from '@/routes/movies';
import { upload as seriesUpload } from '@/routes/series';
import type { DiskOverview, UploadOverview } from '@/types/dashboard';

const props = defineProps<{
    uploadOverview: UploadOverview;
    diskOverview?: DiskOverview;
}>();

const uploadNotifications = useUploadResultNotifications();

const scopeLabel = computed(() =>
    props.uploadOverview.scope === 'installation'
        ? 'All users'
        : 'Your uploads',
);

const summaryCards = computed(() => [
    {
        key: 'active',
        label: 'Active',
        description: 'Pending or uploading',
        count: props.uploadOverview.counts.active,
        icon: Activity,
        iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
    {
        key: 'paused',
        label: 'Paused',
        description: 'Waiting to resume',
        count: props.uploadOverview.counts.paused,
        icon: CirclePause,
        iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    {
        key: 'processing',
        label: 'Processing',
        description: 'Validating and finalizing',
        count: props.uploadOverview.counts.processing,
        icon: LoaderCircle,
        iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    {
        key: 'failed',
        label: 'Failed',
        description: 'Needs attention',
        count: props.uploadOverview.counts.failed,
        icon: CircleX,
        iconClass: 'bg-destructive/10 text-destructive',
    },
    {
        key: 'expiring',
        label: 'Expiring soon',
        description: 'Due within 24 hours',
        count: props.uploadOverview.counts.expiring,
        icon: ClockAlert,
        iconClass: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    },
]);

const byteFormatter = new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 1,
});

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'Unavailable';
    }

    if (bytes >= 1_099_511_627_776) {
        return `${byteFormatter.format(bytes / 1_099_511_627_776)} TB`;
    }

    if (bytes >= 1_073_741_824) {
        return `${byteFormatter.format(bytes / 1_073_741_824)} GB`;
    }

    return `${byteFormatter.format(bytes / 1_048_576)} MB`;
}

function formatDeadline(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function retryDiskOverview(): void {
    router.reload({ only: ['diskOverview'] });
}

usePoll(
    15_000,
    {
        only: ['uploadOverview'],
    },
    {
        mode: 'rest',
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Head title="Dashboard" />

        <section
            class="relative isolate overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
        >
            <div
                class="absolute inset-0 -z-10 bg-gradient-to-br from-primary/12 via-transparent to-primary/5"
            />
            <div
                class="grid items-center gap-8 p-6 md:p-10 lg:grid-cols-[1fr_auto]"
            >
                <div class="flex max-w-2xl flex-col gap-5">
                    <span
                        class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm"
                    >
                        <Film class="size-6" />
                    </span>
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-medium text-primary">
                            Movie library
                        </p>
                        <h1
                            class="text-3xl font-semibold tracking-tight md:text-4xl"
                        >
                            Add a movie safely
                        </h1>
                        <p
                            class="max-w-xl text-sm leading-6 text-muted-foreground md:text-base"
                        >
                            Choose the source, confirm the movie, and check its
                            exact Jellyfin destination in a focused five-step
                            wizard.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button size="lg" as-child>
                            <Link :href="movieUpload()">
                                Upload movie
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="seriesUpload()">
                                Upload show episodes
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                    </div>
                </div>

                <div
                    class="grid min-w-64 gap-3 rounded-2xl border bg-background/80 p-4 shadow-sm backdrop-blur-sm"
                    aria-label="Upload preparation overview"
                >
                    <div class="flex items-center gap-3">
                        <FileVideo2 class="size-5 text-primary" />
                        <span class="text-sm font-medium"
                            >File-first preparation</span
                        >
                    </div>
                    <div class="flex items-center gap-3">
                        <ShieldCheck class="size-5 text-primary" />
                        <span class="text-sm font-medium"
                            >Global conflict checks</span
                        >
                    </div>
                    <div class="flex items-center gap-3">
                        <HardDrive class="size-5 text-primary" />
                        <span class="text-sm font-medium"
                            >Exact path preview</span
                        >
                    </div>
                </div>
            </div>
        </section>

        <UploadResultNotificationControl
            :state="uploadNotifications.state.value"
            :error-message="uploadNotifications.requestError.value"
            @enable="uploadNotifications.requestPermission"
            @disable="uploadNotifications.disableNotifications"
            @test="uploadNotifications.sendTestNotification"
        />

        <section class="flex flex-col gap-4" aria-labelledby="upload-overview">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="flex flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2
                            id="upload-overview"
                            class="text-xl font-semibold tracking-tight"
                        >
                            Operational overview
                        </h2>
                        <Badge variant="outline">{{ scopeLabel }}</Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Upload status refreshes automatically every 15 seconds.
                    </p>
                </div>

                <Button
                    v-if="uploadOverview.scope === 'installation'"
                    variant="outline"
                    as-child
                >
                    <a :href="operations().url">
                        <ServerCog class="size-4" />
                        Open operations
                        <ExternalLink class="size-3.5" />
                    </a>
                </Button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <Card
                    v-for="summary in summaryCards"
                    :key="summary.key"
                    class="gap-4 py-5"
                    :aria-labelledby="`summary-${summary.key}`"
                >
                    <CardHeader class="px-5">
                        <div
                            :class="[
                                'flex size-9 items-center justify-center rounded-lg',
                                summary.iconClass,
                            ]"
                        >
                            <component :is="summary.icon" class="size-4.5" />
                        </div>
                        <CardAction class="text-3xl font-semibold tabular-nums">
                            {{ summary.count.toLocaleString() }}
                        </CardAction>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-0.5 px-5">
                        <h3 :id="`summary-${summary.key}`" class="font-medium">
                            {{ summary.label }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ summary.description }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section
            class="grid items-start gap-4 xl:grid-cols-2"
            aria-label="Upload warnings"
        >
            <Card>
                <CardHeader>
                    <div
                        class="flex size-9 items-center justify-center rounded-lg bg-destructive/10 text-destructive"
                    >
                        <AlertTriangle class="size-4.5" />
                    </div>
                    <CardTitle>Failed uploads</CardTitle>
                    <CardDescription>
                        Review recent failures that require owner attention.
                    </CardDescription>
                    <CardAction>
                        <Badge
                            :variant="
                                uploadOverview.counts.failed
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{ uploadOverview.counts.failed }}
                        </Badge>
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="uploadOverview.warnings.failed.length === 0"
                        class="flex items-center gap-3 rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        <CircleCheck
                            class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        No failed uploads need attention.
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <article
                            v-for="warning in uploadOverview.warnings.failed"
                            :key="warning.uuid"
                            class="flex flex-col gap-3 rounded-xl border bg-muted/15 p-4 sm:flex-row sm:items-center"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate font-medium">
                                        {{ warning.original_filename }}
                                    </h3>
                                    <Badge variant="destructive">
                                        {{ warning.status }}
                                    </Badge>
                                </div>
                                <p
                                    v-if="warning.owner_name"
                                    class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <UserRound class="size-3" />
                                    {{ warning.owner_name }}
                                </p>
                                <p
                                    class="mt-2 text-sm leading-5 text-muted-foreground"
                                >
                                    {{
                                        warning.failure_detail ??
                                        'The upload could not be processed.'
                                    }}
                                </p>
                            </div>
                            <Button
                                v-if="warning.can_open_recovery"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link :href="movieUpload()">
                                    <RotateCcw class="size-4" />
                                    Review upload
                                </Link>
                            </Button>
                            <Badge v-else variant="outline">
                                Owner action required
                            </Badge>
                        </article>
                        <p
                            v-if="
                                uploadOverview.counts.failed >
                                uploadOverview.warnings.failed.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            +{{
                                uploadOverview.counts.failed -
                                uploadOverview.warnings.failed.length
                            }}
                            older failures not shown
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div
                        class="flex size-9 items-center justify-center rounded-lg bg-orange-500/10 text-orange-600 dark:text-orange-400"
                    >
                        <ClockAlert class="size-4.5" />
                    </div>
                    <CardTitle>Expiring soon</CardTitle>
                    <CardDescription>
                        Active or paused sessions due within 24 hours.
                    </CardDescription>
                    <CardAction>
                        <Badge
                            :variant="
                                uploadOverview.counts.expiring
                                    ? 'default'
                                    : 'secondary'
                            "
                        >
                            {{ uploadOverview.counts.expiring }}
                        </Badge>
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="uploadOverview.warnings.expiring.length === 0"
                        class="flex items-center gap-3 rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        <CircleCheck
                            class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        No upload sessions expire within 24 hours.
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <article
                            v-for="warning in uploadOverview.warnings.expiring"
                            :key="warning.uuid"
                            class="flex flex-col gap-3 rounded-xl border bg-muted/15 p-4 sm:flex-row sm:items-center"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate font-medium">
                                        {{ warning.original_filename }}
                                    </h3>
                                    <Badge variant="outline">
                                        {{ warning.status }}
                                    </Badge>
                                </div>
                                <p
                                    v-if="warning.owner_name"
                                    class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <UserRound class="size-3" />
                                    {{ warning.owner_name }}
                                </p>
                                <div
                                    class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        >{{ warning.progress_percentage }}%
                                        uploaded</span
                                    >
                                    <span
                                        >{{
                                            formatBytes(warning.confirmed_bytes)
                                        }}
                                        of
                                        {{
                                            formatBytes(warning.declared_bytes)
                                        }}</span
                                    >
                                    <span
                                        >Deadline:
                                        {{
                                            formatDeadline(warning.expires_at)
                                        }}</span
                                    >
                                </div>
                            </div>
                            <Button
                                v-if="warning.can_open_recovery"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link :href="movieUpload()">
                                    <RotateCcw class="size-4" />
                                    Continue upload
                                </Link>
                            </Button>
                            <Badge v-else variant="outline">
                                Owner action required
                            </Badge>
                        </article>
                        <p
                            v-if="
                                uploadOverview.counts.expiring >
                                uploadOverview.warnings.expiring.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            +{{
                                uploadOverview.counts.expiring -
                                uploadOverview.warnings.expiring.length
                            }}
                            additional sessions not shown
                        </p>
                    </div>
                </CardContent>
            </Card>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="disk-health">
            <div class="flex flex-col gap-1">
                <h2
                    id="disk-health"
                    class="text-xl font-semibold tracking-tight"
                >
                    Disk health
                </h2>
                <p class="text-sm text-muted-foreground">
                    Movie and show root availability is checked once when this
                    page opens.
                </p>
            </div>

            <Deferred data="diskOverview">
                <template #fallback>
                    <div
                        class="grid animate-pulse gap-4 md:grid-cols-2 xl:grid-cols-3"
                        role="status"
                        aria-label="Checking disk health"
                    >
                        <Card v-for="index in 3" :key="index">
                            <CardHeader>
                                <Skeleton class="size-10 rounded-lg" />
                                <Skeleton class="h-5 w-32" />
                                <Skeleton class="h-4 w-44" />
                            </CardHeader>
                            <CardContent class="grid grid-cols-2 gap-3">
                                <Skeleton class="h-14 rounded-lg" />
                                <Skeleton class="h-14 rounded-lg" />
                            </CardContent>
                        </Card>
                    </div>
                </template>

                <template #rescue="{ reloading }">
                    <Card class="border-destructive/40">
                        <CardContent
                            class="flex flex-col items-start gap-3 sm:flex-row sm:items-center"
                        >
                            <AlertTriangle
                                class="size-5 shrink-0 text-destructive"
                            />
                            <div class="flex-1">
                                <h3 class="font-medium">
                                    Disk health could not be loaded
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    The upload overview remains available. Try
                                    the filesystem check again.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                :disabled="reloading"
                                @click="retryDiskOverview"
                            >
                                <RotateCcw
                                    :class="[
                                        'size-4',
                                        reloading && 'motion-safe:animate-spin',
                                    ]"
                                />
                                {{ reloading ? 'Checking…' : 'Retry' }}
                            </Button>
                        </CardContent>
                    </Card>
                </template>

                <template v-if="diskOverview">
                    <Card
                        v-if="diskOverview.status === 'unavailable'"
                        class="border-amber-500/40"
                    >
                        <CardContent class="flex items-start gap-3">
                            <AlertTriangle
                                class="size-5 shrink-0 text-amber-600 dark:text-amber-400"
                            />
                            <div>
                                <h3 class="font-medium">
                                    Disk health is unavailable
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        diskOverview.message ??
                                        'The disk configuration could not be checked.'
                                    }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-else-if="diskOverview.volumes.length === 0">
                        <CardContent class="flex items-start gap-3">
                            <HardDrive
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <div>
                                <h3 class="font-medium">
                                    No media disks configured
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Add a media disk before accepting uploads.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div v-else class="grid gap-4 xl:grid-cols-2">
                        <Card
                            v-for="volume in diskOverview.volumes"
                            :key="volume.id"
                        >
                            <CardHeader>
                                <div
                                    :class="[
                                        'flex size-10 items-center justify-center rounded-lg',
                                        volume.health === 'healthy'
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                            : 'bg-destructive/10 text-destructive',
                                    ]"
                                >
                                    <HardDrive class="size-5" />
                                </div>
                                <CardTitle>{{ volume.label }}</CardTitle>
                                <CardDescription>
                                    Shared filesystem capacity
                                </CardDescription>
                                <CardAction
                                    class="flex flex-wrap justify-end gap-1"
                                >
                                    <Badge
                                        :variant="
                                            volume.health === 'healthy'
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                    >
                                        {{ volume.health }}
                                    </Badge>
                                    <Badge variant="outline">
                                        {{
                                            volume.eligible
                                                ? 'Eligible'
                                                : 'Ineligible'
                                        }}
                                    </Badge>
                                </CardAction>
                            </CardHeader>
                            <CardContent class="flex flex-col gap-4">
                                <dl class="grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-lg bg-muted/40 p-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Free
                                        </dt>
                                        <dd
                                            class="mt-1 font-medium tabular-nums"
                                        >
                                            {{ formatBytes(volume.free_bytes) }}
                                        </dd>
                                    </div>
                                    <div class="rounded-lg bg-muted/40 p-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Capacity
                                        </dt>
                                        <dd
                                            class="mt-1 font-medium tabular-nums"
                                        >
                                            {{
                                                formatBytes(volume.total_bytes)
                                            }}
                                        </dd>
                                    </div>
                                </dl>

                                <div
                                    class="flex flex-col gap-3"
                                    aria-label="Configured media disks"
                                >
                                    <div
                                        v-for="disk in volume.disks"
                                        :key="disk.id"
                                        class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                                    >
                                        <div
                                            class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                                        >
                                            <div>
                                                <h3 class="font-medium">
                                                    {{ disk.label }}
                                                </h3>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{ disk.id }}
                                                </p>
                                            </div>
                                            <div class="flex flex-wrap gap-1">
                                                <Badge
                                                    :variant="
                                                        disk.health ===
                                                        'healthy'
                                                            ? 'secondary'
                                                            : 'destructive'
                                                    "
                                                >
                                                    {{ disk.health }}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {{
                                                        disk.eligible
                                                            ? 'Eligible'
                                                            : 'Ineligible'
                                                    }}
                                                </Badge>
                                            </div>
                                        </div>

                                        <dl
                                            class="grid grid-cols-2 gap-3 text-sm"
                                        >
                                            <div
                                                class="rounded-lg bg-muted/40 p-3"
                                            >
                                                <dt
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    Usable
                                                </dt>
                                                <dd
                                                    class="mt-1 font-medium tabular-nums"
                                                >
                                                    {{
                                                        formatBytes(
                                                            disk.usable_bytes,
                                                        )
                                                    }}
                                                </dd>
                                            </div>
                                            <div
                                                class="rounded-lg bg-muted/40 p-3"
                                            >
                                                <dt
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    Safety reserve
                                                </dt>
                                                <dd
                                                    class="mt-1 font-medium tabular-nums"
                                                >
                                                    {{
                                                        formatBytes(
                                                            disk.safety_reserve_bytes,
                                                        )
                                                    }}
                                                </dd>
                                            </div>
                                        </dl>

                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <div
                                                v-for="root in disk.roots"
                                                :key="root.kind"
                                                :class="[
                                                    'flex flex-col gap-2 rounded-lg border p-3',
                                                    root.health === 'healthy'
                                                        ? 'border-emerald-500/25 bg-emerald-500/5'
                                                        : 'border-amber-500/30 bg-amber-500/5',
                                                ]"
                                            >
                                                <div
                                                    class="flex flex-wrap items-center justify-between gap-2"
                                                >
                                                    <Badge variant="outline">
                                                        {{
                                                            root.kind ===
                                                            'movies'
                                                                ? 'Movies'
                                                                : 'Show'
                                                        }}
                                                    </Badge>
                                                    <div
                                                        class="flex flex-wrap gap-1"
                                                    >
                                                        <Badge
                                                            :variant="
                                                                root.health ===
                                                                'healthy'
                                                                    ? 'secondary'
                                                                    : 'destructive'
                                                            "
                                                        >
                                                            {{ root.health }}
                                                        </Badge>
                                                        <Badge
                                                            variant="outline"
                                                        >
                                                            {{
                                                                root.eligible
                                                                    ? 'Eligible'
                                                                    : 'Ineligible'
                                                            }}
                                                        </Badge>
                                                    </div>
                                                </div>
                                                <p
                                                    v-for="reason in root.reasons"
                                                    :key="reason.code"
                                                    class="text-xs leading-5 text-muted-foreground"
                                                >
                                                    {{ reason.message }}
                                                </p>
                                                <p
                                                    v-if="
                                                        root.reasons.length ===
                                                        0
                                                    "
                                                    class="flex items-center gap-2 text-xs text-muted-foreground"
                                                >
                                                    <CircleCheck
                                                        class="size-4 text-emerald-600 dark:text-emerald-400"
                                                    />
                                                    Checks passed.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </template>
            </Deferred>
        </section>
    </div>
</template>
