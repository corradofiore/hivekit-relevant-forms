/**
 * Relevant Forms upload JS.
 *
 * Progressive upload flow: authorize -> PUT/POST -> hidden token list.
 * No dependencies.
 */
(function () {
	'use strict';

	function statusLine(input, message) {
		var el = document.getElementById(input.id + '-status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function authorize(baseUrl, formId, fieldId, file) {
		var body = new FormData();
		body.append('action', 'relevant_forms_upload_authorize');
		body.append('_relevant_upload_nonce', document.querySelector('.relevant-forms[data-form-id="' + formId + '"]').dataset.uploadNonce || '');
		body.append('form_id', formId);
		body.append('field_id', fieldId);
		body.append('filename', file.name);
		body.append('content_type', file.type || 'application/octet-stream');
		body.append('size', String(file.size));

		return fetch(baseUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.json();
		});
	}

	function putFile(authorization, file) {
		return fetch(authorization.url, {
			method: authorization.method || 'PUT',
			headers: authorization.headers || {},
			body: file,
			credentials: 'same-origin',
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('upload_failed');
			}
			return response;
		});
	}

	function finishLocal(authorization, uploadId, token, file) {
		var body = new FormData();
		body.append('action', 'relevant_forms_local_upload');
		body.append('upload_id', String(uploadId));
		body.append('token', token);
		body.append('file', file);

		return fetch(authorization.url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error('upload_failed');
			}
		});
	}

	function finishRemote(baseUrl, uploadId, token) {
		var body = new FormData();
		body.append('action', 'relevant_forms_upload_complete');
		body.append('upload_id', String(uploadId));
		body.append('token', token);

		return fetch(baseUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error('upload_failed');
			}
		});
	}

	function processFile(baseUrl, formId, fieldId, file, input) {
		var uploadId = 0;

		return authorize(baseUrl, formId, fieldId, file).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || 'upload_rejected');
			}
			var data = payload.data;
			uploadId = Number(data.upload_id);
			if (data.method === 'POST') {
				return finishLocal(data, data.upload_id, data.token, file);
			}
			return putFile(data, file).then(function () {
				return finishRemote(baseUrl, data.upload_id, data.token);
			});
		}).then(function () {
			var idsInput = document.getElementById(input.id + '-ids');
			var ids = [];
			try {
				ids = JSON.parse(idsInput.value || '[]');
			} catch (e) {
				ids = [];
			}
			ids.push(uploadId);
			idsInput.value = JSON.stringify(ids);
			input.value = '';
			statusLine(input, '');
		}).catch(function (error) {
			statusLine(input, error.message || 'upload_failed');
		});
	}

	function bindFileInputs() {
		var forms = document.querySelectorAll('.relevant-forms[data-upload-nonce]');
		forms.forEach(function (form) {
			var formId = form.dataset.formId;
			// Forms post to their own page (empty action = current URL); the
			// front-end handler serves the upload helper actions there too.
			var baseUrl = form.getAttribute('action') || window.location.href;
			form.querySelectorAll('.relevant-forms-file').forEach(function (input) {
				input.addEventListener('change', function () {
					var files = Array.prototype.slice.call(input.files || []);
					files.forEach(function (file) {
						statusLine(input, 'Uploading…');
						processFile(baseUrl, formId, input.dataset.fieldId, file, input);
					});
				});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindFileInputs);
	} else {
		bindFileInputs();
	}
})();
