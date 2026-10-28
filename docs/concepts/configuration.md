# Configuration

YorvoID is configured with typed PHP objects, and each one checks its values when it's created, so a mistake fails when your application starts, not when a user hits the code path. The framework bridges map their own formats (Symfony's YAML, for example) onto the same objects.

Every configuration error is an `IntegratorError`: its message says what's wrong, how to fix it, and links to the page that explains it.

## Durations

Lifetimes (of tokens, sessions, re-authentication windows) are `Duration`s: a whole number of seconds, written in configuration as a number and a unit.

| Written | Means |
|---------|-------|
| `90s` | 90 seconds |
| `15m` | 15 minutes |
| `8h` | 8 hours |
| `30d` | 30 days |

```php
use YorvoID\Core\Duration;

Duration::parse('8h');
Duration::hours(8);     // the same
Duration::minutes(90);  // printed as "90m"
```

- Only one unit per value: write `90m`, not `1h30m`.
- No months or years: their length varies, and a security lifetime must mean exactly one thing. Use days.
- At least 1 second, at most 100 years. Anything else is almost certainly a mistake, such as milliseconds where seconds were meant.
- `Duration::after($moment)` is exactly that many seconds later, also across a change to or from daylight saving time.
