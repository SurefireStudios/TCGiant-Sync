# Behavioural harnesses

```
php tests/run.php          # all of them; non-zero exit if any assertion fails
php tests/test-units.php   # just one
composer test              # same as run.php
```

None of this ships with the plugin — `tests/` is excluded from both distribution routes.

## What these are, and are not

Each `test-*.php` is a plain PHP script with no framework. It stubs the handful of WordPress and WooCommerce functions a piece of logic calls, reproduces that logic, and asserts what it must do. Several of them begin by reproducing a **bug from the old code** — the exact wrong figure a merchant reported — and only then assert the fix, so the fix is measured against the fault rather than assumed. Where a rounding policy was chosen, the harness runs the rejected alternative too and asserts that it drifts, so the test is known to have teeth.

Some read the real plugin source (a constant's value, a docblock, the order of statements in a file) so that a refactor which moves or renames something is caught. Others carry a verbatim copy of the function under test, which means **a change to that function must be mirrored here** — the runner will not notice by itself. That is a known weakness; a WordPress-stub bootstrap that loads the real classes is on the roadmap.

They are **not** an integration suite. Nothing here talks to eBay, WooCommerce or a database. A change that touches either needs a real site.

| File | What it proves |
|---|---|
| `test-uninstall.php` | Deleting the plugin clears its schedule always, and its data only when asked — and every hook the source schedules is on the uninstall list |
| `test-units.php` | Weights and sizes import in the right system per listing; the reported 17.01 kg / 9.92 kg / 25.4 cm figures reproduce from the old logic and not the new |
| `test-weight-export.php` | The export round-trip reaches a fixed point immediately in every store unit, and rounding-up would not have |
| `test-title-condition.php` | Titles keep their punctuation; eBay condition IDs carry eBay's own names and the restricted grades are grouped |
| `test-edition.php` | The OAuth state is byte-identical for every plugin in the field, and an unrecognised edition never resolves to `pro` |
| `test-mad-signature.php` | Account-deletion notices are signed with the key each site was issued, not the shared fallback |
| `test-log-tail.php` | The dashboard's log reader never holds a whole file and never shows a fragment as a record |

When you fix a bug, add the case that would have caught it.
