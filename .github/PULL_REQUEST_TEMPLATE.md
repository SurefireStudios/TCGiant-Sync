## What this changes, and why

<!-- Plain words. What a merchant would notice, and the reason the change exists. -->

## Checklist

- [ ] `composer check` passes locally (version, lint, views, formats, tabs, limit gates, hooks, tests, PHPStan)
- [ ] If a hook was deliberately added or removed: `tools/hooks.txt` updated with `php tools/check-hooks.php --update`, and the diff is what I meant
- [ ] If a bug was fixed: a case in `tests/` that reproduces it
- [ ] `changelog.txt` and the `readme.txt` changelog have an entry, written for a shop owner
- [ ] Nothing here names a merchant, a store domain, an order, or a credential
- [ ] Code every edition needs is in a file every edition ships (see *The editions rule* in CONTRIBUTING.md)

## How I tested it

<!-- Which site, which marketplace, what you pushed or imported. "Ran the harness" is fine for logic changes; anything touching eBay needs a real listing. -->
