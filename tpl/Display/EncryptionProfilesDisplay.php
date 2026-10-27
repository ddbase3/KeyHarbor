<?php
$errorCode = (string)($this->_['errorCode'] ?? '');
$translations = is_array($this->_['translations'] ?? null) ? $this->_['translations'] : [];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key): string => (string)($translations[$key] ?? '');
?>
<link rel="stylesheet" href="<?php echo $e($this->_['cssUrl']); ?>">
<link rel="stylesheet" href="<?php echo $e($this->_['dialogCssUrl']); ?>">
<section class="keyharbor-app keyharbor-encryption" data-keyharbor-encryption
	data-service-url="<?php echo $e($this->_['service']); ?>"
	data-dialog-url="<?php echo $e($this->_['dialogJsUrl']); ?>"
	data-strings="<?php echo $e((string)json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
	<div class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow"><?php echo $e($t('eyebrow')); ?></p>
			<h2><?php echo $e($t('title')); ?></h2>
			<p class="keyharbor-lead"><?php echo $e($t('lead')); ?></p>
		</div>
		<?php if ($errorCode === ''): ?>
			<div class="keyharbor-hero-actions">
				<button type="button" class="keyharbor-button" data-action="refresh"><?php echo $e($t('refresh')); ?></button>
				<button type="button" class="keyharbor-button keyharbor-button-primary" data-action="create"><?php echo $e($t('create')); ?></button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ($errorCode !== ''): ?>
		<div role="alert" class="keyharbor-alert keyharbor-alert-error"><?php echo $e($t('error_' . $errorCode)); ?></div>
	<?php else: ?>
		<div class="keyharbor-alert" role="status" aria-live="polite" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading><?php echo $e($t('loading')); ?></div>
		<div class="keyharbor-table-wrap" data-profiles-wrap hidden>
			<table class="keyharbor-table">
				<thead><tr>
					<th scope="col"><?php echo $e($t('id')); ?></th>
					<th scope="col"><?php echo $e($t('label')); ?></th>
					<th scope="col"><?php echo $e($t('method')); ?></th>
					<th scope="col"><?php echo $e($t('active_key')); ?></th>
					<th scope="col"><?php echo $e($t('keys')); ?></th>
					<th scope="col"><?php echo $e($t('actions')); ?></th>
				</tr></thead>
				<tbody data-profiles-body></tbody>
			</table>
		</div>
		<div class="keyharbor-empty" data-empty hidden><?php echo $e($t('empty')); ?></div>
		<div class="keyharbor-encryption-targets">
			<h2><?php echo $e($t('targets_title')); ?></h2>
			<p class="keyharbor-lead"><?php echo $e($t('targets_lead')); ?></p>
			<div class="keyharbor-table-wrap" data-targets-wrap hidden>
				<table class="keyharbor-table">
					<thead><tr><th scope="col"><?php echo $e($t('target')); ?></th><th scope="col"><?php echo $e($t('profile')); ?></th></tr></thead>
					<tbody data-targets-body></tbody>
				</table>
			</div>
			<p class="keyharbor-empty" data-targets-empty hidden><?php echo $e($t('targets_empty')); ?></p>
		</div>
	<div class="keyharbor-dialog-content" data-editor-content hidden>
		<div class="keyharbor-alert" role="alert" aria-live="assertive" data-editor-notice hidden></div>
		<div class="keyharbor-form-row">
			<label class="keyharbor-field"><span><?php echo $e($t('id')); ?></span><input data-field="id" maxlength="128" autocomplete="off" required></label>
			<label class="keyharbor-field"><span><?php echo $e($t('label')); ?></span><input data-field="label" maxlength="200" required></label>
		</div>
		<div class="keyharbor-form-row">
			<label class="keyharbor-field"><span><?php echo $e($t('method')); ?></span><select data-field="method"></select></label>
			<label class="keyharbor-field"><span><?php echo $e($t('active_key')); ?></span><select data-field="key_id"></select></label>
		</div>
		<h3><?php echo $e($t('keys_title')); ?></h3>
		<p class="keyharbor-lead"><?php echo $e($t('keys_help')); ?></p>
		<div data-key-list></div>
		<button type="button" class="keyharbor-button" data-action="add-key"><?php echo $e($t('add_key')); ?></button>

	</div>
	<?php endif; ?>
</section>
<?php if ($errorCode === ''): ?><script src="<?php echo $e($this->_['scriptUrl']); ?>" defer></script><?php endif; ?>
