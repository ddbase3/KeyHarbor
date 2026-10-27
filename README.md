# KeyHarbor

KeyHarbor is the BASE3 implementation plugin for durable API credentials and centrally managed encryption profiles.

The current implementation provides:

- immutable credential records
- owner-bound repository lookups and updates
- service grants stored separately from credential metadata
- migration-owned database schema
- cryptographically secure bearer-token generation
- token parsing and constant-time secret verification
- discovery of credential-protected services through `IClassMap`
- strict duplicate-service detection
- bearer authentication against credential lifecycle and service grants
- user-scoped credential management
- system-administrator credential overview and revocation
- one-time token display during creation and rotation

## Technical service credentials (service-identity extension)

KeyHarbor now supports a separate `CredentialSubject('service', 'contourz')` alongside legacy user-owned credentials. The **owner** remains the administrator who created the key; the **subject** is the principal verified by the credential. They are not interchangeable. Service credentials are HMAC-only and must have explicit, exact operations for each selected service. An unrestricted `authorizeService()` call denies service principals.

Admin creation, editing, rotation, revocation and deletion are available through the existing AJAX-only `KeyHarborAdminDisplay`, guarded on the server with `IAuthorizationService` (`system:admin`). The one-time token is visible only on successful create/rotate. Existing user management excludes service credentials.

Providers must declare the exact methods before administrators can grant them, for example:

```php
new CredentialServiceDefinition('xrm:entity:data', 'XRM Entity Data', '', ['getEntries', 'getEntry']);
```

**No production service is granted automatically.** A newly created service credential cannot authenticate as a human user through `KeyHarborAuthentication`. Step 1 introduces no XRM wiring or browser/data endpoint changes. The subsequent XRM project integration must accept the verified service principal, enforce `ICredentialOperationAccess::authorizeOperation()` and separately invoke the authoritative `IAuthorizationService` plus Memora native ACL. This plugin does **not** create an ACL policy backend or grant administrator rights to services.

### Additive schema migration 002

`KeyHarborMigrationProvider` now declares `Migration002CredentialSubjects`, adding `subject_type`, `subject_id` and per-service `operations_json`. Existing rows are backfilled to `subject_type=user`, `subject_id=owner_user_id`. Migration 001 remains immutable. The project must use the configured `DatabaseMigrationRunner`, not `NoMigrationRunner`. **Database backup and a successful migration check are required before replacing the repository code.** Migration 002 adds columns and does not drop data. See the release INSTALL guide.

## Dependencies

KeyHarbor depends on:

- BASE3 Framework
- CredentialFoundation
- ClientStack (ModularDialog, >= 4.23.0)
- an active `IUsermanager`
- an active `IDatabase` implementation for the default repository
- `DatabaseMigrationRunner` in the final project composition

The plugin does not create tables during normal repository operations. Database
schema is owned by `KeyHarborMigrationProvider` and its immutable migration
steps.

## Dialog UI

The three existing KeyHarbor displays (user credentials, administrator credentials,
and encryption profiles) use `ClientStack`'s `createStandardDialog()` for their
editors and one-time token dialogs. The module is loaded through `IAssetResolver`
and declared as a required dependency in `base3.json`. Dialog body scrolling,
keyboard focus, backdrop, header and action footer are owned by ClientStack,
not by a second KeyHarbor dialog framework. The content remains in the existing
KeyHarbor templates and all actions continue to use the existing AJAX JSON
endpoints. One-time credential tokens are cleared when their dialog closes.

The ClientStack dialog body must remain scrollable on small viewports: when
working with many credential grants or encryption keys, the header and footer
stay visible while the fields scroll independently. The encrypted key material
and all saved encryption profiles are unaffected by this UI-only change.

## Token format

Generated bearer tokens use this format:

```text
b3k_<public-id>_<secret>
```

The components are:

- a 20-character lowercase hexadecimal public id
- a 43-character base64url secret generated from 32 random bytes

Persistence stores only:

- the public id used for indexed lookup
- the SHA-256 hash of the high-entropy random secret

The full token and plaintext secret are returned only by
`GeneratedCredentialToken` during creation or rotation. The management UI shows
the token once. It must not be logged or persisted by callers.

