<script setup lang="ts">
import { Bell, BellOff, ChevronDown, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { UploadResultNotificationState } from '@/composables/useUploadResultNotifications';

const props = defineProps<{
    state: UploadResultNotificationState;
    errorMessage: string;
    subject?: 'Movie' | 'Show';
}>();

defineEmits<{
    enable: [];
    disable: [];
    test: [];
}>();

const stateLabel = computed(() => {
    const labels: Record<UploadResultNotificationState, string> = {
        unsupported: 'Unsupported',
        off: 'Off',
        requesting: 'Requesting',
        enabled: 'Enabled',
        blocked: 'Blocked',
    };

    return labels[props.state];
});
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border bg-muted/20 p-4 sm:flex-row sm:items-start sm:justify-between"
        aria-labelledby="upload-result-notifications-heading"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground shadow-sm"
            >
                <Bell v-if="state === 'enabled'" class="size-4 text-primary" />
                <BellOff v-else class="size-4" />
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2
                        id="upload-result-notifications-heading"
                        class="text-sm font-semibold"
                    >
                        <template v-if="subject">
                            {{ subject }} upload notifications
                        </template>
                        <template v-else>Upload result notifications</template>
                    </h2>
                    <Badge
                        :variant="
                            state === 'blocked'
                                ? 'destructive'
                                : state === 'enabled'
                                  ? 'secondary'
                                  : 'outline'
                        "
                    >
                        {{ stateLabel }}
                    </Badge>
                </div>
                <p class="mt-1 text-sm leading-5 text-muted-foreground">
                    <template v-if="state === 'unsupported'">
                        This browser does not support desktop notifications.
                    </template>
                    <template v-else-if="state === 'blocked'">
                        Permission is blocked. Allow notifications in your
                        browser's site settings to enable them.
                    </template>
                    <template v-else-if="state === 'enabled'">
                        Notifications are enabled for this browser. Send a test
                        alert to confirm your browser and operating system can
                        display them.
                    </template>
                    <template v-else>
                        <template v-if="subject === 'Show'">
                            Enable once for this browser to get desktop alerts
                            when Show uploads finish or need attention.
                        </template>
                        <template v-else>
                            Enable once for this browser to get desktop alerts
                            when an upload succeeds or needs attention.
                        </template>
                    </template>
                </p>
                <p
                    v-if="state !== 'unsupported'"
                    class="mt-1 text-xs leading-5 text-muted-foreground"
                >
                    This setting stays saved in this browser. The upload page
                    must remain open for alerts.
                </p>
                <p
                    v-if="errorMessage"
                    class="mt-2 text-sm text-destructive"
                    role="alert"
                >
                    {{ errorMessage }}
                </p>
                <details
                    v-if="state !== 'unsupported'"
                    class="group mt-3 rounded-lg border bg-background/70"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-xs font-medium select-none [&::-webkit-details-marker]:hidden"
                    >
                        Notification setup help
                        <ChevronDown
                            class="size-4 shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none"
                        />
                    </summary>
                    <dl
                        class="grid gap-3 border-t p-3 text-xs leading-5 text-muted-foreground sm:grid-cols-2"
                    >
                        <div>
                            <dt class="font-medium text-foreground">Browser</dt>
                            <dd>
                                Open this site's permissions from the address
                                bar and set Notifications to Allow.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-foreground">macOS</dt>
                            <dd>
                                Open System Settings → Notifications → your
                                browser, then enable Allow notifications. Also
                                check that Focus is not silencing the browser.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-foreground">Windows</dt>
                            <dd>
                                Open Settings → System → Notifications, enable
                                notifications for your browser, and check Do not
                                disturb.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-foreground">Linux</dt>
                            <dd>
                                Open your desktop environment's Settings →
                                Notifications, enable your browser, and turn off
                                Do Not Disturb. Names vary by desktop.
                            </dd>
                        </div>
                    </dl>
                </details>
            </div>
        </div>

        <Button
            v-if="state === 'off'"
            type="button"
            variant="outline"
            size="sm"
            class="shrink-0"
            @click="$emit('enable')"
        >
            <Bell class="size-4" /> Enable notifications
        </Button>
        <Button
            v-else-if="state === 'requesting'"
            type="button"
            variant="outline"
            size="sm"
            class="shrink-0"
            disabled
        >
            <LoaderCircle class="size-4 motion-safe:animate-spin" />
            Requesting permission
        </Button>
        <div
            v-else-if="state === 'enabled'"
            class="flex shrink-0 flex-wrap gap-2"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="$emit('test')"
            >
                <Bell class="size-4" /> Send test
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="$emit('disable')"
            >
                <BellOff class="size-4" /> Turn off
            </Button>
        </div>
    </section>
</template>
