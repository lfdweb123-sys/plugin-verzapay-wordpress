<?php
defined( 'ABSPATH' ) || exit;

/**
 * Textes visibles par les clients (page de commande, messages d’erreur, Mon compte).
 * Chaque texte suit la langue du site (fichiers du dossier languages/) et peut être
 * remplacé à la main, langue par langue, dans les réglages de la passerelle.
 */
function vzp_wc_default_texts() {
	return array(
		'title'              => array(
			'label' => __( 'Payment method title', 'verzapay-woocommerce' ),
			'text'  => __( 'Card payment', 'verzapay-woocommerce' ),
		),
		'description'        => array(
			'label' => __( 'Payment method description', 'verzapay-woocommerce' ),
			'text'  => __( 'Secure online payment.', 'verzapay-woocommerce' ),
		),
		'point_redirect'     => array(
			'label' => __( 'Checkout note: redirection', 'verzapay-woocommerce' ),
			'text'  => __( 'You will be redirected to a secure payment page.', 'verzapay-woocommerce' ),
		),
		'point_phone'        => array(
			'label' => __( 'Checkout note: phone format', 'verzapay-woocommerce' ),
			'text'  => __( 'Enter your phone number in international format, with the country code of your billing country.', 'verzapay-woocommerce' ),
		),
		'phone_label'        => array(
			'label' => __( 'Phone field label', 'verzapay-woocommerce' ),
			'text'  => __( 'Phone (international format)', 'verzapay-woocommerce' ),
		),
		'err_country'        => array(
			'label' => __( 'Error: country not available', 'verzapay-woocommerce' ),
			'text'  => __( 'This payment method is not available for the selected country.', 'verzapay-woocommerce' ),
		),
		'err_phone_required' => array(
			'label' => __( 'Error: phone number required', 'verzapay-woocommerce' ),
			'text'  => __( 'A phone number is required.', 'verzapay-woocommerce' ),
		),
		'err_phone_invalid'  => array(
			'label' => __( 'Error: invalid phone number', 'verzapay-woocommerce' ),
			'text'  => __( 'Invalid phone number. Use the international format that matches your country, for example +44 7911 123456.', 'verzapay-woocommerce' ),
		),
		'err_network'        => array(
			'label' => __( 'Error: payment service unreachable', 'verzapay-woocommerce' ),
			'text'  => __( 'Unable to reach the payment service. Please try again in a moment.', 'verzapay-woocommerce' ),
		),
		'err_rate'           => array(
			'label' => __( 'Error: exchange rate unavailable', 'verzapay-woocommerce' ),
			'text'  => __( 'The exchange rate is temporarily unavailable. Please try again later.', 'verzapay-woocommerce' ),
		),
		'err_refused'        => array(
			'label' => __( 'Error: payment refused', 'verzapay-woocommerce' ),
			'text'  => __( 'The payment was refused. Check your phone number and the order amount.', 'verzapay-woocommerce' ),
		),
		'err_unavailable'    => array(
			'label' => __( 'Error: payment unavailable', 'verzapay-woocommerce' ),
			'text'  => __( 'Payment is temporarily unavailable. Please try again later.', 'verzapay-woocommerce' ),
		),
		'btn_pay'            => array(
			'label' => __( 'Button: pay now (My account)', 'verzapay-woocommerce' ),
			'text'  => __( 'Pay now', 'verzapay-woocommerce' ),
		),
	);
}

/**
 * Retourne un texte client dans la langue courante :
 * 1. remplacement manuel pour la langue exacte (fr_FR), puis pour la même langue (fr_BE…) ;
 * 2. ancien réglage général (titre / description) s’il a été personnalisé ;
 * 3. traduction fournie avec le plugin (suit la langue du site).
 */
function vzp_wc_text( $id ) {
	$texts = vzp_wc_default_texts();
	if ( ! isset( $texts[ $id ] ) ) {
		return '';
	}
	$settings = vzp_wc_settings();

	if ( ! empty( $settings['translations'] ) && is_array( $settings['translations'] ) ) {
		$locale = determine_locale();
		if ( ! empty( $settings['translations'][ $locale ][ $id ] ) ) {
			return (string) $settings['translations'][ $locale ][ $id ];
		}
		$lang = strtok( $locale, '_' );
		foreach ( $settings['translations'] as $stored_locale => $items ) {
			if ( strtok( (string) $stored_locale, '_' ) === $lang && ! empty( $items[ $id ] ) ) {
				return (string) $items[ $id ];
			}
		}
	}

	// Réglage général d’une ancienne version, ignoré s’il s’agit d’un ancien texte par défaut.
	if ( 'title' === $id || 'description' === $id ) {
		$legacy = isset( $settings[ $id ] ) ? trim( (string) $settings[ $id ] ) : '';
		$stale  = array( 'Carte bancaire', 'Paiement sécurisé par carte bancaire.', 'Card payment', 'Secure online payment.' );
		if ( '' !== $legacy && ! in_array( $legacy, $stale, true ) ) {
			return $legacy;
		}
	}

	return $texts[ $id ]['text'];
}

/** Langues proposées dans l’éditeur de textes. */
function vzp_wc_locales() {
	$locales = array( 'en_US', 'fr_FR', 'es_ES', 'de_DE', 'it_IT', 'pt_PT', 'nl_NL', get_locale() );
	$locales = array_merge( $locales, get_available_languages() );

	if ( function_exists( 'pll_languages_list' ) ) { // Polylang.
		$locales = array_merge( $locales, (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
	}
	if ( has_filter( 'wpml_active_languages' ) ) { // WPML.
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $langs ) ) {
			foreach ( $langs as $lang ) {
				if ( ! empty( $lang['default_locale'] ) ) {
					$locales[] = $lang['default_locale'];
				}
			}
		}
	}

	$locales = array_filter( array_unique( array_map( 'strval', $locales ) ), function ( $l ) {
		return (bool) preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $l );
	} );
	sort( $locales );
	return array_values( $locales );
}

function vzp_wc_locale_name( $locale ) {
	$names = array(
		'en_US' => 'English (US)',
		'fr_FR' => 'Français',
		'es_ES' => 'Español',
		'de_DE' => 'Deutsch',
		'it_IT' => 'Italiano',
		'pt_PT' => 'Português',
		'nl_NL' => 'Nederlands',
	);
	return isset( $names[ $locale ] ) ? $names[ $locale ] . ' (' . $locale . ')' : $locale;
}
