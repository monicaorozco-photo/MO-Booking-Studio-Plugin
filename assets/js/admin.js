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
})();
