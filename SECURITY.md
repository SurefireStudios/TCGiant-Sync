# Security Policy

TCGiant Sync holds an eBay OAuth token for a merchant's account and moves stock and listings on their behalf. We treat anything that could expose a token, act on an account without the merchant, or alter a live listing unexpectedly as a security issue.

## Reporting a vulnerability

**Please do not open a public issue.**

Email **security@tcgiant.com** with:

- what you found and where (file and line, or the request involved)
- how to reproduce it, or why you believe it is exploitable
- the plugin version and, if relevant, the WordPress / WooCommerce / PHP versions

You will get an acknowledgement within three working days, and we will tell you what we intend to do and when. We will credit you in the release notes unless you'd rather we didn't.

If the report concerns the hosted relay at `tcgiant.com/syncconnect` rather than the plugin, say so — it is separate infrastructure and is handled separately.

## Scope

**In scope**

- Anything in this repository.
- The OAuth connect, claim and token-refresh flows between the plugin and the relay.
- The eBay account-deletion endpoint the plugin exposes, and the signature check that guards it.
- Admin actions that lack a capability check or nonce.
- Anything that could push a listing, end a listing, or change stock without the merchant asking.

**Out of scope**

- eBay's own APIs and the security of eBay accounts themselves.
- WordPress core, WooCommerce, or other plugins.
- Reports that require a compromised WordPress administrator account to begin with.
- The free-tier product cap. It is a commercial limit, not a security boundary, and bypassing it is not a vulnerability.

## What the plugin does and doesn't hold

- **It never holds eBay application credentials.** The application secret lives only on the relay; the plugin ships with no keys.
- **It holds the merchant's OAuth access and refresh tokens** in the `tcgiant_sync_ebay_settings` option, alongside a per-site signing key issued at connect time. Tokens are exchanged for the merchant with eBay directly; the relay sees them only during the OAuth code exchange and refresh.
- **Usage reporting** (Pro only) sends the site URL and product counts to the relay, signed with the per-site key. It can be switched off with the `TCGIANT_SYNC_DISABLE_TELEMETRY` constant or the `tcgiant_sync_telemetry_enabled` filter.

## Supported versions

Security fixes go into the current minor release line. Please update to the latest release before reporting; a good many past issues are already fixed.

| Version | Supported |
|---|---|
| 3.14.x | ✅ |
| older | ⛔ — please update |
