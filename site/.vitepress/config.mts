import { posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitepress';

const repository = 'https://github.com/yorvoid/yorvoid';

/**
 * YorvoID's documentation site (PLT-088). The pages are docs/, as they read on GitHub; this only
 * arranges them: tutorials per kind of application, how-to recipes, concepts, reference, guarantees.
 */
export default defineConfig({
  title: 'YorvoID',
  description: 'Identity and access management for PHP: passkeys, sessions, recovery, signed intents.',
  srcDir: '../docs',
  base: process.env['SITE_BASE'] ?? '/yorvoid/',
  cleanUrls: true,
  // The repository's README and the security reports' trackers aren't pages of the site.
  rewrites: { 'README.md': 'index.md' },
  // The quickstart links to the reader's own http://localhost:8000.
  ignoreDeadLinks: [/^https?:\/\/localhost[:/]/],
  // The pages are outside the site's folder, so Vue isn't in a folder above them: point them at the site's.
  vite: { resolve: { alias: [{ find: /^vue($|\/)/, replacement: `${fileURLToPath(new URL('../node_modules/vue', import.meta.url))}$1` }] } },
  markdown: {
    config: (md) => {
      // Links from docs/ to the rest of the repository (the formal models, the packages) work on GitHub,
      // not on the site: point them at GitHub.
      const linkOpen = md.renderer.rules.link_open ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options));
      md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
        const href = tokens[idx]?.attrGet('href');

        if (href !== null && href !== undefined && href.startsWith('.')) {
          const target = posix.normalize(posix.join('docs', posix.dirname(env.relativePath), href));

          if (!target.startsWith('docs/')) {
            tokens[idx]?.attrSet('href', `${repository}/blob/main/${target}`);
          }
        }

        return linkOpen(tokens, idx, options, env, self);
      };
    },
  },
  themeConfig: {
    search: { provider: 'local' },
    nav: [
      { text: 'Quickstart', link: '/tutorials/symfony-quickstart' },
      { text: 'Concepts', link: '/concepts/principals' },
      { text: 'Reference', link: '/reference/configuration' },
    ],
    sidebar: [
      {
        text: 'Tutorials',
        items: [
          { text: 'Quickstart: Symfony', link: '/tutorials/symfony-quickstart' },
          { text: 'Any PHP application', link: '/tutorials/plain-php' },
          { text: 'A library on YorvoID', link: '/tutorials/library' },
        ],
      },
      {
        text: 'How-to',
        collapsed: true,
        items: [
          { text: 'Check the setup in deployments', link: '/how-to/check-the-setup' },
          { text: 'Move to PostgreSQL', link: '/how-to/postgresql' },
          { text: 'Turn on notifications', link: '/how-to/notifications' },
          { text: 'Rotate the signing keys', link: '/how-to/rotate-keys' },
          { text: 'Change the sign-in pages', link: '/how-to/sign-in-pages' },
        ],
      },
      {
        text: 'Concepts',
        collapsed: true,
        items: [
          { text: 'Realms', link: '/concepts/realms' },
          { text: 'Principals', link: '/concepts/principals' },
          { text: 'Passkeys', link: '/concepts/passkeys' },
          { text: 'Credentials', link: '/concepts/credentials' },
          { text: 'Sessions', link: '/concepts/sessions' },
          { text: 'Step-up', link: '/concepts/step-up' },
          { text: 'Flows', link: '/concepts/flows' },
          { text: 'Symfony', link: '/concepts/symfony' },
          { text: 'Browser client', link: '/concepts/browser-client' },
          { text: 'Signed intents', link: '/concepts/signed-intents' },
          { text: 'Throttling', link: '/concepts/throttling' },
          { text: 'One-time tokens', link: '/concepts/one-time-tokens' },
          { text: 'Setup codes', link: '/concepts/setup-codes' },
          { text: 'Invitations', link: '/concepts/invitations' },
          { text: 'Recovery codes', link: '/concepts/recovery-codes' },
          { text: 'Recovery links', link: '/concepts/recovery-links' },
          { text: 'Notifications', link: '/concepts/notifications' },
          { text: 'Mail', link: '/concepts/mail' },
          { text: 'Translations', link: '/concepts/translations' },
          { text: 'Security log', link: '/concepts/security-log' },
          { text: 'Configuration', link: '/concepts/configuration' },
          { text: 'Secrets', link: '/concepts/secrets' },
          { text: 'Keys', link: '/concepts/keys' },
          { text: 'Operations and events', link: '/concepts/operations-and-events' },
          { text: 'Storage', link: '/concepts/storage' },
          { text: 'Testing', link: '/concepts/testing' },
        ],
      },
      {
        text: 'Reference',
        collapsed: true,
        items: [
          { text: 'Configuration', link: '/reference/configuration' },
          { text: 'Console commands', link: '/reference/console' },
        ],
      },
      {
        text: 'Guarantees',
        collapsed: true,
        items: [
          { text: 'Threat model', link: '/security/threat-model' },
          { text: 'Assurance levels', link: '/security/assurance-levels' },
          { text: 'Dependencies', link: '/security/dependencies' },
          { text: 'Compatibility promise', link: '/compatibility' },
        ],
      },
    ],
    socialLinks: [{ icon: 'github', link: repository }],
    editLink: { pattern: `${repository}/edit/main/docs/:path`, text: 'Change this page on GitHub' },
    footer: { message: 'Apache License 2.0', copyright: 'YorvoLabs' },
  },
});
