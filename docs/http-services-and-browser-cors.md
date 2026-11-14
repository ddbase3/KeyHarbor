# KeyHarbor HTTP service usage, browser integration and CORS operations

This guide explains how KeyHarbor-protected BASE3 services are used by terminals, desktop applications, mobile applications and web browsers. It includes the full credential lifecycle, the security distinction between personal bearer and technical HMAC identities, HTTP request examples, and the required host-side behavior for CORS.

**Implementation status (XRM host):** KeyHarbor provides the credential catalog, secrets, authentication and grants. `Base3XrmWebsite\Middleware\XrmCredentialCorsMiddleware` now implements the opt-in CORS transport for exactly declared KeyHarbor HTTP endpoints. It runs before the unchanged session, authentication and credential-operation middleware. Cross-origin access is **disabled unless the host explicitly configures allowed Origins**. Other BASE3 hosts do not automatically inherit XRM's CORS middleware and must integrate the same existing service catalog at their own HTTP entry boundary.

## 1. Goals and architectural limits

The desired integration looks like this:

```text
Personal CLI / native app / authorized browser UI
    -> HTTPS + personal Bearer credential
    -> host HTTP route and optional browser CORS negotiation
    -> existing KeyHarbor credential authentication and service grant
    -> owning plugin's existing permission/resource checks
    -> service response

Technical scheduled client / microservice
    -> HTTPS + signed HMAC request
    -> existing KeyHarbor signed-request authentication
    -> exact technical operation grant
    -> IAuthorizationService and native resource ACL
    -> existing microservice response
```

There is **one credential implementation**, **one classmap service catalog**, **one HTTP handler per service**, and **one native authorization authority** for the protected operation. CORS must not introduce a proxy service, alternate token format, shared client identity, HTTP router duplicate, permission store or service-specific access fallback.

KeyHarbor is not a generic reverse proxy, HTTP route publisher, CORS security filter or agent runner. The host/project application remains responsible for the HTTP request boundary and for mapping published method/path pairs to the already discovered services. KeyHarbor remains responsible for credential storage and service/operation grants.

## 2. Credential architecture in the current source

| Owner | Role | Important invariant |
| --- | --- | --- |
| `Base3\CredentialFoundation\Api\ICredentialServiceProvider` | Discoverable service descriptors | Service IDs unique across plugins |
| `Base3\KeyHarbor\Service\CredentialServiceCatalog` | Collects service descriptors using `IClassMap` | Duplicate IDs rejected; deterministic ordering |
| `Base3\KeyHarbor\Service\CredentialManagementService` | User-scoped create/edit/rotate/revoke | Owner comes from verified user context, never request input |
| `Base3\KeyHarbor\Display\KeyManagementDisplay` | Personal token editor and one-time token presentation | Existing AJAX UI and ClientStack dialog |
| `Base3\KeyHarbor\Display\KeyHarborAdminDisplay` | Credential administration | Server-side admin authorization required |
| `Base3\KeyHarbor\Accesscontrol\KeyHarborAuthentication` | Identifies the presented Bearer/HMAC credential | Technical subject cannot become a user session |
| `Base3\KeyHarbor\Service\ApiCredentialService` | Credential validation, lifecycle and grant decisions | Separate identity and service/operation decisions |
| `Base3\KeyHarbor\Security\CredentialTokenService` | Generates and parses high-entropy tokens | Secret material never available from a list call |
| `Base3\KeyHarbor\Repository\DatabaseCredentialRepository` | Credential persistence and owner-bound reads | Native owner restrictions at database boundary |
| `Base3\CredentialFoundation\Security\HmacRequestSigner` | Canonical six-line signature format | Exact request bytes and request path matter |
| Project HTTP middleware | Owns routes, response status, CORS and dispatch | Does not invent a second KeyHarbor grant engine |

An application plugin may expose several services through a single provider; multiple providers from different plugins are supported. Service IDs are global stable identifiers, not dynamic URLs. A label is displayed in the credential UI but is not an authorization key.

## 3. Create and maintain a personal token

### User workflow

