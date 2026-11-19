<!-- Thanks for contributing! For a bigger change, please link the issue where the approach was agreed.
The title is the squashed commit's subject: imperative, at most 72 characters, with references at the
end in parentheses, e.g. "Refuse a release whose tag isn't signed (ATN-030)". This description is its
body. -->

## Why

<!-- The problem, and why this is the way to solve it. -->

## What changes

<!-- What behaves differently, as bullets, rather than which files changed. -->

## Tested

<!-- What ran: the tests you added, the checks, anything you checked by hand. And what didn't, with why. -->

## Refs

<!-- The issue or the requirement, e.g. "Fixes #123" or "ATN-030". -->

## Checklist

- [ ] Tests cover the change (a fix has a test that fails without it)
- [ ] `composer qa` passes, and `composer infection` for security-critical code
- [ ] User-facing changes are documented in `docs/` or the README
- [ ] Security-relevant changes update the threat model and the standards traceability
- [ ] `@api` changes follow the compatibility promise (deprecate before removing)
