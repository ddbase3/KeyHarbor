(function() {
	'use strict';

	function init() {
		const root = document.querySelector('[data-keyharbor-encryption]');
		if(!root || root.dataset.initialized === 'true') { return; }
		root.dataset.initialized = 'true';
		const strings = JSON.parse(root.dataset.strings || '{}');
		const serviceUrl = root.dataset.serviceUrl || '';
		const state = { profiles: [], methods: [], targets: [], assignments: {}, editing: null };
		const editor = root.querySelector('[data-editor]');
		const keysList = root.querySelector('[data-key-list]');
		const fields = {
			id: root.querySelector('[data-field="id"]'),
			label: root.querySelector('[data-field="label"]'),
			method: root.querySelector('[data-field="method"]'),
			keyId: root.querySelector('[data-field="key_id"]')
		};

		function t(name) {
			if(typeof strings[name] !== 'string' || !strings[name]) {
				throw new Error('Missing KeyHarbor translation: ' + name);
			}
			return strings[name];
		}
		function node(tag, className, content) {
			const result = document.createElement(tag);
			if(className) { result.className = className; }
			if(content !== undefined) { result.textContent = String(content); }
			return result;
		}
		function button(label, action, id) {
			const result = node('button', 'keyharbor-button', label);
			result.type = 'button';
			result.dataset.action = action;
			if(id !== undefined) { result.dataset.id = id; }
			return result;
		}
		function option(value, label) {
			const result = node('option', '', label);
			result.value = value;
			return result;
		}
		function field(label, name, value, isSecret) {
			const wrapper = node('label', 'keyharbor-field');
			wrapper.appendChild(node('span', '', label));
			const input = node('input');
			input.dataset.prop = name;
			input.type = isSecret ? 'password' : 'text';
			input.autocomplete = 'off';
			input.value = value || '';
			if(isSecret) { input.placeholder = t('secret_placeholder'); }
			wrapper.appendChild(input);
			return wrapper;
		}
		function notice(message, error) {
			const editorNotice = editor.querySelector('[data-editor-notice]');
			const pageNotice = root.querySelector('[data-notice]');
			const target = editor.open ? editorNotice : pageNotice;
			const other = editor.open ? pageNotice : editorNotice;
			other.hidden = true;
			other.textContent = '';
			target.hidden = !message;
			target.textContent = message || '';
			target.className = 'keyharbor-alert ' + (error ? 'keyharbor-alert-error' : 'keyharbor-alert-success');
		}
		function errorText(response) {
			if(response.error_code === 'internal_error') {
				return t('error_internal') + ' ' + (response.error_id || '');
			}
			const code = response.error_code || 'unavailable';
			return ['validation', 'unavailable', 'not_authenticated', 'access_denied', 'hmac_configuration'].includes(code)
				? t('error_' + code)
				: t('error_unavailable');
		}
		async function request(payload) {
			const response = await fetch(serviceUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify(payload)
			});
			let result;
			try { result = await response.json(); }
			catch(error) { throw new Error(t('error_invalid_response')); }
			if(!response.ok || !result || result.ok !== true) {
				throw new Error(errorText(result || {}));
			}
			return result;
		}
		async function load() {
			root.querySelector('[data-loading]').hidden = false;
			try {
				const response = await request({ mode: 'list' });
				Object.assign(state, response);
				render();
			}
			catch(error) { notice(error.message, true); }
			finally { root.querySelector('[data-loading]').hidden = true; }
		}
		function render() {
			const tbody = root.querySelector('[data-profiles-body]');
			tbody.textContent = '';
			state.profiles.forEach((profile) => {
				const row = node('tr');
				row.appendChild(node('td', '', profile.id));
				row.appendChild(node('td', '', profile.label));
				row.appendChild(node('td', '', profile.method));
				row.appendChild(node('td', '', profile.key_id));
				row.appendChild(node('td', '', profile.keys.length));
				const actions = node('td', 'keyharbor-card-actions');
				const edit = button(t('edit'), 'edit', profile.id);
				edit.setAttribute('aria-label', t('edit') + ': ' + profile.label);
				actions.appendChild(edit);
				const active = profile.keys.find((key) => key.id === profile.key_id);
				if(active && active.mode === 'fixed') {
					const rotate = button(t('rotate'), 'rotate', profile.id);
					rotate.setAttribute('aria-label', t('rotate') + ': ' + profile.label);
					rotate.title = t('keys_help');
					actions.appendChild(rotate);
				}
				row.appendChild(actions);
				tbody.appendChild(row);
			});
			root.querySelector('[data-profiles-wrap]').hidden = state.profiles.length === 0;
			root.querySelector('[data-empty]').hidden = state.profiles.length > 0;
			renderTargets();
		}
		function renderTargets() {
			const tbody = root.querySelector('[data-targets-body]');
			tbody.textContent = '';
			const all = [...state.targets];
			Object.keys(state.assignments).forEach((id) => {
				if(!all.some((target) => target.target_id === id)) {
					all.push({ target_id: id, label: id + ' (' + t('unavailable_target') + ')' });
				}
			});
			all.forEach((target) => {
				const row = node('tr');
				const desc = node('td');
				desc.appendChild(node('strong', '', target.label));
				desc.appendChild(node('small', 'keyharbor-encryption-id', target.target_id));
				row.appendChild(desc);
				const cell = node('td');
				const select = node('select');
				select.className = 'keyharbor-encryption-select';
				select.dataset.targetId = target.target_id;
				select.setAttribute('aria-label', t('profile') + ': ' + target.label);
				select.appendChild(option('', t('no_encryption')));
				state.profiles.forEach((profile) => select.appendChild(option(profile.id, profile.label + ' (' + profile.id + ')')));
				const selected = state.assignments[target.target_id] || '';
				if(selected && !state.profiles.some((profile) => profile.id === selected)) {
					select.appendChild(option(selected, selected + ' (' + t('missing_profile') + ')'));
				}
				select.value = selected;
				cell.appendChild(select);
				row.appendChild(cell);
				tbody.appendChild(row);
			});
			root.querySelector('[data-targets-wrap]').hidden = all.length === 0;
			root.querySelector('[data-targets-empty]').hidden = all.length !== 0;
		}
		function updateActiveKey() {
			const previous = fields.keyId.value;
			fields.keyId.textContent = '';
			keysList.querySelectorAll('[data-key-entry]').forEach((entry) => {
				const id = entry.querySelector('[data-prop="id"]').value.trim();
				if(id && !Array.from(fields.keyId.options).some((item) => item.value === id)) {
					fields.keyId.appendChild(option(id, id));
				}
			});
			if(Array.from(fields.keyId.options).some((item) => item.value === previous)) {
				fields.keyId.value = previous;
			}
		}
		function addKey(data, persisted) {
			const definition = data || { id: '', mode: 'env' };
			const locked = persisted === true;
			const block = node('div', 'keyharbor-encryption-key');
			block.dataset.keyEntry = 'true';
			if(locked) { block.dataset.persisted = 'true'; }
			const head = node('div', 'keyharbor-encryption-key-header');
			head.appendChild(field(t('key_id'), 'id', definition.id || '', false));
			const keyIdInput = head.querySelector('input');
			keyIdInput.readOnly = locked;
			if(!locked) { keyIdInput.addEventListener('input', updateActiveKey); }
			const source = node('label', 'keyharbor-field');
			source.appendChild(node('span', '', t('source')));
			const select = node('select');
			select.dataset.prop = 'mode';
			[['env','source_env'],['file','source_file'],['configuration','source_configuration'],['fixed','source_fixed']].forEach(([value, key]) => {
				select.appendChild(option(value, t(key)));
			});
			select.value = definition.mode || 'env';
			select.disabled = locked;
			source.appendChild(select);
			head.appendChild(source);
			if(!locked) {
				const discardKey = button(t('cancel'), 'discard-key');
				discardKey.setAttribute('aria-label', t('cancel') + ': ' + (definition.id || t('key_id')));
				head.appendChild(discardKey);
			}
			block.appendChild(head);
			const details = node('div', 'keyharbor-encryption-key-details');
			block.appendChild(details);
			function showSourceFields() {
				details.textContent = '';
				const mode = select.value;
				if(mode === 'env') { details.appendChild(field(t('env_name'), 'name', definition.name, false)); }
				if(mode === 'file') { details.appendChild(field(t('file_path'), 'path', definition.path, false)); }
				if(mode === 'configuration') {
					details.appendChild(field(t('config_group'), 'group', definition.group, false));
					details.appendChild(field(t('config_key'), 'key', definition.key, false));
				}
				if(mode === 'fixed') {
					details.appendChild(field(t('fixed_secret'), 'secret', '', true));
					details.appendChild(node('small', 'keyharbor-encryption-note', t('fixed_secret_help')));
				}
			}
			select.addEventListener('change', () => {
				definition.name = ''; definition.path = ''; definition.group = ''; definition.key = '';
				showSourceFields();
			});
			showSourceFields();
			keysList.appendChild(block);
			updateActiveKey();
		}
		function openEditor(profile) {
			notice('', false);
			state.editing = profile || null;
			root.querySelector('[data-editor-title]').textContent = profile ? t('edit_title') : t('create_title');
			fields.id.value = profile ? profile.id : '';
			fields.id.disabled = Boolean(profile);
			fields.label.value = profile ? profile.label : '';
			fields.method.textContent = '';
			fields.method.appendChild(option('', t('choose_method')));
			state.methods.forEach((method) => fields.method.appendChild(option(method, method)));
			fields.method.value = profile ? profile.method : '';
			fields.method.disabled = Boolean(profile);
			keysList.textContent = '';
			(profile ? profile.keys : []).forEach((key) => addKey(key, true));
			fields.keyId.value = profile ? profile.key_id : '';
			if(typeof editor.showModal === 'function') { editor.showModal(); }
			else { editor.setAttribute('open', 'open'); }
			fields.label.focus();
		}
		function closeEditor() {
			notice('', false);
			if(typeof editor.close === 'function') { editor.close(); }
			else { editor.removeAttribute('open'); }
			keysList.querySelectorAll('[data-prop="secret"]').forEach((input) => { input.value = ''; });
		}
		function collectKeys() {
			return Array.from(keysList.querySelectorAll('[data-key-entry]')).map((entry) => {
				const result = {};
				entry.querySelectorAll('[data-prop]').forEach((input) => {
					result[input.dataset.prop] = input.value.trim();
				});
				return result;
			});
		}
		async function saveProfile() {
			notice('', false);
			const keys = collectKeys();
			const profile = {
				id: fields.id.value.trim(), label: fields.label.value.trim(), method: fields.method.value,
				key_id: fields.keyId.value, keys
			};
			if(!profile.id || !profile.label || !profile.method || !profile.key_id || keys.length === 0) {
				notice(t('error_validation'), true); return;
			}
			try {
				await request({ mode: 'save', profile });
				closeEditor();
				await load();
				notice(t('saved'), false);
			} catch(error) { notice(error.message, true); }
		}
		async function rotateFixedKey(id) {
			if(!window.confirm(t('keys_help') + '\n' + t('rotate') + '?')) { return; }
			try {
				const response = await request({ mode: 'rotate', profile_id: id });
				await load();
				notice(t('saved') + ' ' + response.key_id, false);
			} catch(error) { notice(error.message, true); }
		}
		async function changeAssignment(select) {
			const targetId = select.dataset.targetId;
			const previous = state.assignments[targetId] || '';
			const selected = select.value;
			if(selected === previous) { return; }
			if((selected === '' || previous !== '') && !window.confirm(t('confirm_assignment'))) {
				select.value = previous; return;
			}
			select.disabled = true;
			try {
				await request({ mode: 'assign', target_id: targetId, profile_id: selected, confirm_change: previous !== '' });
				await load(); notice(t('assignment_saved'), false);
			} catch(error) {
				select.value = previous; notice(error.message, true);
			} finally { select.disabled = false; }
		}

		root.addEventListener('click', (event) => {
			const action = event.target.closest('[data-action]');
			if(!action || !root.contains(action)) { return; }
			const id = action.dataset.id || '';
			if(action.dataset.action === 'refresh') { load(); }
			if(action.dataset.action === 'create') { openEditor(null); }
			if(action.dataset.action === 'edit') { openEditor(state.profiles.find((p) => p.id === id) || null); }
			if(action.dataset.action === 'rotate') { rotateFixedKey(id); }
			if(action.dataset.action === 'add-key') { addKey({ mode: 'env' }, false); }
			if(action.dataset.action === 'discard-key') {
				const entry = action.closest('[data-key-entry]');
				if(entry && !entry.dataset.persisted) { entry.remove(); updateActiveKey(); }
			}
			if(action.dataset.action === 'save') { saveProfile(); }
			if(action.dataset.action === 'cancel') { closeEditor(); }
		});
		root.addEventListener('change', (event) => {
			if(event.target.matches('[data-target-id]')) { changeAssignment(event.target); }
		});
		editor.addEventListener('cancel', (event) => { event.preventDefault(); closeEditor(); });
		load();
	}

	if(document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
})();
