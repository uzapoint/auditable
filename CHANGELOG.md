# Changelog

## Unreleased (proposed 1.0.4)

### Fixed

- **Updates were never audited.** The `updated` handler returned when the
  event *was* enabled (`if (static::shouldAudit('updated')) return;`). It now
  publishes unless the resolved event (`updated`, `voided`, `approved`,
  `rejected`) is disabled in `auditable.events`.
- **The fallback table was never used.** The publisher read
  `auditable.fallback_enabled`; the config key is `auditable.fallback.enabled`.
  The old key is still honoured if set. Fallback rows now set `created_at` and
  `updated_at`, and `retry_after_minutes` is cast to an integer.
- **Resolving the causer could throw, and with it the model save.**
  - Without an `X-Person` header the name was built from
    `$person['first_name'] . ' ' . $person['last_name']`, which reads
    undefined keys. Names are now built from whichever parts exist.
  - With no request user, resolving ended at `auth('api')`, which throws in
    services without an `api` guard. The guard is now configurable
    (`auditable.auth_guard`) and skipped when undefined.
  - Contexts set with `UserContextResolver::set()` that lacked keys broke
    publishing; every context now has every key.
  - A malformed `X-Person` header is ignored instead of decoding to `null`.
  - `UserContextResolver::clear()` no longer fails when nothing was set.
- **Encrypted and hidden attributes leaked.** `updated` published
  `getOriginal()` values, which Eloquent decrypts, and `created`/`deleted`
  published encrypted (non-hidden) attributes decrypted. Hidden, encrypted and
  `$auditExclude` attributes are now redacted, keeping their keys.
- **Sensitive-field matching was exact and case-sensitive.** `API_KEY`,
  `Password` or `client_secret` were published as is. Matching now ignores
  case and covers `_`-suffixed names; more defaults were added (`api_key`,
  `secret_key`, `access_token`, `refresh_token`, `private_key`, `passkey`,
  `authorization`).
- **Fallback rows could store unredacted payloads.** Redaction now runs before
  enrichment, so a failure at any later step stores a redacted payload. The
  publisher catches `Throwable`, not only `Exception`.
- **`AuditException::publishFailed()` threw `ArgumentCountError`.** It passed
  the context array as a fourth constructor argument. The context is now a
  `context` property.
- `restored` events now carry `business_id` and `properties` like the others,
  and `AuditPublisher::log()` passes `business_id` through.

### Added

- Test suite (Orchestra Testbench, SQLite): `composer test`.
