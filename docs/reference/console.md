# Console commands

The Symfony bundle's commands, as `bin/console help` describes them. [Symfony](../concepts/symfony.md#console-commands) says when to use each.

## `yorvoid:doctor`

Check YorvoID's setup, and how to fix each problem.

```bash
php bin/console yorvoid:doctor [options]
```

| Argument or option | | Default |
|---|---|---|
| `--strict` | Fail on warnings too, e.g. in a deployment pipeline |  |

## `yorvoid:keys:rotate`

Take the next step in rotating YorvoID's signing keys.

```bash
php bin/console yorvoid:keys:rotate [options]
```

| Argument or option | | Default |
|---|---|---|
| `--remove` | Remove this verify-only key, once nothing it signed still needs checking |  |

## `yorvoid:migrate`

Create or update YorvoID's tables.

```bash
php bin/console yorvoid:migrate [options]
```

| Argument or option | | Default |
|---|---|---|
| `--dry-run` | Show the SQL of the pending migrations, without running them |  |
| `--allow-blocking` | Run migrations that block tables while they run: in a maintenance window |  |

## `yorvoid:purge`

Remove ended sessions, expired tokens and other state YorvoID no longer needs.

```bash
php bin/console yorvoid:purge [options]
```

| Argument or option | | Default |
|---|---|---|
| `--keep-sessions` | How long ended sessions stay listed, e.g. "30d" | `30d` |
| `--keep-devices` | How long an unused device stays known: longer than its cookie lives | `400d` |

## `yorvoid:recovery-link`

Print a recovery link for someone who lost access.

```bash
php bin/console yorvoid:recovery-link [options] [--] <email>
```

| Argument or option | | Default |
|---|---|---|
| `email` | Their email address |  |
| `--realm` | Their realm | `default` |
| `--url` | The recovery page, with {token} where the token goes, e.g. "https://example.com/recover/{token}" |  |
| `--lifetime` | How long the link works | `15m` |

## `yorvoid:setup-code`

Create the code that claims a fresh installation.

```bash
php bin/console yorvoid:setup-code [options] [--] [<realm>]
```

| Argument or option | | Default |
|---|---|---|
| `realm` | The realm to claim | `default` |
| `--lifetime` | How long the code works, e.g. "24h" | `24h` |

## `yorvoid:templates:diff`

Show what YorvoID changed in the templates you published.

```bash
php bin/console yorvoid:templates:diff [options] [--] [<templates>...]
```

| Argument or option | | Default |
|---|---|---|
| `templates` | The templates; every published one if not given |  |
| `--accept` | Take YorvoID's changes: replaces the copies you didn't change, and marks the ones you merged by hand |  |

## `yorvoid:templates:lint`

Check the flow pages, with your templates, for what each step needs.

```bash
php bin/console yorvoid:templates:lint [options]
```

| Argument or option | | Default |
|---|---|---|
| `--locale` | The language to render the pages in | `en` |

## `yorvoid:templates:publish`

Copy YorvoID's flow templates into the application, to change them.

```bash
php bin/console yorvoid:templates:publish [options] [--] [<templates>...]
```

| Argument or option | | Default |
|---|---|---|
| `templates` | The templates, e.g. "passkey layout"; or a flow, e.g. "yorvoid.setup", for its steps' templates |  |
| `--force` | Replace the application's own |  |
