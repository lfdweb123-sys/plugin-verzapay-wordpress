<?php
/**
 * Plugin Name:       Passerelle Verzapay pour WooCommerce
 * Description:       Acceptez les paiements Verzapay (API v1) dans WooCommerce : carte bancaire et Mobile Money, webhook sécurisé, pays synchronisés avec la documentation Verzapay.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Text Domain:       verzapay-woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'VZP_WC_VERSION', '2.0.0' );
define( 'VZP_WC_FILE', __FILE__ );
define( 'VZP_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'VZP_WC_URL', plugin_dir_url( __FILE__ ) );
/** Date de la dernière synchronisation de la liste des pays avec la documentation Verzapay. */
define( 'VZP_WC_SYNC', '28/10/2026 14:52:46' );

require_once VZP_WC_DIR . 'includes/helpers.php';

/* Compatibilité HPOS (tables de commandes WooCommerce). */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VZP_WC_FILE, true );
	}
} );

add_action( 'plugins_loaded', 'vzp_wc_boot', 11 );

function vzp_wc_boot() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Passerelle Verzapay</strong> nécessite WooCommerce actif.</p></div>';
		} );
		return;
	}

	require_once VZP_WC_DIR . 'includes/class-gateway.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'VZP_WC_Gateway';
		return $gateways;
	} );

	add_filter( 'woocommerce_countries_allowed_countries', 'vzp_wc_filter_store_countries' );
	add_filter( 'woocommerce_billing_fields', 'vzp_wc_filter_billing_fields', 20 );
	add_filter( 'woocommerce_my_account_my_orders_actions', 'vzp_wc_resume_payment_action', 10, 2 );
	add_action( 'wp_enqueue_scripts', 'vzp_wc_enqueue_checkout_assets' );
	add_action( 'admin_enqueue_scripts', 'vzp_wc_enqueue_admin_assets' );
}

/**
 * Liste des pays de la boutique limitée aux pays autorisés (uniquement si la passerelle est active).
 */
function vzp_wc_filter_store_countries( $countries ) {
	if ( ! vzp_wc_is_enabled() ) {
		return $countries;
	}
	$settings = vzp_wc_settings();
	if ( isset( $settings['limit_store_countries'] ) && 'yes' !== $settings['limit_store_countries'] ) {
		return $countries;
	}
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $countries;
	}
	return array_intersect_key( $countries, array_flip( vzp_wc_allowed_countries() ) );
}

/**
 * Téléphone obligatoire, au format international.
 */
function vzp_wc_filter_billing_fields( $fields ) {
	if ( vzp_wc_is_enabled() && isset( $fields['billing_phone'] ) ) {
		$fields['billing_phone']['required']    = true;
		$fields['billing_phone']['label']       = 'Téléphone (format international)';
		$fields['billing_phone']['placeholder'] = '+33 6 12 34 56 78';
		$fields['billing_phone']['type']        = 'tel';
	}
	return $fields;
}

/**
 * Bouton « Payer maintenant » dans Mon compte → Commandes pour une commande en attente.
 */
function vzp_wc_resume_payment_action( $actions, $order ) {
	if ( 'verzapay' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
		$url = (string) $order->get_meta( '_verzapay_checkout_url' );
		if ( vzp_wc_is_safe_url( $url ) ) {
			$actions['verzapay_pay'] = array(
				'url'  => $url,
				'name' => 'Payer maintenant',
			);
		}
	}
	return $actions;
}

function vzp_wc_enqueue_checkout_assets() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! vzp_wc_is_enabled() ) {
		return;
	}
	wp_enqueue_style( 'vzp-wc-checkout', VZP_WC_URL . 'assets/css/checkout.css', array(), VZP_WC_VERSION );
	wp_enqueue_script( 'vzp-wc-checkout', VZP_WC_URL . 'assets/js/checkout.js', array( 'jquery' ), VZP_WC_VERSION, true );

	$dial = array();
	$all  = vzp_wc_dial_codes();
	foreach ( vzp_wc_allowed_countries() as $iso ) {
		$dial[ $iso ] = '+' . $all[ $iso ];
	}
	wp_localize_script( 'vzp-wc-checkout', 'vzpWc', array( 'dial' => $dial ) );
}

function vzp_wc_enqueue_admin_assets() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

	if ( 'wc-settings' !== $page || 'verzapay' !== $section ) {
		return;
	}
	wp_enqueue_style( 'vzp-wc-admin', VZP_WC_URL . 'assets/css/admin.css', array(), VZP_WC_VERSION );
	wp_enqueue_script( 'vzp-wc-admin', VZP_WC_URL . 'assets/js/admin.js', array( 'jquery' ), VZP_WC_VERSION, true );
}