Rotation keeps the internal credential id and ownership metadata, but replaces
the public id and secret hash. The previous token stops authenticating
immediately after the database update.

## User management

`KeyManagementDisplay` is the user-scoped management surface. It resolves the
current actor through `IUsermanager` and supports:

- listing only credentials owned by the current user
- creating bearer credentials
- editing label, notification metadata, expiration and service grants
- rotating the token
- irrevocably revoking a credential

The JSON endpoint never accepts an owner id. Ownership is derived from the
current user and enforced again by `getByOwner()` and `updateForOwner()` in the
repository.

Open the display through the normal BASE3 output route:

```text
name=keymanagementdisplay
out=html
```

The plaintext token is returned only by successful create and rotate actions.
The browser dialog cannot recover a token after it has been closed.

## Administration

`KeyHarborAdminDisplay` requires:

```php
Permission::for('system', 'admin')
```

It provides a searchable overview of all credentials, owners, lifecycle state,
service grants and notification state. Administrators can revoke credentials,
but cannot view or recover secret material.

The administration language files register:

```text
API Credentials
  My Credentials
  All Credentials
```

## Service providers

Consumer plugins expose protected services by implementing
`ICredentialServiceProvider` from CredentialFoundation:

```php
final class ExampleCredentialServiceProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'examplecredentialserviceprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition(
				'example:report:read',
				'Example report read',
				'Allows an API credential to read example reports.'
			),
			new CredentialServiceDefinition(
				'example:report:write',
				'Example report write',
				'Allows an API credential to write example reports.'
			)
		];
	}
}
```

`CredentialServiceCatalog` discovers all providers through `IClassMap`. One
provider may expose multiple services. Service ids are sorted for deterministic
output and must be globally unique.

If two providers expose the same service id, KeyHarbor throws
`DuplicateCredentialServiceException`. It does not select a winner and does not
use registration order as a fallback.

Existing credentials may temporarily contain grants for a provider that is no
longer installed. The management UI marks those grants as unavailable. They may
be preserved or removed during editing, but an unavailable service cannot be
newly granted.

## Bearer authentication

Consumer endpoints depend on the CredentialFoundation service:

```php
$result = $credentialService->authenticateBearer(
	$token,
	'example:report:read'
);
```

KeyHarbor validates in this order:

1. token syntax
2. public credential lookup
3. constant-time secret verification
4. revocation state
5. expiration state
6. existence of the requested discovered service
7. grant of that service to the credential

A successful result contains:

- the internal credential id
- the owner user id
- the requested service id
- the credential expiration timestamp, when present

Detailed failure codes are intended for internal handling. Public endpoints
should normally return a generic authentication or authorization response.

A successful service grant does not replace endpoint-specific domain or RBAC
checks.

## HMAC boundary

Bearer-only credentials return `hmac_not_enabled` when passed to
`authenticateHmac()`.

HMAC credentials are authenticated through the dedicated encrypted-secret,
timestamp, nonce and signature verification path. KeyHarbor never treats an
HMAC credential as a bearer credential or falls back to plaintext.

The management UI supports bearer and HMAC credentials. Rotation of HMAC
credentials replaces the hash and encrypted secret together.

## Database schema

Migration `001` creates:

```text
base3_keyharbor_key
base3_keyharbor_grant
```

The key table stores owner metadata, lifecycle state, notification state and
encrypted-secret fields for the HMAC mode. HMAC cannot be enabled without
encrypted secret material.

The grant table stores one row per credential and service id.

No additional migration is required for the management UI. Rotation uses the
existing `public_id` and `secret_hash` columns.

## Ownership boundary

User-scoped consumers must use:

```php
$repository->getByOwner($credentialId, $ownerUserId);
$repository->updateForOwner($credential, $ownerUserId);
```

Both operations include `owner_user_id` in their SQL condition. A
request-provided credential id alone is never sufficient for user-scoped
access.

## Migration activation

`KeyHarborMigrationProvider` is active only when the service bound to
`ICredentialRepository` is the provided `DatabaseCredentialRepository`.
A project that replaces the repository also owns the replacement persistence
schema.

## Expiration notifications

KeyHarbor exposes two classmap-discovered `IMessageTypeProvider` implementations:

```text
keyharborexpiring
keyharborexpired
```

