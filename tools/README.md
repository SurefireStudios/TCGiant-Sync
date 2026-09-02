# Development tooling

None of this ships with the plugin — `.gitattributes` strips it from release
archives, and `build-zip.php` uses an allowlist that excludes it.

## Requirements

Only **PHP** is required, and it is already installed:

```
winget install PHP.PHP.8.4
```

> PATH changes only apply to **newly opened** terminals. If `php` is "not
> recognised", close the window and open a fresh one.

Composer is optional. `composer.phar` sits in the project root (gitignored) and
is only needed to reinstall the analysis dependencies into `vendor/`.

## Building a release zip

Double-click **`tools\build-zip.bat`**, or run:

```
php tools/build-zip.php
```

Writes `zips/tcgiant-sync-<version>.zip` — version read from the plugin header,
so the filename can never disagree with the contents. The zip contains only
`admin/`, `includes/`, `languages/`, `assets/` and the root runtime files,
wrapped in a `tcgiant-sync/` folder so it installs through
**Plugins → Add New → Upload**.

Expected size is around **1.6 MB**. If it is tens of megabytes, `vendor/` has
been picked up — that is the bug this script exists to prevent.

## Pre-release checks

Double-click **`tools\check.bat`**, or run:

```
php tools/check-version.php
php tools/lint.php
php tools/check-views.php
php tools/check-formats.php
php tools/check-tabs.php
php tools/check-hooks.php
php tools/check-limit-gates.php
php vendor/phpstan/phpstan/phpstan.phar analyse --memory-limit=3G
```

Or `composer check`, which runs the same set.

Run this before tagging. The same checks run in CI on every push
(`.github/workflows/static-analysis.yml`), linting against PHP 7.4 — the
oldest version the plugin claims to support — and analysing on 8.3.

`check-formats.php` exists because of a live incident. A translator string
picked up a stray backslash, giving `sprintf()` a specifier it could not
read. On PHP 8 that is a `ValueError` rather than a warning, so instead of
reporting why a connection had failed, the plugin took the site down —
including wp-admin, leaving FTP as the only way back in. The file parses
cleanly, so `php -l` sees nothing, and PHPStan does not check the shape of
the string. This runs each one through `sprintf()` and reports what throws.

`check-hooks.php` guards refactors. It records every `add_action`,
`add_filter` and scheduled event as a `hook => callback` pair in
`tools/hooks.txt`, deliberately without the class or file — so moving a
registration between classes passes, and losing one fails.

That distinction is the whole point. Losing a stock-sync registration produces
no fatal, no warning and no log line: stock simply stops flowing to eBay, and
the merchant finds out by overselling. When a change is meant to alter the set,
read the GONE list first, then run:

```
php tools/check-hooks.php --update
```

`check-version.php` compares the `Version:` header, the `TCGIANT_SYNC_VERSION`
constant and `Stable tag` in `readme.txt`. All three are set by hand and nothing
previously made them agree, so a zip could be named one version, report a second
to every site running it, and advertise a third in the readme.

`check-archive-parity.php` is not part of the routine run, because it needs a
committed tree. There are two ways a customer receives this plugin — the
uploaded zip, from the allowlist in `build-manifest.php`, and the GitHub source
archive, from `export-ignore` in `.gitattributes` — and almost every paying
customer receives the second. Two hand-maintained lists that must agree, with
nothing checking that they do, will not stay agreed. Run it with
`composer check-release` before tagging.

Note that `git archive` reads `.gitattributes` from the **committed** tree, so a
rule you have just written has no effect until it is committed.

## Reinstalling the analysis dependencies

Only needed if `vendor/` is missing or `composer.json` changed:

```
php composer.phar install
```

## Releasing

1. Bump the version in `tcgiant-sync.php` (header **and** the
   `TCGIANT_SYNC_VERSION` constant) and `Stable tag` in `readme.txt`.
2. Add entries to `changelog.txt` and the `== Changelog ==` section of
   `readme.txt`.
3. Run `tools\check.bat`.
4. Commit, then run `composer check-release` — it repeats the checks and
   compares what auto-updating sites will receive against the uploaded zip.
   It needs the commit to exist first, which is why it comes after step 3.
5. `git tag -a vX.Y.Z -m "..."` and push both.
6. Publish the GitHub release for that tag.

Step 6 is what actually reaches customers: Plugin Update Checker looks for the
**latest published release**, falling back to the latest tag, then the branch.
Pushing a commit or a tag alone changes nothing for existing installs — the
update only goes out when the release is published.
