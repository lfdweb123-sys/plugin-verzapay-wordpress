(function ($) {
	'use strict';

	var dial = (window.vzpWc && window.vzpWc.dial) || {};

	function updatePhonePlaceholder() {
		var country = $('#billing_country').val();
		var $phone = $('#billing_phone');
		if ($phone.length && country && dial[country]) {
			$phone.attr('placeholder', dial[country] + ' …');
		}
	}

	$(document.body)
		.on('change', '#billing_country', updatePhonePlaceholder)
		.on('updated_checkout', updatePhonePlaceholder);

	$(updatePhonePlaceholder);
})(jQuery);
