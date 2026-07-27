(function() {
	'use strict';

	function init() {
		const root = document.querySelector('[data-keyharbor-user]');
		if(!root) { return; }

		const serviceUrl = root.dataset.serviceUrl || '';
		const profile = parseJson(root.dataset.profile, {});
		const loading = root.querySelector('[data-loading]');
		const grid = root.querySelector('[data-credential-grid]');
		const empty = root.querySelector('[data-empty]');
		const notice = root.querySelector('[data-notice]');
		const editorDialog = root.querySelector('[data-editor-dialog]');
		const editorTitle = root.querySelector('[data-editor-title]');
		const editorError = root.querySelector('[data-editor-error]');
		const serviceList = root.querySelector('[data-service-list]');
		const saveButton = root.querySelector('[data-save-button]');
		const tokenDialog = root.querySelector('[data-token-dialog]');
		const tokenValue = root.querySelector('[data-token-value]');
		const copyStatus = root.querySelector('[data-copy-status]');
		const tokenModeNote = root.querySelector('[data-token-mode-note]');
		const fields = {
			id: root.querySelector('[data-field="credential_id"]'),
			label: root.querySelector('[data-field="label"]'),
			authenticationMode: root.querySelector('[data-field="authentication_mode"]'),
			notificationAddress: root.querySelector('[data-field="notification_address"]'),
			notificationLanguage: root.querySelector('[data-field="notification_language"]'),
			expiresEnabled: root.querySelector('[data-field="expires_enabled"]'),
			expiresAt: root.querySelector('[data-field="expires_at"]')
		};
		const state = {
			credentials: [],
			services: [],
			profile
		};

		async function postJson(payload) {
			const response = await fetch(serviceUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: JSON.stringify(payload)
			});
			const text = await response.text();
			let data;
			try { data = JSON.parse(text); }
			catch(error) { throw new Error('Credential service returned an invalid response.'); }
			if(!response.ok || !data || data.ok !== true) {
				throw new Error(data && data.error ? String(data.error) : 'Credential request failed.');
			}
			return data;
		}

		async function loadCredentials(showMessage) {
			setLoading(true);
			try {
				const response = await postJson({ mode: 'list' });
				state.credentials = Array.isArray(response.credentials) ? response.credentials : [];
				state.services = Array.isArray(response.services) ? response.services : [];
				state.profile = response.profile && typeof response.profile === 'object' ? response.profile : state.profile;
				renderCredentials();
				if(showMessage) { setNotice('Credential list refreshed.', 'success'); }
			}
			catch(error) {
				setNotice(errorMessage(error), 'error');
				state.credentials = [];
				renderCredentials();
			}
			finally { setLoading(false); }
		}

		function renderCredentials() {
			grid.textContent = '';
			state.credentials.forEach((credential) => grid.appendChild(createCredentialCard(credential)));
			const hasCredentials = state.credentials.length > 0;
			grid.hidden = !hasCredentials;
			empty.hidden = hasCredentials;
			updateCreateButtons();
		}

		function createCredentialCard(credential) {
			const card = element('article', 'keyharbor-card');
			const header = element('div', 'keyharbor-card-header');
			const title = element('div', 'keyharbor-card-title');
			title.appendChild(textElement('h2', credential.label || 'Unnamed credential'));
			title.appendChild(textElement('code', credential.token_prefix || ('b3k_' + (credential.public_id || '') + '_')));
			header.appendChild(title);
			header.appendChild(statusBadge(credential.status));
			card.appendChild(header);

			const meta = element('dl', 'keyharbor-meta');
			appendMeta(meta, 'Created', formatDate(credential.created_at));
			appendMeta(meta, 'Expiration', credential.expires_at ? formatDate(credential.expires_at) : 'Permanent');
			appendMeta(meta, 'Mode', credential.auth_mode === 'hmac' ? 'HMAC' : 'Bearer');
			appendMeta(meta, 'Notifications', credential.notification_address || 'Not configured');
			card.appendChild(meta);

			const chips = element('div', 'keyharbor-service-chips');
			(Array.isArray(credential.service_ids) ? credential.service_ids : []).forEach((serviceId) => {
				const service = serviceById(serviceId);
				const chip = textElement('span', service ? service.label : serviceId, 'keyharbor-chip');
				if(!service) {
					chip.classList.add('keyharbor-chip-unavailable');
					chip.title = 'This service is not currently available.';
				}
				chips.appendChild(chip);
			});
			card.appendChild(chips);

			const actions = element('div', 'keyharbor-card-actions');
			const isRevoked = credential.status === 'revoked';
			const isExpired = credential.status === 'expired';
			actions.appendChild(actionButton('Edit', 'edit', credential.id, 'keyharbor-button-secondary', isRevoked));
			const rotateButton = actionButton('Rotate', 'rotate', credential.id, 'keyharbor-button-secondary', isRevoked || isExpired);
			if(isExpired) { rotateButton.title = 'Extend the expiration before rotating this credential.'; }
			actions.appendChild(rotateButton);
			if(isRevoked) {
				actions.appendChild(actionButton('Delete', 'delete', credential.id, 'keyharbor-button-danger', false));
			}
			else {
				actions.appendChild(actionButton('Revoke', 'revoke', credential.id, 'keyharbor-button-danger', false));
			}
			card.appendChild(actions);
			return card;
		}

		function openEditor(credential) {
			clearEditorError();
			const existing = credential && typeof credential === 'object';
			fields.id.value = existing ? String(credential.id || '') : '';
			fields.label.value = existing ? String(credential.label || '') : '';
			fields.authenticationMode.value = existing ? String(credential.auth_mode || 'bearer') : 'bearer';
			fields.authenticationMode.disabled = existing;
			fields.notificationAddress.value = existing
				? String(credential.notification_address || '')
				: String(state.profile.email || '');
			fields.notificationLanguage.value = existing
				? String(credential.notification_language || '')
				: String(state.profile.language || 'en');
			fields.expiresEnabled.checked = existing && credential.expires_at !== null && credential.expires_at !== undefined;
			fields.expiresAt.disabled = !fields.expiresEnabled.checked;
			fields.expiresAt.required = fields.expiresEnabled.checked;
			fields.expiresAt.value = fields.expiresEnabled.checked ? toLocalDateTime(Number(credential.expires_at)) : '';
			fields.notificationAddress.required = fields.expiresEnabled.checked;
			editorTitle.textContent = existing ? 'Edit credential' : 'Create credential';
			saveButton.textContent = existing ? 'Save changes' : 'Create credential';
			renderServiceOptions(existing ? credential : null);
			openDialog(editorDialog);
			window.setTimeout(() => fields.label.focus(), 0);
		}

		function renderServiceOptions(credential) {
			serviceList.textContent = '';
			const selected = new Set(
				credential && Array.isArray(credential.service_ids) ? credential.service_ids.map(String) : []
			);
			const rendered = new Set();

			state.services.forEach((service) => {
				const serviceId = String(service.service_id || '');
				if(!serviceId) { return; }
				serviceList.appendChild(serviceOption(serviceId, String(service.label || serviceId), String(service.description || ''), selected.has(serviceId), true));
				rendered.add(serviceId);
			});

			selected.forEach((serviceId) => {
				if(rendered.has(serviceId)) { return; }
				serviceList.appendChild(serviceOption(serviceId, serviceId, 'Service is not currently available. Uncheck it to remove the grant.', true, false));
			});

			if(serviceList.children.length === 0) {
				serviceList.appendChild(textElement('p', 'No credential-protected services are currently available.'));
			}
		}

		function serviceOption(serviceId, label, description, checked, available) {
			const option = element('label', 'keyharbor-service-option');
			if(!available) { option.classList.add('keyharbor-service-option-unavailable'); }
			const checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.value = serviceId;
			checkbox.checked = checked;
			checkbox.dataset.serviceGrant = '1';
			option.appendChild(checkbox);
			option.appendChild(textElement('strong', label));
			option.appendChild(textElement('small', description || serviceId));
			return option;
		}

		async function saveEditor() {
			clearEditorError();
			if(!validateEditor()) { return; }
			const expiresAt = readExpiration();
			const payload = {
				mode: fields.id.value ? 'update' : 'create',
				credential_id: fields.id.value,
				label: fields.label.value,
				authentication_mode: fields.authenticationMode.value,
				notification_address: fields.notificationAddress.value,
				notification_language: fields.notificationLanguage.value,
				expires_at: expiresAt,
				service_ids: Array.from(serviceList.querySelectorAll('[data-service-grant]:checked')).map((input) => input.value)
			};
			setButtonBusy(saveButton, true, payload.mode === 'create' ? 'Creating…' : 'Saving…');
			try {
				const response = await postJson(payload);
				closeDialog(editorDialog);
				await loadCredentials(false);
				if(response.token) { showToken(response.token, response.credential ? response.credential.auth_mode : 'bearer'); }
				setNotice(payload.mode === 'create' ? 'Credential created.' : 'Credential updated.', 'success');
			}
			catch(error) { setEditorError(errorMessage(error)); }
			finally { setButtonBusy(saveButton, false); }
		}

		function validateEditor() {
			if(!fields.label.value.trim()) {
				setEditorError('Credential label is required.');
				fields.label.focus();
				return false;
			}
			if(fields.expiresEnabled.checked && !fields.expiresAt.value) {
				setEditorError('Select an expiration date.');
				fields.expiresAt.focus();
				return false;
			}
			if(fields.expiresEnabled.checked && !fields.notificationAddress.value.trim()) {
				setEditorError('An expiring credential requires a notification address.');
				fields.notificationAddress.focus();
				return false;
			}
			if(serviceList.querySelectorAll('[data-service-grant]:checked').length === 0) {
				setEditorError('Select at least one credential service.');
				return false;
			}
			return true;
		}

		function readExpiration() {
			if(!fields.expiresEnabled.checked) { return null; }
			if(!fields.expiresAt.value) { throw new Error('Select an expiration date.'); }
			const timestamp = Math.floor(new Date(fields.expiresAt.value).getTime() / 1000);
			if(!Number.isFinite(timestamp)) { throw new Error('Expiration date is invalid.'); }
			return timestamp;
		}

		async function rotateCredential(credentialId) {
			const credential = credentialById(credentialId);
			if(!credential || !window.confirm('Rotate "' + (credential.label || 'credential') + '"? The current token will stop working immediately.')) { return; }
			try {
				setNotice('Rotating credential…', 'info');
				const response = await postJson({ mode: 'rotate', credential_id: credentialId });
				await loadCredentials(false);
				showToken(response.token || '', response.credential ? response.credential.auth_mode : 'bearer');
				setNotice('Credential rotated. The previous token is invalid.', 'success');
			}
			catch(error) { setNotice(errorMessage(error), 'error'); }
		}

		async function revokeCredential(credentialId) {
			const credential = credentialById(credentialId);
			if(!credential || !window.confirm('Revoke "' + (credential.label || 'credential') + '"? This action cannot be undone.')) { return; }
			try {
				setNotice('Revoking credential…', 'info');
				await postJson({ mode: 'revoke', credential_id: credentialId });
				await loadCredentials(false);
				setNotice('Credential revoked.', 'success');
			}
			catch(error) { setNotice(errorMessage(error), 'error'); }
		}

		async function deleteCredential(credentialId) {
			const credential = credentialById(credentialId);
			if(!credential || credential.status !== 'revoked' || !window.confirm('Permanently delete "' + (credential.label || 'credential') + '"? This removes the credential and its grants and cannot be undone.')) { return; }
			try {
				setNotice('Deleting credential…', 'info');
				await postJson({ mode: 'delete', credential_id: credentialId });
				state.credentials = state.credentials.filter((item) => String(item.id || '') !== String(credentialId));
				renderCredentials();
				setNotice('Credential deleted.', 'success');
			}
			catch(error) {
				await loadCredentials(false);
				setNotice(errorMessage(error), 'error');
			}
		}

		function showToken(token, authenticationMode) {
			tokenValue.textContent = String(token || '');
			copyStatus.textContent = '';
			const hmac = authenticationMode === 'hmac';
			tokenModeNote.hidden = !hmac;
			tokenModeNote.textContent = hmac
				? 'Use this token with X-BASE3-Timestamp, X-BASE3-Nonce and X-BASE3-Signature. Sign the canonical request with the token secret using HMAC-SHA256.'
				: '';
			openDialog(tokenDialog);
			tokenValue.focus();
		}

		async function copyToken() {
			const token = tokenValue.textContent || '';
			if(!token) { return; }
			try {
				if(navigator.clipboard && window.isSecureContext) {
					await navigator.clipboard.writeText(token);
				}
				else {
					const area = document.createElement('textarea');
					area.value = token;
					area.style.position = 'fixed';
					area.style.opacity = '0';
					document.body.appendChild(area);
					area.select();
					document.execCommand('copy');
					area.remove();
				}
				copyStatus.textContent = 'Token copied.';
			}
			catch(error) { copyStatus.textContent = 'Copy failed. Select the token and copy it manually.'; }
		}

		function closeToken() {
			closeDialog(tokenDialog);
			tokenValue.textContent = '';
			copyStatus.textContent = '';
			tokenModeNote.textContent = '';
			tokenModeNote.hidden = true;
		}

		function toggleExpiration() {
			fields.expiresAt.disabled = !fields.expiresEnabled.checked;
			fields.expiresAt.required = fields.expiresEnabled.checked;
			fields.notificationAddress.required = fields.expiresEnabled.checked;
			if(fields.expiresEnabled.checked && !fields.expiresAt.value) {
				fields.expiresAt.value = toLocalDateTime(Math.floor(Date.now() / 1000) + 90 * 24 * 3600);
			}
		}

		function setLoading(active) {
			loading.hidden = !active;
			if(active) { grid.hidden = true; empty.hidden = true; }
			root.querySelectorAll('[data-action="create"]').forEach((button) => {
				button.disabled = active || state.services.length === 0;
			});
		}

		function updateCreateButtons() {
			root.querySelectorAll('[data-action="create"]').forEach((button) => {
				button.disabled = state.services.length === 0;
				button.title = state.services.length === 0 ? 'No credential services are currently available.' : '';
			});
		}

		function setNotice(message, type) {
			notice.hidden = !message;
			notice.textContent = message || '';
			notice.className = 'keyharbor-alert';
			if(type === 'error') { notice.classList.add('keyharbor-alert-error'); }
			if(type === 'success') { notice.classList.add('keyharbor-alert-success'); }
			if(type === 'warning') { notice.classList.add('keyharbor-alert-warning'); }
		}

		function setEditorError(message) {
			editorError.textContent = message;
			editorError.hidden = !message;
		}

		function clearEditorError() { setEditorError(''); }

		function serviceById(serviceId) {
			return state.services.find((service) => String(service.service_id || '') === String(serviceId)) || null;
		}

		function credentialById(credentialId) {
			return state.credentials.find((credential) => String(credential.id || '') === String(credentialId)) || null;
		}

		function actionButton(label, action, credentialId, className, disabled) {
			const button = textElement('button', label, 'keyharbor-button');
			button.type = 'button';
			button.classList.add(className);
			button.dataset.action = action;
			button.dataset.credentialId = credentialId;
			button.disabled = !!disabled;
			return button;
		}

		function statusBadge(status) {
			const normalized = ['active', 'expired', 'revoked'].includes(status) ? status : 'active';
			const badge = textElement('span', normalized, 'keyharbor-badge');
			badge.classList.add('keyharbor-badge-' + normalized);
			return badge;
		}

		function appendMeta(list, label, value) {
			const wrapper = document.createElement('div');
			wrapper.appendChild(textElement('dt', label));
			wrapper.appendChild(textElement('dd', value));
			list.appendChild(wrapper);
		}

		function formatDate(timestamp) {
			if(!timestamp) { return '—'; }
			return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(Number(timestamp) * 1000));
		}

		function toLocalDateTime(timestamp) {
			const date = new Date(Number(timestamp) * 1000);
			const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
			return local.toISOString().slice(0, 16);
		}

		function setButtonBusy(button, busy, label) {
			if(busy) {
				button.dataset.originalLabel = button.textContent;
				button.textContent = label || 'Working…';
				button.disabled = true;
			}
			else {
				button.textContent = button.dataset.originalLabel || button.textContent;
				button.disabled = false;
				delete button.dataset.originalLabel;
			}
		}

		function openDialog(dialog) {
			if(!dialog) { return; }
			if(typeof dialog.showModal === 'function') { dialog.showModal(); }
			else { dialog.setAttribute('open', 'open'); }
		}

		function closeDialog(dialog) {
			if(!dialog) { return; }
			if(typeof dialog.close === 'function') { dialog.close(); }
			else { dialog.removeAttribute('open'); }
		}

		function element(tag, className) {
			const node = document.createElement(tag);
			if(className) { node.className = className; }
			return node;
		}

		function textElement(tag, text, className) {
			const node = element(tag, className);
			node.textContent = text === null || text === undefined ? '' : String(text);
			return node;
		}

		function parseJson(value, fallback) {
			try { return value ? JSON.parse(value) : fallback; }
			catch(error) { return fallback; }
		}

		function errorMessage(error) {
			return error && error.message ? String(error.message) : String(error || 'Unknown error');
		}

		root.addEventListener('click', (event) => {
			const button = event.target.closest('[data-action]');
			if(!button || !root.contains(button)) { return; }
			const action = button.dataset.action || '';
			const credentialId = button.dataset.credentialId || '';
			if(action === 'create') { openEditor(null); }
			if(action === 'refresh') { loadCredentials(true); }
			if(action === 'edit') { openEditor(credentialById(credentialId)); }
			if(action === 'rotate') { rotateCredential(credentialId); }
			if(action === 'revoke') { revokeCredential(credentialId); }
			if(action === 'delete') { deleteCredential(credentialId); }
			if(action === 'save-editor') { saveEditor(); }
			if(action === 'close-editor') { closeDialog(editorDialog); }
			if(action === 'copy-token') { copyToken(); }
			if(action === 'close-token') { closeToken(); }
		});

		fields.expiresEnabled.addEventListener('change', toggleExpiration);
		tokenDialog.addEventListener('cancel', (event) => event.preventDefault());
		loadCredentials(false);
	}

	if(document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
})();
