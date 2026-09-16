# KeyHarbor FAQ

## What is KeyHarbor?

KeyHarbor is the BASE3 implementation plugin for durable API credentials. It provides credential creation, storage, rotation, revocation, deletion, service grants, bearer authentication, HMAC-SHA256 authentication, user management, administration, and expiration notifications.

It implements the credential contracts from CredentialFoundation and supplies the concrete runtime services used by credential-protected consumers.

## What credential modes are supported?

KeyHarbor supports two immutable authentication modes per credential:

- `bearer`
- `hmac`

The mode is selected when the credential is created. Rotation preserves the selected mode.

A bearer credential cannot be used as an HMAC credential, and an HMAC-enabled credential cannot be accepted through the bearer-only authentication path.

## What does a generated token look like?

The token format is:

```text
b3k_<public-id>_<secret>
```

The current generator creates:

- a 32-character lowercase hexadecimal internal credential id
- a 20-character lowercase hexadecimal public id
- a 43-character base64url secret generated from 32 random bytes

The full token is shown only during creation or rotation.

## Is the plaintext bearer secret stored in the database?

No. For bearer credentials, persistence contains only the SHA-256 hash of the randomly generated high-entropy secret. Authentication hashes the presented secret and compares it with the stored hash using `hash_equals()`.

The full plaintext token is returned only in the successful create or rotate response and is displayed once by the management UI.

## Why is the HMAC secret stored differently?

HMAC verification requires access to the original secret. For HMAC-enabled credentials, KeyHarbor therefore stores an encrypted copy of the secret in addition to the normal secret hash.

The secret is encrypted with sodium `secretbox` using a configured 32-byte master key. The database stores base64-encoded ciphertext and nonce, not the plaintext secret.

## How is the HMAC master key configured?

The normal configuration value is:

```ini
[keyharbor]
hmac_master_key = "BASE64_ENCODED_32_BYTE_KEY"
hmac_clock_skew_seconds = 300
```

The master key may also be provided through a BASE3 ConfigValue definition using `env` or `file` mode.

The master key must remain stable. Losing or changing it makes existing encrypted HMAC secrets unreadable.

## What happens if the sodium PHP extension is unavailable?

HMAC credential encryption and decryption fail with a KeyHarbor HMAC configuration exception. Bearer credentials do not require the encrypted-secret path.

## How does HMAC authentication work?

The client sends:

```text
Authorization: Bearer <full token>
X-BASE3-Timestamp: <Unix timestamp>
X-BASE3-Nonce: <unique nonce>
X-BASE3-Signature: <lowercase HMAC-SHA256 hex>
```

The signed canonical request is:

```text
HTTP_METHOD
/path
raw=query&string
unix_timestamp
nonce
sha256_hex_of_raw_body
```

KeyHarbor validates the credential lifecycle, timestamp window, nonce format, HMAC signature, and replay state before establishing the credential identity.

## How does replay protection work?

After a valid HMAC signature has been verified, KeyHarbor creates an atomic `IStateStore` entry whose key contains:

- the credential id
- a SHA-256 hash of the nonce

The state value is the request timestamp. The entry receives a TTL based on twice the accepted clock-skew window plus 60 seconds.

If the same nonce key already exists, authentication fails with `replay_detected`.

The raw nonce is not stored in the replay key.

## What timestamp window is accepted for HMAC requests?

The configured value is read from:

```text
keyharbor.hmac_clock_skew_seconds
```

The implementation clamps the effective value to a minimum of 30 seconds and a maximum of 3600 seconds. The default is 300 seconds.

## How are service grants represented?

Each credential has one or more stable service ids. The grants are stored separately from the credential record in the `base3_keyharbor_grant` table.

Credential-protected services are discovered through `ICredentialServiceProvider` implementations. `CredentialServiceCatalog` builds a deterministic catalog from those providers.

## What happens if two providers publish the same service id?

KeyHarbor throws `DuplicateCredentialServiceException`. It does not choose one provider based on discovery order and does not silently overwrite a duplicate.

## What happens if a credential still contains a grant for a service that is no longer available?

The stored grant can remain on the credential. Existing unavailable grants may be preserved or removed during editing, but a service that is not currently present in the catalog cannot be newly granted.

Authentication authorization fails with `service_not_found` if a consumer asks for a service that is not in the active catalog.

## How are credential identity and service authorization separated?

KeyHarbor implements both `IApiCredentialService` and `ICredentialAccess`.

For request-level authentication, `KeyHarborAuthentication` can identify a bearer or HMAC credential and establish the credential owner as the current BASE3 user.

The consuming service then calls:

```php
$credentialAccess->authorizeService('consumer:area:service');
```

This prevents the access-control composition from needing hardcoded knowledge of every consumer service id.

## Does a service grant replace normal application authorization?

No. A service grant proves that the credential may call that logical credential service. The consuming component may still apply normal role, permission, resource, or domain access checks for the credential owner.

## What happens when an explicit API credential is invalid?

`KeyHarborAuthentication` returns user id `0` for a malformed or failed explicit credential request. It does not silently fall back to another authenticated browser identity for that request.

If no Authorization or HMAC headers are present, the authentication returns `null` and remains inactive.

## Which request data does HMAC authentication read?

The access-control authentication reads:

