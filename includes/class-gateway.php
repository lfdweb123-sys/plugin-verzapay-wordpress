<?php
defined( 'ABSPATH' ) || exit;

/**
 * Passerelle de paiement Verzapay (API v1).
 */
class VZP_WC_Gateway extends WC_Payment_Gateway {

	const API_BASE = 'https://www.verzapay.com/api/v1';

	public function __construct() {
		$this->id                 = 'verzapay';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = 'Verzapay';
		$this->method_description = __( 'Accept Verzapay payments (card and Mobile Money) in WooCommerce.', 'verzapay-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_webhook' ) );
	}

	/* ------------------------------------------------------------------
	 * Titre et description : suivent la langue courante
	 * --------------------------------------------------------------- */

	public function get_title() {
		return apply_filters( 'woocommerce_gateway_title', vzp_wc_text( 'title' ), $this->id );
	}

	public function get_description() {
		return apply_filters( 'woocommerce_gateway_description', vzp_wc_text( 'description' ), $this->id );
	}

	/* ------------------------------------------------------------------
	 * Réglages
	 * --------------------------------------------------------------- */

	public function init_form_fields() {
		$names   = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
		$options = array();
		foreach ( vzp_wc_dial_codes() as $iso => $dial ) {
			$label           = isset( $names[ $iso ] ) ? $names[ $iso ] : $iso;
			$options[ $iso ] = $label . ' (+' . $dial . ')';
		}
		asort( $options );

		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Enable', 'verzapay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Verzapay payment', 'verzapay-woocommerce' ),
				'default' => 'no',
			),
			'translations'          => array(
				'title'       => __( 'Checkout texts by language', 'verzapay-woocommerce' ),
				'type'        => 'vzp_translations',
				'description' => __( 'Texts shown to customers, in each language. Leave a field empty to use the built-in translation, which follows the language of your site.', 'verzapay-woocommerce' ),
			),
			'payment_description'   => array(
				'title'       => __( 'Description sent to Verzapay', 'verzapay-woocommerce' ),
				'type'        => 'text',
				'default'     => 'Service rendu',
				'description' => __( 'Text sent to the payment aggregator. It never contains the order name or the products.', 'verzapay-woocommerce' ),
			),
			'secret_key'            => array(
				'title'       => __( 'Secret key', 'verzapay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Key generated in the Developer area of Verzapay (it starts with vpz_sk_). You can also define it in wp-config.php:', 'verzapay-woocommerce' ) . " define( 'VERZAPAY_SECRET_KEY', 'vpz_sk_…' );",
			),
			'webhook_token'         => array(
				'title'       => __( 'Webhook secret token', 'verzapay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Generated automatically if empty. It is part of the webhook URL: if you change it, update the URL at Verzapay too.', 'verzapay-woocommerce' ),
			),
			'country_mode'          => array(
				'title'       => __( 'Accepted countries', 'verzapay-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'all',
				'options'     => array(
					'all'     => __( 'All countries supported by Verzapay', 'verzapay-woocommerce' ),
					'western' => __( 'Western countries only (no African country)', 'verzapay-woocommerce' ),
					'custom'  => __( 'Custom selection', 'verzapay-woocommerce' ),
				),
				/* translators: %s: date of the last synchronization with the Verzapay documentation. */
				'description' => sprintf( __( 'The country list comes from the Verzapay documentation (synced on %s).', 'verzapay-woocommerce' ), VZP_WC_SYNC ),
			),
			'countries'             => array(
				'title'   => __( 'Custom selection', 'verzapay-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $options,
				'default' => vzp_wc_western_countries(),
			),
			'limit_store_countries' => array(
				'title'       => __( 'Shop country list', 'verzapay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Limit the countries offered at checkout to the accepted countries', 'verzapay-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'The billing country must match the country code of the phone number.', 'verzapay-woocommerce' ),
			),
			'allowed_hosts'         => array(
				'title'       => __( 'Allowed payment domains', 'verzapay-woocommerce' ),
				'type'        => 'text',
				'default'     => 'verzapay.com, joonapay.com',
				'description' => __( 'The customer is only redirected to these domains (https required), separated by commas.', 'verzapay-woocommerce' ),
			),
		);
	}

	/**
	 * Champ personnalisé : éditeur de textes par langue.
	 */
	public function generate_vzp_translations_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$values    = $this->get_option( $key, array() );
		$values    = is_array( $values ) ? $values : array();
		$texts     = vzp_wc_default_texts();
		$locales   = vzp_wc_locales();
		$current   = determine_locale();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="vzp-wc-locale"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="vzp-wc-tr">
					<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
					<select id="vzp-wc-locale" class="vzp-wc-locale-switch">
						<?php foreach ( $locales as $locale ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $locale, $current ); ?>>
								<?php echo esc_html( vzp_wc_locale_name( $locale ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php foreach ( $locales as $locale ) : ?>
						<div class="vzp-wc-locale-panel" data-locale="<?php echo esc_attr( $locale ); ?>" <?php echo $locale === $current ? '' : 'style="display:none"'; ?>>
							<?php foreach ( $texts as $id => $item ) : ?>
								<?php $value = isset( $values[ $locale ][ $id ] ) ? $values[ $locale ][ $id ] : ''; ?>
								<p class="vzp-wc-field">
									<label>
										<span><?php echo esc_html( $item['label'] ); ?></span>
										<textarea rows="2" name="<?php echo esc_attr( $field_key . '[' . $locale . '][' . $id . ']' ); ?>" placeholder="<?php echo esc_attr( $item['text'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
									</label>
								</p>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function validate_vzp_translations_field( $key, $value ) {
		$texts = vzp_wc_default_texts();
		$clean = array();

		if ( ! is_array( $value ) ) {
			return $clean;
		}
		foreach ( $value as $locale => $items ) {
			if ( ! preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', (string) $locale ) || ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $id => $text ) {
				if ( ! isset( $texts[ $id ] ) ) {
					continue;
				}
				$text = trim( wp_kses_post( wp_unslash( (string) $text ) ) );
				if ( '' !== $text ) {
					$clean[ $locale ][ $id ] = $text;
				}
			}
		}
		return $clean;
	}

	public function admin_options() {
		$key_ok = '' !== $this->get_secret_key();
		$url    = $this->get_webhook_url();
		$count  = count( vzp_wc_allowed_countries() );
		?>
		<div class="vzp-wc-admin">
			<div class="vzp-wc-hero">
				<div class="vzp-wc-hero-text">
					<h2>Verzapay</h2>
					<p><?php esc_html_e( 'Card and Mobile Money payments for WooCommerce.', 'verzapay-woocommerce' ); ?></p>
				</div>
				<ul class="vzp-wc-badges">
					<li class="<?php echo $key_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $key_ok ? esc_html__( 'API key set', 'verzapay-woocommerce' ) : esc_html__( 'API key missing', 'verzapay-woocommerce' ); ?></li>
					<?php /* translators: %d: number of accepted countries. */ ?>
					<li class="is-info"><?php echo esc_html( sprintf( __( 'Accepted countries: %d', 'verzapay-woocommerce' ), $count ) ); ?></li>
					<?php /* translators: %s: date of the last synchronization. */ ?>
					<li class="is-info"><?php echo esc_html( sprintf( __( 'Countries synced on %s', 'verzapay-woocommerce' ), VZP_WC_SYNC ) ); ?></li>
				</ul>
			</div>

			<div class="vzp-wc-card">
				<h3><?php esc_html_e( 'Webhook URL', 'verzapay-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Copy this URL into the Developer area of Verzapay. It must start with https://', 'verzapay-woocommerce' ); ?></p>
				<div class="vzp-wc-copy">
					<code id="vzp-wc-webhook"><?php echo esc_html( $url ); ?></code>
					<button type="button" class="button" id="vzp-wc-copy" data-target="#vzp-wc-webhook"><?php esc_html_e( 'Copy', 'verzapay-woocommerce' ); ?></button>
				</div>
				<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
					<p class="vzp-wc-alert"><?php esc_html_e( 'Your site does not use https: Verzapay may not be able to deliver notifications.', 'verzapay-woocommerce' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="vzp-wc-card">
				<h3><?php esc_html_e( 'Settings', 'verzapay-woocommerce' ); ?></h3>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
			</div>
		</div>
		<?php
	}

	private function get_secret_key() {
		if ( defined( 'VERZAPAY_SECRET_KEY' ) && VERZAPAY_SECRET_KEY ) {
			return trim( (string) VERZAPAY_SECRET_KEY );
		}
		return trim( (string) $this->get_option( 'secret_key' ) );
	}

	private function get_payment_description() {
		$text = trim( wp_strip_all_tags( (string) $this->get_option( 'payment_description' ) ) );
		return '' !== $text ? $text : 'Service rendu';
	}

	private function get_webhook_token() {
		$token = (string) $this->get_option( 'webhook_token' );
		if ( '' === $token ) {
			$token = wp_generate_password( 40, false );
			$this->update_option( 'webhook_token', $token );
		}
		return $token;
	}

	private function get_webhook_url() {
		return add_query_arg( 'k', $this->get_webhook_token(), WC()->api_request_url( $this->id ) );
	}

	/* ------------------------------------------------------------------
	 * Affichage à la commande
	 * --------------------------------------------------------------- */

	public function is_available() {
		if ( 'yes' !== $this->enabled || '' === $this->get_secret_key() ) {
			return false;
		}
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$country = WC()->customer->get_billing_country();
			if ( $country && ! in_array( $country, vzp_wc_allowed_countries(), true ) ) {
				return false;
			}
		}
		return true;
	}

	public function payment_fields() {
		echo '<div class="vzp-wc-box">';
		$description = vzp_wc_text( 'description' );
		if ( '' !== $description ) {
			echo '<div class="vzp-wc-desc">' . wp_kses_post( wpautop( wptexturize( $description ) ) ) . '</div>';
		}
		echo '<ul class="vzp-wc-points">';
		echo '<li>' . wp_kses_post( vzp_wc_text( 'point_redirect' ) ) . '</li>';
		echo '<li>' . wp_kses_post( vzp_wc_text( 'point_phone' ) ) . '</li>';
		echo '</ul></div>';
	}

	/**
	 * Validation côté serveur au moment de la commande (checkout classique).
	 */
	public function validate_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- le nonce est vérifié par WooCommerce.
		$country = isset( $_POST['billing_country'] ) ? wc_clean( wp_unslash( $_POST['billing_country'] ) ) : '';
		$phone   = isset( $_POST['billing_phone'] ) ? wc_clean( wp_unslash( $_POST['billing_phone'] ) ) : '';
		// phpcs:enable

		if ( '' === $country ) {
			return true; // Page « payer la commande » : contrôle refait dans process_payment().
		}
		return $this->check_country_and_phone( $country, $phone );
	}

	private function check_country_and_phone( $country, $phone ) {
		if ( ! in_array( $country, vzp_wc_allowed_countries(), true ) ) {
			wc_add_notice( vzp_wc_text( 'err_country' ), 'error' );
			return false;
		}
		if ( '' === trim( (string) $phone ) ) {
			wc_add_notice( vzp_wc_text( 'err_phone_required' ), 'error' );
			return false;
		}
		if ( ! vzp_wc_normalize_phone( $phone, $country ) ) {
			wc_add_notice( vzp_wc_text( 'err_phone_invalid' ), 'error' );
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * API Verzapay
	 * --------------------------------------------------------------- */

	private function api_request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_secret_key(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'data' => is_array( $data ) ? $data : array(),
		);
	}

	private function log( $message ) {
		wc_get_logger()->info( $message, array( 'source' => 'verzapay' ) );
	}

	/* ------------------------------------------------------------------
	 * Création du paiement
	 * --------------------------------------------------------------- */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$country = $order->get_billing_country();
		if ( ! $this->check_country_and_phone( $country, $order->get_billing_phone() ) ) {
			return array( 'result' => 'failure' );
		}
		$phone     = vzp_wc_normalize_phone( $order->get_billing_phone(), $country );
		$reference = 'CMD-' . $order->get_id();

		$payload = array(
			'amount'             => (float) wc_format_decimal( $order->get_total(), 2 ),
			'currency'           => strtoupper( $order->get_currency() ),
			// Description neutre envoyée à l’agrégateur (« Service rendu » par défaut), jamais le nom de la commande.
			'description'        => $this->get_payment_description(),
			'customer_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_phone'     => $phone,
			'customer_email'     => $order->get_billing_email(),
			'merchant_reference' => $reference,
			'metadata'           => array( 'order_id' => (string) $order->get_id() ),
		);

		$result = $this->api_request( 'POST', '/payments', $payload );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Network error: ' . $result->get_error_message() );
			wc_add_notice( vzp_wc_text( 'err_network' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$code = $result['code'];
		$data = $result['data'];

		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			$this->log( 'Payment creation refused (HTTP ' . $code . ') for order #' . $order->get_id() . ': ' . wp_json_encode( $data ) );
			if ( 503 === $code ) {
				wc_add_notice( vzp_wc_text( 'err_rate' ), 'error' );
			} elseif ( 400 === $code ) {
				wc_add_notice( vzp_wc_text( 'err_refused' ), 'error' );
			} else {
				wc_add_notice( vzp_wc_text( 'err_unavailable' ), 'error' );
			}
			return array( 'result' => 'failure' );
		}

		// checkout_url : page hébergée par Verzapay (ou page alternative si elle n’a pu être créée).
		// fallback_checkout_url : page alternative, utilisée seulement si la première n’est pas acceptable.
		$candidates = array(
			isset( $data['checkout_url'] ) ? (string) $data['checkout_url'] : '',
			isset( $data['fallback_checkout_url'] ) ? (string) $data['fallback_checkout_url'] : '',
		);
		$url = '';
		foreach ( $candidates as $candidate ) {
			if ( vzp_wc_is_safe_url( $candidate ) ) {
				$url = $candidate;
				break;
			}
		}

		if ( '' === $url ) {
			$this->log( 'Payment URL refused (domain not allowed or not https): ' . wp_json_encode( $candidates ) );
			wc_add_notice( vzp_wc_text( 'err_unavailable' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_id = sanitize_text_field( $data['id'] );
		$order->update_meta_data( '_verzapay_payment_id', $payment_id );
		$order->update_meta_data( '_verzapay_reference', $reference );
		$order->update_meta_data( '_verzapay_checkout_url', $url );

		/* translators: %s: Verzapay payment ID. */
		$note = sprintf( __( 'Waiting for Verzapay payment (ID %s).', 'verzapay-woocommerce' ), $payment_id );
		if ( isset( $data['charged_amount'], $data['charged_currency'] ) ) {
			/* translators: 1: charged amount, 2: charged currency. */
			$note .= ' ' . sprintf( __( 'Expected charge: %1$s %2$s.', 'verzapay-woocommerce' ), wc_clean( $data['charged_amount'] ), wc_clean( $data['charged_currency'] ) );
			if ( isset( $data['exchange_rate'] ) ) {
				/* translators: %s: exchange rate. */
				$note .= ' ' . sprintf( __( 'Exchange rate: %s.', 'verzapay-woocommerce' ), wc_clean( $data['exchange_rate'] ) );
			}
		}
		$order->update_status( 'on-hold', $note );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $url ),
		);
	}

	/* ------------------------------------------------------------------
	 * Webhook : jeton secret + contre-vérification via l’API + idempotence
	 * --------------------------------------------------------------- */

	public function handle_webhook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given = isset( $_GET['k'] ) ? (string) wp_unslash( $_GET['k'] ) : '';
		if ( ! hash_equals( $this->get_webhook_token(), $given ) ) {
			wp_send_json( array( 'error' => 'unauthorized' ), 401 );
		}

		$event = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['paymentId'] ) ) {
			wp_send_json( array( 'received' => true ), 200 );
		}
		if ( ! in_array( $event['type'], array( 'payment.completed', 'payment.failed' ), true ) ) {
			wp_send_json( array( 'received' => true ), 200 ); // Types inconnus ou décaissements : ignorés.
		}

		$payment_id = sanitize_text_field( $event['paymentId'] );
		$orders     = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => '_verzapay_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $payment_id,            // phpcs:ignore WordPress.DB.SlowDBQuery
		) );

		if ( empty( $orders ) ) {
			$this->log( 'Webhook ignored: unknown payment ' . $payment_id );
			wp_send_json( array( 'received' => true ), 200 );
		}

		$order = $orders[0];

		// Idempotence : commande déjà payée.
		if ( $order->is_paid() ) {
			wp_send_json( array( 'received' => true ), 200 );
		}

		// La notification seule ne prouve rien : on interroge l’API.
		$result = $this->api_request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || empty( $result['data']['status'] ) ) {
			$this->log( 'Verification impossible for ' . $payment_id );
			wp_send_json( array( 'error' => 'verification_failed' ), 503 );
		}

		$remote = $result['data'];

		if ( 'completed' === $remote['status'] ) {
			$same_ref      = isset( $remote['merchant_reference'] ) && $remote['merchant_reference'] === $order->get_meta( '_verzapay_reference' );
			$same_amount   = isset( $remote['amount'] ) && abs( (float) $remote['amount'] - (float) $order->get_total() ) < 0.01;
			$same_currency = isset( $remote['currency'] ) && strtoupper( $remote['currency'] ) === strtoupper( $order->get_currency() );

			if ( $same_ref && $same_amount && $same_currency ) {
				$order->payment_complete( $payment_id );
				/* translators: %s: Verzapay payment ID. */
				$order->add_order_note( sprintf( __( 'Verzapay payment confirmed (ID %s).', 'verzapay-woocommerce' ), $payment_id ) );
			} else {
				/* translators: %s: Verzapay payment ID. */
				$order->add_order_note( sprintf( __( 'Verzapay payment received but the amount, currency or reference differ: manual check required (ID %s).', 'verzapay-woocommerce' ), $payment_id ) );
				$this->log( 'Mismatch for order #' . $order->get_id() );
			}
		} elseif ( 'failed' === $remote['status'] ) {
			/* translators: %s: Verzapay payment ID. */
			$order->update_status( 'failed', sprintf( __( 'Verzapay payment failed (ID %s).', 'verzapay-woocommerce' ), $payment_id ) );
		}

		wp_send_json( array( 'received' => true ), 200 );
	}
}
