<?php
$error = (string)($this->_['error'] ?? '');
$serviceUrl = (string)($this->_['service'] ?? '');
$cssUrl = (string)($this->_['cssUrl'] ?? '');
$scriptUrl = (string)($this->_['scriptUrl'] ?? '');
$translations = is_array($this->_['translations'] ?? null) ? $this->_['translations'] : [];
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static function(string $key) use ($translations): string {
	if (!isset($translations[$key]) || trim((string)$translations[$key]) === '') {
		throw new \UnexpectedValueException('Missing KeyHarbor translation: ' . $key);
	}
	return (string)$translations[$key];
};
?>
<link rel="stylesheet" href="<?php echo $e($cssUrl); ?>">

<section
	class="keyharbor-app"
	data-keyharbor-admin
	data-service-url="<?php echo $e($serviceUrl); ?>"
	data-strings="<?php echo $e((string)json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
>
	<div class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow"><?php echo $e($t('eyebrow')); ?></p>
			<h2><?php echo $e($t('title')); ?></h2>
			<p class="keyharbor-lead"><?php echo $e($t('lead')); ?></p>
		</div>
		<div class="keyharbor-hero-actions">
			<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="create-service"><?php echo $e($t('create_service')); ?></button>
			<button class="keyharbor-button keyharbor-button-quiet" type="button" data-action="refresh"><?php echo $e($t('refresh')); ?></button>
		</div>
	</div>

	<?php if ($error !== ''): ?>
		<div class="keyharbor-alert keyharbor-alert-error" role="alert">
			<strong><?php echo $e($t('unavailable')); ?></strong>
			<span><?php echo $e($error); ?></span>
		</div>
	<?php else: ?>
		<div class="keyharbor-admin-summary" data-summary></div>
		<div class="keyharbor-admin-filters">
			<label class="keyharbor-field">
				<span><?php echo $e($t('search')); ?></span>
				<input type="search" data-filter="search" placeholder="<?php echo $e($t('search_placeholder')); ?>">
			</label>
			<label class="keyharbor-field keyharbor-field-compact">
				<span><?php echo $e($t('status')); ?></span>
				<select data-filter="status">
					<option value=""><?php echo $e($t('status_all')); ?></option>
					<option value="active"><?php echo $e($t('status_active')); ?></option>
					<option value="expired"><?php echo $e($t('status_expired')); ?></option>
					<option value="revoked"><?php echo $e($t('status_revoked')); ?></option>
				</select>
			</label>
		</div>

		<div class="keyharbor-alert" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading><?php echo $e($t('loading')); ?></div>
		<div class="keyharbor-table-wrap" data-table-wrap hidden>
			<table class="keyharbor-table">
				<thead>
					<tr>
						<th><?php echo $e($t('column_credential')); ?></th>
						<th><?php echo $e($t('column_owner')); ?></th>
						<th><?php echo $e($t('column_status')); ?></th>
						<th><?php echo $e($t('column_services')); ?></th>
						<th><?php echo $e($t('column_expiration')); ?></th>
						<th class="keyharbor-table-actions"><?php echo $e($t('column_action')); ?></th>
					</tr>
				</thead>
				<tbody data-table-body></tbody>
			</table>
		</div>
		<div class="keyharbor-empty" data-empty hidden>
			<h2><?php echo $e($t('empty_title')); ?></h2>
			<p><?php echo $e($t('empty_text')); ?></p>
		</div>
	<?php endif; ?>
	<?php if ($error === ''): ?>
	<dialog class="keyharbor-dialog" data-service-editor aria-labelledby="keyharbor-service-editor-title">
		<div class="keyharbor-dialog-surface">
			<header class="keyharbor-dialog-header">
				<h2 id="keyharbor-service-editor-title" data-service-editor-title><?php echo $e($t('create_service')); ?></h2>
				<button class="keyharbor-icon-button" type="button" data-action="close-service-editor" aria-label="<?php echo $e($t('close')); ?>">×</button>
			</header>
			<div class="keyharbor-dialog-body">
				<div class="keyharbor-alert keyharbor-alert-error" data-service-editor-error role="alert" hidden></div>
				<input type="hidden" data-service-field="credential_id">
				<label class="keyharbor-field">
					<span><?php echo $e($t('service_subject')); ?></span>
					<input type="text" data-service-field="subject_id" maxlength="160" autocomplete="off" required pattern="[a-z][a-z0-9._:-]*" placeholder="contourz">
					<small><?php echo $e($t('service_subject_help')); ?></small>
				</label>
				<label class="keyharbor-field">
					<span><?php echo $e($t('service_label')); ?></span>
					<input type="text" data-service-field="label" maxlength="255" autocomplete="off" required>
				</label>
				<div class="keyharbor-form-row">
					<label class="keyharbor-field">
						<span><?php echo $e($t('service_notification')); ?></span>
						<input type="email" data-service-field="notification_address" maxlength="512" autocomplete="email">
					</label>
					<label class="keyharbor-field keyharbor-field-compact">
						<span><?php echo $e($t('service_language')); ?></span>
						<input type="text" data-service-field="notification_language" maxlength="24" value="en" autocomplete="off">
					</label>
				</div>
				<div class="keyharbor-field">
					<span><?php echo $e($t('service_expiration')); ?></span>
					<label class="keyharbor-switch-row">
						<input type="checkbox" data-service-field="expires_enabled">
						<span><?php echo $e($t('service_set_expiration')); ?></span>
					</label>
					<input type="datetime-local" data-service-field="expires_at" disabled>
				</div>
				<fieldset class="keyharbor-service-fieldset">
					<legend><?php echo $e($t('service_operations')); ?></legend>
					<p><?php echo $e($t('service_operations_help')); ?></p>
					<div data-service-operations></div>
				</fieldset>
			</div>
			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="close-service-editor"><?php echo $e($t('cancel')); ?></button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="save-service" data-service-save><?php echo $e($t('save_service')); ?></button>
			</footer>
		</div>
	</dialog>
	<dialog class="keyharbor-dialog keyharbor-token-dialog" data-service-token-dialog aria-labelledby="keyharbor-token-dialog-title">
		<div class="keyharbor-dialog-surface">
			<header class="keyharbor-dialog-header"><h2 id="keyharbor-token-dialog-title"><?php echo $e($t('service_token_title')); ?></h2></header>
			<div class="keyharbor-dialog-body">
				<p class="keyharbor-alert keyharbor-alert-warning"><?php echo $e($t('service_token_warning')); ?></p>
				<pre class="keyharbor-token" data-service-token-value tabindex="0"></pre>
				<div class="keyharbor-copy-status" data-service-copy-status aria-live="polite"></div>
			</div>
			<footer class="keyharbor-dialog-footer">
				<button class="keyharbor-button keyharbor-button-secondary" type="button" data-action="copy-service-token"><?php echo $e($t('copy_service_token')); ?></button>
				<button class="keyharbor-button keyharbor-button-primary" type="button" data-action="close-service-token"><?php echo $e($t('close_service_token')); ?></button>
			</footer>
		</div>
	</dialog>
	<?php endif; ?>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo $e($scriptUrl); ?>" defer></script>
<?php endif; ?>
