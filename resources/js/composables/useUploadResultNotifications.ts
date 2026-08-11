import { onBeforeUnmount, onMounted, ref } from 'vue';

export type UploadResultNotificationState =
    'unsupported' | 'off' | 'requesting' | 'enabled' | 'blocked';

export type UploadResultNotificationOptions = {
    uploadUuid: string;
    result: 'completed' | 'failed';
    body: string;
    onClick?: () => void;
};

const preferenceKey = 'movie-upload-completion-notifications';
const notifiedUploadResults = new Set<string>();

function notificationsAreSupported(): boolean {
    return typeof window !== 'undefined' && 'Notification' in window;
}

function readPreference(): string | null {
    try {
        return window.localStorage.getItem(preferenceKey);
    } catch {
        return null;
    }
}

function writePreference(value: 'enabled' | 'disabled'): void {
    try {
        window.localStorage.setItem(preferenceKey, value);
    } catch {
        // A private or restricted browser may deny storage without affecting uploads.
    }
}

export function useUploadResultNotifications() {
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
            readPreference() === 'enabled'
                ? 'enabled'
                : 'off';
    }

    function handleStorageChange(event: StorageEvent): void {
        if (event.key === preferenceKey || event.key === null) {
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
            'Movie upload success and failure alerts are enabled for this browser.',
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
                writePreference('enabled');
                state.value = 'enabled';
                sendTestNotification();

                return;
            }

            writePreference('disabled');
            state.value = permission === 'denied' ? 'blocked' : 'off';

            if (permission === 'default') {
                requestError.value =
                    'Notification permission was not granted. You can try again.';
            }
        } catch {
            writePreference('disabled');
            state.value = 'off';
            requestError.value =
                'Notifications could not be enabled. Your upload will continue normally.';
        }
    }

    function disableNotifications(): void {
        writePreference('disabled');
        requestError.value = '';
        state.value = 'off';
    }

    function notifyUploadResult(
        options: UploadResultNotificationOptions,
    ): void {
        const notificationKey = `${options.uploadUuid}:${options.result}`;

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
                ? 'Movie upload complete'
                : 'Movie upload needs attention',
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
