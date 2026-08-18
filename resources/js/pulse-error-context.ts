const fallbackCopy = (text: string): boolean => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    const copied = document.execCommand('copy');
    textarea.remove();

    return copied;
};

const copyContext = async (button: HTMLButtonElement): Promise<void> => {
    const sourceId = button.dataset.copySource;
    const source = sourceId ? document.getElementById(sourceId) : null;
    const label = button.querySelector<HTMLElement>('[data-copy-label]');

    if (!(source instanceof HTMLTextAreaElement) || label === null) {
        return;
    }

    const originalLabel = label.textContent ?? 'Copy';

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(source.value);
        } else if (!fallbackCopy(source.value)) {
            throw new Error('Clipboard access is unavailable.');
        }

        label.textContent = 'Copied';
    } catch {
        label.textContent = 'Copy failed';
    }

    window.setTimeout(() => {
        label.textContent = originalLabel;
    }, 2000);
};

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest<HTMLButtonElement>(
        '[data-copy-context]',
    );

    if (button === null) {
        return;
    }

    void copyContext(button);
});