1. Sign in with the desired human account to the target BASE3 installation.
2. Open the existing KeyHarbor personal credential display (`keymanagementdisplay`).
3. Create a credential and select the precise discovered service grant(s). Granting one service does not grant every service from the same plugin.
4. Copy the full generated token from the one-time success dialog.
5. Save it in the client's trusted credential store. Do not put it in source code, a URL, a shared browser page or an unencrypted configuration file.
6. Configure the client to send the standard HTTP `Authorization` header, and use only the method and URL published by the owning service.
7. Revoke the credential in KeyHarbor when the client is retired or its token may have leaked.

Token syntax:

```text
b3k_<20-lowercase-hex-public-id>_<43-character-base64url-secret>
```

The credential repository stores a public identifier and SHA-256 secret hash. The full token is displayed on creation and rotation only. Neither a credential list nor an administrator overview reveals it later.

### Management and rotation

A personal credential has an owner, selected service grants and lifecycle state. It may have an expiration date and notification fields. The existing user manager resolves the current account, and KeyHarbor owner-bound queries enforce ownership on the database side. Token rotation keeps the credential record identity but replaces the public ID and secret hash, thereby invalidating the old token.

**A personal credential is not an administrator token.** An account's service grant does not bypass the native user permissions required by the target operation. Service-specific calls must recheck their own authorization after KeyHarbor verifies the token.

### User identity versus native account identifiers

KeyHarbor credentials identify the **native owner account**. Some hosts store native primary keys such as `User::$id`, while their active usermanager expects a different exact login identifier such as `User::$userid`. Those values must not be interchanged. A correct host integration resolves the trusted native account to the required current login using the native usermanager, without guessing from digits and without treating a deleted account as authenticated.

`CredentialSubject('user', '42')` is not authorization to treat the literal string `42` as a login. Conversely, `CredentialSubject('service', 'automation')` is not a human account. This distinction remains mandatory for every browser and non-browser client.

## 4. Personal Bearer HTTP calls

For a service that advertises a JSON `POST`, the transport has this shape:

```http
POST /service-output.json HTTP/1.1
Host: example.net
Authorization: Bearer b3k_<PUBLIC_ID>_<SECRET>
Content-Type: application/json
Accept: application/json

{"message":"Example request"}
```

The request body above is **service-specific**, not a universal KeyHarbor schema. In particular, KeyHarbor does not define `message`, `conversation_id`, `system_prompt`, `agent_id`, streaming or agent memory. Those fields are owned by the actual HTTP handler.

The project-owned Jarvis integration, if installed, illustrates this model:

```http
POST /jarvisagentapi.json HTTP/1.1
Host: xrm.base3.de
Authorization: Bearer <PERSONAL_KEYHARBOR_TOKEN>
Content-Type: application/json
Accept: application/json

{"message":"Hallo Jarvis!"}
```

The associated service grant is `missionbay:agent:jarvis`. Changing the HTTP transport does not allow invoking arbitrary agents or changing Jarvis's stored model, system prompt, memory or tool configuration. For full details, follow the deployed Base3XrmWebsite documentation under `docs/jarvis-api.md` in the owning project plugin.

### CLI and native clients

Linux, macOS, Windows PowerShell, native iOS/Android networking and ordinary server-side HTTP clients do not enforce the browser's CORS protocol. They still require the correct personal credential and all existing permissions. A CORS failure in a website but a successful call in `curl` is therefore possible and does not imply that the browser has a different credential grant.

For a trusted Bash interactive test:

```sh
read -r -s -p 'KeyHarbor personal token: ' TOKEN
printf '\n'
curl -i -X POST 'https://xrm.base3.de/jarvisagentapi.json' \
  -H "Authorization: Bearer ${TOKEN}" \
  -H 'Content-Type: application/json' \
  --data '{"message":"Hallo Jarvis!"}'
unset TOKEN
```

Keep the token out of recorded terminal sessions and debug logs. OS-native keyrings are preferable for regular client operation. The example assumes Bash and the installed Jarvis service; it is not a universal KeyHarbor endpoint.

## 5. Technical HMAC credentials remain separate

Technical credentials are explicitly created for a service principal and grant **individual operations**. They are not a different flavor of a logged-in person.

