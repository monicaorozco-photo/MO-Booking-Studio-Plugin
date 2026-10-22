/* MO Booking Studio: utilidades compartidas del formulario y del portal. Sin dependencias. */
(function (window) {
	'use strict';

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/** Convierte saltos de línea en <br> sobre texto ya escapado. */
	function lines(value) {
		return esc(value).replace(/\n/g, '<br>');
	}

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		var hex = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}

	/**
	 * Llama a la API. Devuelve {ok, status, data}. Nunca lanza por errores HTTP;
	 * los errores de red se devuelven con status 0.
	 */
	function api(base, path, options) {
		options = options || {};
		var headers = { 'Accept': 'application/json' };
		var body;
		if (options.form) {
			body = options.form;
		} else if (options.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(options.body);
		}
		if (options.csrf) {
			headers['X-MO-CSRF'] = options.csrf;
		}
		if (options.frequent) {
			headers['X-MO-Frequent'] = options.frequent;
		}
		// La base puede ser /wp-json/mo-booking/v1/ o ?rest_route=/mo-booking/v1/ (enlaces simples).
		var parts = String(path).split('?');
		var url = base + parts[0];
		if (parts[1]) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + parts[1];
		}
		return window.fetch(url, {
			method: options.method || (body ? 'POST' : 'GET'),
			headers: headers,
			body: body,
			credentials: 'same-origin',
			cache: 'no-store'
		}).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
				return { ok: res.ok, status: res.status, data: data };
			});
		}).catch(function () {
			return { ok: false, status: 0, data: { message: 'No hay conexión. Revisa tu internet e intenta de nuevo.' } };
		});
	}

	function errorMessage(result) {
		if (result && result.data && result.data.message) {
			return result.data.message;
		}
		return 'Ocurrió un error. Intenta de nuevo.';
	}

	function errorField(result) {
		return result && result.data && result.data.data && result.data.data.field ? result.data.data.field : null;
	}

	function errorCode(result) {
		return result && result.data && result.data.code ? String(result.data.code).replace(/^mo_/, '') : null;
	}

	/** Formulario de subida de comprobante compartido por la confirmación y el portal. */
	function uploadForm(view, idPrefix) {
		if (!view.can_upload) {
			return view.uploads_note ? '<p class="mo-booking__note">' + esc(view.uploads_note) + '</p>' : '';
		}
		var id = idPrefix + '-file';
		return (view.proof_text ? '<p class="mo-booking__note">' + esc(view.proof_text) + '</p>' : '') +
			'<form class="mo-booking__upload" data-mo-upload novalidate>' +
			'<label class="mo-booking__label" for="' + id + '">Comprobante de transferencia (JPG, PNG o PDF, máximo ' + esc(view.max_mb) + ' MB)</label>' +
			'<input class="mo-booking__file" type="file" id="' + id + '" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>' +
			'<p class="mo-booking__field-error" data-error-for="file" role="alert"></p>' +
			'<button type="submit" class="mo-booking__button mo-booking__button--primary">Enviar comprobante</button>' +
			'</form>';
	}

	function bindUpload(root, api, getCsrf, maxMb, onDone) {
		var form = root.querySelector('[data-mo-upload]');
		if (!form) {
			return;
		}
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var input = form.querySelector('input[type=file]');
			var errorEl = form.querySelector('[data-error-for=file]');
			errorEl.textContent = '';
			if (!input.files || !input.files[0]) {
				errorEl.textContent = 'Elige un archivo.';
				input.focus();
				return;
			}
			var file = input.files[0];
			if (file.size > maxMb * 1024 * 1024) {
				errorEl.textContent = 'El archivo pesa más de ' + maxMb + ' MB.';
				return;
			}
			var button = form.querySelector('button');
			button.disabled = true;
			button.textContent = 'Enviando…';
			var data = new window.FormData();
			data.append('file', file);
			api('portal/proofs', { form: data, csrf: getCsrf() }).then(function (res) {
				button.disabled = false;
				button.textContent = 'Enviar comprobante';
				if (!res.ok) {
					errorEl.textContent = errorMessage(res);
					return;
				}
				onDone(res.data);
			});
		});
	}

	window.MOBooking = {
		esc: esc,
		lines: lines,
		uuid: uuid,
		api: api,
		errorMessage: errorMessage,
		errorField: errorField,
		errorCode: errorCode,
		uploadForm: uploadForm,
		bindUpload: bindUpload
	};
})(window);
