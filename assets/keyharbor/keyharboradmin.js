(function() {
	'use strict';

	function init() {
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
			catch(error) { throw new Error(t('invalid_response', 'KeyHarbor returned an invalid response.')); }
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
				if(showMessage) { setNotice(t('refreshed', 'Credential list refreshed.'), 'success'); }
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
				[t('summary_total', 'Total'), counts.total],
				[t('status_active', 'Active'), counts.active],
				[t('status_expired', 'Expired'), counts.expired],
				[t('status_revoked', 'Revoked'), counts.revoked]
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
			credentialCell.appendChild(textElement('strong', credential.label || t('unnamed_credential', 'Unnamed credential')));
			credentialCell.appendChild(document.createElement('br'));
			credentialCell.appendChild(textElement('code', credential.public_id || ''));
			row.appendChild(credentialCell);

			const ownerCell = document.createElement('td');
			const owner = element('div', 'keyharbor-owner');
			owner.appendChild(textElement('strong', credential.owner_name || credential.owner_login || credential.owner_user_id || t('unknown', 'Unknown')));
			owner.appendChild(textElement('small', (credential.owner_login || '') + ' · ID ' + (credential.owner_user_id || '')));
			owner.appendChild(textElement('small', credential.notification_address || t('no_notification_address', 'No notification address')));
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

			row.appendChild(textElement('td', credential.expires_at ? formatDate(credential.expires_at) : t('permanent', 'Permanent')));

			const actionCell = element('td', 'keyharbor-table-actions');
			const revoked = credential.status === 'revoked';
			const button = textElement('button', revoked ? t('delete', 'Delete') : t('revoke', 'Revoke'), 'keyharbor-button');
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
				t('confirm_revoke', 'Revoke \"{credential}\" for {owner}?'),
				{
					credential: credential.label || t('credential_fallback', 'credential'),
					owner: credential.owner_name || credential.owner_login || t('user_fallback', 'this user')
				}
			))) { return; }
			try {
				setNotice(t('revoking', 'Revoking credential…'), 'info');
				await postJson({ mode: 'revoke', credential_id: credentialId });
				await load(false);
				setNotice(t('revoked_success', 'Credential revoked.'), 'success');
			}
			catch(error) { setNotice(errorMessage(error), 'error'); }
		}

		async function deleteCredential(credentialId) {
			const credential = state.credentials.find((item) => String(item.id || '') === String(credentialId));
			if(!credential || credential.status !== 'revoked' || !window.confirm(formatText(
				t('confirm_delete', 'Permanently delete \"{credential}\" for {owner}? This removes the credential and its grants.'),
				{
					credential: credential.label || t('credential_fallback', 'credential'),
					owner: credential.owner_name || credential.owner_login || t('user_fallback', 'this user')
				}
			))) { return; }
			try {
				setNotice(t('deleting', 'Deleting credential…'), 'info');
				await postJson({ mode: 'delete', credential_id: credentialId });
				state.credentials = state.credentials.filter((item) => String(item.id || '') !== String(credentialId));
				renderSummary();
				renderTable();
				setNotice(t('deleted_success', 'Credential deleted.'), 'success');
			}
			catch(error) {
				await load(false);
				setNotice(errorMessage(error), 'error');
			}
		}

		function serviceById(serviceId) {
			return state.services.find((service) => String(service.service_id || '') === String(serviceId)) || null;
		}

		function statusBadge(status) {
			const normalized = ['active', 'expired', 'revoked'].includes(status) ? status : 'active';
			const labels = {
				active: t('status_active', 'Active'),
				expired: t('status_expired', 'Expired'),
				revoked: t('status_revoked', 'Revoked')
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

		function t(key, fallback) {
			const value = strings && typeof strings[key] === 'string' ? strings[key].trim() : '';
			return value || fallback;
		}

		function formatText(text, values) {
			return Object.keys(values || {}).reduce((result, key) => {
				return result.split('{' + key + '}').join(String(values[key] ?? ''));
			}, String(text || ''));
		}

		function responseError(data) {
			const code = data && data.error_code ? String(data.error_code) : '';
			if(code === 'internal_error') {
				return formatText(t('error_internal', 'The request failed. Reference: {id}'), { id: data && data.error_id ? data.error_id : '-' });
			}
			const localized = code ? t('error_' + code, '') : '';
			return localized || t('request_failed', 'KeyHarbor admin request failed.');
		}

		function errorMessage(error) {
			return error && error.message ? String(error.message) : String(error || t('unknown_error', 'Unknown error'));
		}

		root.addEventListener('click', (event) => {
			const button = event.target.closest('[data-action]');
			if(!button || !root.contains(button)) { return; }
			if(button.dataset.action === 'refresh') { load(true); }
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