For technical service calls, KeyHarbor verifies the public token, HMAC authentication mode, canonical request signature, timestamp window and nonce replay protection before an operation can be authorized. The canonical request uses:

```text
METHOD
EXTERNAL_PATH
EXTERNAL_QUERY_STRING
UNIX_TIMESTAMP
NONCE
SHA256_HEX_RAW_BODY
```

The client transmits:

```http
Authorization: Bearer <TECHNICAL_KEYHARBOR_TOKEN>
X-BASE3-Timestamp: <UNIX_TIMESTAMP>
X-BASE3-Nonce: <RANDOM_UNIQUE_NONCE>
X-BASE3-Signature: <LOWERCASE_HMAC_SHA256_HEX>
```

The actual signature depends on the secret, original request method/path/query, timestamp, nonce and **raw bytes** of the body. It must not be generated using a normalized or rewritten path. The existing HMAC settings are read from the `keyharbor` configuration section; documented keys in the existing implementation include `hmac_master_key` and `hmac_clock_skew_seconds`.

`ICredentialOperationAccess::authorizeOperation()` checks exact operation grants. `IAuthorizationService` and the owning resource subsystem remain responsible for policy and ACL decisions. The two mechanisms are cumulative, not interchangeable.

**CORS does not change any of this.** In particular, a browser's unauthenticated `OPTIONS` request is not a valid HMAC request, must not consume a nonce, must not log in a service principal and must not trigger a microservice operation. XRM's CORS transport applies to **any** canonical HTTP endpoint declared in the service catalog, including technical-only endpoints, when its Origin is explicitly allowlisted. No endpoint is cross-origin accessible by default. A successful preflight never bypasses HMAC verification or exact operation authorization on the real request. Operators should only allow browser origins they trust with the corresponding service.

## 6. The CORS problem when using KeyHarbor from a web page

Browsers use an origin made up of scheme, hostname and port. For example, `https://portal.example` and `https://xrm.base3.de` are different origins. A JavaScript `fetch()` using a Bearer header and JSON typically triggers a browser `OPTIONS` preflight *before* the `POST`.

Example:

```http
OPTIONS /jarvisagentapi.json HTTP/1.1
Host: xrm.base3.de
Origin: https://client.example
Access-Control-Request-Method: POST
Access-Control-Request-Headers: authorization,content-type
```

There is **no bearer token in this preflight**. A host middleware that rejects every request without a KeyHarbor token will reject it before the browser sends the actual `POST`. This is expected browser behavior, not a reason to weaken KeyHarbor or to move the token into the URL.

CORS and KeyHarbor answer different questions:

| Question | Owner |
| --- | --- |
| Is this HTTP method/path an exposed service endpoint? | Existing HTTP host and service metadata |
| Is the requesting browser origin allowed to read the endpoint's response? | Host HTTP CORS policy |
| Does the presented secret identify an active credential? | KeyHarbor |
| Does that credential grant this logical service? | KeyHarbor |
| Can this technical principal call this exact operation? | KeyHarbor operation grant and host `IAuthorizationService` |
| May the account/principal access the requested resource? | Owning domain/host authorization |
| How does the response body look? | Owning service HTTP handler |

A positive preflight **never** substitutes for any of the later decisions.

## 7. Implemented XRM CORS transport and policy

CORS is best implemented at the **existing HTTP middleware/response boundary of the project hosting the KeyHarbor services**. The host should read the same discovered service catalog and, where available, the service's authoritative HTTP endpoint metadata. It must not add another KeyHarbor catalog, introduce per-token CORS profiles, or copy route tables into a separate whitelist maintained by hand.

### Policy requirements

- Disabled by default without an explicit approved HTTPS origin.
- One installation-level allowlist via existing static deployment configuration, not new per-user or per-token settings.
- Exact scheme + hostname + port comparisons, not substring, suffix or domain wildcards.
- `Origin: null`, empty and malformed origins are not granted access.
- Only exact, programmatically published method/path pairs qualify.
- Only explicitly documented request headers qualify; use `Authorization`, `Content-Type` for a minimal personal JSON `POST`.
- The current HMAC technical routes and authentication remain unchanged.
- No session-cookie fallback or additional login variant is enabled.
- No automatic CORS enablement for unrelated output pages, administrative AJAX or static assets.
- No changes to existing token lifecycle, service grants, operation checks or native ACLs.

