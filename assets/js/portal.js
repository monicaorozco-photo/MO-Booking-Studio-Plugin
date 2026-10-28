/* MO Booking Studio: portal privado del cliente. El enlace llega como #acceso=TOKEN. */
(function (window, document) {
	'use strict';

	var M = window.MOBooking;
	var esc = M.esc;

	function Portal(root, config) {
		this.root = root;
		this.config = config;
		this.api = function (path, options) { return M.api(config.api, path, options); };
		this.csrf = null;
		this.view = null;
	}

	/** Reserva de ejemplo para el editor de Elementor (no consulta datos reales). */
	function previewView() {
		return {
			reference: 'MO-EJEMPLO', status: 'pending_payment', status_label: 'Pendiente de pago', status_help: 'Tu horario está apartado. Transfiere el anticipo y sube tu comprobante antes de la hora límite.',
			service: 'Holiday Mini (ejemplo)', brand: 'Tu estudio', name: 'Ana López', date_txt: 'sábado 21 de noviembre de 2026', time_txt: '10:30', timezone: 'America/Ciudad_Juarez',
			location: '', instructions: '', location_later: true, delivery_text: '', notices: [], participants: 3, pets: 0, frequent_review: false,
			lines: [{ label: 'Preventa · público general', amount_txt: '$1,400 MXN' }], price_rows: [{ kind: 'reference', label: 'Precio', amount_txt: '$1,800 MXN' }, { kind: 'discount', label: 'Descuento por fecha anticipada', amount_txt: '−$200 MXN' }, { kind: 'discount', label: 'Descuento por preventa', amount_txt: '−$200 MXN' }, { kind: 'discounted', label: 'Precio con descuento', amount_txt: '$1,400 MXN' }], total_txt: '$1,400 MXN', deposit_txt: '$500 MXN', paid_txt: '$0 MXN', balance_txt: '$1,400 MXN',
			deposit_missing_txt: '$500 MXN', expires_txt: 'viernes 20 de noviembre, 22:30 h', bank: 'Banco de ejemplo\nCLABE 000000000000000000', payment_ref: 'Usa tu número de reserva como referencia.',
			proofs: [], payments: [], can_upload: true, max_mb: 10, proof_text: 'Sube aquí tu comprobante de anticipo.', contract: { title: 'Contrato de servicio (ejemplo)', body: '<p>Texto del contrato.</p>', accepted_txt: 'viernes 20 de noviembre de 2026, 10:00 h' },
			print_url: '#', whatsapp_url: '#', change_url: '#',
			reschedule: { can: true, message: '', policy: 'Solo puedes cambiar la fecha de tu sesión UNA VEZ con mínimo 36 horas de anticipación.\n- Tu precio se respeta.\n- Tu anticipo pasa a la nueva fecha.' }
		};
	}

	Portal.prototype.start = function () {
		var self = this;
		if (this.config && this.config.preview) {
			this.view = previewView();
			this.render();
			return;
		}
		var match = /[#&]acceso=([A-Za-z0-9_-]{43})/.exec(window.location.hash || '');
		// beta.4: al terminar la reserva se llega aquí con ?mo_nuevo=1 para mostrar la bienvenida.
		this.isNew = /[?&]mo_nuevo=1/.test(window.location.search || '');
		var search = (window.location.search || '').replace(/([?&])mo_nuevo=1&?/, '$1').replace(/[?&]$/, '');
		if (match || this.isNew) {
			// Quitar el token de la barra de direcciones y del historial.
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', window.location.pathname + search);
			}
		}
		if (match) {
			this.api('portal/session', { body: { token: match[1] } }).then(function (res) { self.handle(res); });
			return;
		}
		this.api('portal/booking').then(function (res) { self.handle(res); });
	};

	Portal.prototype.handle = function (res) {
		if (!res.ok) {
			var code = M.errorCode(res);
			var msg = code === 'portal_auth'
				? 'Para ver tu reserva abre el enlace privado que te enviamos por correo.'
				: M.errorMessage(res);
			this.root.innerHTML = '<div class="mo-booking__panel"><h2 class="mo-booking__title">Mi reserva</h2><p class="mo-booking__alert" role="alert">' + esc(msg) + '</p></div>';
			return;
		}
		if (res.data.csrf) { this.csrf = res.data.csrf; }
		this.view = res.data.booking;
		this.render();
	};

	Portal.prototype.render = function (message, uploadMessage) {
		var b = this.view;
		var I = M.icon;
		var awaiting = ['pending_payment', 'correction_requested', 'payment_review'].indexOf(b.status) !== -1;
		var html = '';
		if (this.isNew) {
			html += '<div class="mo-booking__welcome-new" role="status"><span class="mo-booking__welcome-icon">' + I('check') + '</span><div><p class="mo-booking__eyebrow">Solicitud recibida</p><h2 class="mo-booking__panel-title">¡Tu horario está apartado!</h2><p>Esta es tu página de reserva. Te enviamos el enlace por correo y te escribiremos por WhatsApp con cada novedad. Guárdalo para volver cuando quieras.</p></div></div>';
		}
		var showBrand = !this.config || this.config.showBrand !== false;
		var showTitle = !this.config || this.config.showTitle !== false;
		if (showBrand) { html += '<p class="mo-booking__brand">' + esc(b.brand) + ' · Consulta de reserva</p>'; }
		html += '<div aria-live="polite" role="status" class="mo-booking__live">' + (message ? '<p class="mo-booking__success">' + esc(message) + '</p>' : '') + '</div>';

		// Tarjeta principal: servicio, estado y lo que falta por pagar.
		html += '<section class="mo-booking__panel mo-booking__hero"><div class="mo-booking__hero-main">';
		if (showTitle) { html += '<h2 class="mo-booking__title" tabindex="-1">' + esc(b.service) + '</h2>'; }
		html += '<p class="mo-booking__ref">Reserva <strong>' + esc(b.reference) + '</strong> · ' + esc(b.name) + '</p>';
		html += '<span class="mo-booking__pill mo-booking__pill--' + esc(b.status) + '">' + I(b.status === 'confirmed' || b.status === 'completed' ? 'check' : 'clock') + esc(b.status_label) + '</span>';
		html += '<p class="mo-booking__status">' + esc(b.status_help) + '</p></div>';
		if (b.expires_txt) {
			html += '<div class="mo-booking__hero-amount mo-booking__deadline"><span class="mo-booking__caps">Anticipo pendiente</span><strong>' + esc(b.deposit_txt) + '</strong><span>Antes del ' + esc(b.expires_txt) + ' (' + esc(b.timezone) + ')</span></div>';
		} else if (awaiting && b.deposit_missing_txt) {
			html += '<div class="mo-booking__hero-amount"><span class="mo-booking__caps">Anticipo por verificar</span><strong>' + esc(b.deposit_missing_txt) + '</strong></div>';
		} else if (b.balance_txt && ['confirmed'].indexOf(b.status) !== -1) {
			html += '<div class="mo-booking__hero-amount"><span class="mo-booking__caps">Saldo pendiente</span><strong>' + esc(b.balance_txt) + '</strong></div>';
		}
		html += '</section>';

		// Avance de la reserva.
		var stages = ['Horario apartado', 'Comprobante en revisión', 'Sesión confirmada', 'Sesión realizada'];
		var at = { pending_payment: 0, correction_requested: 0, payment_review: 1, confirmed: 2, completed: 4 }[b.status];
		if (at !== undefined) {
			html += '<ol class="mo-booking__track" aria-label="Avance de tu reserva">' + stages.map(function (label, i) {
				var state = i < at ? 'done' : (i === at ? 'current' : 'todo');
				return '<li class="mo-booking__track-step mo-booking__track-step--' + state + '"' + (i === at ? ' aria-current="step"' : '') + '><span class="mo-booking__track-dot">' + (i < at ? I('check') : '') + '</span><span>' + esc(label) + '</span></li>';
			}).join('') + '</ol>';
		}
		if (b.correction) {
			html += '<div class="mo-booking__alert"><strong>Qué necesitamos:</strong> ' + M.lines(b.correction.reason) + (b.correction.until_txt ? '<br>Plazo: ' + esc(b.correction.until_txt) + ' (' + esc(b.timezone) + ')' : '') + '</div>';
		}

		var title = function (icon, text) { return '<h3 class="mo-booking__card-title">' + I(icon, 'mo-booking__card-icon') + '<span>' + esc(text) + '</span></h3>'; };
		var tile = function (label, value) { return '<div class="mo-booking__tile"><span class="mo-booking__caps">' + esc(label) + '</span><strong>' + value + '</strong></div>'; };
		// En celular, si falta pagar, primero van los datos para transferir y el comprobante.
		html += '<div class="mo-booking__grid' + (awaiting && (b.bank || b.can_upload) ? ' mo-booking__grid--pay-first' : '') + '"><div class="mo-booking__col">';

		// Detalles de la sesión.
		html += '<section class="mo-booking__panel">' + title('camera', 'Detalles de la sesión') + '<div class="mo-booking__tiles">';
		html += tile('Fecha', esc(b.date_txt)) + tile('Horario', esc(b.time_txt) + ' h') + tile('Personas', esc(b.participants) + (b.pets ? ' y ' + esc(b.pets) + ' mascota(s)' : '')) + tile('Estado', esc(b.status_label));
		html += '</div><dl class="mo-booking__summary mo-booking__summary--plain"><dt>Nombre</dt><dd>' + esc(b.name) + '</dd><dt>Zona horaria</dt><dd>' + esc(b.timezone) + '</dd>';
		if (b.location) { html += '<dt>Lugar</dt><dd>' + esc(b.location) + '</dd>'; }
		html += '</dl>';
		if (b.location_later) { html += '<p class="mo-booking__hint">' + I('pin') + '<span>Lugar: te lo compartiremos aquí en cuanto confirmemos tu anticipo.</span></p>'; }
		(b.notices || []).forEach(function (n) { html += '<p class="mo-booking__notice">' + esc(n) + '</p>'; });
		if (b.instructions) { html += '<div class="mo-booking__instructions"><h4>Indicaciones</h4><p>' + M.lines(b.instructions) + '</p></div>'; }
		if (b.delivery_text) { html += '<p class="mo-booking__note">' + esc(b.delivery_text) + '</p>'; }
		html += '</section>';

		// Resumen de inversión.
		html += '<section class="mo-booking__panel">' + title('receipt', 'Resumen de inversión') + '<table class="mo-booking__lines"><tbody>';
		html += b.price_rows ? M.priceRows(b.price_rows) : b.lines.map(function (l) { return '<tr><th scope="row">' + esc(l.label) + '</th><td>' + esc(l.amount_txt) + '</td></tr>'; }).join('');
		html += '</tbody><tfoot><tr class="mo-booking__row--total"><th scope="row">Total</th><td>' + esc(b.total_txt) + '</td></tr>';
		html += '<tr><th scope="row">Pagado y verificado</th><td>' + esc(b.paid_txt) + '</td></tr>';
		html += '<tr><th scope="row">Saldo</th><td>' + esc(b.balance_txt) + '</td></tr></tfoot></table>';
		if (b.deposit_missing_txt && awaiting) { html += '<p class="mo-booking__note">Anticipo pendiente de verificar: ' + esc(b.deposit_missing_txt) + '.</p>'; }
		if (b.refund_due_txt) { html += '<p class="mo-booking__note">Devolución pendiente a tu favor: ' + esc(b.refund_due_txt) + '. Te contactaremos para realizarla.</p>'; }
		if (b.credit_txt) { html += '<p class="mo-booking__note">Saldo a tu favor: ' + esc(b.credit_txt) + '.</p>'; }
		if (b.frequent_review) { html += '<p class="mo-booking__note">Estamos revisando si aplica tu tarifa de cliente frecuente.</p>'; }
		if (b.payments && b.payments.length) {
			html += '<h4 class="mo-booking__subtitle">Pagos recibidos</h4><ul class="mo-booking__payments">';
			b.payments.forEach(function (p) { html += '<li>' + I('check', 'mo-booking__ok') + '<span>' + esc(p.date) + '</span><strong>' + esc(p.amount_txt) + '</strong><span class="mo-booking__note">' + esc(p.method) + '</span></li>'; });
			html += '</ul>';
		}
		html += '</section>';
		html += '</div><div class="mo-booking__col">';

		// Datos para transferencia con botones para copiar.
		if (b.bank) {
			html += '<section class="mo-booking__panel mo-booking__bank">' + title('bank', 'Datos para transferencia');
			html += '<div class="mo-booking__copybox"><p>' + M.lines(b.bank) + '</p><button type="button" class="mo-booking__copy" data-copy="' + esc(b.bank) + '">' + I('copy') + '<span>Copiar</span></button></div>';
			html += '<div class="mo-booking__copybox mo-booking__copybox--ref"><p><span class="mo-booking__caps">Referencia</span><strong>' + esc(b.reference) + '</strong></p><button type="button" class="mo-booking__copy" data-copy="' + esc(b.reference) + '">' + I('copy') + '<span>Copiar</span></button></div>';
			if (b.payment_ref) { html += '<p class="mo-booking__note">' + esc(b.payment_ref) + '</p>'; }
			html += '</section>';
		}

		// Comprobantes.
		var uploadBox = (uploadMessage ? '<p class="mo-booking__success" role="status">' + esc(uploadMessage) + '</p>' : '');
		uploadBox += M.uploadForm(b, 'mo-portal');
		if (b.proofs.length) {
			uploadBox += '<h4 class="mo-booking__subtitle">Comprobantes enviados</h4><ul class="mo-booking__proofs">';
			b.proofs.forEach(function (p) {
				uploadBox += '<li>' + I('file', 'mo-booking__ok') + '<span>' + esc(p.date) + '</span><strong>' + esc(p.status) + '</strong>' + (p.note ? '<span>' + esc(p.note) + '</span>' : '') + (p.url ? '<a class="mo-booking__link" href="' + esc(p.url) + '" target="_blank" rel="noopener">' + (p.kind === 'PDF' ? 'Descargar' : 'Ver') + '</a>' : '') + '</li>';
			});
			uploadBox += '</ul>';
		}
		if (uploadBox) {
			html += '<section class="mo-booking__panel mo-booking__upload-wrap">' + ((b.can_upload || b.proof_whatsapp_url || b.proofs.length) ? title('upload', 'Enviar comprobante') : '') + uploadBox + '</section>';
		}
		// beta.6: cambio de fecha por la clienta (una vez).
		if (b.reschedule) {
			html += '<section class="mo-booking__panel mo-booking__reschedule">' + title('calendar', 'Cambiar fecha');
			if (b.reschedule.can) {
				html += '<p class="mo-booking__note">Puedes cambiar la fecha de tu sesión una vez desde aquí.</p><button type="button" class="mo-booking__button mo-booking__button--primary" data-action="reschedule-open">' + I('calendar') + 'Cambiar fecha</button>';
			} else {
				html += '<p class="mo-booking__hint">' + I('info') + '<span>' + esc(b.reschedule.message) + '</span></p>';
			}
			html += '</section>';
		}
		if (b.whatsapp_url || b.change_url) {
			html += '<section class="mo-booking__panel mo-booking__help-card">' + title('phone', '¿Necesitas ayuda?') + '<div class="mo-booking__stack">';
			if (b.whatsapp_url) { html += '<a class="mo-booking__button" href="' + esc(b.whatsapp_url) + '" target="_blank" rel="noopener noreferrer">' + I('phone') + 'Escribir por WhatsApp</a>'; }
			if (b.change_url) { html += '<a class="mo-booking__button" href="' + esc(b.change_url) + '" target="_blank" rel="noopener noreferrer">' + I('calendar') + 'Pedir cambio o cancelación</a>'; }
			html += '</div></section>';
		}
		html += '</div></div>';

		if (b.contract) {
			html += '<details class="mo-booking__policies mo-booking__contract"><summary>' + I('file') + '<span>Contrato que aceptaste: ' + esc(b.contract.title) + '</span></summary>' +
				(b.contract.accepted_txt ? '<p class="mo-booking__note">Aceptado el ' + esc(b.contract.accepted_txt) + '.</p>' : '') +
				'<div class="mo-booking__contract-body">' + b.contract.body + '</div></details>';
		}
		html += '<div class="mo-booking__actions mo-booking__actions--portal">';
		if (b.print_url) {
			html += '<span class="mo-booking__print"><a class="mo-booking__button" href="' + esc(b.print_url) + '" target="_blank" rel="noopener">' + I('download') + 'Descargar mi contrato y datos de la sesión</a></span>';
		}
		html += '<button type="button" class="mo-booking__button" data-action="logout">' + I('logout') + 'Cerrar sesión</button></div>';
		this.root.innerHTML = html;
		this.bind();
	};

	// ----- beta.6: cambio de fecha -----

	var MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
	var WEEKDAYS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
	function daysIn(y, m) { return new Date(Date.UTC(y, m, 0)).getUTCDate(); }

	/** Texto de la política: renglones con "- " se vuelven lista. */
	function policyHtml(text) {
		var out = '';
		var list = false;
		String(text || '').split(/\n/).forEach(function (line) {
			var t = line.trim();
			if (/^[-•*]\s+/.test(t)) {
				if (!list) { out += '<ul>'; list = true; }
				out += '<li>' + esc(t.replace(/^[-•*]\s+/, '')) + '</li>';
				return;
			}
			if (list) { out += '</ul>'; list = false; }
			if (t) { out += '<p>' + esc(t) + '</p>'; }
		});
		return out + (list ? '</ul>' : '');
	}

	/** Ventana con la política; solo "Entendido" abre el calendario. */
	Portal.prototype.openPolicy = function () {
		var self = this;
		var opener = document.activeElement;
		var wrap = document.createElement('div');
		wrap.className = 'mo-booking mo-booking__modal-wrap';
		wrap.innerHTML = '<div class="mo-booking__modal" role="dialog" aria-modal="true" aria-labelledby="mo-rs-title"><h2 class="mo-booking__card-title" id="mo-rs-title">' + M.icon('calendar', 'mo-booking__card-icon') + '<span>Cambio de fecha</span></h2>' +
			'<div class="mo-booking__policy">' + policyHtml(this.view.reschedule.policy) + '</div>' +
			'<div class="mo-booking__actions"><button type="button" class="mo-booking__button" data-rs="cancel">Cancelar</button><button type="button" class="mo-booking__button mo-booking__button--primary" data-rs="ok">Entendido, elegir nueva fecha</button></div></div>';
		document.body.appendChild(wrap);
		var close = function () {
			document.removeEventListener('keydown', onKey);
			wrap.parentNode.removeChild(wrap);
			if (opener && opener.focus) { opener.focus(); }
		};
		var onKey = function (ev) { if (ev.key === 'Escape') { close(); } };
		document.addEventListener('keydown', onKey);
		wrap.addEventListener('click', function (ev) { if (ev.target === wrap) { close(); } });
		wrap.querySelector('[data-rs=cancel]').addEventListener('click', close);
		wrap.querySelector('[data-rs=ok]').addEventListener('click', function () { close(); self.startReschedule(); });
		wrap.querySelector('[data-rs=ok]').focus();
	};

	Portal.prototype.startReschedule = function () {
		var now = new Date();
		this.rs = { year: now.getFullYear(), month: now.getMonth() + 1, days: {}, date: null, slot: null, preview: null, busy: false, error: '' };
		this.findRsMonth(this.rs.year, this.rs.month, 0);
	};

	Portal.prototype.findRsMonth = function (y, m, tries) {
		var self = this;
		this.loadRsMonth(y, m).then(function (hasOpen) {
			if (!hasOpen && tries < 11 && self.rs) {
				self.findRsMonth(m === 12 ? y + 1 : y, m === 12 ? 1 : m + 1, tries + 1);
			} else if (!hasOpen && self.rs) {
				var now = new Date();
				self.loadRsMonth(now.getFullYear(), now.getMonth() + 1);
			}
		});
	};

	Portal.prototype.loadRsMonth = function (y, m) {
		var self = this;
		var rs = this.rs;
		rs.year = y; rs.month = m; rs.busy = true;
		this.renderReschedule();
		if (this.config && this.config.preview) {
			rs.busy = false;
			for (var d = 1; d <= daysIn(y, m); d++) {
				var open = d % 3 !== 0;
				rs.days[ymd(y, m, d)] = { date: ymd(y, m, d), status: open ? 'available' : 'closed', slots: open ? [{ start: ymd(y, m, d) + 'T17:00:00Z', time: '10:00', available: true, label: 'Disponible' }, { start: ymd(y, m, d) + 'T18:00:00Z', time: '11:00', available: true, label: 'Disponible' }] : [] };
			}
			this.renderReschedule();
			return Promise.resolve(true);
		}
		return this.api('portal/reschedule/availability?from=' + ymd(y, m, 1) + '&to=' + ymd(y, m, daysIn(y, m))).then(function (res) {
			if (!self.rs) { return false; }
			rs.busy = false;
			if (!res.ok) { rs.error = M.errorMessage(res); self.renderReschedule(); return false; }
			var hasOpen = false;
			res.data.days.forEach(function (d) { rs.days[d.date] = d; if (d.status === 'available') { hasOpen = true; } });
			self.renderReschedule();
			return hasOpen;
		});
	};

	Portal.prototype.renderReschedule = function () {
		var rs = this.rs;
		var b = this.view;
		var y = rs.year;
		var m = rs.month;
		var now = new Date();
		var canPrev = (y > now.getFullYear()) || (y === now.getFullYear() && m > now.getMonth() + 1);
		var html = (!this.config || this.config.showBrand !== false) ? '<p class="mo-booking__brand">' + esc(b.brand) + ' · Cambio de fecha</p>' : '';
		html += '<h2 class="mo-booking__title" tabindex="-1" data-rs-heading>Elige tu nueva fecha</h2>';
		html += '<p class="mo-booking__lead">Tu cita actual: ' + esc(b.date_txt) + ', ' + esc(b.time_txt) + ' h. Puedes cambiarla una sola vez.</p>';
		if (rs.error) { html += '<p class="mo-booking__alert" role="alert">' + esc(rs.error) + '</p>'; }
		html += '<div class="mo-booking__grid"><section class="mo-booking__panel mo-booking__cal-card"><div class="mo-booking__month">' +
			'<button type="button" class="mo-booking__nav" data-rs="prev"' + (canPrev ? '' : ' disabled') + ' aria-label="Mes anterior">‹</button>' +
			'<p class="mo-booking__month-name" aria-live="polite">' + esc(MONTHS[m - 1]) + ' ' + y + '</p>' +
			'<button type="button" class="mo-booking__nav" data-rs="next" aria-label="Mes siguiente">›</button></div>';
		if (rs.busy) { html += '<p class="mo-booking__loading" role="status">Cargando disponibilidad…</p>'; }
		html += '<div class="mo-booking__calendar" role="grid"><div class="mo-booking__weekdays" role="row">' + WEEKDAYS.map(function (d) { return '<span role="columnheader">' + d + '</span>'; }).join('') + '</div><div class="mo-booking__days" role="row">';
		var offset = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
		for (var i = 0; i < offset; i++) { html += '<span class="mo-booking__day mo-booking__day--blank" aria-hidden="true"></span>'; }
		for (var d = 1; d <= daysIn(y, m); d++) {
			var date = ymd(y, m, d);
			var info = rs.days[date];
			var ok = info && info.status === 'available';
			var cls = 'mo-booking__day ' + (ok ? 'mo-booking__day--available mo-booking__day--regular' : (info && info.status === 'sold_out' ? 'mo-booking__day--soldout' : 'mo-booking__day--closed')) + (rs.date === date ? ' mo-booking__day--selected' : '');
			html += '<button type="button" role="gridcell" class="' + cls + '" data-rs-date="' + date + '"' + (ok ? '' : ' disabled') + ' aria-pressed="' + (rs.date === date ? 'true' : 'false') + '" aria-label="' + d + ' de ' + MONTHS[m - 1] + (ok ? ', disponible' : ', sin horarios') + '"><span class="mo-booking__day-num">' + d + '</span>' + (ok ? '<span class="mo-booking__day-dot" aria-hidden="true"></span>' : '') + '</button>';
		}
		html += '</div></div></section><div class="mo-booking__side"><section class="mo-booking__panel mo-booking__slots-wrap"><h3 class="mo-booking__card-title">Elige un horario</h3>';
		var day = rs.date ? rs.days[rs.date] : null;
		if (day) {
			html += '<div class="mo-booking__slots">';
			day.slots.forEach(function (slot) {
				var sel = rs.slot === slot.start;
				html += '<button type="button" class="mo-booking__slot' + (slot.available ? '' : ' mo-booking__slot--off') + (sel ? ' mo-booking__slot--selected' : '') + '" data-rs-slot="' + esc(slot.start) + '"' + (slot.available ? '' : ' disabled') + ' aria-pressed="' + (sel ? 'true' : 'false') + '">' + esc(slot.time) + (slot.available ? '' : ' <span class="mo-booking__slot-state">' + esc(slot.label) + '</span>') + '</button>';
			});
			html += '</div><p class="mo-booking__note">Horario de ' + esc(b.timezone) + '.</p>';
		} else {
			html += '<p class="mo-booking__empty">' + M.icon('calendar') + '<span>Elige una fecha en el calendario para ver sus horarios.</span></p>';
		}
		html += '</section>';
		var p = rs.preview;
		if (p) {
			html += '<section class="mo-booking__panel mo-booking__rs-preview">' + '<h3 class="mo-booking__card-title">' + M.icon('receipt', 'mo-booking__card-icon') + '<span>Tu nueva fecha</span></h3>' +
				'<dl class="mo-booking__summary"><dt>Fecha</dt><dd>' + esc(p.date_txt) + '</dd><dt>Hora</dt><dd>' + esc(p.time_txt) + ' h</dd><dt>Total actual</dt><dd>' + esc(p.old_total_txt) + '</dd><dt>Nuevo total</dt><dd>' + esc(p.new_total_txt) + '</dd>' +
				(p.difference !== 0 ? '<dt>Diferencia</dt><dd>' + esc(p.difference_txt) + '</dd>' : '') + (p.due_now_txt ? '<dt>Por pagar para apartar</dt><dd>' + esc(p.due_now_txt) + '</dd>' : '') + '</dl>';
			if (p.difference === 0) { html += '<p class="mo-booking__note">El precio no cambia.</p>'; }
			if (p.difference < 0) { html += '<p class="mo-booking__note">La nueva fecha cuesta menos: la diferencia se descuenta de tu saldo.</p>'; }
			if (p.needs_accept) {
				html += '<label class="mo-booking__check"><input type="checkbox" data-rs="accept"> <span>Acepto la diferencia de ' + esc(p.difference_txt.replace('+', '')) + ' por la nueva fecha.</span></label><p class="mo-booking__field-error" data-rs-error role="alert"></p>';
			}
			html += '</section>';
		}
		html += '</div></div>';
		html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button mo-booking__button--back" data-rs="close">Cancelar</button><button type="button" class="mo-booking__button mo-booking__button--primary mo-booking__button--next" data-rs="confirm"' + (p ? '' : ' disabled') + '>Confirmar cambio</button></div>';
		this.root.innerHTML = html;
		this.bindReschedule();
	};

	Portal.prototype.bindReschedule = function () {
		var self = this;
		var rs = this.rs;
		var root = this.root;
		var on = function (sel, fn) { root.querySelectorAll(sel).forEach(function (el) { el.addEventListener('click', function () { fn(el); }); }); };
		on('[data-rs=prev], [data-rs=next]', function (el) {
			var m = rs.month + (el.getAttribute('data-rs') === 'next' ? 1 : -1);
			var y = rs.year;
			if (m < 1) { m = 12; y--; }
			if (m > 12) { m = 1; y++; }
			self.loadRsMonth(y, m);
		});
		on('[data-rs-date]', function (el) { rs.date = el.getAttribute('data-rs-date'); rs.slot = null; rs.preview = null; rs.error = ''; self.renderReschedule(); });
		on('[data-rs-slot]', function (el) {
			rs.slot = el.getAttribute('data-rs-slot');
			rs.preview = null; rs.error = '';
			self.renderReschedule();
			if (self.config && self.config.preview) {
				rs.preview = { date_txt: self.dateFromIso(rs.slot), time_txt: '', old_total_txt: '$1,400 MXN', new_total_txt: '$1,600 MXN', difference: 20000, difference_txt: '+$200 MXN', due_now_txt: '', needs_accept: true };
				self.renderReschedule();
				return;
			}
			self.api('portal/reschedule/preview', { body: { start: rs.slot }, csrf: self.csrf }).then(function (res) {
				if (!self.rs || rs.slot === null) { return; }
				if (!res.ok) { rs.error = M.errorMessage(res); rs.slot = null; } else { rs.preview = res.data; }
				self.renderReschedule();
				var target = root.querySelector('.mo-booking__rs-preview') || root.querySelector('.mo-booking__alert');
				if (target && target.scrollIntoView) { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			});
		});
		on('[data-rs=close]', function () { self.rs = null; self.render(); });
		on('[data-rs=confirm]', function (btn) {
			var accept = root.querySelector('[data-rs=accept]');
			if (rs.preview && rs.preview.needs_accept && !(accept && accept.checked)) {
				root.querySelector('[data-rs-error]').textContent = 'Para continuar, acepta la diferencia de precio.';
				accept.focus();
				return;
			}
			if (self.config && self.config.preview) { return; }
			btn.disabled = true; btn.textContent = 'Cambiando…';
			self.api('portal/reschedule', { body: { start: rs.slot, accept_difference: !!(accept && accept.checked) }, csrf: self.csrf }).then(function (res) {
				if (!res.ok) {
					rs.error = M.errorMessage(res); rs.preview = null; rs.slot = null;
					self.renderReschedule();
					return;
				}
				self.rs = null;
				self.view = res.data.booking;
				self.render(res.data.message);
				if (self.root.scrollIntoView) { self.root.scrollIntoView({ block: 'start' }); }
			});
		});
		var heading = root.querySelector('[data-rs-heading]');
		if (heading && !rs.focused) { rs.focused = true; heading.focus(); if (heading.scrollIntoView) { heading.scrollIntoView({ block: 'start' }); } }
	};

	Portal.prototype.dateFromIso = function (iso) {
		var p = String(iso).slice(0, 10).split('-');
		return (+p[2]) + ' de ' + MONTHS[+p[1] - 1] + ' de ' + p[0];
	};

	Portal.prototype.bind = function () {
		var self = this;
		var rsOpen = this.root.querySelector('[data-action=reschedule-open]');
		if (rsOpen) { rsOpen.addEventListener('click', function () { self.openPolicy(); }); }
		M.bindUpload(this.root, this.api, function () { return self.csrf; }, this.view.max_mb, function (data) {
			self.view = data.booking;
			// beta.4: el aviso de comprobante recibido va dentro del mismo recuadro.
			self.render('', data.message);
			var box = self.root.querySelector('.mo-booking__upload-wrap');
			if (box && box.scrollIntoView) { box.scrollIntoView({ block: 'center' }); }
		});
		this.root.querySelectorAll('[data-copy]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var text = btn.getAttribute('data-copy');
				var label = btn.querySelector('span');
				var done = function () { if (label) { label.textContent = 'Copiado'; window.setTimeout(function () { label.textContent = 'Copiar'; }, 2000); } };
				if (window.navigator.clipboard) { window.navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copia el texto:', text); }); } else { window.prompt('Copia el texto:', text); }
			});
		});
		var logout = this.root.querySelector('[data-action=logout]');
		if (logout) {
			logout.addEventListener('click', function () {
				self.api('portal/logout', { body: {}, csrf: self.csrf }).then(function () {
					self.root.innerHTML = '<div class="mo-booking__panel"><p class="mo-booking__success" role="status">Cerraste la sesión. Para volver, abre el enlace de tu reserva.</p></div>';
				});
			});
		}
	};

	function init() {
		document.querySelectorAll('[data-mo-portal]').forEach(function (root) {
			if (root.getAttribute('data-mo-ready')) { return; }
			root.setAttribute('data-mo-ready', '1');
			var config = {};
			try { config = JSON.parse(root.getAttribute('data-mo-portal')); } catch (e) { return; }
			new Portal(root, config).start();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	// Editor de Elementor: el widget se dibuja después de cargar la página.
	window.addEventListener('elementor/frontend/init', function () {
		if (window.elementorFrontend && window.elementorFrontend.hooks) {
			window.elementorFrontend.hooks.addAction('frontend/element_ready/mo-booking-portal.default', init);
		}
	});
})(window, document);
