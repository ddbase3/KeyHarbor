# KeyHarbor Privacy and Data Processing

This document describes the data processed by the KeyHarbor plugin itself. It covers credential management, credential authentication, persistent credential metadata, HMAC replay protection, administration, logging, and expiration notifications.

This document is technical documentation and is not a legal privacy notice. The final deployment must also document the concrete database, state-store, logger, messaging, backup, access-control, and hosting implementations that surround KeyHarbor.

## Purpose and scope

KeyHarbor provides durable API credentials for BASE3 services. A credential is issued by a user and authenticates either that user or an explicitly named technical service. It grants one or more logical services and can optionally expire. The plugin supports bearer tokens and HMAC-SHA256 credentials.

The component processes security-sensitive authentication material and can also process personal data because credentials are associated with user accounts and notification recipients.

## Persistent credential data

The default database repository stores credential records in `base3_keyharbor_key` and grants in `base3_keyharbor_grant`.

A credential record contains:

- internal credential id
- public credential id
- owner (issuing administrator) user id
- authenticated subject type (`user` or `service`)
- authenticated subject identifier (stable user or service id)
- owner login
- owner display name
- notification address
- notification language
- user-defined credential label
- SHA-256 secret hash
- HMAC-enabled flag
- encrypted HMAC secret, if HMAC mode is enabled
- encryption nonce, if HMAC mode is enabled
- creation timestamp
- expiration timestamp, if configured
- revocation timestamp, if revoked
- warning notification timestamp, if sent
- expiry notification timestamp, if sent

The grant table stores:

- credential id
- granted service id
- creation timestamp
- optional JSON list of specifically granted service operations (exact method names)
- optional versioned resource-constraint JSON per granted operation in `constraints_json`; this can contain resource attributes and values configured by the administrator

Owner identifiers, login names, display names, notification addresses, and user-defined labels can be personal data.

## Plaintext credential tokens

A generated token contains a public id and a high-entropy secret.

KeyHarbor does not persist the full plaintext token. The full token is available only in memory during creation or rotation and is returned to the user-facing JSON endpoint once.

The management browser UI displays the token in a dialog. The provided JavaScript does not store it in local storage, session storage, or cookies. When the dialog is closed, the token text is cleared from the dialog.

The UI offers a copy action. If the user copies the token, the token enters the browser and operating-system clipboard environment. Clipboard history, synchronization, or retention is outside KeyHarbor's control.

## Bearer secret storage

For bearer credentials, the database stores only the SHA-256 hash of the random 32-byte secret. The plaintext secret is not recoverable from the stored credential record through KeyHarbor.

Authentication hashes the presented secret and compares it with the stored hash using a constant-time comparison.

Because the generated secret is high-entropy random material, this hash is used as a token verifier rather than as a human-password hash.

## HMAC secret storage

HMAC validation requires the original secret, so an HMAC-enabled credential stores an encrypted copy of the secret.

KeyHarbor uses sodium `secretbox` with:

- a configured 32-byte master key
- a random 24-byte nonce per encryption
- base64-encoded ciphertext and nonce in the database

The master key itself is not stored in the credential table. It is obtained from BASE3 configuration directly or through an `env` or `file` ConfigValue definition.

The master key is highly sensitive deployment configuration. It must be access-controlled, backed up appropriately, and kept stable for as long as existing HMAC credentials must remain usable.

## HMAC request data

For an HMAC-authenticated request, KeyHarbor processes:

- full credential token from the Authorization header
- request method
- request path
- original raw query string from the client-requested URL (not internal server rewrite parameters)
- Unix timestamp header
- nonce header
- HMAC signature header
- raw request body

The raw body is read so KeyHarbor can calculate its SHA-256 digest for canonical request verification.

These values are held for request processing. The authentication code does not persist the full token, signature, raw nonce, query string, or body to the credential database.

The surrounding HTTP server, reverse proxy, application host, or diagnostics system may have its own request logging. That behavior is outside KeyHarbor and must be reviewed separately.

## HMAC replay state

After a valid HMAC signature is verified, KeyHarbor writes temporary replay state through `IStateStore`.

The replay-state key contains:

- credential id
- SHA-256 hash of the nonce

