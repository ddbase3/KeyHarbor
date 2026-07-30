<?php
$error = (string)($this->_['error'] ?? '');
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
	data-keyharbor-admin
	data-service-url="<?php echo $e($serviceUrl); ?>"
>
	<header class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow"><?php echo $e($t('eyebrow', 'KeyHarbor administration')); ?></p>
			<h1><?php echo $e($t('title', 'All API credentials')); ?></h1>
			<p class="keyharbor-lead"><?php echo $e($t('lead', 'Review credential ownership, lifecycle and service grants. Secret material is never displayed.')); ?></p>
		</div>
		<div class="keyharbor-hero-actions">
			<button class="keyharbor-button keyharbor-button-quiet" type="button" data-action="refresh"><?php echo $e($t('refresh', 'Refresh')); ?></button>
		</div>
	</header>

	<?php if ($error !== ''): ?>
		<div class="keyharbor-alert keyharbor-alert-error" role="alert">
			<strong><?php echo $e($t('unavailable', 'Administration unavailable.')); ?></strong>
			<span><?php echo $e($error); ?></span>
		</div>
	<?php else: ?>
		<div class="keyharbor-admin-summary" data-summary></div>
		<div class="keyharbor-admin-filters">
			<label class="keyharbor-field">
				<span><?php echo $e($t('search', 'Search')); ?></span>
				<input type="search" data-filter="search" placeholder="<?php echo $e($t('search_placeholder', 'User, label, public ID or service')); ?>">
			</label>
			<label class="keyharbor-field keyharbor-field-compact">
				<span><?php echo $e($t('status', 'Status')); ?></span>
				<select data-filter="status">
					<option value=""><?php echo $e($t('status_all', 'All')); ?></option>
					<option value="active"><?php echo $e($t('status_active', 'Active')); ?></option>
					<option value="expired"><?php echo $e($t('status_expired', 'Expired')); ?></option>
					<option value="revoked"><?php echo $e($t('status_revoked', 'Revoked')); ?></option>
				</select>
			</label>
		</div>

		<div class="keyharbor-alert" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading><?php echo $e($t('loading', 'Loading credentials…')); ?></div>
		<div class="keyharbor-table-wrap" data-table-wrap hidden>
			<table class="keyharbor-table">
				<thead>
					<tr>
						<th><?php echo $e($t('column_credential', 'Credential')); ?></th>
						<th><?php echo $e($t('column_owner', 'Owner')); ?></th>
						<th><?php echo $e($t('column_status', 'Status')); ?></th>
						<th><?php echo $e($t('column_services', 'Services')); ?></th>
						<th><?php echo $e($t('column_expiration', 'Expiration')); ?></th>
						<th class="keyharbor-table-actions"><?php echo $e($t('column_action', 'Action')); ?></th>
					</tr>
				</thead>
				<tbody data-table-body></tbody>
			</table>
		</div>
		<div class="keyharbor-empty" data-empty hidden>
			<h2><?php echo $e($t('empty_title', 'No matching credentials')); ?></h2>
			<p><?php echo $e($t('empty_text', 'Change the filters or create a credential from the user view.')); ?></p>
		</div>
	<?php endif; ?>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo $e($scriptUrl); ?>" defer></script>
<?php endif; ?>