- Authorization header
- HMAC timestamp header
- HMAC nonce header
- HMAC signature header
- request method
- request URI path
- raw query string
- raw request body

The raw body is used only to calculate the canonical request hash for signature verification.

## What data is stored for a credential?

The default database repository stores:

- internal credential id
- public id
- owner user id
- owner login
- owner display name
- notification address
- notification language
- user-managed label
- secret hash
- HMAC enabled flag
- encrypted HMAC secret and nonce when HMAC is enabled
- creation timestamp
- expiration timestamp
- revocation timestamp
- warning notification timestamp
- expiry notification timestamp
- granted service ids

The full plaintext token is not stored.

## Which database tables are used?

Migration `001` creates:

```text
base3_keyharbor_key
base3_keyharbor_grant
```

Schema creation is handled by the KeyHarbor migration provider. The normal repository does not create or alter tables during runtime operations.

## When is the migration provider active?

`KeyHarborMigrationProvider` is active only when the configured `ICredentialRepository` is KeyHarbor's `DatabaseCredentialRepository`.

If a project replaces the repository with another implementation, that replacement is responsible for its own persistence model.

## Can users manage only their own credentials?

Yes. The user management service derives the current owner from `IUsermanager`. User-scoped repository reads and updates include `owner_user_id` in the storage query.

The JSON management endpoint does not accept an arbitrary owner id from the client.

## What can a normal user do?

The user-facing management display supports:

- list own credentials
- list available services
- create a credential
- edit label, notification data, expiration, and service grants
- rotate a credential
- revoke a credential
- permanently delete a revoked credential

The create and rotate operations return the new plaintext token exactly once.

## What can a system administrator do?

The administration display requires:

```php
Permission::for('system', 'admin')
```

It can list all credentials and their ownership and lifecycle metadata, revoke credentials, and permanently delete revoked credentials.

It does not return plaintext secret material.

## Can active credentials be permanently deleted?

No. A credential must be revoked first. Both user-scoped and administrator deletion paths reject permanent deletion of a credential that is not revoked.

Deletion removes its grant rows and then the credential row.

## Does revocation delete the record?

No. Revocation writes a revocation timestamp. This immediately stops authentication but retains the credential record until a later explicit permanent deletion.

## Does KeyHarbor automatically purge old credentials?

No. There is no automatic retention or deletion job for credential records. Revoked credentials remain stored until an authorized user or administrator explicitly deletes them.

## How does the user management endpoint accept changes?

Management actions are JSON-only AJAX POST requests. The endpoint requires:

- HTTP `POST`
- `Content-Type: application/json`
- `X-Requested-With: XMLHttpRequest`

The supported user actions are list, create, update, rotate, revoke, and delete.

The administration JSON endpoint uses the same AJAX JSON request restrictions for list, revoke, and delete.

## Does the browser store the one-time token?

The provided management JavaScript does not write the token to local storage, session storage, or cookies.

The token is placed into the token dialog after a successful create or rotate action. The UI can copy it to the clipboard. When the dialog is closed, the displayed token text is cleared.

Clipboard contents are controlled by the browser and operating system after the user chooses to copy the token.

## Can the full token be recovered later from KeyHarbor?

No. Bearer secret plaintext is not persisted, and administrators do not have a recovery function. Rotation creates a new token and invalidates the previous token immediately after the persisted credential is updated.

## How do expiration notifications work?

`KeyExpirationNotificationJob` can scan for:

- credentials that have just expired
- credentials expiring within the next seven days

For each due credential it renders and enqueues a message through the MessagingFoundation interfaces. The notification timestamp is marked only after the message has been successfully enqueued.

The job has an hourly interval policy and also uses an atomic state-store lock with a 15-minute TTL to prevent concurrent runs.

## Which data is included in expiration notifications?

The default template context includes:

- owner display name
- credential label
- public id
- formatted expiration time
- granted service labels
- system name
- credential management URL

The recipient is the credential notification address. Message metadata also contains the credential id, public id, owner user id, and notification kind.

## Does KeyHarbor itself deliver email or other messages?

No. KeyHarbor renders and enqueues messages through the messaging contracts. The active messaging implementation decides how queued messages are stored and which transport performs delivery.

## How is the notification address selected?

During creation or update, an explicitly entered notification address is used first. If it is empty, the current user's email address is used as the default.

An expiring credential must have a non-empty notification address.

## What is logged by KeyHarbor?

The management displays log unexpected exceptions with a KeyHarbor scope and an optional short reference id. The formatted log entry can include exception class, message, file, line, and chained exception information.

The expiration job logs failed notification attempts with credential id, notification kind, error message, and exception class.

The authentication service itself does not log presented tokens, signatures, nonces, or request bodies.

## Are detailed authentication failures returned publicly?

The credential result contracts support detailed failure codes. The concrete public endpoint decides how much of that detail to expose.

The KeyHarbor management displays expose management validation and error categories to the authenticated UI. Unexpected failures receive a generated reference id.

## Does KeyHarbor make external HTTP requests?

The core credential implementation does not make external HTTP requests. Expiration messages can leave the local runtime only through the configured messaging implementation and transport.

## Where can I find the privacy and data-processing details?

See [PRIVACY.md](../PRIVACY.md).
