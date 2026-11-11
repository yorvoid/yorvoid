export function wireCodes(root, browser = browserEnvironment) {
    const group = root.querySelector('[data-yorvoid-save-codes]');
    if (group === null) {
        return;
    }
    // Only on a page with the codes: the others don't need the browser's clipboard.
    const environment = browser();
    const codes = Array.from(root.querySelectorAll('[data-yorvoid-recovery-codes] code'), (code) => code.textContent?.trim() ?? '').filter((code) => code !== '');
    const name = group.dataset['yorvoidSite'] || environment.hostname;
    const site = fileName(name);
    const text = () => `${group.dataset['yorvoidHeading'] ?? ''} ${name}, ${environment.now().toISOString().slice(0, 10)}\n\n${codes.join('\n')}\n`;
    const control = (selector) => group.querySelector(selector);
    control('[data-yorvoid-download]')?.addEventListener('click', () => environment.save(`${site}-recovery-codes.txt`, text()));
    control('[data-yorvoid-print]')?.addEventListener('click', () => environment.print());
    const copy = control('[data-yorvoid-copy]');
    const copied = control('[data-yorvoid-copied]');
    const write = environment.copy;
    if (copy !== null) {
        if (write === null) {
            copy.hidden = true;
        }
        else {
            copy.addEventListener('click', () => {
                write(text()).then(() => {
                    if (copied !== null) {
                        copied.hidden = false;
                    }
                }, () => undefined);
            });
        }
    }
    group.hidden = false;
}
/**
 * The host name as a file name: only [a-z0-9.-], so nothing in it can name another place.
 */
export function fileName(hostname) {
    return hostname.toLowerCase().replace(/[^a-z0-9.-]/g, '') || 'site';
}
/**
 * The browser's: a Blob downloaded through an <a download>, the clipboard, window.print().
 */
export function browserEnvironment() {
    const clipboard = typeof navigator.clipboard?.writeText === 'function' && window.isSecureContext ? navigator.clipboard : null;
    return {
        hostname: location.hostname,
        now: () => new Date(),
        save: (name, text) => {
            const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 0);
        },
        copy: clipboard === null ? null : (text) => clipboard.writeText(text),
        print: () => window.print(),
    };
}
