<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Library, Plus, SkipForward } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { SeriesQueueItem } from '@/composables/useSeriesUploadWizard';
import { index as seriesIndex } from '@/routes/series';
import type { SeriesBatch } from '@/types/series';

const props = defineProps<{
    batch: SeriesBatch;
    items: SeriesQueueItem[];
    completedCount: number;
    skippedCount: number;
}>();

defineEmits<{ uploadMore: [] }>();

const outcome = computed(() => {
    if (props.completedCount === 0) {
        return {
            title: 'Show batch skipped',
            description:
                'No new episodes were added; every item was explicitly skipped.',
        };
    }

    if (props.skippedCount > 0) {
        return {
            title: 'Show upload completed with skips',
            description: `${props.completedCount} uploaded and ${props.skippedCount} skipped.`,
        };
    }

    return {
        title: 'Show upload complete',
        description: `${props.completedCount} ${props.completedCount === 1 ? 'episode was' : 'episodes were'} uploaded and validated.`,
    };
});

const finalizedBytes = computed(() =>
    props.items.reduce(
        (total, item) => total + (item.batchItem.finalized?.size_bytes ?? 0),
        0,
    ),
);

function formatBytes(bytes: number): string {
    return `${new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 1,
    }).format(bytes / 1_000_000_000)} GB`;
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-6 py-8">
        <div class="flex flex-col items-center gap-3 text-center">
            <span
                class="flex size-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600"
            >
                <CheckCircle2 class="size-8" />
            </span>
            <p class="text-xs font-medium text-primary">Step 6 of 6</p>
            <h2
                id="wizard-step-6"
                tabindex="-1"
                class="text-3xl font-semibold tracking-tight outline-none"
            >
                {{ outcome.title }}
            </h2>
            <p class="max-w-2xl text-sm leading-6 text-muted-foreground">
                {{ outcome.description }}
            </p>
        </div>

        <div class="rounded-2xl border bg-card p-5">
            <div class="flex items-center justify-between gap-3 border-b pb-4">
                <div>
                    <h3 class="font-semibold">{{ batch.series.name }}</h3>
                    <p class="text-xs text-muted-foreground">
                        Stored on
                        {{ batch.home_disk.label ?? batch.home_disk.id }}
                    </p>
                </div>
                <Badge>{{ items.length }} episodes</Badge>
            </div>
            <dl class="grid gap-3 border-b py-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">Uploaded</dt>
                    <dd class="font-semibold">{{ completedCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Skipped</dt>
                    <dd class="font-semibold">{{ skippedCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Finalized size
                    </dt>
                    <dd class="font-semibold">
                        {{ formatBytes(finalizedBytes) }}
                    </dd>
                </div>
            </dl>
            <ul class="divide-y">
                <li
                    v-for="item in items"
                    :key="item.batchItem.upload_uuid"
                    class="flex items-center gap-3 py-3"
                >
                    <CheckCircle2
                        v-if="item.status === 'completed'"
                        class="size-5 shrink-0 text-emerald-600"
                    />
                    <SkipForward
                        v-else
                        class="size-5 shrink-0 text-muted-foreground"
                    />
                    <span class="min-w-0 flex-1 text-sm">
                        <span class="block truncate font-medium"
                            >{{ item.batchItem.episode.identity }} ·
                            {{ item.batchItem.episode.title }}</span
                        >
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{
                                item.batchItem.finalized?.relative_path ??
                                item.batchItem.destination
                            }}
                            <template v-if="item.batchItem.replacement">
                                · Replacement
                            </template>
                        </span>
                    </span>
                    <Badge variant="outline">{{
                        item.status === 'completed' ? 'Uploaded' : 'Skipped'
                    }}</Badge>
                </li>
            </ul>
        </div>

        <div class="flex flex-wrap justify-center gap-3">
            <Button as-child variant="outline">
                <Link :href="seriesIndex()">
                    <Library class="size-4" /> View Shows
                </Link>
            </Button>
            <Button type="button" @click="$emit('uploadMore')">
                <Plus class="size-4" /> Upload more episodes
            </Button>
        </div>
    </section>
</template>
