(function ($) {
	'use strict';

	$(function () {
		// Affiche la sélection personnalisée uniquement en mode « personnalisé ».
		var $mode = $('#woocommerce_verzapay_country_mode');
		var $row = $('#woocommerce_verzapay_countries').closest('tr');

		function toggleCountries() {
			$row.toggle($mode.val() === 'custom');
		}
		if ($mode.length) {
			$mode.on('change', toggleCountries);
			toggleCountries();
		}

		// Bouton « Copier » de l’URL du webhook.
		$('#vzp-wc-copy').on('click', function () {
			var $btn = $(this);
			var text = $($btn.data('target')).text().trim();
			var label = $btn.text();

			function done() {
				$btn.text('Copié !');
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
