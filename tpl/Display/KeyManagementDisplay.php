<?php
$error = (string)($this->_['error'] ?? '');
$profile = is_array($this->_['profile'] ?? null) ? $this->_['profile'] : [];
$serviceUrl = (string)($this->_['service'] ?? '');
$cssUrl = (string)($this->_['cssUrl'] ?? '');
$scriptUrl = (string)($this->_['scriptUrl'] ?? '');
$translations = is_array($this->_['translations'] ?? null) ? $this->_['translations'] : [];
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key, string $fallback): string => trim((string)($translations[$key] ?? '')) !== ''
	? (string)$translations[$key]
	: $fallback;
?>
<link rel="stylesheet" href="<?php echo $e($cssUrl); ?>">

<section
	class="keyharbor-app"
	data-keyharbor-user
	data-service-url="<?php echo $e($serviceUrl); ?>"
	data-profile="<?php echo $e((string)json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
>
	<header class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow">KeyHarbor</p>
			<h1><?php echo $e($t('title', 'My API credentials')); ?></h1>
			<p class="keyharbor-lead"><?php echo $e($t('lead', 'Create scoped credentials for BASE3 services. Plaintext tokens are shown once and cannot be recovered later.')); ?></p>
		</div>
		<div class="keyharbor-hero-actions">
			<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="create"><?php echo $e($t('create', 'Create credential')); ?></button>
		</div>
	</header>

	<?php if ($error !== ''): ?>
		<div class="keyharbor-alert keyharbor-alert-error" role="alert">
			<strong><?php echo $e($t('unavailable', 'Credential management unavailable.')); ?></strong>
			<span><?php echo $e($error); ?></span>
		</div>
	<?php else: ?>
		<div class="keyharbor-toolbar">
			<div class="keyharbor-profile">
				<span class="keyharbor-profile-avatar" aria-hidden="true"><?php echo $e(strtoupper(substr((string)($profile['name'] ?? $profile['login'] ?? 'U'), 0, 1))); ?></span>
				<span>
					<strong><?php echo $e((string)($profile['name'] ?? $profile['login'] ?? $t('current_user', 'Current user'))); ?></strong>
					<small><?php echo $e((string)($profile['email'] ?? '')); ?></small>
				</span>
			</div>
			<button class="keyharbor-button keyharbor-button-quiet" type="button" data-action="refresh"><?php echo $e($t('refresh', 'Refresh')); ?></button>
		</div>

		<div class="keyharbor-alert" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading><?php echo $e($t('loading', 'Loading credentials…')); ?></div>
		<div class="keyharbor-grid" data-credential-grid hidden></div>
		<div class="keyharbor-empty" data-empty hidden>
			<div class="keyharbor-empty-icon" aria-hidden="true">⌁</div>
			<h2><?php echo $e($t('empty_title', 'No credentials yet')); ?></h2>
			<p><?php echo $e($t('empty_text', 'Create a credential, grant only the services it needs, and store the token immediately.')); ?></p>
			<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="create"><?php echo $e($t('create_first', 'Create first credential')); ?></button>
		</div>
	<?php endif; ?>

	<dialog class="keyharbor-dialog" data-editor-dialog>
		<div class="keyharbor-dialog-surface" data-editor-panel>
			<header class="keyharbor-dialog-header">
				<div>
					<p class="keyharbor-eyebrow"><?php echo $e($t('editor_eyebrow', 'Credential')); ?></p>
					<h2 data-editor-title><?php echo $e($t('editor_create_title', 'Create credential')); ?></h2>
				</div>
				<button class="keyharbor-icon-button" type="button" data-action="close-editor" aria-label="<?php echo $e($t('close', 'Close')); ?>">×</button>
			</header>

			<div class="keyharbor-dialog-body">
				<div class="keyharbor-alert keyharbor-alert-error" data-editor-error hidden></div>
				<input type="hidden" data-field="credential_id">

				<label class="keyharbor-field">
					<span><?php echo $e($t('label', 'Label')); ?></span>
					<input type="text" maxlength="255" autocomplete="off" required data-field="label" placeholder="<?php echo $e($t('label_placeholder', 'Reporting integration')); ?>">
					<small><?php echo $e($t('label_help', 'Use a name that identifies the consuming system.')); ?></small>
				</label>

				<label class="keyharbor-field">
					<span><?php echo $e($t('authentication_mode', 'Authentication mode')); ?></span>
					<select data-field="authentication_mode">
						<option value="bearer"><?php echo $e($t('authentication_bearer', 'Bearer token')); ?></option>
						<option value="hmac"><?php echo $e($t('authentication_hmac', 'HMAC-SHA256 signed requests')); ?></option>
					</select>
					<small><?php echo $e($t('authentication_help', 'The mode is fixed after creation. HMAC protects method, path, query, timestamp, nonce and request body.')); ?></small>
				</label>

				<div class="keyharbor-form-row">
					<label class="keyharbor-field">
						<span><?php echo $e($t('notification_address', 'Notification address')); ?></span>
						<input type="text" maxlength="512" autocomplete="email" data-field="notification_address" placeholder="user@example.org">
						<small><?php echo $e($t('notification_help', 'Required when the credential expires.')); ?></small>
					</label>
					<label class="keyharbor-field keyharbor-field-compact">
						<span><?php echo $e($t('language', 'Language')); ?></span>
						<input type="text" maxlength="24" autocomplete="off" data-field="notification_language" placeholder="en">
					</label>
				</div>

				<div class="keyharbor-field">
					<span><?php echo $e($t('expiration', 'Expiration')); ?></span>
					<label class="keyharbor-switch-row">
						<input type="checkbox" data-field="expires_enabled">
						<span><?php echo $e($t('set_expiration', 'Set an expiration date')); ?></span>
					</label>
					<input type="datetime-local" data-field="expires_at" disabled>
					<small><?php echo $e($t('expiration_help', 'Permanent credentials can be revoked at any time.')); ?></small>
				</div>

				<fieldset class="keyharbor-service-fieldset">
					<legend><?php echo $e($t('service_grants', 'Service grants')); ?></legend>
					<p><?php echo $e($t('service_grants_help', 'Grant the smallest possible set of services. Existing unavailable grants can be removed, but not newly added.')); ?></p>
					<div class="keyharbor-service-list" data-service-list></div>
				</fieldset>
			</div>

			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="close-editor"><?php echo $e($t('cancel', 'Cancel')); ?></button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="save-editor" data-save-button><?php echo $e($t('save', 'Save credential')); ?></button>
			</footer>
		</div>
	</dialog>

	<dialog class="keyharbor-dialog keyharbor-token-dialog" data-token-dialog>
		<div class="keyharbor-dialog-surface">
			<header class="keyharbor-dialog-header">
				<div>
					<p class="keyharbor-eyebrow"><?php echo $e($t('secret_eyebrow', 'One-time secret')); ?></p>
					<h2><?php echo $e($t('secret_title', 'Store this token now')); ?></h2>
				</div>
			</header>
			<div class="keyharbor-dialog-body">
				<div class="keyharbor-alert keyharbor-alert-warning">
					<strong><?php echo $e($t('secret_warning_title', 'This token will not be shown again.')); ?></strong>
					<span><?php echo $e($t('secret_warning_text', 'Copy it into the consuming application before closing this dialog.')); ?></span>
				</div>
				<pre class="keyharbor-token" data-token-value tabindex="0"></pre>
				<div class="keyharbor-alert" data-token-mode-note hidden></div>
				<div class="keyharbor-copy-status" data-copy-status aria-live="polite"></div>
			</div>
			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="copy-token"><?php echo $e($t('copy_token', 'Copy token')); ?></button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="close-token"><?php echo $e($t('stored_token', 'I stored the token')); ?></button>
			</footer>
		</div>
	</dialog>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo $e($scriptUrl); ?>" defer></script>
<?php endif; ?>