The providers define text-first default templates with these placeholders:

```text
user_name
key_label
key_public_id
expires_at
service_labels
system_name
manage_url
```

The default HTML body is intentionally empty. MessageHub currently applies one
shared placeholder context to text and HTML variants without output escaping.
Using text defaults avoids interpreting user-managed labels as HTML. Operators
may create reviewed HTML variants in MessageHub when appropriate.

`KeyExpirationNotificationJob` is a policy-controlled job. Its interval policy
allows a successful run at most once per hour. The job:

1. acquires an atomic `IStateStore` lock
2. loads newly expired credentials
3. loads credentials expiring within the next seven days
4. synchronizes the required MessageHub type and language variants
5. renders and enqueues one message per credential
6. marks the respective notification timestamp only after enqueue succeeds
7. calls `markRun()` after the complete scan
8. releases the lock in `finally`

Individual credential failures are logged and left unmarked so that the next
hourly run retries them. A central loading or synchronization exception aborts
the run before `markRun()`.

Enable the job in the BASE3 `job` configuration group:

```ini
[job]
keyexpirationnotificationjob.active = 1
keyexpirationnotificationjob.priority = 1
```

MessageHub must provide these active services:

```text
IMessageTypeSynchronizationService
IMessageRenderer
IMessageService
```

The MessageHub queue worker must also be active for queued messages to be
delivered:

```ini
[job]
messagequeueworkerjob.active = 1
messagequeueworkerjob.priority = 1
```

KeyHarbor does not select a transport. MessageHub resolves the template/default
transport according to its own configuration.

Warning and expiry markers use conditional single-column updates:

```text
warning_notified_at
expiry_notified_at
```

After the update, KeyHarbor verifies the stored timestamp with a scalar lookup.
It does not use `IDatabase::affectedRows()`, because adapters such as
Base3IliasDatabase intentionally return `0` even after a successful write.
This keeps the job result aligned with the persisted notification marker and
prevents an already queued notification from being reported as failed.

No new database migration is required because both columns were created by
migration `001`.

## HMAC-SHA256 credentials

Credential creation accepts the immutable authentication mode `bearer` or
`hmac`. HMAC credentials cannot be used through `authenticateBearer()` and
return `hmac_required` instead. Rotation preserves the selected mode and
re-encrypts the newly generated secret.

The HMAC secret is encrypted with sodium secretbox before persistence. The
normal configuration is a direct base64-encoded 32-byte master key:

```ini
[keyharbor]
hmac_master_key = "BASE64_ENCODED_32_BYTE_KEY"
hmac_clock_skew_seconds = 300
```

Generate suitable key material on the CLI with:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Or generate a complete INI line:

```bash
php -r 'echo "hmac_master_key = \"" . base64_encode(random_bytes(32)) . "\"" . PHP_EOL;'
```

Keep the generated master key stable and protect the configuration file. A
changed or lost master key makes existing encrypted HMAC credential secrets
unreadable. Structured `env` and `file` ConfigValue definitions remain
supported for deployments that explicitly use late value resolution.

The canonical request is the newline-separated sequence:

```text
HTTP_METHOD
/path
raw=query&string
unix_timestamp
nonce
sha256_hex_of_raw_body
```

The signature is lowercase hexadecimal HMAC-SHA256 over that canonical string,
using the secret part of the one-time token. Consumers transmit the complete
credential token plus `X-BASE3-Timestamp`, `X-BASE3-Nonce` and
`X-BASE3-Signature`. The timestamp must be within the configured clock-skew
window. Nonces are stored atomically in `IStateStore` until the replay window
expires.

`KeyExpirationNotificationJob` remains the only KeyHarbor job. MessageHub and
its queue worker are not modified by this feature.

## Base3IliasLab navigation

Base3IliasLab exposes an `API Keys` area with `My API Keys` and the protected
KeyHarbor administration display.


## AJAX-only management UI

Credential management and administration actions are executed only through JSON AJAX requests.
The KeyHarbor templates contain no HTML forms, submit controls, or action hyperlinks.
Create, update, rotate, revoke, refresh, and administration list operations use explicit buttons and `fetch()`.
The JSON endpoints require a same-origin POST request with `Content-Type: application/json` and
`X-Requested-With: XMLHttpRequest`.

