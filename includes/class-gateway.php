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
		$this->method_description = 'Paiement par carte bancaire ou Mobile Money via Verzapay.';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_webhook' ) );
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
				'title'   => 'Activer',
				'type'    => 'checkbox',
				'label'   => 'Activer le paiement Verzapay',
				'default' => 'no',
			),
			'title'                 => array(
				'title'   => 'Titre affiché',
				'type'    => 'text',
				'default' => 'Carte bancaire',
			),
			'description'           => array(
				'title'   => 'Description affichée',
				'type'    => 'textarea',
				'default' => 'Paiement sécurisé par carte bancaire.',
			),
			'payment_description'   => array(
				'title'       => 'Description envoyée à Verzapay',
				'type'        => 'text',
				'default'     => 'Service rendu',
				'description' => 'Texte transmis à l’agrégateur de paiement. Il ne contient jamais le nom de la commande ni des produits.',
			),
			'secret_key'            => array(
				'title'       => 'Clé secrète',
				'type'        => 'password',
				'description' => 'Clé générée dans l’espace Développeur de Verzapay (elle commence par vpz_sk_). Vous pouvez aussi la définir dans wp-config.php avec define( \'VERZAPAY_SECRET_KEY\', \'vpz_sk_…\' );',
			),
			'webhook_token'         => array(
				'title'       => 'Jeton secret du webhook',
				'type'        => 'text',
				'description' => 'Généré automatiquement s’il est vide. Il fait partie de l’URL du webhook : si vous le changez, mettez aussi à jour l’URL chez Verzapay.',
			),
			'country_mode'          => array(
				'title'       => 'Pays acceptés',
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'all',
				'options'     => array(
					'all'     => 'Tous les pays pris en charge par Verzapay',
					'western' => 'Pays occidentaux uniquement (aucun pays africain)',
					'custom'  => 'Sélection personnalisée',
				),
				'description' => 'La liste des pays vient de la documentation Verzapay (synchronisée le ' . VZP_WC_SYNC . ').',
			),
			'countries'             => array(
				'title'   => 'Sélection personnalisée',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $options,
				'default' => vzp_wc_western_countries(),
			),
			'limit_store_countries' => array(
				'title'       => 'Liste des pays de la boutique',
				'type'        => 'checkbox',
				'label'       => 'Limiter les pays proposés à la commande aux pays acceptés',
				'default'     => 'yes',
				'description' => 'Le pays de facturation doit correspondre à l’indicatif du numéro de téléphone.',
			),
			'allowed_hosts'         => array(
				'title'       => 'Domaines de paiement autorisés',
				'type'        => 'text',
				'default'     => 'verzapay.com, joonapay.com',
				'description' => 'Le client n’est redirigé que vers ces domaines (https obligatoire), séparés par des virgules.',
			),
		);
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
					<p>Paiement par carte bancaire et Mobile Money pour WooCommerce.</p>
				</div>
				<ul class="vzp-wc-badges">
					<li class="<?php echo $key_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $key_ok ? 'Clé API renseignée' : 'Clé API manquante'; ?></li>
					<li class="is-info"><?php echo esc_html( $count ); ?> pays acceptés</li>
					<li class="is-info">Pays synchronisés le <?php echo esc_html( VZP_WC_SYNC ); ?></li>
				</ul>
			</div>

			<div class="vzp-wc-card">
				<h3>URL du webhook</h3>
				<p>À copier dans l’espace Développeur de Verzapay. Elle doit commencer par <code>https://</code>.</p>
				<div class="vzp-wc-copy">
					<code id="vzp-wc-webhook"><?php echo esc_html( $url ); ?></code>
					<button type="button" class="button" id="vzp-wc-copy" data-target="#vzp-wc-webhook">Copier</button>
				</div>
				<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
					<p class="vzp-wc-alert">Votre site n’utilise pas https : Verzapay risque de ne pas livrer les notifications.</p>
				<?php endif; ?>
			</div>

			<div class="vzp-wc-card">
				<h3>Réglages</h3>
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
		if ( $this->description ) {
			echo '<div class="vzp-wc-desc">' . wp_kses_post( wpautop( wptexturize( $this->description ) ) ) . '</div>';
		}
		echo '<ul class="vzp-wc-points">';
		echo '<li>Vous serez redirigé vers une page de paiement sécurisée.</li>';
		echo '<li>Indiquez votre téléphone au format international, avec l’indicatif de votre pays de facturation.</li>';
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
			wc_add_notice( 'Ce moyen de paiement n’est pas disponible pour le pays sélectionné.', 'error' );
			return false;
		}
		if ( '' === trim( (string) $phone ) ) {
			wc_add_notice( 'Le numéro de téléphone est obligatoire.', 'error' );
			return false;
		}
		if ( ! vzp_wc_normalize_phone( $phone, $country ) ) {
			wc_add_notice( 'Numéro de téléphone invalide. Utilisez le format international correspondant à votre pays, par exemple +33 6 12 34 56 78.', 'error' );
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
			$this->log( 'Erreur réseau : ' . $result->get_error_message() );
			wc_add_notice( 'Impossible de contacter le service de paiement. Veuillez réessayer dans un instant.', 'error' );
			return array( 'result' => 'failure' );
		}

		$code = $result['code'];
		$data = $result['data'];

		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			$this->log( 'Création du paiement refusée (HTTP ' . $code . ') pour la commande #' . $order->get_id() . ' : ' . wp_json_encode( $data ) );
			if ( 503 === $code ) {
				wc_add_notice( 'Le taux de change est momentanément indisponible. Veuillez réessayer plus tard.', 'error' );
			} elseif ( 400 === $code ) {
				wc_add_notice( 'Le paiement a été refusé. Vérifiez votre numéro de téléphone et le montant de la commande.', 'error' );
			} else {
				wc_add_notice( 'Le paiement est momentanément indisponible. Veuillez réessayer plus tard.', 'error' );
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
			$this->log( 'URL de paiement refusée (domaine non autorisé ou non https) : ' . wp_json_encode( $candidates ) );
			wc_add_notice( 'Le paiement est momentanément indisponible. Veuillez réessayer plus tard.', 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_id = sanitize_text_field( $data['id'] );
		$order->update_meta_data( '_verzapay_payment_id', $payment_id );
		$order->update_meta_data( '_verzapay_reference', $reference );
		$order->update_meta_data( '_verzapay_checkout_url', $url );

		$note = 'En attente du paiement Verzapay (ID ' . $payment_id . ').';
		if ( isset( $data['charged_amount'], $data['charged_currency'] ) ) {
			$note .= ' Débit prévu : ' . wc_clean( $data['charged_amount'] ) . ' ' . wc_clean( $data['charged_currency'] );
			if ( isset( $data['exchange_rate'] ) ) {
				$note .= ' (taux ' . wc_clean( $data['exchange_rate'] ) . ')';
			}
			$note .= '.';
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
			$this->log( 'Webhook ignoré : paiement inconnu ' . $payment_id );
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
			$this->log( 'Vérification impossible pour ' . $payment_id );
			wp_send_json( array( 'error' => 'verification_failed' ), 503 );
		}

		$remote = $result['data'];

		if ( 'completed' === $remote['status'] ) {
			$same_ref      = isset( $remote['merchant_reference'] ) && $remote['merchant_reference'] === $order->get_meta( '_verzapay_reference' );
			$same_amount   = isset( $remote['amount'] ) && abs( (float) $remote['amount'] - (float) $order->get_total() ) < 0.01;
			$same_currency = isset( $remote['currency'] ) && strtoupper( $remote['currency'] ) === strtoupper( $order->get_currency() );

			if ( $same_ref && $same_amount && $same_currency ) {
				$order->payment_complete( $payment_id );
				$order->add_order_note( 'Paiement Verzapay confirmé (ID ' . $payment_id . ').' );
			} else {
				$order->add_order_note( 'Paiement Verzapay reçu mais montant, devise ou référence différents : vérification manuelle nécessaire (ID ' . $payment_id . ').' );
				$this->log( 'Incohérence pour la commande #' . $order->get_id() );
			}
		} elseif ( 'failed' === $remote['status'] ) {
			$order->update_status( 'failed', 'Paiement Verzapay échoué (ID ' . $payment_id . ').' );
		}

		wp_send_json( array( 'received' => true ), 200 );
	}
}