**Implemented configuration:** the XRM middleware reads the existing `IConfiguration` interface with `getArray('keyharbor', 'cors_origins', [])`. Put the following in the deployment's existing `cnf/config.ini` or its supported included file, using your actual frontend origins:

```ini
[keyharbor]
cors_origins[] = "https://portal.example"
cors_origins[] = "https://client.example:8443"
# Optional local development only:
cors_origins[] = "http://localhost:5173"
```

This is **one installation-wide static configuration list**, not a token profile, new SettingsStore group, new grant, per-service override or browser UI. Missing/empty list means zero approved Origins. Origins are compared **exactly**, including scheme and port. Do not include trailing slashes, URL paths, wildcards, credentials, query strings, or `Origin: null`. Only HTTPS Origins are supported, with an explicit HTTP exception for `localhost`, `127.0.0.1` and `[::1]` development Origins. A local browser app served on a different port must have its exact port listed. Updating this configuration never changes an existing KeyHarbor token grant. The actual hosted service must still use HTTPS.

### Approved preflight

```http
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://client.example
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-BASE3-Timestamp, X-BASE3-Nonce, X-BASE3-Signature
Access-Control-Max-Age: 600
Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers
```

`Access-Control-Max-Age: 600` is the implemented XRM interval. The browser may apply a shorter internal limit. It does **not** cache the user's credential authorization, expiry or revocation state.

Do not automatically add `Access-Control-Allow-Credentials: true`. For a browser application deliberately sending a personal Bearer header with `credentials: 'omit'`, cookie forwarding is not part of the intended protocol. Browsers require more restrictive header combinations for credentialed requests; that is a different transport decision.

### Actual response, including authorization failures

For an allowed origin and a published endpoint, the existing response should carry:

```http
Access-Control-Allow-Origin: https://client.example
Vary: Origin
```

This applies to successful responses and early auth failures, including empty `401` or `403` responses from credential middleware. Otherwise a client may see a generic CORS error rather than the true HTTP status. Do not expose secret values or detailed internal verification results merely to make browser troubleshooting easier.

For disallowed origins, **omit** the allow-origin header. Do not echo arbitrary origins and do not return wildcard access merely because a token was valid. Do not identify an account from the untrusted Origin header.

## 8. Browser client example after XRM configuration

This example assumes the **XRM middleware is deployed** and the exact Origin `https://client.example` was entered in `[keyharbor] cors_origins[]`. Other hosts may not have CORS configured, even when KeyHarbor is installed.

```js
async function askJarvis(personalToken, message, conversationId = null) {
    const payload = { message };
    if (conversationId) payload.conversation_id = conversationId;

    const response = await fetch('https://xrm.base3.de/jarvisagentapi.json', {
        method: 'POST',
        credentials: 'omit',
        headers: {
            'Authorization': `Bearer ${personalToken}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    // Do not require an error JSON body: middleware may return an empty 401/403.
    if (!response.ok) throw new Error(`Jarvis HTTP ${response.status}`);
    const data = await response.json();
    if (data.ok !== true || typeof data.conversation_id !== 'string') {
        throw new Error('Jarvis did not return a successful response');
    }
    return data;
}
```

The caller should store only its own conversation ID and should clear or securely retain the personal token according to its security model. An untrusted webpage given a Bearer token can act as that user; an origin allowlist is an additional browser sharing decision, **not** protection against a compromised allowed website.

### Browser extensions

Chrome/Edge/Firefox extensions may use browser-specific host permissions and a privileged extension background context for HTTP access without general webpage CORS. Never send personal tokens to page DOM, content scripts, unrestricted message listeners or a website's local storage. Review each browser's current extension networking rules and host permissions. An extension's ability to call the API does not change KeyHarbor grants.

### Desktop and mobile applications

Native Windows, Linux, macOS, iOS and Android HTTP clients do not require CORS. Use a personal token stored in the platform's protected credential storage: Windows Credential Manager, desktop keyring/libsecret, Apple Keychain or Android Keystore-backed storage. These clients must still respect the same service authorization and HTTP contract. Avoid creating mobile-specific routes or a separate authentication mode.

## 9. Operators: enablement and validation workflow

The CORS implementation should add no new database tables, migrations or credential-profile choices. It should use the hosting application's existing HTTP composition and static configuration mechanisms. Operational steps are:

1. Confirm the current deployed KeyHarbor, CredentialFoundation and project HTTP versions together, not only the token UI version.
2. Verify that the host's service catalog exposes authoritative published paths/methods. Some older Foundation versions only have logical service/operation metadata; do not guess a route from the service ID.
3. Configure `cors_origins[]` under `[keyharbor]` for each intended frontend Origin. This list applies to all **canonically declared HTTP endpoints** on the XRM host, not just Jarvis.
4. Verify a positive browser preflight on one known service and a negative preflight on an unknown path or origin.
5. Verify that the real POST still requires an authenticated personal token and its service grant.
6. Verify that failures with allowed origin return the normal `401`/`403` status to the browser.
7. Recheck production HMAC clients, native ACL filtering, nonce replay, expiration and revocation on the staging deployment.
8. Confirm that no cookies or unauthorized access-control response headers are introduced.
9. Repeat the tests after proxy changes or migration to a different host/port.
10. Document the active allowlist and request logging in the host's privacy/deployment documentation.

### Positive preflight probe

```sh
curl -i -X OPTIONS 'https://xrm.base3.de/jarvisagentapi.json' \
  -H 'Origin: https://client.example' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: authorization,content-type'
