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
			var wa = view.proof_whatsapp_url ? '<p><a class="mo-booking__button mo-booking__button--primary mo-booking__whatsapp" href="' + esc(view.proof_whatsapp_url) + '" target="_blank" rel="noopener noreferrer">' + esc(view.proof_whatsapp_label || 'Enviar mi comprobante por WhatsApp') + '</a></p>' : '';
			return (view.uploads_note ? '<p class="mo-booking__note">' + esc(view.uploads_note) + '</p>' : '') + wa;
		}
		var id = idPrefix + '-file';
		return (view.proof_text ? '<p class="mo-booking__note">' + esc(view.proof_text) + '</p>' : '') +
			'<form class="mo-booking__upload" data-mo-upload novalidate>' +
			'<label class="mo-booking__drop" for="' + id + '">' + icon('upload', 'mo-booking__drop-icon') +
			'<span class="mo-booking__drop-title" data-file-name>Seleccionar comprobante</span>' +
			'<span class="mo-booking__drop-help">Comprobante de transferencia (JPG, PNG o PDF, máximo ' + esc(view.max_mb) + ' MB)</span></label>' +
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
		var picker = form.querySelector('input[type=file]');
		var nameEl = form.querySelector('[data-file-name]');
		if (picker && nameEl) {
			picker.addEventListener('change', function () {
				var has = picker.files && picker.files[0];
				nameEl.textContent = has ? picker.files[0].name : 'Seleccionar comprobante';
				form.classList.toggle('mo-booking__upload--ready', !!has);
			});
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

	/* Íconos de línea (rediseño). Heredan el color del texto. */
	var ICONS = {
		calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
		clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		users: '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5c.6-3.3 3-5 6-5s5.4 1.7 6 5"/><circle cx="17" cy="9.5" r="2.4"/><path d="M16.5 14.6c2.3.2 4 1.7 4.5 4.4"/>',
		user: '<circle cx="12" cy="8.5" r="3.6"/><path d="M4.5 20c.8-3.8 3.7-5.8 7.5-5.8s6.7 2 7.5 5.8"/>',
		mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
		phone: '<path d="M12 20.5a8.5 8.5 0 1 0-7.4-4.3L3.5 20.5l4.4-1.1a8.5 8.5 0 0 0 4.1 1.1z"/><path d="M9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.6-2.2-1.1-1 1c-1.1-.5-2.1-1.5-2.6-2.6l1-1-1.1-2.2z"/>',
		paw: '<circle cx="7" cy="10" r="1.8"/><circle cx="10.5" cy="6.5" r="1.8"/><circle cx="14.5" cy="6.5" r="1.8"/><circle cx="18" cy="10" r="1.8"/><path d="M12.5 11.5c-2.6 0-5 3.4-5 5.4 0 1.6 1.3 2.4 2.6 2.1 1-.2 1.6-.6 2.4-.6s1.4.4 2.4.6c1.3.3 2.6-.5 2.6-2.1 0-2-2.4-5.4-5-5.4z"/>',
		camera: '<path d="M4 8.5h3l1.6-2.5h6.8L17 8.5h3v10.5H4z"/><circle cx="12" cy="13.5" r="3.4"/>',
		sparkle: '<path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1-5.1-1.9 5.1-1.9z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
		heart: '<path d="M12 20s-7.5-4.6-7.5-10.1A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.6C19.5 15.4 12 20 12 20z"/>',
		arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
		back: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
		upright: '<path d="M7 17L17 7M9 7h8v8"/>',
		tag: '<path d="M20 12.6l-7.4 7.4a1.8 1.8 0 0 1-2.5 0L3.5 13.4V4.5h8.9l7.6 7.6a.4.4 0 0 1 0 .5z"/><circle cx="8" cy="9" r="1.4"/>',
		bolt: '<path d="M13 3L5 13.5h6L10 21l8-10.5h-6z"/>',
		plus: '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/>',
		file: '<path d="M6 3.5h8l4 4v13H6z"/><path d="M14 3.5v4h4M9 12h6M9 15.5h6"/>',
		info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.2"/>',
		check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		upload: '<path d="M12 15.5V4.5M7.5 9L12 4.5 16.5 9M5 15.5v4h14v-4"/>',
		copy: '<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5v-3a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 5.5V14A1.5 1.5 0 0 0 6 15.5h2.5"/>',
		pin: '<path d="M12 21s-6.5-5.8-6.5-11a6.5 6.5 0 0 1 13 0c0 5.2-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
		download: '<path d="M12 4.5v11M7.5 11l4.5 4.5 4.5-4.5M5 19.5h14"/>',
		logout: '<path d="M14 4.5H6v15h8M10 12h10M16.5 8.5L20 12l-3.5 3.5"/>',
		bank: '<path d="M3.5 9.5L12 4.5l8.5 5M5.5 10v7M9.5 10v7M14.5 10v7M18.5 10v7M3.5 19.5h17"/>',
		receipt: '<path d="M6 3.5h12v17l-2.5-1.5-2 1.5-1.5-1.2-1.5 1.2-2-1.5L6 20.5z"/><path d="M9 8.5h6M9 12h6M9 15.5h3.5"/>',
		gift: '<rect x="4" y="9" width="16" height="11" rx="1.5"/><path d="M3.5 9h17v3.5h-17zM12 9v11M12 9S10.8 4.5 8.5 4.5a2 2 0 0 0 0 4.5M12 9s1.2-4.5 3.5-4.5a2 2 0 0 1 0 4.5"/>',
		edit: '<path d="M4.5 19.5l1-4L15.8 5.2a2 2 0 0 1 2.9 0l.1.1a2 2 0 0 1 0 2.9L8.5 18.5z"/>',
		minus: '<path d="M6 12h12"/>',
		add: '<path d="M12 6v12M6 12h12"/>',
		hourglass: '<path d="M7 3.5h10M7 20.5h10M8 3.5c0 4.5 8 4.5 8 8.5s-8 4-8 8.5M16 3.5c0 4.5-8 4.5-8 8.5s8 4 8 8.5"/>'
	};
	function icon(name, cls) {
		return '<svg class="mo-booking__icon' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (ICONS[name] || '') + '</svg>';
	}

	/** Renglones del precio: precio de referencia tachado, descuentos, precio con descuento y extras. */
	function priceRows(rows) {
		return (rows || []).map(function (r) {
			var kind = r.kind || 'line';
			var amount = kind === 'reference' ? '<s>' + esc(r.amount_txt) + '</s>' : esc(r.amount_txt);
			return '<tr class="mo-booking__row mo-booking__row--' + esc(kind) + '"><th scope="row">' + esc(r.label) + '</th><td>' + amount + '</td></tr>';
		}).join('');
	}

	window.MOBooking = {
		icon: icon,
		priceRows: priceRows,
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
