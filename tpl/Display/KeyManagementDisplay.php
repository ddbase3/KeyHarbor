<?php
$error = (string)($this->_['error'] ?? '');
$profile = is_array($this->_['profile'] ?? null) ? $this->_['profile'] : [];
$serviceUrl = (string)($this->_['service'] ?? '');
$cssUrl = (string)($this->_['cssUrl'] ?? '');
$scriptUrl = (string)($this->_['scriptUrl'] ?? '');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>">

<section
	class="keyharbor-app"
	data-keyharbor-user
	data-service-url="<?php echo htmlspecialchars($serviceUrl, ENT_QUOTES, 'UTF-8'); ?>"
	data-profile="<?php echo htmlspecialchars((string)json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
>
	<header class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow">KeyHarbor</p>
			<h1>My API credentials</h1>
			<p class="keyharbor-lead">Create scoped credentials for BASE3 services. Plaintext tokens are shown once and cannot be recovered later.</p>
		</div>
		<div class="keyharbor-hero-actions">
			<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="create">Create credential</button>
		</div>
	</header>

	<?php if ($error !== ''): ?>
		<div class="keyharbor-alert keyharbor-alert-error" role="alert">
			<strong>Credential management unavailable.</strong>
			<span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
	<?php else: ?>
		<div class="keyharbor-toolbar">
			<div class="keyharbor-profile">
				<span class="keyharbor-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr((string)($profile['name'] ?? $profile['login'] ?? 'U'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
				<span>
					<strong><?php echo htmlspecialchars((string)($profile['name'] ?? $profile['login'] ?? 'Current user'), ENT_QUOTES, 'UTF-8'); ?></strong>
					<small><?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
				</span>
			</div>
			<button class="keyharbor-button keyharbor-button-quiet" type="button" data-action="refresh">Refresh</button>
		</div>

		<div class="keyharbor-alert" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading>Loading credentials…</div>
		<div class="keyharbor-grid" data-credential-grid hidden></div>
		<div class="keyharbor-empty" data-empty hidden>
			<div class="keyharbor-empty-icon" aria-hidden="true">⌁</div>
			<h2>No credentials yet</h2>
			<p>Create a credential, grant only the services it needs, and store the token immediately.</p>
			<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="create">Create first credential</button>
		</div>
	<?php endif; ?>

	<dialog class="keyharbor-dialog" data-editor-dialog>
		<div class="keyharbor-dialog-surface" data-editor-panel>
			<header class="keyharbor-dialog-header">
				<div>
					<p class="keyharbor-eyebrow">Credential</p>
					<h2 data-editor-title>Create credential</h2>
				</div>
				<button class="keyharbor-icon-button" type="button" data-action="close-editor" aria-label="Close">×</button>
			</header>

			<div class="keyharbor-dialog-body">
				<div class="keyharbor-alert keyharbor-alert-error" data-editor-error hidden></div>
				<input type="hidden" data-field="credential_id">

				<label class="keyharbor-field">
					<span>Label</span>
					<input type="text" maxlength="255" autocomplete="off" required data-field="label" placeholder="Reporting integration">
					<small>Use a name that identifies the consuming system.</small>
				</label>

				<label class="keyharbor-field">
					<span>Authentication mode</span>
					<select data-field="authentication_mode">
						<option value="bearer">Bearer token</option>
						<option value="hmac">HMAC-SHA256 signed requests</option>
					</select>
					<small>The mode is fixed after creation. HMAC protects method, path, query, timestamp, nonce and request body.</small>
				</label>

				<div class="keyharbor-form-row">
					<label class="keyharbor-field">
						<span>Notification address</span>
						<input type="text" maxlength="512" autocomplete="email" data-field="notification_address" placeholder="user@example.org">
						<small>Required when the credential expires.</small>
					</label>
					<label class="keyharbor-field keyharbor-field-compact">
						<span>Language</span>
						<input type="text" maxlength="24" autocomplete="off" data-field="notification_language" placeholder="en">
					</label>
				</div>

				<div class="keyharbor-field">
					<span>Expiration</span>
					<label class="keyharbor-switch-row">
						<input type="checkbox" data-field="expires_enabled">
						<span>Set an expiration date</span>
					</label>
					<input type="datetime-local" data-field="expires_at" disabled>
					<small>Permanent credentials can be revoked at any time.</small>
				</div>

				<fieldset class="keyharbor-service-fieldset">
					<legend>Service grants</legend>
					<p>Grant the smallest possible set of services. Existing unavailable grants can be removed, but not newly added.</p>
					<div class="keyharbor-service-list" data-service-list></div>
				</fieldset>
			</div>

			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="close-editor">Cancel</button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="save-editor" data-save-button>Save credential</button>
			</footer>
		</div>
	</dialog>

	<dialog class="keyharbor-dialog keyharbor-token-dialog" data-token-dialog>
		<div class="keyharbor-dialog-surface">
			<header class="keyharbor-dialog-header">
				<div>
					<p class="keyharbor-eyebrow">One-time secret</p>
					<h2>Store this token now</h2>
				</div>
			</header>
			<div class="keyharbor-dialog-body">
				<div class="keyharbor-alert keyharbor-alert-warning">
					<strong>This token will not be shown again.</strong>
					<span>Copy it into the consuming application before closing this dialog.</span>
				</div>
				<pre class="keyharbor-token" data-token-value tabindex="0"></pre>
				<div class="keyharbor-alert" data-token-mode-note hidden></div>
				<div class="keyharbor-copy-status" data-copy-status aria-live="polite"></div>
			</div>
			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="copy-token">Copy token</button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="close-token">I stored the token</button>
			</footer>
		</div>
	</dialog>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>