```

Expected **with the XRM CORS middleware deployed and the origin configured**: an HTTP `204` with empty body and a successful preflight response, exact allow-origin, POST in allow-methods, and authorization/content-type in allow-headers. No token or JSON response body is required for the preflight.

### Negative origin probe

```sh
curl -i -X OPTIONS 'https://xrm.base3.de/jarvisagentapi.json' \
  -H 'Origin: https://untrusted.example' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: authorization,content-type'
```

Expected: no usable `Access-Control-Allow-Origin` for the untrusted origin. It is valid for a host to return a denial status. Be aware that `curl` does not enforce CORS: it merely prints the headers for inspection.

### Negative method probe

```sh
curl -i -X OPTIONS 'https://xrm.base3.de/jarvisagentapi.json' \
  -H 'Origin: https://client.example' \
  -H 'Access-Control-Request-Method: DELETE' \
  -H 'Access-Control-Request-Headers: authorization,content-type'
```

Expected: no approval of DELETE, and absolutely no agent or microservice invocation.

## 10. Compatibility matrix

| Existing behavior | Must remain unchanged with CORS |
| --- | --- |
| CLI and native personal Bearer calls | Yes, without an Origin header |
| KeyHarbor user token issuance/rotation/revocation | Yes |
| Personal service grant checks | Yes, exact service ID |
| Technical HMAC signatures and request canonicalization | Yes |
| Technical operation grants | Yes, no broad default |
| Native user versus technical service identity | Yes, no impersonation |
| Native resource authorization | Yes, independent of CORS |
| Existing `KeyManagementDisplay` AJAX calls | Yes, no global CORS exposure |
| Technical XRM authentication/usermanager microservices | Yes, no newly accepted credentials or methods |
| Legacy routes, URLs, query output selectors | Yes, no inferred CORS aliases |
| Rate limiting, error handling, caching | No token lifetime or authorization caching added by preflight |
| Site's existing data processing and retention | No change except documented HTTP metadata handling where implemented |

## 11. Failure diagnosis

| Symptom | What to check |
| --- | --- |
| Native CLI works, browser fails before POST | Look for an `OPTIONS` preflight and check origin, method and requested headers. |
| Browser console shows CORS network error, server logs show 403 | Was `OPTIONS` rejected as an unauthenticated API call? Was the origin missing from the host allowlist? |
| Browser receives actual `401` | Personal token absent/invalid/expired/revoked, or credential identity not established. |
| Browser receives actual `403` | Service grant absent, wrong user/host identity, or authorization rejected. |
| Browser shows CORS error for invalid token | CORS response headers may be missing on early middleware errors for an otherwise allowed origin. |
| Works for one app domain but not another | Origin includes scheme and port; compare exact values. |
| Cookie/credential warning | Check whether the client sends `credentials: 'include'` or the server wrongly emits credentialed/wildcard headers. |
| Unexpected successful preflight on unknown route | Stop rollout. The CORS path selection may be broader than the discovered endpoint catalog. |
| Technical service behavior changed | Stop rollout and restore the previous middleware composition. CORS must not change HMAC identity or permission checks. |
| Duplicate service IDs after plugin install | Repair the owning `ICredentialServiceProvider` definitions. Do not select one by registration order. |

The browser intentionally hides many details of CORS failures from JavaScript. Inspect the browser network panel and the public headers/status of the preflight and actual request. Never collect plaintext personal tokens in support tickets or screenshots.

## 12. Security and privacy

A service token is a secret even if the HTTP endpoint URL and method are public. Approved browser origins are explicit deployment trust decisions. Every allowed origin should be reviewed for XSS exposure, third-party dependencies and storage behavior. Browser local storage is not a default safe location for transferable personal bearer credentials.

The CORS preflight needs only path, method and requested header names. It must not process a user profile, access the agent conversation memory, consume a technical nonce, mint a credential or store an additional authorization record. If the host logs Origins or preflight metadata, describe the actual logs and retention accurately in the host's privacy documentation.

For credential-related processing, storage, recipients, deletion and known deployment gaps, see KeyHarbor's root [PRIVACY.md](../PRIVACY.md). For the concrete service's prompts, output, model providers and memory, see the **owning service's** privacy information. KeyHarbor's privacy document alone cannot establish the deployment's GDPR posture.

## 13. Developer checklist for a safe patch

- [ ] Compare the uploaded source with the last accepted deployed KeyHarbor/CredentialFoundation/host versions.
- [ ] Reuse the existing `CredentialServiceCatalog` for service discovery.
- [ ] Use the installed `CredentialServiceDefinition` and any supported HTTP endpoint metadata without guessing signatures.
- [x] XRM: CORS runs at the host HTTP boundary before session and credential authentication; other hosts remain responsible for their own integration.
- [ ] Require a deliberate origin allowlist; no unknown origin or route wildcard.
- [ ] Do not introduce new KeyHarbor credential modes, origin tables, grants or profiles.
- [ ] Keep exact operation authorization in the existing KeyHarbor and host services.
- [ ] Apply CORS headers to authorized-origin negative responses, not just successful handler output.
- [ ] Test exact origin/method/header matching and all negative paths.
- [ ] Test existing technical HMAC calls and personal Bearer identity resolution.
- [ ] Keep all user-visible translations in the owning plugin's language files if UI text changes.
- [ ] Update relevant privacy documentation based on actual processing.
- [ ] Verify installer and rollback against source hashes from the accepted baseline.

This document covers both the existing credential semantics and the XRM host's implemented CORS middleware. **Installing the documentation does not enable cross-origin access:** only deploying the middleware and deliberately configuring `keyharbor/cors_origins` enables an origin. Non-XRM hosts require their own implementation at their existing HTTP boundary.

## 14. Further reading and related sources

- [CredentialFoundation: HTTP service publication and CORS contract](../../CredentialFoundation/docs/http-service-publication-and-cors.md)
- [KeyHarbor main README](../README.md)
- [KeyHarbor FAQ](faq.md)
- [KeyHarbor privacy information](../PRIVACY.md)
- [WHATWG Fetch Standard](https://fetch.spec.whatwg.org/#http-cors-protocol)
- [MDN CORS guide](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS)
- `src/Service/CredentialServiceCatalog.php`
- `src/Service/CredentialManagementService.php`
- `src/Service/ApiCredentialService.php`
- `src/Accesscontrol/KeyHarborAuthentication.php`
- `src/Display/KeyManagementDisplay.php`
- `src/Display/KeyHarborAdminDisplay.php`
- `src/Security/CredentialTokenService.php`

Paths in the last list are relative to `plugin/KeyHarbor/`. The exact host-side CORS implementation and configuration in XRM are documented in `Base3XrmWebsite/docs/keyharbor-http-cors.md`.
