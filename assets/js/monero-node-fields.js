(function () {
	'use strict';

	function update(list) {
		var rows = list.querySelectorAll('.monero-node-row');
		rows.forEach(function (row, index) {
			row.querySelectorAll('[name]').forEach(function (field) {
				field.name = field.name.replace(/node_configs\[\d+\]/, 'node_configs[' + index + ']');
			});
			var auth = row.querySelector('select');
			row.classList.toggle('has-auth', auth && auth.value !== 'none');
		});
	}

	document.addEventListener('click', function (event) {
		var list = event.target.closest('.monero-node-list');
		if (!list) return;
		if (event.target.classList.contains('monero-node-add')) {
			var row = list.querySelector('.monero-node-row').cloneNode(true);
			row.dataset.passwordSaved = 'false';
			row.querySelectorAll('input').forEach(function (input) { input.value = ''; input.placeholder = ''; });
			row.querySelector('select').value = 'none';
			list.insertBefore(row, event.target);
			update(list);
		}
		if (event.target.classList.contains('monero-node-remove')) {
			var rows = list.querySelectorAll('.monero-node-row');
			if (rows.length > 1) event.target.closest('.monero-node-row').remove();
			else rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
			update(list);
		}
	});

	document.addEventListener('change', function (event) {
		if (event.target.matches('.monero-node-row select')) update(event.target.closest('.monero-node-list'));
	});
	document.querySelectorAll('.monero-node-list').forEach(update);
}());