The state value is the request timestamp. The entry receives a TTL of twice the configured clock-skew window plus 60 seconds.

The raw nonce is not stored in the replay-state key. The state exists only to detect reuse within the accepted replay window.

The concrete state-store backend determines the physical storage location.

## Current request identity

The credential service can retain the currently identified credential and identity in its service instance between identity establishment and the subsequent service authorization call.

This state is runtime request context, not durable credential persistence. The surrounding runtime must ensure that request-scoped authentication state is reset correctly before processing a new explicit credential request. `KeyHarborAuthentication` calls `reset()` when it begins a credential login attempt.

## User management data

The user management service reads the current user from `IUsermanager` and may use:

- user id
- login
- display name
- email address
- language
- system administrator permission

For credential creation, owner id, login, and display name are copied into the credential record. The user's email and language can be used as defaults for notification settings.

The user-facing list response also returns the current user's id, login, name, email, language, and admin flag to that user's browser.

## Ownership protection

User-scoped credential management derives the owner from the current user and excludes service subjects. The client does not submit an owner id when creating or editing credentials.

Repository methods for user-scoped reads and updates include `owner_user_id` in their database condition. A credential id by itself is not sufficient for user-scoped access.

## Administration data

The dedicated `keyharborserviceadmindisplay` reuses the existing authenticated
administrator API and authorization. It lists technical service credentials only,
without introducing a separate dataset or additional disclosure recipients.
The general administrator overview remains available.

The administration display requires system administrator permission. Its JSON list can expose all stored credential metadata needed for administration, including:

- credential id and public id
- owner (issuing administrator) user id
- authenticated subject type (`user` or `service`)
- authenticated subject identifier (stable user or service id)
- owner login
- owner display name
- notification address and language
- label
- authentication mode
- creation, expiration, revocation, and notification timestamps
- status
- granted service ids
- subject type, subject id and per-service operation grants

It does not expose the secret hash, encrypted HMAC secret, encryption nonce, or full plaintext token.

Access to the administration display should therefore be treated as access to account-related security metadata and personal data.

## Credential labels

Credential labels are user-controlled strings. Operators and users should not put unnecessary personal, confidential, or secret information into labels because labels are persisted, shown in administration, and can be included in expiration notifications.

## Service grants

Service ids are technical authorization metadata. They reveal which protected capabilities were granted to a credential.

In some deployments this may itself be sensitive operational information and should be covered by normal access controls and backup protections.

## Expiration notifications

If `KeyExpirationNotificationJob` is enabled, KeyHarbor scans for newly expired credentials and credentials expiring within the next seven days.

The rendered notification context contains:

- owner display name
- credential label
- credential public id
- expiration time
- granted service labels
- system name
- management URL

The recipient address is the credential's notification address.

Message metadata additionally contains:

- internal credential id
- public id
- owner (issuing administrator) user id
- authenticated subject type (`user` or `service`)
- authenticated subject identifier (stable user or service id)
- notification kind (`expiring` or `expired`)

KeyHarbor enqueues the message through MessagingFoundation interfaces. The active messaging implementation determines queue persistence, transport selection, provider communication, delivery logs, retention, and any external transfer.

KeyHarbor itself does not choose the final transport.

## Notification markers

After a message has been successfully enqueued, KeyHarbor writes either:

- `warning_notified_at`
- `expiry_notified_at`

This prevents the normal job path from repeatedly queueing the same notification. If queueing fails, the marker remains unset so a later run can retry.

Changing a credential expiration resets both notification markers so the new expiration lifecycle can be notified again.

## Logging

KeyHarbor uses the BASE3 logger for unexpected management errors and notification failures.

Management display error logs can include:

- KeyHarbor scope
- generated error reference id
- exception class
- exception message
- source file and line
- chained exception details

The expiration job warning log can include:

- credential id
- notification kind
- error message
- exception class

The authentication service does not intentionally log:

- presented credential token
- bearer secret
- HMAC secret
- HMAC signature
- HMAC nonce
- raw request body

Operators should still review the configured logger, PHP error handling, web-server access logs, reverse-proxy logs, and exception reporting because those systems exist outside this plugin.

## Management request bodies

The management and administration JSON endpoints accept same-origin application requests through JSON AJAX POST calls. User management request bodies can contain:

- credential id
- label
- notification address
- notification language
- expiration timestamp
- service ids
- requested authentication mode during creation

These request bodies are not explicitly logged by KeyHarbor.

## Credential authentication results

Successful authentication results can contain:

- credential id
- owner (issuing administrator) user id
- authenticated subject type (`user` or `service`)
- authenticated subject identifier (stable user or service id)
- service id
- expiration timestamp

Failed results contain detailed internal failure codes. Consumers decide how much of that information to expose to their API clients.

Detailed public failure responses can reveal credential lifecycle state, so consumer endpoints should make an intentional choice about their error mapping.

## Access-control identity

`KeyHarborAuthentication` converts a valid explicit credential into the credential owner's BASE3 user id.

If an explicit credential is malformed or fails authentication, the authentication returns user id `0`. This prevents a failed API credential from being silently replaced by an unrelated host-session identity when KeyHarbor authentication is active for the request.

If no credential headers are present, the KeyHarbor authentication remains inactive.

## Retention

KeyHarbor has no automatic credential-retention purge.

Credential lifecycle behavior is:

1. Active credentials remain stored.
2. Expired credentials remain stored.
3. Revocation adds a revocation timestamp but keeps the record.
4. Permanent deletion is allowed only after revocation.
5. Explicit deletion removes grants and then the credential record.

A deployment must therefore define its own operational retention policy for revoked and expired credentials and decide when authorized deletion should occur.

## Deletion

Users can permanently delete only their own revoked credentials. System administrators can permanently delete any revoked credential.

Deletion removes service-grant rows first and then the credential row. The repository verifies deletion by performing a lookup after the delete operation.

Deleting a credential from KeyHarbor does not automatically delete records that may already have been copied into other systems, for example message queues, delivery logs, backups, infrastructure logs, or audit systems. Those systems require their own retention and deletion rules.

## Database backups

Database backups can contain:

- owner identity data
- notification addresses
- credential labels
- service grants
- secret hashes
- encrypted HMAC secret material
- lifecycle timestamps

Backups should therefore receive access controls and retention appropriate for authentication data and personal data.

For HMAC credentials, database backups and the HMAC master key together can restore decryptable HMAC secret material. Master-key backup and separation should be designed deliberately.

## External communication

KeyHarbor's core credential storage and authentication paths do not contact an external HTTP service.

External communication can occur when expiration messages are delivered by the configured messaging system. The actual transport and provider are outside KeyHarbor and must be documented by that implementation and deployment.

## Browser-side processing

The provided management JavaScript performs local UI behavior and same-origin JSON requests. It does not contain third-party analytics or remote asset calls.

The one-time token can be copied through the browser clipboard API when the user requests it.

## Data minimization recommendations

For a production deployment:

- use technical labels that do not contain unnecessary personal data
- grant only required service ids
- configure expiration only when it is operationally useful
- use a notification address appropriate for the credential owner or responsible team
- avoid exposing detailed credential failure reasons to untrusted callers unless required
- do not log full Authorization headers
- do not log raw HMAC request bodies by default
- protect the HMAC master key separately from normal credential administration
- periodically delete revoked credentials according to the deployment's retention policy

## Operator checklist

Before production use, document and verify:

- database backend and backup policy
- state-store backend used for replay protection and worker locks
- HMAC master-key storage, backup, access, and rotation procedure
- accepted HMAC clock-skew configuration
- user and administrator permission mapping
- credential retention and deletion schedule
- logger backend and log retention
- web-server and reverse-proxy treatment of Authorization headers and request bodies
- messaging implementation, queue persistence, transport, and provider
- notification retention and delivery logs
- handling of clipboard and browser security in administrator and user environments

## Authorization update (2026-10-19)

Credential management's administrator decision is delegated to the single
`IAuthorizationService` selected by project composition. KeyHarbor no longer reads
permissions through `IUsermanager::can()`. Existing credential persistence, logging,
expiry and deletion behavior is otherwise unaffected by this authorization refactor.


## Managed encryption profiles and access

KeyHarbor also stores administrator-configured encryption profiles in
`ISettingsStore` groups `keyharbor_encryption_profiles` and
`keyharbor_encryption_targets`. Profile data consists of administrator-chosen
IDs and labels, encryption method, active key ID, source definitions and
consumer assignments. These labels and file/environment/configuration source
names may contain organization-sensitive information and are visible only to
users authorized for system administration through the new page.