Known HMAC configuration failures are returned with the stable error code `hmac_configuration`.
Unexpected failures receive a short reference id that is also written to the BASE3 logger together
with the original exception class and message.

## Database compatibility

KeyHarbor repository writes do not start, commit, or roll back database transactions. This keeps the repository compatible with host database adapters such as the ILIAS PDO database layer, which may not support transactions through the BASE3 `IDatabase` adapter.

## Permanent deletion

Users may permanently delete only their own revoked credentials. System administrators may permanently delete any revoked credential. Active and expired-but-not-revoked credentials must be revoked first. Deletion is exposed only through the AJAX JSON endpoints. The repository removes grants first, then the revoked credential, and verifies the result with a lookup instead of relying on `IDatabase::affectedRows()`. This is required for adapters such as Base3IliasDatabase, where affected-row counts are intentionally unavailable. KeyHarbor does not use database transactions because the ILIAS database adapter may not support them.

## Accesscontrol authentication

`KeyHarbor\Accesscontrol\KeyHarborAuthentication` implements the existing BASE3 `IAuthentication` contract. A project plugin creates the concrete instance and adds it to the `authentications` list used by `SelectedAccesscontrol`.

The project supplies a trusted request-to-service resolver. The authentication remains inactive when the resolver returns `null`. For a protected request it validates bearer or HMAC credentials through `IApiCredentialService` and returns the credential owner id to `SelectedAccesscontrol`.

A missing or invalid credential on a protected request returns user id `0`. This deliberately overrides any host-session identity and prevents a failed API credential from silently falling back to a logged-in browser user.

The expected order is:

```php
[
	new Base3IliasAuth(...),
	new KeyHarborAuthentication(...),
]
```

The explicit KeyHarbor credential is evaluated last and therefore becomes the current BASE3 user for the request. The usermanager can then apply the normal role, permission and object-access checks for the credential owner.

## Accesscontrol integration

`KeyHarborAuthentication` identifies bearer and KeyHarbor-HMAC credentials before request handling and returns the credential owner to `SelectedAccesscontrol`. It does not decide which consumer service may run.

Consumer services depend on `CredentialFoundation\Api\ICredentialAccess` and call `authorizeService()` with their own stable service id. This keeps route and plugin knowledge out of the project accesscontrol composition.

## Documentation

- [Frequently Asked Questions](docs/faq.md)
- [Privacy and data processing](PRIVACY.md)


## Managed encryption profiles

Encryption is administered in **one separate** `EncryptionProfilesDisplay` page.
The existing `KeyManagementDisplay` and `KeyHarborAdminDisplay` retain their
API credential responsibilities and user interfaces. In the supplied BASE3/ILIAS
Lab project configuration the new page appears as a single additional subtab
under Credentials. No new navigation subsystem is used.

`EncryptionProfileService` implements `CredentialFoundation\Api\IEncryptionProfileService`
from CredentialFoundation and is registered in the KeyHarbor plugin through DI.
All profile and assignment changes are authorized as `system/admin` operations
and require AJAX JSON POST in the administration display. Providers are
classmap-discoverable `IEncryptionTargetProvider` implementations from other
plugins and enumerate real configured consumers with globally stable IDs.
The current version adds the provider contract; consumer plugins must implement
it to appear as selectable targets. The list is empty when none are installed.

Two named `ISettingsStore` groups hold the complete authoritative state:

- `keyharbor_encryption_profiles`: name = stable profile ID, value = label,
  ICrypt method, active `key_id`, and the map of source definitions under `keys`.
- `keyharbor_encryption_targets`: name = stable target ID, value = `profile_id`.

A source definition supports exactly `fixed`, `configuration`, `env` or `file`.
Environment and file sources resolve using BASE3 `IConfigValueResolver` at
runtime; `configuration` uses the existing group/key resolver. Fixed sources
are encrypted **before** entering `ISettingsStore` with the existing
`CredentialSecretCipher` and its deployment master key. Fixed secrets therefore
require `keyharbor.hmac_master_key` to be configured as a base64-encoded 32-byte
value or a supported env/file resolver definition. The UI never returns fixed
secrets or their encrypted ciphertext/nonce. New profiles can be saved without
manually adding keys: KeyHarbor generates the first `fixed` key and its random
ID on the server. For newly added `fixed` entries, the administrator may leave
the key ID empty, and KeyHarbor generates it as well. The administrator never
enters or receives plaintext fixed-key material. Adding keys from `env`, `file`
or `configuration` still requires the appropriate source settings.
Back up the KeyHarbor master key separately from settings storage.

