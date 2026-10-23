/* MO Booking Studio: mejoras del panel. Todo funciona también sin JavaScript. */
(function () {
	'use strict';

	// Confirmación antes de acciones delicadas.
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-confirm]');
		if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
			ev.preventDefault();
		}
	});

	// Eliminar permanentemente desde una lista: se escribe ELIMINAR (el servidor lo vuelve a comprobar).
	var askTyped = function (message) {
		var typed = window.prompt(message || 'Esta acción no se puede deshacer. Escribe ELIMINAR para confirmar.');
		return typed === null ? null : typed.trim();
	};
	document.addEventListener('click', function (ev) {
		var link = ev.target.closest('a[data-typed-confirm]');
		if (!link) { return; }
		ev.preventDefault();
		var typed = askTyped(link.getAttribute('data-typed-confirm'));
		if (typed !== null) { window.location.href = link.href + '&confirm_text=' + encodeURIComponent(typed); }
	});
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		if (!form.classList || !form.classList.contains('mo-list-form')) { return; }
		var submitter = ev.submitter || document.activeElement;
		var select = form.querySelector(submitter && submitter.id === 'doaction2' ? '[name=action2]' : '[name=action]');
		if (!select || select.value !== 'purge' || (submitter && submitter.name === 'filter_action')) { return; }
		var typed = askTyped('Se eliminarán definitivamente los elementos marcados, con todo lo que depende de ellos. Escribe ELIMINAR para confirmar.');
		if (typed === null) { ev.preventDefault(); return; }
		var field = form.querySelector('[name=confirm_text]') || document.createElement('input');
		field.type = 'hidden'; field.name = 'confirm_text'; field.value = typed;
		form.appendChild(field);
	});

	// Evitar doble envío.
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		if (!form.classList || !form.classList.contains('mo-form')) { return; }
		if (form.getAttribute('data-sent')) { ev.preventDefault(); return; }
		form.setAttribute('data-sent', '1');
		setTimeout(function () { form.removeAttribute('data-sent'); }, 4000);
	});

	// Agregar filas en tablas repetibles.
	document.querySelectorAll('[data-mo-add-row]').forEach(function (button) {
		button.addEventListener('click', function () {
			var table = button.closest('.mo-card').querySelector('.mo-repeat tbody');
			var rows = table.querySelectorAll('tr');
			var last = rows[rows.length - 1];
			var clone = last.cloneNode(true);
			var index = rows.length;
			clone.querySelectorAll('input, select, textarea').forEach(function (el) {
				el.name = el.name.replace(/\[\d+\]/, '[' + index + ']');
				if (el.id) { el.id = el.id + '-' + index; }
				if (el.type === 'checkbox') { el.checked = false; } else if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else if (el.type !== 'number') { el.value = ''; }
			});
			table.appendChild(clone);
		});
	});

	// Selector de imagen de la biblioteca.
	document.querySelectorAll('[data-mo-media]').forEach(function (box) {
		var input = box.querySelector('input[type=hidden]');
		var img = box.querySelector('img');
		var pick = box.querySelector('[data-mo-media-pick]');
		var clear = box.querySelector('[data-mo-media-clear]');
		if (pick && window.wp && window.wp.media) {
			pick.addEventListener('click', function () {
				var frame = window.wp.media({ title: 'Foto del servicio', multiple: false, library: { type: 'image' } });
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					input.value = a.id;
					img.src = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
					img.hidden = false;
				});
				frame.open();
			});
		}
		if (clear) {
			clear.addEventListener('click', function () { input.value = ''; img.hidden = true; });
		}
	});

	// Notificaciones: insertar códigos, avisar de códigos desconocidos y vista previa.
	var sampleEl = document.getElementById('mo-sample-vars');
	if (sampleEl) {
		var sample = {};
		try { sample = JSON.parse(sampleEl.textContent); } catch (e) { sample = {}; }
		var unknownCodes = function (text) {
			var out = [];
			text.replace(/\{\{\s*([^{}\s]+)\s*\}\}/g, function (all, code) { if (!Object.prototype.hasOwnProperty.call(sample, code)) { out.push(all); } return all; });
			text.replace(/(^|[^{])\{([a-z_]+)\}(?!\})/g, function (all, pre, code) { out.push('{' + code + '}'); return all; });
			return out.filter(function (v, i, a) { return a.indexOf(v) === i; });
		};
		var check = function (field) {
			var warn = document.querySelector('[data-warning-for="' + field.id + '"]');
			if (!warn) { return; }
			var bad = unknownCodes(field.value);
			warn.hidden = !bad.length;
			warn.textContent = bad.length ? 'No reconocemos: ' + bad.join(' ') + '. Usa doble llave y un código de la guía (por ejemplo {{nombre}}); si no, saldrá vacío.' : '';
		};
		document.querySelectorAll('[data-mo-codes]').forEach(function (field) {
			check(field);
			field.addEventListener('input', function () { check(field); });
		});
		document.addEventListener('click', function (ev) {
			var btn = ev.target.closest('.mo-code');
			if (btn) {
				var target = document.getElementById(btn.getAttribute('data-target'));
				if (!target) { return; }
				var code = btn.getAttribute('data-insert');
				var start = target.selectionStart || 0;
				var end = target.selectionEnd || 0;
				target.value = target.value.slice(0, start) + code + target.value.slice(end);
				target.focus();
				target.selectionStart = target.selectionEnd = start + code.length;
				check(target);
				return;
			}
			var prev = ev.target.closest('[data-mo-preview]');
			if (prev) {
				var id = prev.getAttribute('data-mo-preview');
				var field = document.getElementById(id);
				var out = document.querySelector('[data-preview-for="' + id + '"]');
				if (!field || !out) { return; }
				out.textContent = field.value.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (all, code) { return Object.prototype.hasOwnProperty.call(sample, code) ? sample[code] : ''; });
				out.hidden = false;
			}
		});
	}
})();
