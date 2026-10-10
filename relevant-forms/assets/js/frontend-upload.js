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

	/**
	 * Resolve a request target, refusing anything that is not same-origin.
	 *
	 * Every upload request is a POST to this site's own admin-post handler: the
	 * base comes from the form (empty action = current page) and the target from
	 * the server's authorization response. Neither is allowed to point anywhere
	 * else, so tampered markup or a tampered response cannot send the visitor's
	 * file to a third party. The server only ever authorizes same-origin POSTs
	 * (see Uploads/Storage/*Provider::createUploadAuthorization).
	 *
	 * @param {string} url Candidate request target.
	 * @return {string} Absolute same-origin URL.
	 */
	function sameOrigin(url) {
		var resolved = new URL(url, window.location.href);

		if (resolved.origin !== window.location.origin) {
			throw new Error('upload_target_rejected');
		}

		return resolved.toString();
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

		// nosemgrep: sameOrigin() pins the target to this site's own origin.
		return fetch(sameOrigin(baseUrl), { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.json();
		});
	}

	function finishLocal(authorization, uploadId, token, file) {
		var body = new FormData();
		body.append('action', 'relevant_forms_local_upload');
		body.append('upload_id', String(uploadId));
		body.append('token', token);
		body.append('file', file);

		// nosemgrep: sameOrigin() pins the target to this site's own origin.
		return fetch(sameOrigin(authorization.url), { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
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

			// Every provider (local storage and managed cloud) authorizes the same
			// browser contract: a same-origin multipart POST that the site then
			// relays server-side. A non-POST authorization means a provider this
			// script does not know how to complete, so fail loudly.
			if ('POST' !== data.method) {
				throw new Error('upload_unsupported_method');
			}

			return finishLocal(data, data.upload_id, data.token, file);
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

	/**
	 * Bind every file input under a root.
	 *
	 * @param {Element} root Scope to search (document when omitted).
	 */
	function bindFileInputs( root ) {
		var forms = (root || document).querySelectorAll('.relevant-forms[data-upload-nonce]');
		forms.forEach(function (form) {
			if ('1' === form.getAttribute('data-rf-upload-ready')) {
				return;
			}

			form.setAttribute('data-rf-upload-ready', '1');

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
		document.addEventListener('DOMContentLoaded', function () {
			bindFileInputs( document );
		});
	} else {
		bindFileInputs( document );
	}

	// Allow re-runs on dynamically injected markup (Divi previews).
	(window.relevantFormsFrontend = window.relevantFormsFrontend || []).push(bindFileInputs);
})();