If a fixed-key save reports that the KeyHarbor master key is unavailable,
configure `keyharbor.hmac_master_key` in the active BASE3 `IConfiguration`
backend. This is a deployment-level prerequisite, not the key entered in the
profile form. Generate a master key using
`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` and configure the
resulting base64 value, or configure an `env`/`file` ConfigValue definition.
In embedded deployments, use the actual active BASE3 configuration backend
rather than assuming a project-local INI file. Do not replace an existing
master key: previous fixed secrets and HMAC credentials may become unreadable.
The encryption administration returns a localized, actionable message for
this configuration error instead of a generic error reference; unexpected
failures continue to be logged with their reference ID.

Encryption method IDs are discovered from BASE3 `ICrypt`; KeyHarbor does not
implement encryption algorithms. A profile's method and ID are immutable after
creation. Global uniqueness of key IDs is checked across configured profiles.
Stored encryption profile records and their key definitions are intentionally
**not deletable** through KeyHarbor administration or its AJAX API. A persisted
key ID, source mode, source path/name and fixed secret cannot be edited or
replaced. The UI renders stored keys read-only and offers **Cancel** only
for new entries not yet saved. The server rejects omitted or changed stored
keys even when a direct JSON request bypasses the editor. Labels, additional
keys and the active key selection can still be updated.

For profiles whose active key uses `fixed`, the **Rotate** action reuses the
same server-side generation as initial creation: it generates 32 random bytes,
base64-encodes them, encrypts the result with the existing master key and
saves it under a new globally unique key ID. Only the active
key ID changes. All previous keys remain available to `getReadKey()` so
historical ciphertext remains readable. Unlike API credential rotation,
**encryption-key rotation does not revoke or overwrite old secrets**.

For `env`, `file` and `configuration` key sources, operators add a new
uniquely named source, then select it as active. KeyHarbor does not modify
external secret files, environment variables or configuration values. These
sources remain mutable outside KeyHarbor; external changes can still break
historical decryption. Removing a consumer assignment does not prove that a
historical key is unused. KeyHarbor never scans consumers' data tables.

Consumers persist method and key ID beside ciphertext. The active profile
assignment determines only new writes; `getReadKey($method, $keyId)` finds
previously configured keys independently of the current target assignment.
Deleting key material outside KeyHarbor or changing external secret material
may permanently make historical data unreadable. KeyHarbor cannot determine
whether a key is still needed by arbitrary external data. Persisted profiles
and keys are protected from deletion through the application, but the SettingsStore
backend and the KeyHarbor master key still require reliable backups and
access control according to deployment-specific retention policies.

MissionBay database-memory presets are now discovered through the shared
provider and lookup contracts. Encryption assignments are configured only in
KeyHarbor, without local encryption settings on the memory resource. Historical
ciphertext still requires its original key material; the integration does not
rewrite existing encrypted data.

## Embedded administration page headings

All three KeyHarbor administration views (personal credential management, administrator credential
management and encryption profiles) render their intro/action bar as a neutral `.keyharbor-hero`
`<div>`, with the localized page title as `<h2>` instead of a nested `<header>`/`<h1>`.
The surrounding BASE3 admin tab owns the page structure. No extra ARIA landmarks are introduced.
Nested `<header class="keyharbor-dialog-header">` elements remain unchanged: they label
individual modal dialogs and are not the embedded page intro.

The shared stylesheet preserves the old hero title typography for `<h2>` throughout KeyHarbor.
Service-credential controls, encryption-profile controls, AJAX action attributes, dialog markup,
asset loading, access checks and service APIs are unchanged. No new translations or data flows are
introduced by this markup-only update.

Run the standalone KeyHarbor hero regression test (including 12 locale catalogs, normal and
error states, and existing dialog/action semantics):

```sh
php plugin/KeyHarbor/test/Display/EncryptionProfilesHeaderSmoke.php
```
