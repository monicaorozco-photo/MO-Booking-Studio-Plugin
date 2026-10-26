/* MO Booking Studio: formulario público de reserva. El servidor calcula precios y disponibilidad. */
(function (window, document) {
	'use strict';

	var M = window.MOBooking;
	var esc = M.esc;
	var WEEKDAYS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
	var WEEKDAY_NAMES = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
	var MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

	var TAG_ICON = '<svg class="mo-booking__tag" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.6 12.6l-8 8a2 2 0 0 1-2.8 0l-6.4-6.4a2 2 0 0 1-.6-1.4V4.8C2.8 3.8 3.6 3 4.6 3h8c.5 0 1 .2 1.4.6l6.6 6.2a2 2 0 0 1 0 2.8z" fill="currentColor"/><circle cx="7.5" cy="7.5" r="1.6" fill="#fff"/><path d="M9.5 15.5l5-5" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="11" r="1" fill="#fff"/><circle cx="14" cy="15" r="1" fill="#fff"/></svg>';
	var PRICE_TYPE = { presale: 'Preventa', regular: 'Precio regular' };

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
	function daysInMonth(y, m) { return new Date(Date.UTC(y, m, 0)).getUTCDate(); }

	function previewData() {
		var now = new Date();
		var y = now.getFullYear();
		var m = now.getMonth() + 1;
		var days = [];
		for (var d = 1; d <= daysInMonth(y, m); d++) {
			var open = d % 3 !== 0;
			days.push({
				date: ymd(y, m, d), open: open, available: open ? (d % 5 === 0 ? 0 : 4) : 0, total: open ? 4 : 0,
				status: !open ? 'closed' : (d % 5 === 0 ? 'sold_out' : 'available'),
				price_txt: open ? '$1,400 MXN' : null, special: d % 4 === 1, saving_txt: '$200 MXN', notices: [],
				slots: open ? ['10:00', '10:30', '11:00', '11:30'].map(function (t, i) {
					return { start: '', time: t, available: d % 5 !== 0 && i !== 1, label: (d % 5 === 0 || i === 1) ? 'Agotado' : 'Disponible' };
				}) : []
			});
		}
		return {
			service: {
				title: 'Vista previa del servicio', brand: 'Tu marca', description: 'Esta es una vista de demostración: no crea reservas ni envía mensajes.',
				duration: 20, participants_included: 6, participants_max: 10, extra_participant_txt: '$100 MXN', pets_allowed: true, pet_friendly: true, fast_delivery: { label: 'Entrega rápida', price_txt: '$300 MXN', question: '¿Deseas agregar entrega rápida de 2 días hábiles a tu sesión?', regular_text: 'Entrega regular de 5 a 7 días hábiles.' },
				contract: { title: 'Contrato de servicio (demostración)', version: 1, body: '<p>Texto del contrato.</p>' },
				extras: [], questions: [], deposit_txt: 'Anticipo de $500 MXN para apartar tu horario.', hold_hours: 12, timezone: 'America/Ciudad_Juarez', policies: [], terms_text: ''
			},
			month: { year: y, month: m },
			days: days
		};
	}

	function App(root, config) {
		this.root = root;
		this.config = config;
		this.api = function (path, options) { return M.api(config.api, path, options); };
		this.state = {
			step: 0, welcome: 'choose', frequentToken: null, locked: {}, identify: { name: '', email: '', phone: '', phone_country: 'MX' }, identifyMessage: '', service: null, year: null, month: null, days: {}, selectedDate: null, selectedSlot: null,
			form: { children: '', adults: '', pets: '', important_info: '', extras: {}, answers: {}, fast_delivery: false, accept_contract: false, name: '', email: '', phone: '', phone_country: 'MX', whatsapp_consent: false, frequent_claim: false },
			quote: null, idempotencyKey: null, result: null, csrf: null, message: '', busy: false
		};
	}

	App.prototype.start = function () {
		var self = this;
		if (this.config.preview) {
			var p = previewData();
			this.state.service = p.service;
			this.state.year = p.month.year;
			this.state.month = p.month.month;
			this.state.days = {};
			p.days.forEach(function (d) { self.state.days[d.date] = d; });
			this.state.monthLoaded = true;
			this.render();
			return;
		}
		this.api('services/' + encodeURIComponent(this.config.service)).then(function (res) {
			if (!res.ok) {
				self.root.innerHTML = '<p class="mo-booking__alert" role="alert">' + esc(M.errorMessage(res)) + '</p>';
				return;
			}
			self.state.service = res.data;
			self.render();
		});
	};

	/** Empieza el calendario con los precios del tipo de cliente elegido. */
	App.prototype.openCalendar = function () {
		var s = this.state;
		s.step = 1;
		s.days = {};
		s.selectedDate = null;
		s.selectedSlot = null;
		if (this.config.preview) { this.render(); return; }
		var now = new Date();
		this.findFirstMonth(now.getFullYear(), now.getMonth() + 1, 0);
	};

	/** Busca el primer mes con fechas abiertas (hasta 12 meses). */
	App.prototype.findFirstMonth = function (y, m, tries) {
		var self = this;
		this.loadMonth(y, m).then(function (hasOpen) {
			if (!hasOpen && tries < 11) {
				var next = m === 12 ? { y: y + 1, m: 1 } : { y: y, m: m + 1 };
				self.findFirstMonth(next.y, next.m, tries + 1);
				return;
			}
			if (!hasOpen) {
				var now = new Date();
				self.state.year = now.getFullYear();
				self.state.month = now.getMonth() + 1;
				self.loadMonth(self.state.year, self.state.month);
			}
		});
	};

	App.prototype.loadMonth = function (y, m) {
		var self = this;
		this.state.year = y;
		this.state.month = m;
		this.state.busy = true;
		this.render();
		var from = ymd(y, m, 1);
		var to = ymd(y, m, daysInMonth(y, m));
		return this.api('services/' + encodeURIComponent(this.config.service) + '/availability?from=' + from + '&to=' + to, { frequent: this.state.frequentToken }).then(function (res) {
			self.state.busy = false;
			if (!res.ok) {
				self.state.message = M.errorMessage(res); self.state.messageOk = false;
				self.render();
				return false;
			}
			var hasOpen = false;
			res.data.days.forEach(function (d) {
				self.state.days[d.date] = d;
				if (d.open) { hasOpen = true; }
			});
			self.state.availabilityNote = res.data.note;
			self.state.presaleText = res.data.presale_txt;
			self.state.category = res.data.category;
			self.render();
			return hasOpen;
		});
	};

	App.prototype.setStep = function (step) {
		this.state.step = step;
		this.state.message = '';
		this.state.messageOk = false;
		this.render();
		var heading = this.root.querySelector('[data-step-heading]');
		if (heading) {
			heading.focus();
			if (heading.scrollIntoView) { heading.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
		}
	};

	App.prototype.render = function () {
		var s = this.state;
		if (!s.service) {
			return;
		}
		var html = '';
		if (this.config.preview) {
			html += '<p class="mo-booking__preview-badge">Vista previa de demostración: no crea reservas.</p>';
		}
		html += this.renderHeader();
		if (s.step > 0) { html += this.renderSteps(); }
		html += '<div class="mo-booking__live" aria-live="polite" role="status">' + (s.message ? '<p class="' + (s.messageOk ? 'mo-booking__success' : 'mo-booking__alert') + '">' + esc(s.message) + '</p>' : '') + '</div>';
		if (s.step === 0) { html += this.renderWelcome(); }
		if (s.step === 1) { html += this.renderCalendar(); }
		if (s.step === 2) { html += this.renderDetails(); }
		if (s.step === 3) { html += this.renderSummary(); }
		if (s.step === 4) { html += this.renderDone(); }
		this.root.innerHTML = html;
		this.bind();
	};

	App.prototype.renderHeader = function () {
		var svc = this.state.service;
		var html = '<header class="mo-booking__header">';
		if (svc.brand) { html += '<p class="mo-booking__brand">' + esc(svc.brand) + '</p>'; }
		html += '<h2 class="mo-booking__title">' + esc(svc.title) + '</h2>';
		if (this.config.intro) { html += '<p class="mo-booking__intro">' + M.lines(this.config.intro) + '</p>'; }
		if (this.state.step <= 1 && this.config.showDescription) {
			if (svc.image) { html += '<img class="mo-booking__image" src="' + esc(svc.image) + '" alt="" loading="lazy">'; }
			if (svc.description) { html += '<div class="mo-booking__description">' + M.lines(svc.description) + '</div>'; }
			html += '<ul class="mo-booking__facts">';
			html += '<li>Duración: ' + esc(svc.duration) + ' minutos</li>';
			html += '<li>Incluye hasta ' + esc(svc.participants_included) + ' ' + (svc.participants_included === 1 ? 'persona' : 'personas') +
				(svc.participants_max > svc.participants_included ? '; ' + esc(svc.extra_participant_txt) + ' por persona adicional, máximo ' + esc(svc.participants_max) : '') + '</li>';
			if (svc.pet_friendly) { html += '<li>Pet friendly</li>'; }
			if (svc.location) { html += '<li>Lugar: ' + esc(svc.location) + '</li>'; }
			if (svc.delivery_text) { html += '<li>' + esc(svc.delivery_text) + '</li>'; }
			if (svc.deposit_txt) { html += '<li>' + esc(svc.deposit_txt) + '</li>'; }
			html += '</ul>';
		}
		html += '</header>';
		return html;
	};

	App.prototype.renderSteps = function () {
		var labels = ['Fecha y hora', 'Tus datos', 'Resumen', 'Listo'];
		var s = Math.max(1, this.state.step);
		return '<ol class="mo-booking__steps">' + labels.map(function (l, i) {
			var n = i + 1;
			var state = n < s ? 'done' : (n === s ? 'current' : 'todo');
			return '<li class="mo-booking__step mo-booking__step--' + state + '"' + (n === s ? ' aria-current="step"' : '') + '><span class="mo-booking__step-num">' + n + '</span> ' + esc(l) + '</li>';
		}).join('') + '</ol>';
	};

	App.prototype.renderWelcome = function () {
		var s = this.state;
		var w = this.config.welcome || {};
		// Textos de Elementor o del shortcode. Títulos y botones nunca quedan vacíos; los subtítulos sí pueden ocultarse.
		var t = function (key, fallback, optional) { var v = typeof w[key] === 'string' ? w[key] : fallback; return v || optional ? v : fallback; };
		var sub = function (text) { return text ? '<span class="mo-booking__choice-text">' + esc(text) + '</span>' : ''; };
		var html = '<section class="mo-booking__panel mo-booking__welcome" aria-labelledby="mo-step-0">';
		if (s.welcome === 'choose') {
			html += '<h3 class="mo-booking__panel-title" id="mo-step-0" tabindex="-1" data-step-heading>' + esc(t('title', 'Antes de ver las fechas')) + '</h3>';
			var question = t('question', '¿Ya te has tomado fotos con nosotros?', true);
			if (question) { html += '<p>' + esc(question) + '</p>'; }
			html += '<div class="mo-booking__choices">';
			html += '<button type="button" class="mo-booking__choice" data-action="new-client"><span class="mo-booking__choice-title">' + esc(t('new_title', 'Soy cliente nuevo')) + '</span>' + sub(t('new_text', 'Ver fechas y precios', true)) + '</button>';
			html += '<button type="button" class="mo-booking__choice" data-action="returning"><span class="mo-booking__choice-title">' + esc(t('returning_title', 'Ya me he tomado fotos con ustedes')) + '</span>' + sub(t('returning_text', 'Revisamos si aplica tu precio de cliente frecuente', true)) + '</button>';
			html += '</div>';
		} else if (s.welcome === 'identify') {
			var id = s.identify;
			html += '<form data-form="identify" novalidate>';
			html += '<h3 class="mo-booking__panel-title" id="mo-step-0" tabindex="-1" data-step-heading>' + esc(t('identify_title', '¡Qué gusto verte de nuevo!')) + '</h3>';
			var identifyText = t('identify_text', 'Escribe tu nombre y el correo y teléfono con los que reservaste antes.', true);
			if (identifyText) { html += '<p>' + esc(identifyText) + '</p>'; }
			html += this.field('id-name', 'Nombre y apellido:', '<input class="mo-booking__input" type="text" id="mo-f-id-name" name="id-name" autocomplete="name" value="' + esc(id.name) + '" required>');
			html += this.field('id-email', 'Correo electrónico:', '<input class="mo-booking__input" type="email" id="mo-f-id-email" name="id-email" autocomplete="email" value="' + esc(id.email) + '">');
			html += '<div class="mo-booking__field"><label class="mo-booking__label" for="mo-f-id-phone">Teléfono (WhatsApp):</label><div class="mo-booking__phone">' +
				'<select class="mo-booking__input mo-booking__country" name="id-phone_country" aria-label="País del teléfono"><option value="MX"' + (id.phone_country === 'MX' ? ' selected' : '') + '>México +52</option><option value="US"' + (id.phone_country === 'US' ? ' selected' : '') + '>EE. UU. +1</option></select>' +
				'<input class="mo-booking__input" type="tel" id="mo-f-id-phone" name="id-phone" autocomplete="tel-national" inputmode="tel" value="' + esc(id.phone) + '"></div>' +
				'<p class="mo-booking__help">Con que coincida el correo o el teléfono es suficiente; escribe los dos si puedes.</p><p class="mo-booking__field-error" data-error-for="id-phone" role="alert"></p></div>';
			html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button" data-action="welcome-back">Atrás</button><button type="submit" class="mo-booking__button mo-booking__button--primary">Revisar</button></div>';
			html += '</form>';
		} else if (s.welcome === 'not-found') {
			html += '<h3 class="mo-booking__panel-title" id="mo-step-0" tabindex="-1" data-step-heading>No encontramos tu tarifa de cliente frecuente</h3>';
			html += '<p>' + esc(s.identifyMessage) + '</p>';
			html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button" data-action="returning">Intentar con otros datos</button><button type="button" class="mo-booking__button mo-booking__button--primary" data-action="continue-general">Continuar</button></div>';
		}
		html += '</section>';
		return html;
	};

	App.prototype.submitIdentify = function (form) {
		var self = this;
		var s = this.state;
		var data = new window.FormData(form);
		var str = function (k) { var v = data.get(k); return v === null ? '' : String(v).trim(); };
		s.identify = { name: str('id-name').replace(/\s+/g, ' '), email: str('id-email'), phone: str('id-phone'), phone_country: str('id-phone_country') || 'MX' };
		var id = s.identify;
		if (id.name.length < 2) { this.showFieldError('id-name', 'Escribe tu nombre y apellido.'); return; }
		if (!id.email && !id.phone) { this.showFieldError('id-email', 'Escribe tu correo o tu teléfono (mejor los dos).'); return; }
		if (id.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(id.email)) { this.showFieldError('id-email', 'Revisa tu correo.'); return; }
		if (id.phone && id.phone.replace(/\D/g, '').length < 10) { this.showFieldError('id-phone', 'Escribe tu teléfono a 10 dígitos.'); return; }
		// Lo que escribió aquí se usa en "Tus datos" para no pedirlo dos veces.
		s.form.name = id.name; s.form.email = id.email; s.form.phone = id.phone; s.form.phone_country = id.phone_country;
		if (this.config.preview) { s.frequentToken = null; s.form.frequent_claim = true; this.openCalendar(); return; }
		var button = form.querySelector('button[type=submit]');
		button.disabled = true; button.textContent = 'Revisando…';
		this.api('services/' + encodeURIComponent(this.config.service) + '/identify', {
			body: { name: id.name, email: id.email, phone: id.phone, phone_country: id.phone_country }
		}).then(function (res) {
			button.disabled = false; button.textContent = 'Revisar';
			if (!res.ok) {
				var field = M.errorField(res);
				var map = { name: 'id-name', email: 'id-email', phone: 'id-phone', contact: 'id-email' };
				if (!field || !self.showFieldError(map[field] || field, M.errorMessage(res))) {
					s.message = M.errorMessage(res); s.messageOk = false; self.render();
				}
				return;
			}
			s.form.frequent_claim = true;
			if (res.data.frequent) {
				s.frequentToken = res.data.token;
				s.locked = res.data.locked || {};
				self.openCalendar();
				s.message = res.data.message; s.messageOk = true;
				return;
			}
			s.frequentToken = null;
			s.locked = {};
			s.identifyMessage = res.data.message;
			s.welcome = 'not-found';
			self.setStep(0);
		});
	};

	App.prototype.renderCalendar = function () {
		var s = this.state;
		var y = s.year;
		var m = s.month;
		var now = new Date();
		var canPrev = (y > now.getFullYear()) || (y === now.getFullYear() && m > now.getMonth() + 1);
		var html = '<section class="mo-booking__panel" aria-labelledby="mo-step-1">';
		html += '<h3 class="mo-booking__panel-title" id="mo-step-1" tabindex="-1" data-step-heading>Elige fecha y hora</h3>';
		html += '<p class="mo-booking__who">' + (s.frequentToken ? '<span class="mo-booking__who-badge">Precios de cliente frecuente</span>' : '<span class="mo-booking__who-badge mo-booking__who-badge--general">Precios para público general</span>' + (s.form.frequent_claim ? ' <span class="mo-booking__who-note">Revisaremos tu tarifa de cliente frecuente antes de confirmar.</span>' : '')) +
			' <button type="button" class="mo-booking__link" data-action="welcome-back">Cambiar</button></p>';
		if (s.presaleText) {
			html += '<p class="mo-booking__presale">' + esc(s.presaleText) + '</p>';
		}
		html += '<div class="mo-booking__month">';
		html += '<button type="button" class="mo-booking__nav" data-action="prev"' + (canPrev ? '' : ' disabled') + ' aria-label="Mes anterior">‹</button>';
		html += '<p class="mo-booking__month-name" aria-live="polite">' + esc(MONTHS[m - 1]) + ' ' + y + '</p>';
		html += '<button type="button" class="mo-booking__nav" data-action="next" aria-label="Mes siguiente">›</button>';
		html += '</div>';
		if (s.busy) {
			html += '<p class="mo-booking__loading" role="status">Cargando disponibilidad…</p>';
		}
		html += '<div class="mo-booking__calendar" role="grid" aria-label="Calendario de ' + esc(MONTHS[m - 1]) + '">';
		html += '<div class="mo-booking__weekdays" role="row">' + WEEKDAYS.map(function (d, i) { return '<span role="columnheader" aria-label="' + WEEKDAY_NAMES[i] + '">' + d + '</span>'; }).join('') + '</div>';
		html += '<div class="mo-booking__days" role="row">';
		var first = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
		var offset = (first + 6) % 7;
		for (var i = 0; i < offset; i++) { html += '<span class="mo-booking__day mo-booking__day--blank" aria-hidden="true"></span>'; }
		for (var d = 1; d <= daysInMonth(y, m); d++) {
			var date = ymd(y, m, d);
			var info = s.days[date];
			var cls = 'mo-booking__day';
			var label = d + ' de ' + MONTHS[m - 1];
			var extra = '';
			var disabled = true;
			if (info && info.status === 'available') {
				disabled = false;
				cls += ' mo-booking__day--available mo-booking__day--' + (info.price_type === 'presale' ? 'presale' : 'regular');
				if (info.special) { extra = TAG_ICON; cls += ' mo-booking__day--special'; }
				label += ', disponible' + (info.price_type ? ', ' + PRICE_TYPE[info.price_type] : '') + (info.price_txt ? ' ' + info.price_txt : '') + (info.special ? ', precio especial por fecha' : '');
			} else if (info && info.status === 'sold_out') {
				cls += ' mo-booking__day--soldout';
				extra = '<span class="mo-booking__soldout-mark" aria-hidden="true">✕</span>';
				label += ', ' + String(this.text('slot_taken', 'No disponible')).toLowerCase();
			} else {
				cls += ' mo-booking__day--closed';
				label += ', sin horarios';
			}
			if (s.selectedDate === date) { cls += ' mo-booking__day--selected'; }
			html += '<button type="button" role="gridcell" class="' + cls + '" data-date="' + date + '"' + (disabled ? ' disabled' : '') +
				' aria-label="' + esc(label) + '" aria-pressed="' + (s.selectedDate === date ? 'true' : 'false') + '"><span class="mo-booking__day-num">' + d + '</span>' + extra + '</button>';
		}
		html += '</div></div>';
		html += this.renderLegend();
		if (s.availabilityNote) { html += '<p class="mo-booking__note">' + esc(s.availabilityNote) + '</p>'; }

		if (s.selectedDate && s.days[s.selectedDate]) {
			var day = s.days[s.selectedDate];
			html += '<div class="mo-booking__slots-wrap"><h4 class="mo-booking__subtitle">Horarios del ' + esc(this.dateLabel(s.selectedDate)) + '</h4>';
			html += '<p class="mo-booking__note">Horario de ' + esc(this.state.service.timezone) + '.</p>';
			if (day.price_txt) {
				html += '<p class="mo-booking__day-detail">' + (day.special ? TAG_ICON + ' ' : '') + 'Precio para esta fecha: <strong>' + esc(day.price_txt) + '</strong>' +
					(day.price_type ? ' · ' + esc(PRICE_TYPE[day.price_type]) : '') + (day.special ? ' · Precio especial por fecha' : '') + '</p>';
			}
			(day.notices || []).forEach(function (n) { html += '<p class="mo-booking__notice">' + esc(n) + '</p>'; });
			html += '<div class="mo-booking__slots">';
			day.slots.forEach(function (slot) {
				var selected = s.selectedSlot && s.selectedSlot.start === slot.start && s.selectedSlot.time === slot.time;
				html += '<button type="button" class="mo-booking__slot' + (slot.available ? '' : ' mo-booking__slot--off') + (selected ? ' mo-booking__slot--selected' : '') + '" data-slot="' + esc(slot.start) + '" data-time="' + esc(slot.time) + '"' +
					(slot.available ? '' : ' disabled') + ' aria-pressed="' + (selected ? 'true' : 'false') + '">' + esc(slot.time) + (slot.available ? '' : ' <span class="mo-booking__slot-state">' + esc(slot.label) + '</span>') + '</button>';
			});
			html += '</div></div>';
		}
		html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button mo-booking__button--primary" data-action="to-details"' + (s.selectedSlot ? '' : ' disabled') + '>Continuar</button></div>';
		html += '</section>';
		return html;
	};

	/** Leyenda: solo muestra los tipos presentes en el mes. */
	App.prototype.renderLegend = function () {
		var s = this.state;
		var has = { presale: false, regular: false, special: false, soldout: false };
		var prefix = ymd(s.year, s.month, 1).slice(0, 7);
		Object.keys(s.days).forEach(function (date) {
			var d = s.days[date];
			if (date.slice(0, 7) !== prefix) { return; }
			if (d.status === 'available') {
				has[d.price_type === 'presale' ? 'presale' : 'regular'] = true;
				if (d.special) { has.special = true; }
			}
			if (d.status === 'sold_out') { has.soldout = true; }
		});
		var items = [];
		if (has.presale) { items.push('<li><span class="mo-booking__swatch mo-booking__swatch--presale" aria-hidden="true"></span> Precio de preventa</li>'); }
		if (has.regular) { items.push('<li><span class="mo-booking__swatch mo-booking__swatch--regular" aria-hidden="true"></span> ' + (has.presale ? 'Precio regular' : 'Disponible') + '</li>'); }
		if (has.special) { items.push('<li>' + TAG_ICON + ' Precio especial por fecha</li>'); }
		if (has.soldout) { items.push('<li><span class="mo-booking__swatch mo-booking__swatch--soldout" aria-hidden="true">✕</span> ' + esc(this.text('slot_taken', 'No disponible')) + '</li>'); }
		return items.length ? '<ul class="mo-booking__legend" aria-label="Significado de los colores">' + items.join('') + '</ul>' : '';
	};

	App.prototype.dateLabel = function (date) {
		var parts = date.split('-');
		var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
		return WEEKDAY_NAMES[(d.getUTCDay() + 6) % 7] + ' ' + (+parts[2]) + ' de ' + MONTHS[+parts[1] - 1];
	};

	/** Texto configurable que manda el servidor con el servicio (Ajustes), con valor de respaldo. */
	App.prototype.text = function (key, fallback) {
		var t = (this.state.service && this.state.service.texts) || {};
		return Object.prototype.hasOwnProperty.call(t, key) ? t[key] : fallback;
	};

	App.prototype.field = function (name, label, input, help) {
		return '<div class="mo-booking__field"><label class="mo-booking__label" for="mo-f-' + name + '">' + label + '</label>' + input +
			(help ? '<p class="mo-booking__help">' + help + '</p>' : '') + '<p class="mo-booking__field-error" data-error-for="' + name + '" role="alert"></p></div>';
	};

	App.prototype.renderDetails = function () {
		var s = this.state;
		var svc = s.service;
		var f = s.form;
		var self = this;
		var html = '<form class="mo-booking__panel" data-form="details" novalidate aria-labelledby="mo-step-2">';
		html += '<h3 class="mo-booking__panel-title" id="mo-step-2" tabindex="-1" data-step-heading>Tus datos</h3>';
		// 6.3, campo 1: fecha elegida y "Cambiar" (vuelve al calendario sin perder lo capturado).
		html += '<p class="mo-booking__selected">Fecha de sesión: <strong>' + esc(this.dateLabel(s.selectedDate)) + ', ' + esc(s.selectedSlot.time) + ' h</strong> - <button type="button" class="mo-booking__link" data-action="to-calendar">Cambiar</button></p>';
		// Campo 2: número de asistentes = niños + adultos (calculado, sin valor predeterminado).
		html += '<p class="mo-booking__total-people" aria-live="polite">Número de asistentes: <output name="attendees" data-attendees>' + esc(this.attendees()) + '</output></p>';
		var num = function (name, label, value, extra) {
			return self.field(name, label, '<input class="mo-booking__input" type="number" inputmode="numeric" pattern="[0-9]*" step="1" min="0" id="mo-f-' + name + '" name="' + name + '" value="' + esc(value) + '"' + (extra || '') + '>');
		};
		html += num('children', 'Niños', f.children);
		html += num('adults', 'Adultos', f.adults);
		if (svc.pets_allowed) {
			html += num('pets', 'Mascotas', f.pets, svc.pets_max ? ' max="' + svc.pets_max + '"' : '');
		}
		html += this.field('important_info', 'Alguna información importante que debamos saber antes de tu sesión:', '<textarea class="mo-booking__input" id="mo-f-important_info" name="important_info" rows="4" maxlength="2000">' + esc(f.important_info) + '</textarea>', '(Opcional. Máximo 2,000 caracteres.)');
		html += this.field('name', 'Nombre y apellido:', '<input class="mo-booking__input" type="text" id="mo-f-name" name="name" autocomplete="name" value="' + esc(f.name) + '" required>');
		html += this.field('email', 'Correo electrónico:', '<input class="mo-booking__input" type="email" id="mo-f-email" name="email" autocomplete="email" value="' + esc(f.email) + '" required' + (s.frequentToken && s.locked.email ? ' readonly' : '') + '>', s.frequentToken && s.locked.email ? 'Es el correo con el que reconocimos tu tarifa de cliente frecuente; para usar otro, vuelve al inicio.' : 'Aquí te enviaremos la confirmación y tu enlace privado.');
		html += '<div class="mo-booking__field"><label class="mo-booking__label" for="mo-f-phone">Teléfono (WhatsApp):</label><div class="mo-booking__phone">' +
			'<select class="mo-booking__input mo-booking__country" name="phone_country" aria-label="País del teléfono"><option value="MX"' + (f.phone_country === 'MX' ? ' selected' : '') + '>México +52</option><option value="US"' + (f.phone_country === 'US' ? ' selected' : '') + '>EE. UU. +1</option></select>' +
			'<input class="mo-booking__input" type="tel" id="mo-f-phone" name="phone" autocomplete="tel-national" inputmode="tel" value="' + esc(f.phone) + '" required aria-describedby="mo-help-phone"' + (s.frequentToken && s.locked.phone ? ' readonly' : '') + '></div>' +
			'<p class="mo-booking__help" id="mo-help-phone">' + (s.frequentToken && s.locked.phone ? 'Es el teléfono con el que reconocimos tu tarifa de cliente frecuente; para usar otro, vuelve al inicio.' : '10 dígitos. Si es de otro país, escribe el número completo con "+".') + '</p><p class="mo-booking__field-error" data-error-for="phone" role="alert"></p></div>';
		// 6.4: preguntas adicionales después de los datos principales y antes de entrega rápida.
		(svc.questions || []).forEach(function (q) {
			var name = 'answers.' + q.key;
			var id = 'mo-f-' + name;
			var value = f.answers[q.key] || '';
			var req = q.required ? ' required' : '';
			var label = esc(q.label) + (q.required ? ' <span aria-hidden="true">*</span>' : ' (opcional)');
			var input;
			if (q.type === 'textarea') {
				input = '<textarea class="mo-booking__input" id="' + id + '" name="' + name + '" rows="3"' + req + '>' + esc(value) + '</textarea>';
			} else if (q.type === 'select') {
				input = '<select class="mo-booking__input" id="' + id + '" name="' + name + '"' + req + '><option value="">Elige una opción</option>' +
					q.options.map(function (o) { return '<option' + (o === value ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>';
			} else if (q.type === 'yesno') {
				input = '<select class="mo-booking__input" id="' + id + '" name="' + name + '"' + req + '><option value="">Elige</option><option value="si"' + (value === 'si' ? ' selected' : '') + '>Sí</option><option value="no"' + (value === 'no' ? ' selected' : '') + '>No</option></select>';
			} else {
				input = '<input class="mo-booking__input" type="' + (q.type === 'number' ? 'number' : 'text') + '" id="' + id + '" name="' + name + '" value="' + esc(value) + '"' + req + '>';
			}
			html += '<div class="mo-booking__field"><label class="mo-booking__label" for="' + id + '">' + label + '</label>' + input + '<p class="mo-booking__field-error" data-error-for="' + name + '" role="alert"></p></div>';
		});
		(svc.extras || []).forEach(function (ex) {
			var opts = '';
			for (var q = 0; q <= ex.max_qty; q++) { opts += '<option value="' + q + '"' + ((f.extras[ex.key] || 0) === q ? ' selected' : '') + '>' + q + '</option>'; }
			html += self.field('extra-' + ex.key, esc(ex.label) + ' (' + esc(ex.price_txt) + ')', '<select class="mo-booking__input" id="mo-f-extra-' + esc(ex.key) + '" name="extra:' + esc(ex.key) + '">' + opts + '</select>', esc(ex.description || ''));
		});
		// Campo 10: entrega rápida al final, como extra con precio, nunca marcada de inicio.
		if (svc.fast_delivery) {
			var fd = svc.fast_delivery;
			html += '<fieldset class="mo-booking__field mo-booking__fast"><legend class="mo-booking__label">' + esc(fd.question) + '</legend>' +
				'<label class="mo-booking__check"><input type="checkbox" name="fast_delivery" id="mo-f-fast_delivery"' + (f.fast_delivery ? ' checked' : '') + ' aria-describedby="mo-help-fast"> Sí, agregar ' + esc(fd.label) + ' (+' + esc(fd.price_txt) + ')</label>' +
				(fd.regular_text ? '<p class="mo-booking__help" id="mo-help-fast">' + esc(fd.regular_text) + '</p>' : '') + '</fieldset>';
		}
		// 6.5: contrato en acordeón, debajo del formulario; aceptarlo es obligatorio.
		if (svc.contract) {
			var notice = this.text('contract_notice', '');
			if (notice) { html += '<p class="mo-booking__contract-notice">' + M.lines(notice) + '</p>'; }
			html += '<details class="mo-booking__policies mo-booking__contract"><summary>' + esc(svc.contract.title) + '</summary><div class="mo-booking__contract-body">' + svc.contract.body + '</div></details>';
			html += '<label class="mo-booking__check"><input type="checkbox" name="accept_contract" id="mo-f-accept_contract"' + (f.accept_contract ? ' checked' : '') + ' required> He leído y acepto el contrato «' + esc(svc.contract.title) + '»' + (svc.privacy_url ? ' y el <a href="' + esc(svc.privacy_url) + '" target="_blank" rel="noopener noreferrer">aviso de privacidad</a>' : '') + '</label>';
			html += '<p class="mo-booking__field-error" data-error-for="accept_contract" role="alert"></p>';
		}
		html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button" data-action="to-calendar">Atrás</button><button type="submit" class="mo-booking__button mo-booking__button--primary">Ver resumen</button></div>';
		html += '</form>';
		return html;
	};

	/** Asistentes visibles = niños + adultos (personas; las mascotas van aparte). */
	App.prototype.attendees = function () {
		var f = this.state.form;
		var c = /^\d+$/.test(String(f.children)) ? parseInt(f.children, 10) : 0;
		var a = /^\d+$/.test(String(f.adults)) ? parseInt(f.adults, 10) : 0;
		return c + a;
	};

	App.prototype.renderSummary = function () {
		var s = this.state;
		var q = s.quote;
		var svc = s.service;
		var f = s.form;
		var html = '<form class="mo-booking__panel" data-form="summary" novalidate aria-labelledby="mo-step-3">';
		html += '<h3 class="mo-booking__panel-title" id="mo-step-3" tabindex="-1" data-step-heading>Revisa tu reserva</h3>';
		html += '<dl class="mo-booking__summary">';
		html += '<dt>Servicio</dt><dd>' + esc(q.service) + '</dd>';
		html += '<dt>Fecha y hora</dt><dd>' + esc(q.date_txt) + ', ' + esc(q.time_txt) + ' h (' + esc(q.timezone) + ')</dd>';
		html += '<dt>Niños</dt><dd>' + esc(f.children === '' ? 0 : f.children) + '</dd>';
		html += '<dt>Adultos</dt><dd>' + esc(f.adults === '' ? 0 : f.adults) + '</dd>';
		html += '<dt>Total de asistentes</dt><dd>' + esc(this.attendees()) + '</dd>';
		if (svc.pets_allowed) { html += '<dt>Mascotas</dt><dd>' + esc(f.pets === '' ? 0 : f.pets) + '</dd>'; }
		if (f.important_info) { html += '<dt>Información importante</dt><dd>' + M.lines(f.important_info) + '</dd>'; }
		html += '<dt>Nombre y apellido</dt><dd>' + esc(f.name) + '</dd>';
		html += '<dt>Contacto</dt><dd>' + esc(f.email) + '<br>' + esc(f.phone) + '</dd>';
		html += '</dl>';
		html += '<table class="mo-booking__lines"><caption class="mo-booking__sr">Desglose del precio</caption><tbody>';
		q.lines.forEach(function (l) { html += '<tr><th scope="row">' + esc(l.label) + '</th><td>' + esc(l.amount_txt) + '</td></tr>'; });
		html += '</tbody><tfoot><tr><th scope="row">Total</th><td>' + esc(q.total_txt) + '</td></tr>';
		html += '<tr><th scope="row">Anticipo requerido</th><td>' + esc(q.deposit_txt) + '</td></tr>';
		html += '<tr><th scope="row">Saldo</th><td>' + esc(q.balance_txt) + '</td></tr></tfoot></table>';
		if (s.frequentToken) {
			html += '<p class="mo-booking__note">Precio de cliente frecuente. Lo confirmamos al revisar tu anticipo.</p>';
		} else if (f.frequent_claim) {
			html += '<p class="mo-booking__note">' + esc(this.config.frequentReview || 'Revisaremos tu tarifa de cliente frecuente antes de confirmar.') + '</p>';
		}
		(q.notices || []).forEach(function (n) { html += '<p class="mo-booking__notice">' + esc(n) + '</p>'; });
		if (q.deposit > 0) {
			html += '<p class="mo-booking__note">Al enviar la solicitud apartamos tu horario por <strong>' + esc(q.hold_hours) + ' horas</strong> para que transfieras el anticipo y subas tu comprobante. Si no lo recibimos a tiempo, el horario se libera.</p>';
		}
		if (this.config.showPolicies && svc.policies && svc.policies.length) {
			html += '<details class="mo-booking__policies"><summary>Políticas de cambios y cancelación</summary>';
			svc.policies.forEach(function (p) { html += '<h4>' + esc(p.title) + '</h4><p>' + M.lines(p.summary) + '</p>'; });
			html += '</details>';
		}
		if (svc.contract) {
			html += '<p class="mo-booking__note">Contrato aceptado: «' + esc(svc.contract.title) + '».</p>';
		}
		var consentRequired = this.text('consent_required', true);
		html += '<label class="mo-booking__check"><input type="checkbox" name="whatsapp_consent" id="mo-f-whatsapp_consent"' + (f.whatsapp_consent ? ' checked' : '') + (consentRequired ? ' required' : '') + '> ' + esc(this.text('whatsapp_consent', 'Acepto recibir por WhatsApp y por correo los avisos de mi reserva')) + (consentRequired ? '' : ' (opcional)') + '</label>';
		html += '<p class="mo-booking__field-error" data-error-for="whatsapp_consent" role="alert"></p>';
		html += '<div class="mo-booking__actions"><button type="button" class="mo-booking__button" data-action="to-details">Atrás</button><button type="submit" class="mo-booking__button mo-booking__button--primary">' + (this.config.preview ? 'Enviar (demostración)' : 'Enviar solicitud') + '</button></div>';
		html += '</form>';
		return html;
	};

	App.prototype.renderDone = function () {
		var r = this.state.result;
		var b = r.booking;
		var html = '<section class="mo-booking__panel" aria-labelledby="mo-step-4">';
		html += '<h3 class="mo-booking__panel-title" id="mo-step-4" tabindex="-1" data-step-heading>¡Recibimos tu solicitud!</h3>';
		html += '<p class="mo-booking__ref">Número de reserva: <strong>' + esc(r.reference) + '</strong></p>';
		html += '<p>' + esc(b.date_txt) + ', ' + esc(b.time_txt) + ' h (' + esc(b.timezone) + ')</p>';
		html += '<p class="mo-booking__status">' + esc(b.status_help) + '</p>';
		if (b.expires_txt) {
			html += '<p class="mo-booking__deadline"><strong>Paga el anticipo de ' + esc(b.deposit_txt) + ' antes del ' + esc(b.expires_txt) + '</strong> (' + esc(b.timezone) + '). Después de esa hora el horario se libera.</p>';
		}
		if (b.bank) {
			html += '<div class="mo-booking__bank"><h4>Datos para transferencia</h4><p>' + M.lines(b.bank) + '</p>' + (b.payment_ref ? '<p>' + esc(b.payment_ref) + ' Referencia: <strong>' + esc(r.reference) + '</strong></p>' : '') + '</div>';
		}
		html += '<div class="mo-booking__upload-wrap"><h4>Sube tu comprobante ahora o después</h4>' + M.uploadForm(b, 'mo-done') + '</div>';
		html += '<div class="mo-booking__portal-link"><h4>Tu enlace privado</h4><p>Con este enlace puedes ver tu reserva y subir tu comprobante más tarde. También te lo enviamos por correo. No lo compartas.</p>' +
			'<a class="mo-booking__button" href="' + esc(r.portal_url) + '" rel="noreferrer">Abrir mi reserva</a> <button type="button" class="mo-booking__button" data-action="copy-link">Copiar enlace</button></div>';
		html += '</section>';
		return html;
	};

	App.prototype.bind = function () {
		var self = this;
		var root = this.root;
		var s = this.state;
		root.querySelectorAll('[data-action]').forEach(function (btn) {
			btn.addEventListener('click', function () { self.action(btn.getAttribute('data-action')); });
		});
		root.querySelectorAll('[data-date]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				s.selectedDate = btn.getAttribute('data-date');
				s.selectedSlot = null;
				self.render();
				var first = root.querySelector('.mo-booking__slot:not([disabled])');
				if (first) { first.focus(); }
			});
		});
		root.querySelectorAll('[data-slot]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				s.selectedSlot = { start: btn.getAttribute('data-slot'), time: btn.getAttribute('data-time') };
				self.render();
				var cont = root.querySelector('[data-action=to-details]');
				if (cont) { cont.focus(); }
			});
		});
		var identify = root.querySelector('[data-form=identify]');
		if (identify) {
			identify.addEventListener('submit', function (ev) { ev.preventDefault(); self.submitIdentify(identify); });
		}
		var details = root.querySelector('[data-form=details]');
		if (details) {
			details.addEventListener('submit', function (ev) { ev.preventDefault(); self.collect(details); self.requestQuote(); });
			details.addEventListener('change', function () { self.collect(details); });
			details.addEventListener('input', function () { self.collect(details); });
		}
		var summary = root.querySelector('[data-form=summary]');
		if (summary) {
			summary.addEventListener('submit', function (ev) { ev.preventDefault(); self.submitBooking(summary); });
		}
		if (s.step === 4) {
			M.bindUpload(root, this.api, function () { return s.csrf; }, s.result.booking.max_mb, function (data) {
				s.result.booking = data.booking;
				self.render();
				self.state.message = data.message;
				var live = root.querySelector('.mo-booking__live');
				if (live) { live.innerHTML = '<p class="mo-booking__success">' + esc(data.message) + '</p>'; }
			});
		}
	};

	App.prototype.collect = function (form) {
		var f = this.state.form;
		var data = new window.FormData(form);
		var str = function (k) { var v = data.get(k); return v === null ? '' : String(v); };
		f.children = str('children').trim();
		f.adults = str('adults').trim();
		f.pets = str('pets').trim();
		f.important_info = str('important_info');
		f.name = str('name');
		f.email = str('email');
		f.phone = str('phone');
		f.phone_country = str('phone_country') || 'MX';
		f.fast_delivery = data.get('fast_delivery') === 'on';
		f.accept_contract = data.get('accept_contract') === 'on';
		f.extras = {};
		f.answers = {};
		data.forEach(function (value, key) {
			if (key.indexOf('extra:') === 0) { f.extras[key.slice(6)] = parseInt(value, 10) || 0; }
			if (key.indexOf('answers.') === 0) { f.answers[key.slice(8)] = value; }
		});
		var out = this.root.querySelector('[data-attendees]');
		if (out) { out.textContent = this.attendees(); }
	};

	App.prototype.showFieldError = function (field, message) {
		var el = this.root.querySelector('[data-error-for="' + field + '"]');
		if (el) {
			el.textContent = message;
			var input = this.root.querySelector('[name="' + field + '"]');
			if (input) { input.setAttribute('aria-invalid', 'true'); input.focus(); }
			return true;
		}
		return false;
	};

	App.prototype.clientValidate = function () {
		var f = this.state.form;
		var svc = this.state.service;
		var whole = /^\d+$/;
		if (f.children !== '' && !whole.test(f.children)) { return ['children', 'Niños: escribe un número entero, 0 o mayor.']; }
		if (f.adults !== '' && !whole.test(f.adults)) { return ['adults', 'Adultos: escribe un número entero, 0 o mayor.']; }
		if (svc.pets_allowed && f.pets !== '' && !whole.test(f.pets)) { return ['pets', 'Mascotas: escribe un número entero, 0 o mayor.']; }
		var n = this.attendees();
		if (n < 1) { return ['adults', 'Indica al menos un asistente entre niños y adultos.']; }
		if (n > svc.participants_max) { return ['adults', 'El máximo es de ' + svc.participants_max + ' personas.']; }
		if (f.important_info.length > 2000) { return ['important_info', 'Máximo 2,000 caracteres.']; }
		if (f.name.replace(/\s+/g, ' ').trim().length < 2) { return ['name', 'Escribe tu nombre y apellido.']; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) { return ['email', 'Escribe un correo válido.']; }
		if (f.phone.replace(/\D/g, '').length < 10) { return ['phone', 'Escribe tu teléfono a 10 dígitos.']; }
		for (var i = 0; i < (svc.questions || []).length; i++) {
			var q = svc.questions[i];
			if (q.required && !String(f.answers[q.key] || '').trim()) { return ['answers.' + q.key, 'Esta respuesta es obligatoria.']; }
		}
		if (svc.contract && !f.accept_contract) { return ['accept_contract', 'Para continuar, lee y acepta el contrato.']; }
		return null;
	};

	App.prototype.requestQuote = function () {
		var self = this;
		var s = this.state;
		var invalid = this.clientValidate();
		if (invalid) { this.showFieldError(invalid[0], invalid[1]); return; }
		if (this.config.preview) {
			s.quote = { service: s.service.title, date_txt: this.dateLabel(s.selectedDate), time_txt: s.selectedSlot.time, timezone: s.service.timezone,
				lines: [{ label: 'Tarifa (demostración)', amount_txt: '$1,400 MXN' }], total_txt: '$1,400 MXN', deposit: 50000, deposit_txt: '$500 MXN', balance_txt: '$900 MXN', hold_hours: 12, notices: [] };
			this.setStep(3);
			return;
		}
		var button = this.root.querySelector('[data-form=details] button[type=submit]');
		if (button) { button.disabled = true; button.textContent = 'Calculando…'; }
		this.api('services/' + encodeURIComponent(this.config.service) + '/quote', {
			body: { start: s.selectedSlot.start, children: s.form.children, adults: s.form.adults, pets: s.form.pets, extras: s.form.extras, fast_delivery: s.form.fast_delivery, frequent_token: s.frequentToken }
		}).then(function (res) {
			if (button) { button.disabled = false; button.textContent = 'Ver resumen'; }
			if (!res.ok) {
				if (M.errorCode(res) === 'slot_unavailable') {
					self.slotLost(M.errorMessage(res));
					return;
				}
				var field = M.errorField(res);
				if (!field || !self.showFieldError(field, M.errorMessage(res))) {
					self.state.message = M.errorMessage(res); self.state.messageOk = false;
					self.render();
				}
				return;
			}
			s.quote = res.data;
			s.idempotencyKey = M.uuid();
			self.setStep(3);
		});
	};

	App.prototype.slotLost = function (message) {
		var s = this.state;
		s.selectedSlot = null;
		s.quote = null;
		s.step = 1;
		var parts = s.selectedDate.split('-');
		this.loadMonth(+parts[0], +parts[1]).then(function () { s.message = message; });
		s.message = message; s.messageOk = false;
	};

	App.prototype.submitBooking = function (form) {
		var self = this;
		var s = this.state;
		var f0 = s.form;
		f0.whatsapp_consent = !!(form.querySelector('[name=whatsapp_consent]') || {}).checked;
		if (this.text('consent_required', true) && !f0.whatsapp_consent && !this.config.preview) {
			this.showFieldError('whatsapp_consent', 'Para continuar acepta recibir los avisos de tu reserva.');
			return;
		}
		if (s.service.contract && !f0.accept_contract) { s.step = 2; this.render(); this.showFieldError('accept_contract', 'Para continuar, lee y acepta el contrato.'); return; }
		if (this.config.preview) {
			s.message = 'Vista previa: en el sitio publicado aquí se enviaría la solicitud.';
			this.render();
			return;
		}
		var button = form.querySelector('button[type=submit]');
		button.disabled = true;
		button.textContent = 'Enviando…';
		var f = s.form;
		this.api('services/' + encodeURIComponent(this.config.service) + '/bookings', {
			body: {
				quote_id: s.quote.quote_id, idempotency_key: s.idempotencyKey, name: f.name, email: f.email, phone: f.phone, phone_country: f.phone_country,
				answers: f.answers, important_info: f.important_info, accept_terms: true, contract_version: s.service.contract ? s.service.contract.version : null,
				whatsapp_consent: f.whatsapp_consent, frequent_claim: f.frequent_claim, frequent_token: s.frequentToken
			}
		}).then(function (res) {
			button.disabled = false;
			button.textContent = 'Enviar solicitud';
			if (res.ok) {
				s.result = res.data;
				s.csrf = res.data.csrf;
				// beta.4: "Listo" es la misma página de reserva que abrirá después desde su enlace.
				if (res.data.portal_url && !self.config.preview) {
					window.location.href = res.data.portal_url.replace(/(#|$)/, (res.data.portal_url.indexOf('?') === -1 ? '?' : '&') + 'mo_nuevo=1$1');
					return;
				}
				self.setStep(4);
				return;
			}
			var code = M.errorCode(res);
			if (code === 'quote_changed' && res.data.data && res.data.data.quote) {
				s.quote = res.data.data.quote;
				s.idempotencyKey = M.uuid();
				self.render();
				self.root.querySelector('.mo-booking__live').innerHTML = '<p class="mo-booking__alert">' + esc(M.errorMessage(res)) + '</p>';
				return;
			}
			if (code === 'quote_expired') {
				self.requestQuote();
				return;
			}
			if (code === 'slot_unavailable') {
				self.slotLost(M.errorMessage(res));
				return;
			}
			var field = M.errorField(res);
			if (field) {
				s.step = 2;
				self.render();
				self.showFieldError(field, M.errorMessage(res));
				return;
			}
			s.message = M.errorMessage(res); s.messageOk = false;
			self.render();
		});
	};

	App.prototype.action = function (name) {
		var s = this.state;
		if (name === 'prev' || name === 'next') {
			var m = s.month + (name === 'next' ? 1 : -1);
			var y = s.year;
			if (m < 1) { m = 12; y--; }
			if (m > 12) { m = 1; y++; }
			if (this.config.preview) { return; }
			this.loadMonth(y, m);
			return;
		}
		if (name === 'new-client') {
			s.form.frequent_claim = false;
			s.frequentToken = null;
			s.locked = {};
			this.openCalendar();
			return;
		}
		if (name === 'returning') { s.welcome = 'identify'; this.setStep(0); return; }
		if (name === 'welcome-back') { s.welcome = 'choose'; s.form.frequent_claim = false; s.frequentToken = null; s.locked = {}; this.setStep(0); return; }
		if (name === 'continue-general') { s.frequentToken = null; s.locked = {}; s.form.frequent_claim = true; this.openCalendar(); return; }
		if (name === 'to-details') {
			var summaryForm = this.root.querySelector('[data-form=summary]');
			if (summaryForm) { s.form.whatsapp_consent = !!(summaryForm.querySelector('[name=whatsapp_consent]') || {}).checked; }
			if (s.selectedSlot) { s.quote = null; this.setStep(2); }
			return;
		}
		if (name === 'to-calendar') {
			var details = this.root.querySelector('[data-form=details]');
			if (details) { this.collect(details); }
			this.setStep(1);
			return;
		}
		if (name === 'copy-link' && s.result) {
			var url = s.result.portal_url;
			if (window.navigator.clipboard) {
				window.navigator.clipboard.writeText(url).then(function () { window.alert('Enlace copiado.'); });
			} else {
				window.prompt('Copia tu enlace:', url);
			}
		}
	};

	function init() {
		document.querySelectorAll('[data-mo-booking]').forEach(function (root) {
			if (root.getAttribute('data-mo-ready')) { return; }
			root.setAttribute('data-mo-ready', '1');
			var config = {};
			try { config = JSON.parse(root.getAttribute('data-mo-booking')); } catch (e) { return; }
			new App(root, config).start();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	// Vista previa en el editor de Elementor.
	window.addEventListener('elementor/frontend/init', function () {
		if (window.elementorFrontend && window.elementorFrontend.hooks) {
			window.elementorFrontend.hooks.addAction('frontend/element_ready/mo-booking-studio.default', init);
		}
	});
})(window, document);
