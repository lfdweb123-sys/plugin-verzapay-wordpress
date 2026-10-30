(function ($) {
	'use strict';

	$(function () {
		// Sélection personnalisée des pays : visible uniquement en mode « personnalisé ».
		var $mode = $('#woocommerce_verzapay_country_mode');
		var $row = $('#woocommerce_verzapay_countries').closest('tr');

		function toggleCountries() {
			$row.toggle($mode.val() === 'custom');
		}
		if ($mode.length) {
			$mode.on('change', toggleCountries);
			toggleCountries();
		}

		// Éditeur de textes par langue : un panneau par langue.
		var $wrap = $('.vzp-wc-tr');
		if ($wrap.length) {
			var $switch = $wrap.find('.vzp-wc-locale-switch');
			var showPanel = function () {
				var locale = $switch.val();
				$wrap.find('.vzp-wc-locale-panel').each(function () {
					$(this).toggle($(this).data('locale') === locale);
				});
			};
			$switch.on('change', showPanel);
			showPanel();
		}

		// Bouton « Copier » de l’URL du webhook.
		$('#vzp-wc-copy').on('click', function () {
			var $btn = $(this);
			var text = $($btn.data('target')).text().trim();
			var label = $btn.text();
			var copied = (window.vzpWcAdmin && window.vzpWcAdmin.copied) || 'Copied!';

			function done() {
				$btn.text(copied);
				setTimeout(function () { $btn.text(label); }, 1800);
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var $tmp = $('<textarea>').val(text).appendTo('body').select();
				document.execCommand('copy');
				$tmp.remove();
				done();
			}
		});
	});
})(jQuery);
