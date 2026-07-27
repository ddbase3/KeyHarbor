<?php
$error = (string)($this->_['error'] ?? '');
$serviceUrl = (string)($this->_['service'] ?? '');
$cssUrl = (string)($this->_['cssUrl'] ?? '');
$scriptUrl = (string)($this->_['scriptUrl'] ?? '');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>">

<section
	class="keyharbor-app"
	data-keyharbor-admin
	data-service-url="<?php echo htmlspecialchars($serviceUrl, ENT_QUOTES, 'UTF-8'); ?>"
>
	<header class="keyharbor-hero">
		<div>
			<p class="keyharbor-eyebrow">KeyHarbor administration</p>
			<h1>All API credentials</h1>
			<p class="keyharbor-lead">Review credential ownership, lifecycle and service grants. Secret material is never displayed.</p>
		</div>
		<div class="keyharbor-hero-actions">
			<button class="keyharbor-button keyharbor-button-quiet" type="button" data-action="refresh">Refresh</button>
		</div>
	</header>

	<?php if ($error !== ''): ?>
		<div class="keyharbor-alert keyharbor-alert-error" role="alert">
			<strong>Administration unavailable.</strong>
			<span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
	<?php else: ?>
		<div class="keyharbor-admin-summary" data-summary></div>
		<div class="keyharbor-admin-filters">
			<label class="keyharbor-field">
				<span>Search</span>
				<input type="search" data-filter="search" placeholder="User, label, public ID or service">
			</label>
			<label class="keyharbor-field keyharbor-field-compact">
				<span>Status</span>
				<select data-filter="status">
					<option value="">All</option>
					<option value="active">Active</option>
					<option value="expired">Expired</option>
					<option value="revoked">Revoked</option>
				</select>
			</label>
		</div>

		<div class="keyharbor-alert" data-notice hidden></div>
		<div class="keyharbor-loading" data-loading>Loading credentials…</div>
		<div class="keyharbor-table-wrap" data-table-wrap hidden>
			<table class="keyharbor-table">
				<thead>
					<tr>
						<th>Credential</th>
						<th>Owner</th>
						<th>Status</th>
						<th>Services</th>
						<th>Expiration</th>
						<th class="keyharbor-table-actions">Action</th>
					</tr>
				</thead>
				<tbody data-table-body></tbody>
			</table>
		</div>
		<div class="keyharbor-empty" data-empty hidden>
			<h2>No matching credentials</h2>
			<p>Change the filters or create a credential from the user view.</p>
		</div>
	<?php endif; ?>
</section>

<?php if ($error === ''): ?>
	<script src="<?php echo htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>
