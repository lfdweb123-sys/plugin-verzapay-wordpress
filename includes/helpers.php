<?php
defined( 'ABSPATH' ) || exit;

/** Réglages enregistrés de la passerelle. */
function vzp_wc_settings() {
	$settings = get_option( 'woocommerce_verzapay_settings', array() );
	return is_array( $settings ) ? $settings : array();
}

function vzp_wc_is_enabled() {
	$settings = vzp_wc_settings();
	return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
}

/**
 * Tous les pays pris en charge par Verzapay : 'ISO' => 'indicatif'.
 * Filtrable avec « vzp_wc_countries ».
 */
function vzp_wc_dial_codes() {
	static $codes = null;
	if ( null === $codes ) {
		$codes = apply_filters( 'vzp_wc_countries', include VZP_WC_DIR . 'includes/countries.php' );
	}
	return $codes;
}

/** Pays occidentaux (aucun pays africain). */
function vzp_wc_western_countries() {
	return array( 'US', 'CA', 'GB', 'FR', 'DE', 'ES', 'IT', 'NL', 'BE', 'CH', 'AT', 'PT', 'SE', 'NO', 'DK', 'GR', 'AU', 'NZ' );
}

/** Pays autorisés selon le mode choisi dans les réglages. */
function vzp_wc_allowed_countries() {
	$settings = vzp_wc_settings();
	$supported = array_keys( vzp_wc_dial_codes() );
	$mode      = isset( $settings['country_mode'] ) ? $settings['country_mode'] : 'all';

	if ( 'all' === $mode ) {
		return $supported;
	}
	if ( 'custom' === $mode && ! empty( $settings['countries'] ) && is_array( $settings['countries'] ) ) {
		return array_values( array_intersect( $settings['countries'], $supported ) );
	}
	return array_values( array_intersect( vzp_wc_western_countries(), $supported ) );
}

/**
 * Met un numéro au format international (+33612345678).
 * Retourne false si le numéro est invalide ou ne correspond pas au pays de facturation.
 */
function vzp_wc_normalize_phone( $phone, $country ) {
	$codes = vzp_wc_dial_codes();
	if ( ! isset( $codes[ $country ] ) ) {
		return false;
	}
	$dial = $codes[ $country ];

	$phone = preg_replace( '/\(\s*0\s*\)/', '', (string) $phone ); // « +33 (0)6… »
	$p     = preg_replace( '/[^\d+]/', '', $phone );

	if ( '' === $p || strpos( $p, '+' ) > 0 ) {
		return false;
	}
	if ( 0 === strpos( $p, '00' ) ) {
		$p = '+' . substr( $p, 2 );
	}

	if ( '+' !== $p[0] ) {
		if ( 0 === strpos( $dial, '1' ) ) { // Plan de numérotation nord-américain.
			if ( 11 === strlen( $p ) && '1' === $p[0] ) {
				$p = '+' . $p;
			} elseif ( 10 === strlen( $p ) ) {
				$p = '+1' . $p;
			} elseif ( 7 === strlen( $p ) && 4 === strlen( $dial ) ) {
				$p = '+' . $dial . $p;
			} else {
				return false;
			}
		} else {
			if ( 'IT' !== $country ) { // En Italie, le 0 initial fait partie du numéro.
				$p = ltrim( $p, '0' );
			}
			$p = '+' . $dial . $p;
		}
	}

	if ( ! preg_match( '/^\+\d{8,15}$/', $p ) ) {
		return false;
	}
	if ( 0 !== strpos( $p, '+' . $dial ) ) {
		return false;
	}
	if ( 0 === strpos( $dial, '1' ) && 12 !== strlen( $p ) ) {
		return false;
	}
	return $p;
}

/** Domaines vers lesquels le client peut être redirigé pour payer. */
function vzp_wc_allowed_hosts() {
	$settings = vzp_wc_settings();
	$raw      = ( isset( $settings['allowed_hosts'] ) && '' !== trim( $settings['allowed_hosts'] ) )
		? $settings['allowed_hosts']
		: 'verzapay.com, joonapay.com';

	$hosts = preg_split( '/[\s,;]+/', strtolower( $raw ) );
	$hosts = array_filter( array_map( 'trim', $hosts ) );
	$hosts[] = 'verzapay.com';

	return array_values( array_unique( apply_filters( 'vzp_wc_allowed_checkout_hosts', $hosts ) ) );
}

/** Vrai si l’URL est en https et appartient à un domaine autorisé. */
function vzp_wc_is_safe_url( $url ) {
	$url = (string) $url;
	if ( 0 !== strpos( $url, 'https://' ) ) {
		return false;
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return false;
	}
	foreach ( vzp_wc_allowed_hosts() as $domain ) {
		if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
			return true;
		}
	}
	return false;
}
