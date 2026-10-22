/* MO Booking Studio: portal privado del cliente. El enlace llega como #acceso=TOKEN. */
(function (window, document) {
	'use strict';

	var M = window.MOBooking;
	var esc = M.esc;

	function Portal(root, config) {
		this.root = root;
		this.api = function (path, options) { return M.api(config.api, path, options); };
		this.csrf = null;
		this.view = null;
	}

	Portal.prototype.start = function () {
		var self = this;
		var match = /[#&]acceso=([A-Za-z0-9_-]{43})/.exec(window.location.hash || '');
		if (match) {
			// Quitar el token de la barra de direcciones y del historial.
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', window.location.pathname + window.location.search);
			}
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

	Portal.prototype.render = function (message) {
		var b = this.view;
		var html = '<div class="mo-booking__panel">';
		html += '<p class="mo-booking__brand">' + esc(b.brand) + '</p>';
		html += '<h2 class="mo-booking__title" tabindex="-1">' + esc(b.service) + '</h2>';
		html += '<div aria-live="polite" role="status" class="mo-booking__live">' + (message ? '<p class="mo-booking__success">' + esc(message) + '</p>' : '') + '</div>';
		html += '<p class="mo-booking__ref">Reserva <strong>' + esc(b.reference) + '</strong> · <span class="mo-booking__pill mo-booking__pill--' + esc(b.status) + '">' + esc(b.status_label) + '</span></p>';
		html += '<p class="mo-booking__status">' + esc(b.status_help) + '</p>';
		if (b.correction) {
			html += '<div class="mo-booking__alert"><strong>Qué necesitamos:</strong> ' + M.lines(b.correction.reason) + (b.correction.until_txt ? '<br>Plazo: ' + esc(b.correction.until_txt) + ' (' + esc(b.timezone) + ')' : '') + '</div>';
		}
		if (b.expires_txt) {
			html += '<p class="mo-booking__deadline"><strong>Paga el anticipo de ' + esc(b.deposit_txt) + ' antes del ' + esc(b.expires_txt) + '</strong> (' + esc(b.timezone) + ').</p>';
		}
		html += '<dl class="mo-booking__summary">';
		html += '<dt>Nombre</dt><dd>' + esc(b.name) + '</dd>';
		html += '<dt>Fecha</dt><dd>' + esc(b.date_txt) + '</dd>';
		html += '<dt>Hora</dt><dd>' + esc(b.time_txt) + ' h (' + esc(b.timezone) + ')</dd>';
		if (b.location) { html += '<dt>Lugar</dt><dd>' + esc(b.location) + '</dd>'; }
		html += '<dt>Personas</dt><dd>' + esc(b.participants) + (b.pets ? ' y ' + esc(b.pets) + ' mascota(s)' : '') + '</dd>';
		html += '</dl>';
		(b.notices || []).forEach(function (n) { html += '<p class="mo-booking__notice">' + esc(n) + '</p>'; });
		if (b.instructions) { html += '<div class="mo-booking__instructions"><h3>Indicaciones</h3><p>' + M.lines(b.instructions) + '</p></div>'; }
		if (b.delivery_text) { html += '<p class="mo-booking__note">' + esc(b.delivery_text) + '</p>'; }

		html += '<h3>Pagos</h3><table class="mo-booking__lines"><tbody>';
		b.lines.forEach(function (l) { html += '<tr><th scope="row">' + esc(l.label) + '</th><td>' + esc(l.amount_txt) + '</td></tr>'; });
		html += '</tbody><tfoot><tr><th scope="row">Total</th><td>' + esc(b.total_txt) + '</td></tr>';
		html += '<tr><th scope="row">Pagado y verificado</th><td>' + esc(b.paid_txt) + '</td></tr>';
		html += '<tr><th scope="row">Saldo</th><td>' + esc(b.balance_txt) + '</td></tr></tfoot></table>';
		if (b.deposit_missing_txt && ['pending_payment', 'correction_requested', 'payment_review'].indexOf(b.status) !== -1) {
			html += '<p class="mo-booking__note">Anticipo pendiente de verificar: ' + esc(b.deposit_missing_txt) + '.</p>';
		}
		if (b.refund_due_txt) { html += '<p class="mo-booking__note">Devolución pendiente a tu favor: ' + esc(b.refund_due_txt) + '. Te contactaremos para realizarla.</p>'; }
		if (b.credit_txt) { html += '<p class="mo-booking__note">Saldo a tu favor: ' + esc(b.credit_txt) + '.</p>'; }
		if (b.frequent_review) { html += '<p class="mo-booking__note">Estamos revisando si aplica tu tarifa de cliente frecuente.</p>'; }

		if (b.bank) {
			html += '<div class="mo-booking__bank"><h3>Datos para transferencia</h3><p>' + M.lines(b.bank) + '</p>' + (b.payment_ref ? '<p>' + esc(b.payment_ref) + ' Referencia: <strong>' + esc(b.reference) + '</strong></p>' : '') + '</div>';
		}
		if (b.proofs.length) {
			html += '<h3>Comprobantes enviados</h3><ul class="mo-booking__proofs">';
			b.proofs.forEach(function (p) { html += '<li>' + esc(p.date) + ': ' + esc(p.status) + (p.note ? ' — ' + esc(p.note) : '') + '</li>'; });
			html += '</ul>';
		}
		html += '<div class="mo-booking__upload-wrap">' + (b.can_upload ? '<h3>Enviar comprobante</h3>' : '') + M.uploadForm(b, 'mo-portal') + '</div>';
		if (b.policies.length) {
			html += '<details class="mo-booking__policies"><summary>Políticas que aceptaste</summary>';
			b.policies.forEach(function (p) { html += '<h4>' + esc(p.title) + '</h4><p>' + M.lines(p.summary) + '</p>'; });
			html += '<p class="mo-booking__note">Para cambiar o cancelar tu cita, escríbenos; te mostraremos las consecuencias antes de aplicar cualquier cambio.</p></details>';
		}
		html += '<div class="mo-booking__actions">';
		if (b.whatsapp_url) { html += '<a class="mo-booking__button" href="' + esc(b.whatsapp_url) + '" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a>'; }
		if (b.change_url) { html += ' <a class="mo-booking__button" href="' + esc(b.change_url) + '" target="_blank" rel="noopener noreferrer">Pedir cambio o cancelación por WhatsApp</a>'; }
		html += '<button type="button" class="mo-booking__button" data-action="logout">Cerrar sesión</button></div>';
		html += '</div>';
		this.root.innerHTML = html;
		this.bind();
	};

	Portal.prototype.bind = function () {
		var self = this;
		M.bindUpload(this.root, this.api, function () { return self.csrf; }, this.view.max_mb, function (data) {
			self.view = data.booking;
			self.render(data.message);
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
})(window, document);
