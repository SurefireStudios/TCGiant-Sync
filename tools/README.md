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
php tools/lint.php
php vendor/phpstan/phpstan/phpstan.phar analyse --memory-limit=3G
```

Run this before tagging. The same checks run in CI on every push
(`.github/workflows/static-analysis.yml`), linting against PHP 7.4 — the
oldest version the plugin claims to support — and analysing on 8.3.

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
4. Commit, then `git tag -a vX.Y.Z -m "..."` and push both.
5. Publish the GitHub release for that tag.

Step 5 is what actually reaches customers: Plugin Update Checker looks for the
**latest published release**, falling back to the latest tag, then the branch.
Pushing a commit or a tag alone changes nothing for existing installs — the
update only goes out when the release is published.
