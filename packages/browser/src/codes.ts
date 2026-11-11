/**
 * Saving the recovery codes from the page that shows them (UX, M2-10 U-3): a [data-yorvoid-save-codes]
 * group, hidden in the markup so the page stays what it is without the script, gets three buttons.
 *
 * - [data-yorvoid-download] saves them as a text file, "<site>-recovery-codes.txt". They're put
 *   together in the page: they never go to the server again.
 * - [data-yorvoid-copy] copies them, and says so in [data-yorvoid-copied]; hidden where the browser
 *   can't copy.
 * - [data-yorvoid-print] prints the page.
 *
 * The group's data-yorvoid-heading starts the file, e.g. "Recovery codes for", followed by the site
 * and the date. The site is the group's data-yorvoid-site, as the printout names it, or else the page's
 * host name.
 */
export interface CodesEnvironment {
  /** The site's host name, e.g. location.hostname */
  readonly hostname: string;
  readonly now: () => Date;
  /** Saves text as a file of that name */
  readonly save: (name: string, text: string) => void;
  /** Null where the browser can't copy */
  readonly copy: ((text: string) => Promise<void>) | null;
  readonly print: () => void;
}

interface Control {
  hidden: boolean;
  textContent: string | null;
  addEventListener(type: 'click', listener: () => void): void;
}

export function wireCodes(root: ParentNode, browser: () => CodesEnvironment = browserEnvironment): void {
  const group = root.querySelector<HTMLElement>('[data-yorvoid-save-codes]');

  if (group === null) {
    return;
  }

  // Only on a page with the codes: the others don't need the browser's clipboard.
  const environment = browser();
  const codes = Array.from(root.querySelectorAll('[data-yorvoid-recovery-codes] code'), (code) => code.textContent?.trim() ?? '').filter((code) => code !== '');
  const name = group.dataset['yorvoidSite'] || environment.hostname;
  const site = fileName(name);
  const text = (): string => `${group.dataset['yorvoidHeading'] ?? ''} ${name}, ${environment.now().toISOString().slice(0, 10)}\n\n${codes.join('\n')}\n`;
  const control = (selector: string): Control | null => group.querySelector(selector) as Control | null;

  control('[data-yorvoid-download]')?.addEventListener('click', () => environment.save(`${site}-recovery-codes.txt`, text()));
  control('[data-yorvoid-print]')?.addEventListener('click', () => environment.print());

  const copy = control('[data-yorvoid-copy]');
  const copied = control('[data-yorvoid-copied]');
  const write = environment.copy;

  if (copy !== null) {
    if (write === null) {
      copy.hidden = true;
    } else {
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
export function fileName(hostname: string): string {
  return hostname.toLowerCase().replace(/[^a-z0-9.-]/g, '') || 'site';
}

/**
 * The browser's: a Blob downloaded through an <a download>, the clipboard, window.print().
 */
export function browserEnvironment(): CodesEnvironment {
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
