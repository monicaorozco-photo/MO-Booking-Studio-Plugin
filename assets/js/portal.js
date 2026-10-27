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
			print_url: '#', whatsapp_url: '#', change_url: '#'
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
		html += '<p class="mo-booking__brand">' + esc(b.brand) + ' · Consulta de reserva</p>';
		html += '<div aria-live="polite" role="status" class="mo-booking__live">' + (message ? '<p class="mo-booking__success">' + esc(message) + '</p>' : '') + '</div>';

		// Tarjeta principal: servicio, estado y lo que falta por pagar.
		html += '<section class="mo-booking__panel mo-booking__hero"><div class="mo-booking__hero-main">';
		html += '<h2 class="mo-booking__title" tabindex="-1">' + esc(b.service) + '</h2>';
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

	Portal.prototype.bind = function () {
		var self = this;
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