A `fixed` secret is encrypted with the existing KeyHarbor HMAC master key using
sodium secretbox and a random nonce before it is passed to `ISettingsStore`.
The encrypted ciphertext and nonce, but not the raw value, are stored in the
profile. For newly created fixed keys, the secret is generated only on the
server from secure random bytes. The plaintext is not submitted by or returned
to the browser. A newly created profile with no keys automatically receives
one fixed key with a generated unique ID. The encryption key is resolved on
demand for trusted runtime consumers. The selected source may alternatively refer to `env`, `file`, or
BASE3 configuration; in those cases KeyHarbor stores the source definition,
not the resolved secret. Access to those sources and master-key backups depends
on the actual deployment. Source definitions are never sent with fixed secrets
in JSON administration responses.

Persisted encryption profiles and keys cannot be deleted or altered through
the KeyHarbor administration API. Key rotation for active fixed keys adds a
new encrypted random secret and selects it for new writes while retaining all
historical secret definitions for decryption. Metadata labels and active key
selections remain editable. For external env/file/configuration sources,
KeyHarbor cannot prevent a system operator from changing or removing secret
material outside KeyHarbor. No external encrypted files, conversation histories,
backups or ciphertexts are inspected or deleted. Their historical dependency
on a secret cannot be determined by KeyHarbor. Runtime `IEncryptionProfileService`
can return the secret to trusted PHP consumers; consumers must never log or
persist it. SettingsStore backend retention, audit logging, backup retention and
possible external transfers depend on the project implementation and cannot be
verified by this plugin alone. The new browser controls do not store secret
values in browser persistent storage. Key edits must be authorized server-side.

## Technical service credential extension

An administrator may create, edit, rotate, revoke, and permanently delete an HMAC-only credential for a technical service subject. The credential stores a stable service identifier, an issuing administrator's account metadata, an encrypted HMAC secret, a hash verifier, and exact method grants. The service identifier and granted operation names may be confidential operational metadata and are available only to authorized administrators. A verified service subject cannot be mapped to the issuer's human session through KeyHarborAuthentication. No read permission to Memora or other domain data is implied by successful signature verification or operation grants.

The administration UI shows the newly generated token only once and clears the on-screen value when the dialog closes; copying it transfers the secret to the operating-system clipboard, whose retention is outside KeyHarbor's control. A service expiry notification is directed at its configured notification recipient and links to the administrator UI; that address and any copy of the token remain subject to deployment-specific retention policies. Service credentials follow the same explicit revocation and deletion lifecycle as user credentials, and the same replay-state TTL.

Migration 002 adds columns and backfills preexisting user credentials without changing their owner identity; it does not remove historic records. Existing databases, backup copies, event logs and message provider queues must follow the host's documented deletion and retention rules. No claim is made about host-specific transfer jurisdictions, exact retention schedules, or external processors, which must be verified separately in the deployed system.

## Embedded administration headings (2026-10-27)

The three KeyHarbor administration intro/action rows now use neutral `div` containers and
localized `h2` headings within the host application's existing page hierarchy. Dialog headings
and their accessible names remain unchanged; no new ARIA landmarks are added. The markup change
does not change the credential or encryption operations, transmitted data, storage, retention,
recipients, or access-control decisions described above. Browser and screen-reader verification
remains necessary for the deployment; the standalone template test is not a WCAG compliance claim.

## ClientStack dialog integration (2026-10-27)

The three existing KeyHarbor displays use the ClientStack ModularDialog runtime
for their browser dialogs. It owns the modal focus management, scrolling and
close lifecycle. The existing KeyHarbor AJAX endpoints, server-side permissions,
secret storage, processing purposes, retention and recipients are unchanged.
One-time API credential tokens remain ephemeral in the browser DOM and are
cleared on dialog close, including keyboard and backdrop-driven closes where
permitted. Copying a token still places it in the operating-system clipboard,
which KeyHarbor cannot delete. No dialog content is written to browser
persistent storage by this integration. Deployment-specific accessibility
verification remains necessary and is not implied by the component switch.
