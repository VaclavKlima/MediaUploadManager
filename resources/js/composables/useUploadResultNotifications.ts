import { onBeforeUnmount, onMounted, ref } from 'vue';

export type UploadResultNotificationState =
    'unsupported' | 'off' | 'requesting' | 'enabled' | 'blocked';

export type UploadResultNotificationOptions = {
    uploadUuid: string;
    result: 'completed' | 'failed';
    body: string;
    onClick?: () => void;
};

const notifiedUploadResults = new Set<string>();
const preferenceKey = 'movie-upload-completion-notifications';

function notificationsAreSupported(): boolean {
    return typeof window !== 'undefined' && 'Notification' in window;
}

function readPreference(preferenceKey: string): string | null {
    try {
        return window.localStorage.getItem(preferenceKey);
    } catch {
        return null;
    }
}

function writePreference(
    value: 'enabled' | 'disabled',
    preferenceKey = 'movie-upload-completion-notifications',
): void {
    try {
        window.localStorage.setItem(preferenceKey, value);
    } catch {
        // A private or restricted browser may deny storage without affecting uploads.
    }
}

export function useUploadResultNotifications(
    scope: 'movie' | 'series' = 'movie',
) {
    const scopedPreferenceKey =
        scope === 'movie'
            ? preferenceKey
            : 'series-upload-completion-notifications';
    const subject = scope === 'series' ? 'Show batch' : 'Movie upload';
    const state = ref<UploadResultNotificationState>('off');
    const requestError = ref('');

    function synchronizeState(): void {
        if (state.value === 'requesting') {
            return;
        }

        if (!notificationsAreSupported()) {
            state.value = 'unsupported';

            return;
        }

        if (window.Notification.permission === 'denied') {
            state.value = 'blocked';

            return;
        }

        state.value =
            window.Notification.permission === 'granted' &&
            readPreference(scopedPreferenceKey) === 'enabled'
                ? 'enabled'
                : 'off';
    }

    function handleStorageChange(event: StorageEvent): void {
        if (event.key === scopedPreferenceKey || event.key === null) {
            synchronizeState();
        }
    }

    function createNotification(
        title: string,
        body: string,
        onClick?: () => void,
    ): Notification | null {
        if (
            !notificationsAreSupported() ||
            state.value !== 'enabled' ||
            window.Notification.permission !== 'granted'
        ) {
            return null;
        }

        try {
            const notification = new window.Notification(title, {
                body,
                requireInteraction: true,
            });

            notification.onerror = () => {
                requestError.value =
                    'The browser or operating system could not display the notification. Check system notification settings and Focus or Do Not Disturb.';
            };
            notification.onclick = () => {
                window.focus();
                onClick?.();
                notification.close();
            };

            return notification;
        } catch {
            requestError.value =
                'The browser could not display the notification. Check system notification settings and try again.';

            return null;
        }
    }

    function sendTestNotification(): void {
        requestError.value = '';
        createNotification(
            'Notifications are working',
            `${subject} success and failure alerts are enabled for this browser.`,
        );
    }

    async function requestPermission(): Promise<void> {
        requestError.value = '';

        if (!notificationsAreSupported()) {
            state.value = 'unsupported';

            return;
        }

        if (window.Notification.permission === 'denied') {
            state.value = 'blocked';

            return;
        }

        state.value = 'requesting';

        try {
            const permission = await window.Notification.requestPermission();

            if (permission === 'granted') {
                if (scope === 'movie') {
                    writePreference('enabled');
                } else {
                    writePreference('enabled', scopedPreferenceKey);
                }

                state.value = 'enabled';
                sendTestNotification();

                return;
            }

            if (scope === 'movie') {
                writePreference('disabled');
            } else {
                writePreference('disabled', scopedPreferenceKey);
            }

            state.value = permission === 'denied' ? 'blocked' : 'off';

            if (permission === 'default') {
                requestError.value =
                    'Notification permission was not granted. You can try again.';
            }
        } catch {
            if (scope === 'movie') {
                writePreference('disabled');
            } else {
                writePreference('disabled', scopedPreferenceKey);
            }

            state.value = 'off';
            requestError.value =
                'Notifications could not be enabled. Your upload will continue normally.';
        }
    }

    function disableNotifications(): void {
        if (scope === 'movie') {
            writePreference('disabled');
        } else {
            writePreference('disabled', scopedPreferenceKey);
        }

        requestError.value = '';
        state.value = 'off';
    }

    function notifyUploadResult(
        options: UploadResultNotificationOptions,
    ): void {
        const uploadResultKey = `${options.uploadUuid}:${options.result}`;
        const notificationKey = `${scope}:${uploadResultKey}`;

        if (
            !notificationsAreSupported() ||
            state.value !== 'enabled' ||
            window.Notification.permission !== 'granted' ||
            document.visibilityState !== 'hidden' ||
            notifiedUploadResults.has(notificationKey)
        ) {
            return;
        }

        const notification = createNotification(
            options.result === 'completed'
                ? scope === 'movie'
                    ? 'Movie upload complete'
                    : 'Show batch complete'
                : scope === 'movie'
                  ? 'Movie upload needs attention'
                  : 'Show batch needs attention',
            options.body,
            options.onClick,
        );

        if (!notification) {
            return;
        }

        notifiedUploadResults.add(notificationKey);
    }

    onMounted(() => {
        synchronizeState();
        window.addEventListener('focus', synchronizeState);
        window.addEventListener('storage', handleStorageChange);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('focus', synchronizeState);
        window.removeEventListener('storage', handleStorageChange);
    });

    return {
        state,
        requestError,
        requestPermission,
        disableNotifications,
        sendTestNotification,
        notifyUploadResult,
    };
}
