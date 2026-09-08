# Contributing to TCGiant Sync

Thank you. This plugin sits between a merchant's shop and their eBay account, and mistakes here cost real money — an over-declared package weight, a listing ended by accident, a condition overstated on a live item. So the bar for changes is "proven", not "looks right". This document is how we get there.

## Before you start

- **Bugs:** open an issue with the plugin version, the WordPress/WooCommerce/PHP versions, and the relevant lines from *TCGiant Sync → Logs*. The log usually says what happened and why.
- **Features:** open an issue first so we can agree on the shape before you build it. Some things that look like features are edition or policy questions (see *The editions rule* below).
- **Security:** do not open an issue. See [SECURITY.md](SECURITY.md).

## Development setup

You need PHP 7.4 or newer on your PATH. Nothing else is required to run the checks; Composer is only needed to install PHPStan.

```bash
git clone https://github.com/SurefireStudios/TCGiant-Sync.git
cd TCGiant-Sync
php composer.phar install        # or: composer install — PHPStan only
```

To try a change on a site, build a zip and upload it, or symlink the checkout into `wp-content/plugins/tcgiant-sync`:

```bash
php tools/build-zip.php          # writes zips/tcgiant-sync-<version>.zip
```

There is no bundled WordPress test environment. The behavioural harnesses in `tests/` exercise the plugin's decision logic with WordPress stubbed out (see below); anything that touches eBay or WooCommerce itself needs a real site.

## The checks every change must pass

```bash
composer check
```

runs, in order: version agreement, syntax on every file, admin view markup, format strings, menu/tab parity, the free-limit gates, the hook baseline, the behavioural harnesses, and PHPStan (level 5, analysing as PHP 7.4). CI runs the same set on every push and pull request. Each check exists because of something that went wrong once; [`tools/README.md`](tools/README.md) tells the stories.

Two deserve a note here:

**`tools/check-hooks.php`** keeps a baseline of every `add_action`, `add_filter` and scheduled event in `tools/hooks.txt`, as `hook => callback` pairs without the class or file. Moving a registration between classes passes; losing one fails. If your change genuinely adds or removes a hook, read the diff it reports, make sure it's what you meant, then `php tools/check-hooks.php --update` and commit `hooks.txt` with your change.

**`tools/check-archive-parity.php`** needs a *committed* tree. Run `composer check-release` after committing and before tagging. If you add a file that should not ship to merchants, add an `export-ignore` rule to `.gitattributes` — and commit it, because `git archive` reads the committed copy.

## Tests

```bash
composer test                    # or: php tests/run.php
```

The files in `tests/` are behavioural harnesses. Each reproduces one piece of the plugin's logic with WordPress and WooCommerce stubbed out, then asserts what it must do — including, where a bug was fixed, a reproduction of the bug from the old logic so the fix is measured against the fault rather than assumed. They are deliberately plain PHP with no framework: `php tests/test-units.php` runs one; the runner runs them all and fails if any assertion does.

When you fix a bug, add the case that would have caught it.

## Code conventions

- WordPress coding style: tabs, `array()`, Yoda conditions, spaces inside parentheses. Match the file you're in.
- Every class is `TCGiant_Sync_<Name>` in `includes/class-tcgiant-sync-<name>.php`; the autoloader derives the path from the name.
- **Comments explain why, not what.** The codebase's comments read as short explanations of the reasoning and, where relevant, the incident that motivated a line. Keep that voice. A comment that restates the code is noise; a comment that says which merchant's fortnight of overselling a guard prevents is worth having.
- Log messages and admin strings are written for a shop owner, not a developer: say what happened and what to do, in plain English.
- Commit messages: a one-line summary in plain words, then the *reason* the change exists. See `git log` — the style is consistent and worth matching.

## The editions rule

This repository is the **Pro** edition, and it is also the single source for the forthcoming **Lite** (WordPress.org) and **Standard** (WooCommerce.com) editions. Those are built from this tree by leaving files out — never by branching it. So:

- Code every edition needs must live in a file every edition ships. If you find yourself adding import-side logic to the exporter, or something the free tier needs to the licence class, stop and put it somewhere neutral.
- No `if ( EDITION === … )` branches. Editions differ by which files exist, not by runtime flags.
- The licence is reached through `TCGiant_Sync_Entitlements`, never named directly outside its own file and UI.

## What must never be committed

This is a public repository. In April 2026 a copy of the OAuth relay — with the eBay application secret in it — was committed and removed the same day. Removal does not take a file out of history. The affected credentials have since been rotated, but the lesson stands:

- The relay (`relay.php`, `telemetry.php`, `dashboard.php`, `connect.php`) and anything under `syncconnect/`. It is deliberately not in this repository.
- `.env` files, `sync.db`, `log.txt`, any file containing an eBay App ID, Cert ID, RuName, verification token or relay secret.
- Merchant names, store domains, order numbers or logs from a customer's site. Anonymise before pasting into an issue or commit.
- Internal audit notes (`AUDIT-*.md`).

`.gitignore` covers most of these. If in doubt, ask before pushing.

## Releasing

Maintainers only. The full procedure is in [`tools/README.md`](tools/README.md#releasing); the short version:

1. Bump the version in `tcgiant-sync.php` (header **and** constant) and `readme.txt`; add entries to `changelog.txt` and the readme changelog. `check-version.php` will refuse anything that disagrees.
2. `composer check`, commit, then `composer check-release`.
3. Tag `vX.Y.Z`, push the tag, publish the GitHub release with notes written for merchants.
4. Download the published archive and verify it — the version constant, the fixes present, no dev tooling. Not the local build; the archive.

Publishing the release is what reaches merchants: the update checker looks for the latest published release. A pushed tag alone changes nothing for anyone.
