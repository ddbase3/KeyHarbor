(function() {
	'use strict';

	async function init() {
		const root = document.querySelector('[data-keyharbor-encryption]');
		if(!root || root.dataset.initialized === 'true') { return; }
		root.dataset.initialized = 'true';
		const strings = JSON.parse(root.dataset.strings || '{}');
		const serviceUrl = root.dataset.serviceUrl || '';
		const state = { profiles: [], methods: [], targets: [], assignments: {}, editing: null };
		const editorContent = root.querySelector('[data-editor-content]');
		const keysList = editorContent.querySelector('[data-key-list]');
		const fields = {
			id: editorContent.querySelector('[data-field="id"]'),
			label: editorContent.querySelector('[data-field="label"]'),
			method: editorContent.querySelector('[data-field="method"]'),
			keyId: editorContent.querySelector('[data-field="key_id"]')
		};

		const dialogModule = await import(new URL(root.dataset.dialogUrl, document.baseURI).href);
		editorContent.hidden = false;
		const editorDialog = dialogModule.createStandardDialog({
			id: 'keyharbor-encryption-editor',
			className: 'keyharbor-app keyharbor-encryption',
			size: 'large',
			title: t('create_title'),
			content: editorContent,
			strings: { close: t('cancel'), closeDialog: t('cancel') },
			closeButtonPlugin: { enabled: false },
			statusPlugin: { renderEmpty: false },
			buttons: [
				{ key: 'cancel', label: t('cancel'), action: 'close' },
				{ key: 'save', label: t('save'), primary: true, async action() { await saveProfile(); } }
			]
		});
		editorDialog.init();


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
		function field(label, name, value) {
			const wrapper = node('label', 'keyharbor-field');
			wrapper.appendChild(node('span', '', label));
			const input = node('input');
			input.dataset.prop = name;
			input.type = 'text';
			input.autocomplete = 'off';
			input.value = value || '';
			wrapper.appendChild(input);
			return wrapper;
		}
		function notice(message, error) {
			const editorNotice = editorContent.querySelector('[data-editor-notice]');
			const pageNotice = root.querySelector('[data-notice]');
			const target = editorDialog.getState().open ? editorNotice : pageNotice;
			const other = editorDialog.getState().open ? pageNotice : editorNotice;
			other.hidden = true;
			other.textContent = '';
			target.hidden = !message;
			target.textContent = message || '';
			target.className = 'keyharbor-alert ' + (error ? 'keyharbor-alert-error' : 'keyharbor-alert-success');
		}
		function errorText(response) {
			if(response.error_code === 'validation' && typeof response.validation_reason === 'string') {
				const key = 'validation_' + response.validation_reason;
				if(typeof strings[key] === 'string' && strings[key]) { return t(key); }
			}
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
			const definition = data || { id: '', mode: 'fixed' };
			const locked = persisted === true;
			const block = node('div', 'keyharbor-encryption-key');
			block.dataset.keyEntry = 'true';
			if(locked) { block.dataset.persisted = 'true'; }
			const head = node('div', 'keyharbor-encryption-key-header');
			head.appendChild(field(t('key_id'), 'id', definition.id || ''));
			const keyIdInput = head.querySelector('input');
			keyIdInput.readOnly = locked;
			if(!locked) { keyIdInput.addEventListener('input', updateActiveKey); }
			const source = node('label', 'keyharbor-field');
			source.appendChild(node('span', '', t('source')));
			const select = node('select');
			select.dataset.prop = 'mode';
			[['fixed','source_fixed'],['env','source_env'],['file','source_file'],['configuration','source_configuration']].forEach(([value, key]) => {
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
				keyIdInput.placeholder = mode === 'fixed' && !locked ? t('fixed_key_id_hint') : '';
				if(mode === 'env') { details.appendChild(field(t('env_name'), 'name', definition.name)); }
				if(mode === 'file') { details.appendChild(field(t('file_path'), 'path', definition.path)); }
				if(mode === 'configuration') {
					details.appendChild(field(t('config_group'), 'group', definition.group));
					details.appendChild(field(t('config_key'), 'key', definition.key));
				}
				if(mode === 'fixed' && !locked) {
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
			return block;
		}
		function openEditor(profile) {
			notice('');
			state.editing = profile || null;
			editorDialog.execute('setTitle', profile ? t('edit_title') : t('create_title'));
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
			editorDialog.open({ source: 'encryptionProfileEditor' });
			fields.label.focus();
		}
		function closeEditor() {
			notice('');
			editorDialog.close({ source: 'encryptionProfileEditor' });

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
			notice('');
			const keys = collectKeys();
			const profile = {
				id: fields.id.value.trim(), label: fields.label.value.trim(), method: fields.method.value,
				key_id: fields.keyId.value, keys
			};
			if(!profile.id) {
				notice(t('validation_invalid_identifier'), true); fields.id.focus(); return;
			}
			if(!profile.label || !profile.method) {
				notice(t('validation_profile_fields'), true);
				(!profile.label ? fields.label : fields.method).focus(); return;
			}
			if(state.editing && keys.length === 0) {
				notice(t('validation_keys_list'), true); return;
			}
			const autoActive = !state.editing && (keys.length === 0 || (keys.length === 1 && keys[0].mode === 'fixed'));
			if(!profile.key_id && !autoActive) {
				notice(t('validation_active_key_missing'), true); fields.keyId.focus(); return;
			}
			try {
				await request({ mode: 'save', profile });
				closeEditor();
				await load();
				notice(t('saved'));
			} catch(error) { notice(error.message, true); }
		}
		async function rotateFixedKey(id) {
			if(!window.confirm(t('keys_help') + '\n' + t('rotate') + '?')) { return; }
			try {
				const response = await request({ mode: 'rotate', profile_id: id });
				await load();
				notice(t('saved') + ' ' + response.key_id);
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
				await load(); notice(t('assignment_saved'));
			} catch(error) {
				select.value = previous; notice(error.message, true);
			} finally { select.disabled = false; }
		}

		function handleClick(event) {
			const action = event.target.closest('[data-action]');
			if(!action || !(root.contains(action) || editorContent.contains(action))) { return; }
			const id = action.dataset.id || '';
			if(action.dataset.action === 'refresh') { load(); }
			if(action.dataset.action === 'create') { openEditor(null); }
			if(action.dataset.action === 'edit') { openEditor(state.profiles.find((p) => p.id === id) || null); }
			if(action.dataset.action === 'rotate') { rotateFixedKey(id); }
			if(action.dataset.action === 'add-key') {
				const entry = addKey({ mode: 'fixed' });
				entry.querySelector('[data-prop="id"]').focus();
			}
			if(action.dataset.action === 'discard-key') {
				const entry = action.closest('[data-key-entry]');
				if(entry && !entry.dataset.persisted) { entry.remove(); updateActiveKey(); }
			}
		}
		root.addEventListener('click', handleClick);
		editorContent.addEventListener('click', handleClick);
		root.addEventListener('change', (event) => {
			if(event.target.matches('[data-target-id]')) { changeAssignment(event.target); }
		});
		load();
	}

	if(document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
})();
