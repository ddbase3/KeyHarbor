<?php
$error = (string)($this->_['error'] ?? '');
$serviceUrl = (string)($this->_['service'] ?? '');
$cssUrl = (string)($this->_['cssUrl'] ?? '');
$scriptUrl = (string)($this->_['scriptUrl'] ?? '');
$translations = is_array($this->_['translations'] ?? null) ? $this->_['translations'] : [];
$serviceOnly = (bool)($this->_['serviceOnly'] ?? false);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static function(string $key) use ($translations): string {
	if (!isset($translations[$key]) || trim((string)$translations[$key]) === '') {
		throw new \UnexpectedValueException('Missing KeyHarbor translation: ' . $key);
	}
	return (string)$translations[$key];
};
?>
<link rel="stylesheet" href="<?php echo $e($cssUrl); ?>">
<link rel="stylesheet" href="<?php echo $e($this->_['dialogCssUrl']); ?>">

<section
	class="keyharbor-app"
	data-keyharbor-admin
	data-service-only="<?php echo $serviceOnly ? '1' : '0'; ?>"
	data-service-url="<?php echo $e($serviceUrl); ?>"
	data-dialog-url="<?php echo $e($this->_['dialogJsUrl']); ?>"
	data-strings="<?php echo $e((string)json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
>
	<div class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow"><?php echo $e($t($serviceOnly ? 'service_page_eyebrow' : 'eyebrow')); ?></p>
			<h2><?php echo $e($t($serviceOnly ? 'service_page_title' : 'title')); ?></h2>
			<p class="keyharbor-lead"><?php echo $e($t($serviceOnly ? 'service_page_lead' : 'lead')); ?></p>
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
			<h2><?php echo $e($t($serviceOnly ? 'service_page_empty_title' : 'empty_title')); ?></h2>
			<p><?php echo $e($t($serviceOnly ? 'service_page_empty_text' : 'empty_text')); ?></p>
		</div>
	<?php endif; ?>
	<?php if ($error === ''): ?>
	<div class="keyharbor-dialog-content" data-service-editor-content hidden>
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
		<label class="keyharbor-field">
			<span><?php echo $e($t('resource_constraints')); ?></span>
			<textarea data-service-field="resource_constraints" rows="8" spellcheck="false" aria-describedby="keyharbor-constraint-help"></textarea>
			<small id="keyharbor-constraint-help"><?php echo $e($t('resource_constraints_help')); ?></small>
		</label>
		<fieldset class="keyharbor-service-fieldset">
			<legend><?php echo $e($t('service_operations')); ?></legend>
			<p><?php echo $e($t('service_operations_help')); ?></p>
			<div data-service-operations></div>
		</fieldset>

	</div>
	<div class="keyharbor-dialog-content" data-service-token-content hidden>
		<p class="keyharbor-alert keyharbor-alert-warning"><?php echo $e($t('service_token_warning')); ?></p>
		<pre class="keyharbor-token" data-service-token-value tabindex="0"></pre>
		<div class="keyharbor-copy-status" data-service-copy-status aria-live="polite"></div>

	</div>
	<?php endif; ?>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo $e($scriptUrl); ?>" defer></script>
<?php endif; ?>
