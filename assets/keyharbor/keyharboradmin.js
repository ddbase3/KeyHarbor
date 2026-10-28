(function() {
	'use strict';

	async function init() {
		const root = document.querySelector('[data-keyharbor-admin]');
		if(!root) { return; }

		const serviceUrl = root.dataset.serviceUrl || '';
		const strings = parseJson(root.dataset.strings, {});
		const loading = root.querySelector('[data-loading]');
		const notice = root.querySelector('[data-notice]');
		const summary = root.querySelector('[data-summary]');
		const tableWrap = root.querySelector('[data-table-wrap]');
		const tableBody = root.querySelector('[data-table-body]');
		const empty = root.querySelector('[data-empty]');
		const searchInput = root.querySelector('[data-filter="search"]');
		const statusSelect = root.querySelector('[data-filter="status"]');
		const state = { credentials: [], services: [] };
		const serviceEditorContent = root.querySelector('[data-service-editor-content]');
		const serviceTokenContent = root.querySelector('[data-service-token-content]');
		const serviceEditorError = serviceEditorContent.querySelector('[data-service-editor-error]');
		const serviceOperations = serviceEditorContent.querySelector('[data-service-operations]');
		const resourceConstraintsInput = serviceEditorContent.querySelector('[data-service-field="resource_constraints"]');
		const tokenValue = serviceTokenContent.querySelector('[data-service-token-value]');
		const copyStatus = serviceTokenContent.querySelector('[data-service-copy-status]');
		const fields = {
			id: serviceEditorContent.querySelector('[data-service-field="credential_id"]'),
			subject: serviceEditorContent.querySelector('[data-service-field="subject_id"]'),
			label: serviceEditorContent.querySelector('[data-service-field="label"]'),
			address: serviceEditorContent.querySelector('[data-service-field="notification_address"]'),
			language: serviceEditorContent.querySelector('[data-service-field="notification_language"]'),
			expiresEnabled: serviceEditorContent.querySelector('[data-service-field="expires_enabled"]'),
			expiresAt: serviceEditorContent.querySelector('[data-service-field="expires_at"]')
		};

		const dialogModule = await import(new URL(root.dataset.dialogUrl, document.baseURI).href);
		serviceEditorContent.hidden = false;
		serviceTokenContent.hidden = false;
		const serviceEditor = dialogModule.createStandardDialog({
			id: 'keyharbor-service-editor',
			className: 'keyharbor-app',
			size: 'large',
			title: t('create_service'),
			content: serviceEditorContent,
			strings: { close: t('close'), closeDialog: t('close') },
			closeButtonPlugin: { label: t('close'), ariaLabel: t('close') },
			statusPlugin: { renderEmpty: false },
			buttons: [
				{ key: 'cancel', label: t('cancel'), action: 'close' },
				{ key: 'save', label: t('save_service'), primary: true, async action() { await saveServiceEditor(); } }
			]
		});
		const tokenDialog = dialogModule.createStandardDialog({
			id: 'keyharbor-service-token',
			className: 'keyharbor-app',
			size: 'medium',
			title: t('service_token_title'),
			content: serviceTokenContent,
			strings: { close: t('close'), closeDialog: t('close') },
			closeButtonPlugin: { enabled: false },
			statusPlugin: { renderEmpty: false },
			buttons: [
				{ key: 'copy', label: t('copy_service_token'), action() { copyServiceToken(); } },
				{ key: 'close', label: t('close_service_token'), primary: true, action: 'close' }
			]
		});
		serviceEditor.init();
		tokenDialog.init();
		tokenDialog.on('afterClose', () => {
			tokenValue.textContent = '';
			copyStatus.textContent = '';
		});

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
			catch(error) { throw new Error(t('invalid_response')); }
			if(!response.ok || !data || data.ok !== true) {
				throw new Error(responseError(data));
			}
			return data;
		}

		async function load(showMessage) {
			setLoading(true);
			try {
				const response = await postJson({ mode: 'list' });
				state.credentials = Array.isArray(response.credentials) ? response.credentials : [];
				state.services = Array.isArray(response.services) ? response.services : [];
				renderSummary();
				renderTable();
				if(showMessage) { setNotice(t('refreshed'), 'success'); }
			}
			catch(error) {
				setNotice(errorMessage(error), 'error');
				state.credentials = [];
				renderSummary();
				renderTable();
			}
			finally { setLoading(false); }
		}

		function renderSummary() {
			summary.textContent = '';
			const counts = { total: state.credentials.length, active: 0, expired: 0, revoked: 0 };
			state.credentials.forEach((credential) => {
				if(counts[credential.status] !== undefined) { counts[credential.status]++; }
			});
			[
				[t('summary_total'), counts.total],
				[t('status_active'), counts.active],
				[t('status_expired'), counts.expired],
				[t('status_revoked'), counts.revoked]
			].forEach(([label, value]) => {
				const card = element('div', 'keyharbor-summary-card');
				card.appendChild(textElement('strong', value));
				card.appendChild(textElement('span', label));
				summary.appendChild(card);
			});
		}

		function renderTable() {
			tableBody.textContent = '';
			const search = String(searchInput.value || '').trim().toLowerCase();
			const status = String(statusSelect.value || '');
			const rows = state.credentials.filter((credential) => {
				if(status && credential.status !== status) { return false; }
				if(!search) { return true; }
				const haystack = [
					credential.label,
					credential.public_id,
					credential.owner_user_id,
					credential.owner_login,
					credential.owner_name,
					credential.notification_address,
					credential.subject_type,
					credential.subject_id,
					...(Array.isArray(credential.service_ids) ? credential.service_ids : [])
				].join(' ').toLowerCase();
				return haystack.includes(search);
			});

			rows.forEach((credential) => tableBody.appendChild(createRow(credential)));
			const hasRows = rows.length > 0;
			tableWrap.hidden = !hasRows;
			empty.hidden = hasRows;
		}

		function createRow(credential) {
			const row = document.createElement('tr');
			const credentialCell = document.createElement('td');
			credentialCell.appendChild(textElement('strong', credential.label || t('unnamed_credential')));
			credentialCell.appendChild(document.createElement('br'));
			credentialCell.appendChild(textElement('code', credential.public_id || ''));
			if(credential.subject_type === 'service') {
				credentialCell.appendChild(document.createElement('br'));
				credentialCell.appendChild(textElement('small', t('service_kind') + ': ' + String(credential.subject_id || '')));
			}
			row.appendChild(credentialCell);

			const ownerCell = document.createElement('td');
			const owner = element('div', 'keyharbor-owner');
			owner.appendChild(textElement('strong', credential.owner_name || credential.owner_login || credential.owner_user_id || t('unknown')));
			owner.appendChild(textElement('small', (credential.owner_login || '') + ' · ID ' + (credential.owner_user_id || '')));
			owner.appendChild(textElement('small', credential.notification_address || t('no_notification_address')));
			ownerCell.appendChild(owner);
			row.appendChild(ownerCell);

			const statusCell = document.createElement('td');
			statusCell.appendChild(statusBadge(credential.status));
			row.appendChild(statusCell);

			const servicesCell = document.createElement('td');
			const chips = element('div', 'keyharbor-service-chips');
			(Array.isArray(credential.service_ids) ? credential.service_ids : []).forEach((serviceId) => {
				const service = serviceById(serviceId);
				const chip = textElement('span', service ? service.label : serviceId, 'keyharbor-chip');
				if(!service) { chip.classList.add('keyharbor-chip-unavailable'); }
				chips.appendChild(chip);
			});
			servicesCell.appendChild(chips);
			row.appendChild(servicesCell);

			row.appendChild(textElement('td', credential.expires_at ? formatDate(credential.expires_at) : t('permanent')));

			const actionCell = element('td', 'keyharbor-table-actions');
			const revoked = credential.status === 'revoked';
			if(credential.subject_type === 'service' && !revoked) {
				const edit = textElement('button', t('edit_service'), 'keyharbor-button');
				edit.type = 'button';
				edit.dataset.action = 'edit-service';
				edit.dataset.credentialId = credential.id || '';
				actionCell.appendChild(edit);
				if(credential.status === 'active') {
					const rotate = textElement('button', t('rotate_service'), 'keyharbor-button');
					rotate.type = 'button';
					rotate.dataset.action = 'rotate-service';
					rotate.dataset.credentialId = credential.id || '';
					actionCell.appendChild(rotate);
				}
			}
			const button = textElement('button', revoked ? t('delete') : t('revoke'), 'keyharbor-button');
			button.classList.add('keyharbor-button-danger');
			button.type = 'button';
			button.dataset.action = revoked ? 'delete' : 'revoke';
			button.dataset.credentialId = credential.id || '';
			actionCell.appendChild(button);
			row.appendChild(actionCell);
			return row;
		}

		async function revoke(credentialId) {
			const credential = state.credentials.find((item) => String(item.id || '') === String(credentialId));
			if(!credential || !window.confirm(formatText(
				t('confirm_revoke'),
				{
					credential: credential.label || t('credential_fallback'),
					owner: credential.owner_name || credential.owner_login || t('user_fallback')
				}
			))) { return; }
			try {
				setNotice(t('revoking'), 'info');
				await postJson({ mode: 'revoke', credential_id: credentialId });
				await load(false);
				setNotice(t('revoked_success'), 'success');
			}
			catch(error) { setNotice(errorMessage(error), 'error'); }
		}

		async function deleteCredential(credentialId) {
			const credential = state.credentials.find((item) => String(item.id || '') === String(credentialId));
			if(!credential || credential.status !== 'revoked' || !window.confirm(formatText(
				t('confirm_delete'),
				{
					credential: credential.label || t('credential_fallback'),
					owner: credential.owner_name || credential.owner_login || t('user_fallback')
				}
			))) { return; }
			try {
				setNotice(t('deleting'), 'info');
				await postJson({ mode: 'delete', credential_id: credentialId });
				state.credentials = state.credentials.filter((item) => String(item.id || '') !== String(credentialId));
				renderSummary();
				renderTable();
				setNotice(t('deleted_success'), 'success');
			}
			catch(error) {
				await load(false);
				setNotice(errorMessage(error), 'error');
			}
		}

		function openServiceEditor(credential) {
			const existing = credential && credential.subject_type === 'service';
			serviceEditorError.hidden = true;
			serviceEditorError.textContent = '';
			fields.id.value = existing ? String(credential.id) : '';
			fields.subject.value = existing ? String(credential.subject_id) : '';
			fields.subject.disabled = existing;
			fields.label.value = existing ? String(credential.label || '') : '';
			fields.address.value = existing ? String(credential.notification_address || '') : '';
			fields.language.value = existing ? String(credential.notification_language || '') : 'en';
			fields.expiresEnabled.checked = existing && credential.expires_at !== null;
			fields.expiresAt.disabled = !fields.expiresEnabled.checked;
			fields.expiresAt.value = fields.expiresEnabled.checked ? toLocalDateTime(credential.expires_at) : '';
			serviceEditor.execute('setTitle', existing ? t('edit_service_title') : t('create_service'));
			renderOperationOptions(existing ? credential.operation_grants : {});
			const storedConstraints = existing ? credential.resource_constraints : null;
			resourceConstraintsInput.value = JSON.stringify(storedConstraints && !Array.isArray(storedConstraints) && typeof storedConstraints === 'object' ? storedConstraints : {}, null, 2);
			serviceEditor.open({ source: 'serviceCredentialEditor' });
			fields.label.focus();
		}

		function renderOperationOptions(grants) {
			serviceOperations.textContent = '';
			let count = 0;
			state.services.forEach((service) => {
				const operations = Array.isArray(service.operations) ? service.operations : [];
				if(!operations.length) { return; }
				const id = String(service.service_id || '');
				if(!id) { return; }
				const fieldset = element('fieldset', 'keyharbor-service-fieldset');
				fieldset.appendChild(textElement('legend', service.label || id));
				if(service.description) { fieldset.appendChild(textElement('small', service.description)); }
				operations.forEach((operation) => {
					const option = element('label', 'keyharbor-service-option');
					const checkbox = document.createElement('input');
					checkbox.type = 'checkbox';
					checkbox.dataset.operationGrant = '1';
					checkbox.dataset.serviceId = id;
					checkbox.value = String(operation);
					checkbox.checked = Array.isArray(grants && grants[id]) && grants[id].includes(operation);
					option.appendChild(checkbox);
					option.appendChild(textElement('span', String(operation)));
					fieldset.appendChild(option);
					count++;
				});
				serviceOperations.appendChild(fieldset);
			});
			if(!count) {
				serviceOperations.appendChild(textElement('p', t('service_no_operations')));
			}
		}

		async function saveServiceEditor() {
			serviceEditorError.hidden = true;
			serviceEditorError.textContent = '';
			if(!fields.label.value.trim() || !fields.subject.value.trim()) {
				showEditorError(t('service_required_fields'));
				return;
			}
			const grants = {};
			serviceOperations.querySelectorAll('[data-operation-grant]:checked').forEach((checkbox) => {
				const id = checkbox.dataset.serviceId;
				if(!grants[id]) { grants[id] = []; }
				grants[id].push(checkbox.value);
			});
			if(!Object.keys(grants).length) {
				showEditorError(t('service_select_operations'));
				return;
			}
			const expiresAt = fields.expiresEnabled.checked ? Math.floor(new Date(fields.expiresAt.value).getTime() / 1000) : null;
			if(fields.expiresEnabled.checked && (!Number.isFinite(expiresAt) || expiresAt <= 0 || !fields.address.value.trim())) {
				showEditorError(t('service_invalid_expiration'));
				return;
			}
			let resourceConstraints;
			try {
				resourceConstraints = JSON.parse(resourceConstraintsInput.value.trim() || '{}');
				if(!resourceConstraints || Array.isArray(resourceConstraints) || typeof resourceConstraints !== 'object') { throw new Error(); }
			}
			catch(error) { showEditorError(t('resource_constraints_invalid')); return; }
			const creating = !fields.id.value;
			const payload = {
				mode: creating ? 'create_service' : 'update_service',
				credential_id: fields.id.value,
				subject_id: fields.subject.value.trim(),
				label: fields.label.value.trim(),
				notification_address: fields.address.value.trim(),
				notification_language: fields.language.value.trim(),
				expires_at: expiresAt,
				operation_grants: grants,
				resource_constraints: resourceConstraints
			};
			try {
				const response = await postJson(payload);
				serviceEditor.close({ source: 'serviceCredentialEditor' });
				await load(false);
				if(response.token) { showServiceToken(response.token); }
				setNotice(creating ? t('service_created') : t('service_updated'), 'success');
			}
			catch(error) { showEditorError(errorMessage(error)); }
		}

		function showEditorError(message) {
			serviceEditorError.textContent = message;
			serviceEditorError.hidden = false;
		}

		async function rotateService(credentialId) {
			const credential = state.credentials.find((entry) => entry.id === credentialId && entry.subject_type === 'service');
			if(!credential || credential.status !== 'active') { return; }
			if(!window.confirm(formatText(t('service_confirm_rotate'), { credential: credential.label }))) { return; }
			try {
				const response = await postJson({ mode: 'rotate_service', credential_id: credentialId });
				await load(false);
				showServiceToken(response.token);
				setNotice(t('service_rotated'), 'success');
			}
			catch(error) { setNotice(errorMessage(error), 'error'); }
		}

		function showServiceToken(token) {
			tokenValue.textContent = String(token);
			copyStatus.textContent = '';
			tokenDialog.open({ source: 'serviceCredentialToken' });
			tokenValue.focus();
		}

		function copyServiceToken() {
			if(navigator.clipboard && tokenValue.textContent) {
				navigator.clipboard.writeText(tokenValue.textContent).then(
					() => { copyStatus.textContent = t('service_token_copied'); },
					() => { copyStatus.textContent = t('service_token_copy_failed'); }
				);
			} else { copyStatus.textContent = t('service_token_copy_failed'); }
		}

		function toLocalDateTime(timestamp) {
			const date = new Date(Number(timestamp) * 1000);
			const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
			return local.toISOString().slice(0, 16);
		}

		fields.expiresEnabled.addEventListener('change', () => {
			fields.expiresAt.disabled = !fields.expiresEnabled.checked;
			if(!fields.expiresEnabled.checked) { fields.expiresAt.value = ''; }
		});


		function serviceById(serviceId) {
			return state.services.find((service) => String(service.service_id || '') === String(serviceId)) || null;
		}

		function statusBadge(status) {
			const normalized = ['active', 'expired', 'revoked'].includes(status) ? status : 'active';
			const labels = {
				active: t('status_active'),
				expired: t('status_expired'),
				revoked: t('status_revoked')
			};
			const badge = textElement('span', labels[normalized], 'keyharbor-badge');
			badge.classList.add('keyharbor-badge-' + normalized);
			return badge;
		}

		function formatDate(timestamp) {
			return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(Number(timestamp) * 1000));
		}

		function setLoading(active) {
			loading.hidden = !active;
			if(active) { tableWrap.hidden = true; empty.hidden = true; }
		}

		function setNotice(message, type) {
			notice.hidden = !message;
			notice.textContent = message || '';
			notice.className = 'keyharbor-alert';
			if(type === 'error') { notice.classList.add('keyharbor-alert-error'); }
			if(type === 'success') { notice.classList.add('keyharbor-alert-success'); }
			if(type === 'warning') { notice.classList.add('keyharbor-alert-warning'); }
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

		function t(key) {
			const value = strings && typeof strings[key] === 'string' ? strings[key].trim() : '';
			if(!value) { throw new Error('Missing KeyHarbor translation: ' + key); }
			return value;
		}

		function formatText(text, values) {
			return Object.keys(values || {}).reduce((result, key) => {
				return result.split('{' + key + '}').join(String(values[key] ?? ''));
			}, String(text || ''));
		}

		function responseError(data) {
			const code = data && data.error_code ? String(data.error_code) : '';
			if(code === 'internal_error') {
				return formatText(t('error_internal'), { id: data && data.error_id ? data.error_id : '-' });
			}
			const localized = code ? t('error_' + code, '') : '';
			return localized || t('request_failed');
		}

		function errorMessage(error) {
			return error && error.message ? String(error.message) : String(error || t('unknown_error'));
		}

		root.addEventListener('click', (event) => {
			const button = event.target.closest('[data-action]');
			if(!button || !root.contains(button)) { return; }
			if(button.dataset.action === 'refresh') { load(true); }
			if(button.dataset.action === 'create-service') { openServiceEditor(null); }
			if(button.dataset.action === 'edit-service') {
				const credential = state.credentials.find((item) => item.id === button.dataset.credentialId);
				if(credential && credential.subject_type === 'service') { openServiceEditor(credential); }
			}
			if(button.dataset.action === 'rotate-service') { rotateService(button.dataset.credentialId || ''); }

			if(button.dataset.action === 'revoke') { revoke(button.dataset.credentialId || ''); }
			if(button.dataset.action === 'delete') { deleteCredential(button.dataset.credentialId || ''); }
		});
		searchInput.addEventListener('input', renderTable);
		statusSelect.addEventListener('change', renderTable);
		load(false);
	}

	if(document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
})();
