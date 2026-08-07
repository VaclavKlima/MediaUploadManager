<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileVideo2,
    Film,
    HardDrive,
    ShieldCheck,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { upload as movieUpload } from '@/routes/movies';

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
                    <Button size="lg" class="w-fit" as-child>
                        <Link :href="movieUpload()">
                            Upload movie
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
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
    </div>
</template>
