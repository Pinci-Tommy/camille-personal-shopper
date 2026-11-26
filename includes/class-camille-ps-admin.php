<?php
/**
 * Settings page under WooCommerce > Camille: connection, products, catalog sync, who sees Camille,
 * usage limits, billing.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and form handlers.
 */
class Camille_PS_Admin {

	const PAGE       = 'camille-personal-shopper';
	const CAPABILITY = 'manage_woocommerce';
	/** Transient (plus the user id) that brings a refused billing form back filled in. */
	const BILLING_FORM = 'camille_ps_billing_form_';
	/** Admin-ajax action of the sync progress panel. */
	const PROGRESS_ACTION = 'camille_ps_sync_progress';

	/**
	 * Products the catalog can send, computed once per page view (estimate and counts).
	 *
	 * @var array|null|false False until computed.
	 */
	private static $index = false;

	/**
	 * Hooks the admin side.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CAMILLE_PS_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'connect_start', 'connect_verify', 'connect_cancel', 'save_settings', 'save_limits', 'save_filters', 'save_persona', 'save_billing', 'sync_start', 'disconnect' ) as $action ) {
			add_action( 'admin_post_camille_ps_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_' . self::PROGRESS_ACTION, array( __CLASS__, 'ajax_sync_progress' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Adds WooCommerce > Camille.
	 */
	public static function add_menu() {
		add_submenu_page( 'woocommerce', __( 'Camille', 'camille-personal-shopper' ), __( 'Camille', 'camille-personal-shopper' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'camille-personal-shopper' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles of the settings page, on that page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'camille-ps-admin', CAMILLE_PS_URL . 'assets/css/admin.css', array(), CAMILLE_PS_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".camille-ps-color").wpColorPicker();});' );
		if ( ! Camille_PS_Options::connection() ) {
			return;
		}
		wp_enqueue_script( 'camille-ps-admin', CAMILLE_PS_URL . 'assets/js/admin.js', array(), CAMILLE_PS_VERSION, true );
		wp_add_inline_script( 'camille-ps-admin', 'window.CamillePsAdmin = ' . wp_json_encode( self::script_data() ) . ';', 'before' );
	}

	/**
	 * Products the catalog can send (see Camille_PS_Catalog_Filter::product_index()).
	 *
	 * @return array|null
	 */
	private static function index() {
		if ( false === self::$index ) {
			self::$index = Camille_PS_Catalog_Filter::product_index();
		}
		return self::$index;
	}

	/**
	 * Data and texts of the settings page script: live estimate of the selection, progress panel.
	 *
	 * @return array
	 */
	private static function script_data() {
		$index = self::index();
		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'action'     => self::PROGRESS_ACTION,
			'nonce'      => wp_create_nonce( self::PROGRESS_ACTION ),
			'pollMs'     => 5000,
			'progress'   => self::progress_for_script(),
			// [id, lowest price, highest price, category ids, left out by hand]; null for very large catalogs.
			'products'   => $index,
			'categories' => array_map(
				static fn( $category ) => array( $category['id'], $category['parent'] ),
				Camille_PS_Catalog_Filter::category_list()
			),
			'texts'      => array(
				/* translators: 1: products Camille will know, 2: products that can be sent. */
				'estimate'    => __( 'Camille will know %1$s of %2$s products.', 'camille-personal-shopper' ),
				'stages'      => array(
					'reading'  => __( 'I am browsing your catalog…', 'camille-personal-shopper' ),
					'saving'   => __( 'I am saving your products…', 'camille-personal-shopper' ),
					'studying' => __( 'I am studying your pieces…', 'camille-personal-shopper' ),
					'ready'    => __( 'I have studied your pieces. I am ready!', 'camille-personal-shopper' ),
					'updated'  => __( 'Your catalog is up to date.', 'camille-personal-shopper' ),
					'failed'   => __( 'The sync stopped.', 'camille-personal-shopper' ),
				),
				'lines'       => array(
					'reading' => array(
						__( 'I read names, prices, sizes and colours of every product…', 'camille-personal-shopper' ),
						__( 'I collect the photos of your products…', 'camille-personal-shopper' ),
					),
					'saving'  => array(
						__( 'I put every colour of a product in my catalog as a separate piece…', 'camille-personal-shopper' ),
						__( 'I check that every piece has a price and a photo…', 'camille-personal-shopper' ),
					),
					'studying' => array(
						__( 'I look at the photo of every piece, one by one…', 'camille-personal-shopper' ),
						__( 'I look at colours and shades to understand what goes well together…', 'camille-personal-shopper' ),
						__( 'I study the fabrics: linen, wool, silk, denim…', 'camille-personal-shopper' ),
						__( 'I look at cuts and lengths to suggest the right fit…', 'camille-personal-shopper' ),
						__( 'I think about the right occasions: office, dinner, ceremony, weekend…', 'camille-personal-shopper' ),
						__( 'I imagine the first outfits for your customers…', 'camille-personal-shopper' ),
					),
				),
				/* translators: 1: products sent, 2: products to send. */
				'readCount'   => __( '%1$s of %2$s products read', 'camille-personal-shopper' ),
				/* translators: 1: pieces saved, 2: pieces to save. */
				'saveCount'   => __( '%1$s of %2$s pieces saved', 'camille-personal-shopper' ),
				/* translators: %s: minutes. */
				'minutesLeft' => __( 'About %s minutes left', 'camille-personal-shopper' ),
				'oneMinute'   => __( 'About a minute left', 'camille-personal-shopper' ),
				'almostDone'  => __( 'Almost done…', 'camille-personal-shopper' ),
				'busy'        => __( 'Pinci is busy: the import will continue by itself in a few minutes.', 'camille-personal-shopper' ),
				'leavePage'   => __( 'You can leave this page: the sync continues in the background.', 'camille-personal-shopper' ),
				'syncButton'  => __( 'Sync the catalog', 'camille-personal-shopper' ),
				'stepNames'   => array(
					__( 'Reading', 'camille-personal-shopper' ),
					__( 'Saving', 'camille-personal-shopper' ),
					__( 'Studying', 'camille-personal-shopper' ),
				),
			),
		);
	}

	/**
	 * The sync progress with its texts ready for the page.
	 *
	 * @return array
	 */
	private static function progress_for_script() {
		$progress                = Camille_PS_Catalog_Sync::progress();
		$progress['messageText'] = 'failed' === $progress['stage'] ? self::sync_error_text( $progress['message'] ) : '';
		$items                   = (int) $progress['items'];
		$left_out                = (int) max( $progress['rejectedCount'], count( $progress['rejected'] ) );
		/* translators: %s: number of pieces. */
		$progress['itemsText'] = sprintf( _n( '%s piece in Camille\'s catalog.', '%s pieces in Camille\'s catalog.', $items, 'camille-personal-shopper' ), number_format_i18n( $items ) );
		/* translators: %d: number of items. */
		$progress['leftOutText'] = $left_out ? sprintf( _n( '%d item left out', '%d items left out', $left_out, 'camille-personal-shopper' ), $left_out ) : '';
		foreach ( $progress['rejected'] as $key => $item ) {
			$progress['rejected'][ $key ]['reasonText'] = self::reason_text( $item['reason'] );
		}
		return $progress;
	}

	/**
	 * Admin-ajax: where the sync is, for the progress panel.
	 */
	public static function ajax_sync_progress() {
		if ( ! current_user_can( self::CAPABILITY ) || ! check_ajax_referer( self::PROGRESS_ACTION, 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		nocache_headers();
		wp_send_json_success( self::progress_for_script() );
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ---------------------------------------------------------------- notices */

	/**
	 * Keeps a message for the next page view of the current user.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Text, may contain safe links.
	 */
	private static function notice( $type, $message ) {
		set_transient( 'camille_ps_notice_' . get_current_user_id(), array( $type, $message ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Keeps a message for the next page view (for the other admin screens of the plugin).
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Escaped text, may contain safe links.
	 */
	public static function add_notice( $type, $message ) {
		self::notice( $type, $message );
	}

	/**
	 * Shows and forgets the stored message.
	 */
	private static function render_notice() {
		$key    = 'camille_ps_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . wp_kses(
			$notice[1],
			array(
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
				'strong' => array(),
			)
		) . '</p></div>';
	}

	/**
	 * Checks the nonce and the capability of a form post, then returns its fields.
	 *
	 * @param string $action Form action.
	 * @return array
	 */
	private static function verified_post( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'camille-personal-shopper' ), 403 );
		}
		check_admin_referer( 'camille_ps_' . $action );
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized where it is read.
	}

	/**
	 * Back to a section of the settings page.
	 *
	 * @param string $tab    Section, the first one when empty.
	 * @param string $anchor Part of the section to scroll to, e.g. #camille-ps-products.
	 */
	private static function back( $tab = '', $anchor = '' ) {
		wp_safe_redirect( self::tab_url( '' === $tab ? self::TAB_CATALOG : $tab ) . $anchor );
		exit;
	}

	/* ---------------------------------------------------------------- handlers */

	/**
	 * Step 1 of the connection: a code is emailed to the Pinci account.
	 */
	public static function handle_connect_start() {
		$post  = self::verified_post( 'connect_start' );
		$email = isset( $post['email'] ) ? sanitize_email( (string) $post['email'] ) : '';
		if ( ! is_email( $email ) ) {
			self::notice( 'error', __( 'Please enter a valid email address.', 'camille-personal-shopper' ) );
			self::back();
		}
		$result = Camille_PS_API::post_public(
			'/v1/connect/start',
			array(
				'email'     => $email,
				'siteUrl'   => home_url( '/' ),
				'storeName' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'locale'    => substr( get_user_locale(), 0, 2 ),
			)
		);
		if ( ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['body']['status'], $result['body']['attemptId'] ) && 'code_sent' === $result['body']['status'] ) {
			Camille_PS_Options::save_pending( sanitize_text_field( (string) $result['body']['attemptId'] ), $email, (int) ( $result['body']['expiresInSeconds'] ?? 900 ) );
			/* translators: %s: email address. */
			self::notice( 'success', sprintf( __( 'We sent a 6-digit code to %s. It is valid for 15 minutes.', 'camille-personal-shopper' ), '<strong>' . esc_html( $email ) . '</strong>' ) );
		} elseif ( ! is_wp_error( $result ) && 400 === $result['code'] && ! empty( $result['body']['fields']['siteUrl'] ) ) {
			self::notice( 'error', __( 'Camille needs your shop to be served over HTTPS. Enable HTTPS and try again.', 'camille-personal-shopper' ) );
		} else {
			self::notice( 'error', Camille_PS_API::error_message( $result ) );
		}
		self::back();
	}

	/**
	 * Step 2 of the connection: the code from the email.
	 */
	public static function handle_connect_verify() {
		$post    = self::verified_post( 'connect_verify' );
		$pending = Camille_PS_Options::pending();
		$code    = isset( $post['code'] ) ? preg_replace( '/\D/', '', (string) $post['code'] ) : '';
		if ( ! $pending ) {
			self::notice( 'error', __( 'The code has expired. Please ask for a new one.', 'camille-personal-shopper' ) );
			self::back();
		}
		if ( 6 !== strlen( $code ) ) {
			self::notice( 'error', __( 'The code has 6 digits.', 'camille-personal-shopper' ) );
			self::back();
		}
		$result = Camille_PS_API::post_public(
			'/v1/connect/verify',
			array(
				'attemptId' => $pending['attempt_id'],
				'code'      => $code,
			)
		);
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['status'] ) ) {
			self::notice( 'error', Camille_PS_API::error_message( $result ) );
			self::back();
		}
		self::apply_verify_result( $result['body'], $pending['email'] );
		self::back();
	}

	/**
	 * Turns the service's answer into the connection or a message.
	 *
	 * @param array  $body  Answer.
	 * @param string $email Email used.
	 */
	private static function apply_verify_result( array $body, $email ) {
		$signup = isset( $body['signupUrl'] ) ? esc_url( (string) $body['signupUrl'] ) : 'https://pinci.ai/signup';
		switch ( (string) $body['status'] ) {
			case 'connected':
				Camille_PS_Options::save_connection( sanitize_text_field( (string) $body['storeId'] ), (string) $body['secret'], $email );
				// A new store starts paused on Pinci: follow the visibility setting (administrators only by default).
				$settings = Camille_PS_Options::settings();
				Camille_PS_API::signed( 'POST', '/v1/shop/active', array( 'isActive' => Camille_PS_Options::VISIBILITY_OFF !== $settings['visibility'] ) );
				Camille_PS_API::refresh_status( true );
				$message = __( 'Your shop is connected to Camille. Now send her your catalog.', 'camille-personal-shopper' );
				if ( ! empty( $body['isWelcomeGranted'] ) ) {
					$message .= ' ' . __( 'You received 300 free credits to get started.', 'camille-personal-shopper' );
				}
				self::notice( 'success', $message );
				return;
			case 'wrong_code':
				/* translators: %d: tries left. */
				self::notice( 'error', sprintf( _n( 'Wrong code. %d try left.', 'Wrong code. %d tries left.', (int) $body['triesLeft'], 'camille-personal-shopper' ), (int) $body['triesLeft'] ) );
				return;
			case 'not_found':
				Camille_PS_Options::clear_pending();
				/* translators: %s: sign-up link. */
				self::notice( 'error', sprintf( __( 'There is no Pinci account with this email. %s, then connect the shop again.', 'camille-personal-shopper' ), '<a href="' . $signup . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create your account', 'camille-personal-shopper' ) . '</a>' ) );
				return;
			case 'not_verified':
				Camille_PS_Options::clear_pending();
				self::notice( 'error', __( 'Your Pinci account is not verified yet. Open the confirmation email Pinci sent you, then connect the shop again.', 'camille-personal-shopper' ) );
				return;
			case 'shopify_account':
				Camille_PS_Options::clear_pending();
				self::notice( 'error', __( 'This email belongs to a Shopify store on Pinci. Use another Pinci account for your WooCommerce shop.', 'camille-personal-shopper' ) );
				return;
			case 'domain_taken':
				Camille_PS_Options::clear_pending();
				self::notice( 'error', __( 'This website is already connected to another Pinci account. Contact support@pinci.ai.', 'camille-personal-shopper' ) );
				return;
			case 'blocked':
				Camille_PS_Options::clear_pending();
				self::notice( 'error', __( 'This Pinci account is blocked. Contact support@pinci.ai.', 'camille-personal-shopper' ) );
				return;
			default:
				Camille_PS_Options::clear_pending();
				self::notice( 'error', __( 'The code has expired or was tried too many times. Please ask for a new one.', 'camille-personal-shopper' ) );
		}
	}

	/**
	 * Back to the email step.
	 */
	public static function handle_connect_cancel() {
		self::verified_post( 'connect_cancel' );
		Camille_PS_Options::clear_pending();
		self::back();
	}

	/**
	 * Saves who sees Camille and how she looks. "Off" also pauses Camille on Pinci's side.
	 */
	public static function handle_save_settings() {
		$post     = self::verified_post( 'save_settings' );
		$tab      = isset( $post['position'] ) ? self::TAB_PERSONALIZE : self::TAB_VISIBILITY;
		$settings = Camille_PS_Options::save_settings( is_array( $post ) ? $post : array() );
		$is_on    = Camille_PS_Options::VISIBILITY_OFF !== $settings['visibility'];
		$status   = Camille_PS_API::cached_status();
		if ( ! isset( $status['isActive'] ) || (bool) $status['isActive'] !== $is_on ) {
			$result = Camille_PS_API::signed( 'POST', '/v1/shop/active', array( 'isActive' => $is_on ) );
			if ( is_wp_error( $result ) || 200 !== $result['code'] ) {
				self::notice( 'warning', __( 'Settings saved, but Camille could not be switched on or off right now:', 'camille-personal-shopper' ) . ' ' . Camille_PS_API::error_message( $result ) );
				self::back( $tab );
			}
			Camille_PS_API::remember_status( array( 'isActive' => $is_on ) );
		}
		self::notice( 'success', __( 'Settings saved.', 'camille-personal-shopper' ) );
		self::back( $tab );
	}

	/**
	 * Saves the usage limits on Camille's service, which applies them to every chat.
	 */
	public static function handle_save_limits() {
		$post    = self::verified_post( 'save_limits' );
		$payload = array(
			'guestChatsPerDay'        => isset( $post['guest_chats_per_day'] ) ? absint( $post['guest_chats_per_day'] ) : 0,
			'guestTryOnsEnabled'      => ! empty( $post['guest_try_ons_enabled'] ),
			'tryOnsPerCustomerPerDay' => isset( $post['try_ons_per_customer_per_day'] ) ? absint( $post['try_ons_per_customer_per_day'] ) : 0,
		);
		$result  = Camille_PS_API::signed( 'POST', '/v1/shop/limits', $payload );
		if ( ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['body']['shopperLimits'] ) && is_array( $result['body']['shopperLimits'] ) ) {
			Camille_PS_API::remember_status( array( 'shopperLimits' => Camille_PS_API::clean_shopper_limits( $result['body']['shopperLimits'] ) ) );
			self::notice( 'success', __( 'Usage limits saved. They apply from the next message in the chat.', 'camille-personal-shopper' ) );
		} elseif ( ! is_wp_error( $result ) && 400 === $result['code'] ) {
			self::notice( 'error', __( 'One of the limits is out of range. Check the values and try again.', 'camille-personal-shopper' ) );
		} else {
			self::notice( 'error', Camille_PS_API::error_message( $result ) );
		}
		self::back( self::TAB_LIMITS );
	}

	/** Uploaded photos above this size are refused before anything else. */
	const AVATAR_MAX_BYTES = 10485760;

	/**
	 * Saves the name, gender and photo of the personal shopper. A new photo is shrunk here and sent
	 * first, then chosen.
	 */
	public static function handle_save_persona() {
		$post   = self::verified_post( 'save_persona' );
		$name   = isset( $post['persona_name'] ) ? sanitize_text_field( (string) $post['persona_name'] ) : '';
		$gender = isset( $post['persona_gender'] ) && 'male' === $post['persona_gender'] ? 'male' : 'female';
		$avatar = isset( $post['persona_avatar'] ) ? sanitize_key( (string) $post['persona_avatar'] ) : '';
		$upload = self::read_avatar_upload();
		if ( is_wp_error( $upload ) ) {
			self::notice( 'error', $upload->get_error_message() );
			self::back( self::TAB_PERSONALIZE );
		}
		if ( null !== $upload ) {
			$result = Camille_PS_API::signed( 'POST', '/v1/shop/persona/avatar', array( 'imageBase64' => $upload ), 60 );
			if ( is_wp_error( $result ) || 200 !== $result['code'] ) {
				self::notice( 'error', ! is_wp_error( $result ) && 400 === $result['code'] ? __( 'Camille could not use this photo. Use a JPEG, PNG or WebP image of up to 10 MB.', 'camille-personal-shopper' ) : Camille_PS_API::error_message( $result ) );
				self::back( self::TAB_PERSONALIZE );
			}
			$avatar = 'custom';
		}
		$result = Camille_PS_API::signed(
			'POST',
			'/v1/shop/persona',
			array(
				'name'           => $name,
				'gender'         => $gender,
				'avatarPresetId' => $avatar,
			)
		);
		if ( ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['body']['persona']['name'] ) ) {
			Camille_PS_API::remember_status( array( 'personaName' => sanitize_text_field( (string) $result['body']['persona']['name'] ) ) );
			self::notice( 'success', __( 'Personal shopper saved. Your customers see the new name and photo from their next visit.', 'camille-personal-shopper' ) );
		} elseif ( ! is_wp_error( $result ) && 400 === $result['code'] ) {
			$detail = isset( $result['body']['detail'] ) ? (string) $result['body']['detail'] : '';
			if ( 0 === strpos( $detail, 'name' ) || '' === $detail ) {
				self::notice( 'error', __( 'The name must have 2 to 30 characters and contain only letters, spaces, apostrophes, dots and hyphens.', 'camille-personal-shopper' ) );
			} elseif ( false !== strpos( $detail, 'gender' ) ) {
				self::notice( 'error', __( 'Choose a photo that matches the gender, or your own photo.', 'camille-personal-shopper' ) );
			} else {
				self::notice( 'error', __( 'Choose a photo from the gallery or upload your own.', 'camille-personal-shopper' ) );
			}
		} else {
			self::notice( 'error', Camille_PS_API::error_message( $result ) );
		}
		self::back( self::TAB_PERSONALIZE );
	}

	/**
	 * The photo uploaded with the persona form, shrunk to 1024 px and base64-encoded.
	 *
	 * @return string|null|WP_Error Null when no photo was uploaded.
	 */
	private static function read_avatar_upload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_save_persona().
		if ( empty( $_FILES['persona_photo'] ) || ! isset( $_FILES['persona_photo']['error'], $_FILES['persona_photo']['tmp_name'], $_FILES['persona_photo']['size'] ) ) {
			return null;
		}
		$error = (int) $_FILES['persona_photo']['error'];
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return null;
		}
		$tmp  = sanitize_text_field( wp_unslash( $_FILES['persona_photo']['tmp_name'] ) );
		$size = (int) $_FILES['persona_photo']['size'];
		// phpcs:enable
		$invalid = new WP_Error( 'camille_ps_photo', __( 'Camille could not use this photo. Use a JPEG, PNG or WebP image of up to 10 MB.', 'camille-personal-shopper' ) );
		if ( UPLOAD_ERR_OK !== $error || $size <= 0 || $size > self::AVATAR_MAX_BYTES || ! is_uploaded_file( $tmp ) ) {
			return $invalid;
		}
		$info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $tmp ) : getimagesize( $tmp );
		if ( ! is_array( $info ) || ! in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return $invalid;
		}
		$path   = $tmp;
		$editor = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1024, 1024, false );
			$editor->set_quality( 88 );
			$saved = $editor->save( wp_tempnam( 'camille-ps-photo' ) . '.jpg', 'image/jpeg' );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
				$path = $saved['path'];
			}
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file.
		if ( $path !== $tmp ) {
			wp_delete_file( $path );
		}
		if ( false === $bytes || strlen( $bytes ) > 1400000 ) {
			return $invalid; // Could not shrink it: too heavy to send.
		}
		return base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image sent as JSON.
	}

	/**
	 * Saves which products Camille suggests, and syncs the catalog when asked.
	 */
	public static function handle_save_filters() {
		$post     = self::verified_post( 'save_filters' );
		$included = isset( $post['included_categories'] ) && is_array( $post['included_categories'] ) ? array_map( 'absint', $post['included_categories'] ) : array();
		$all      = wp_list_pluck( Camille_PS_Catalog_Filter::category_list(), 'id' );
		$excluded = array_values( array_diff( array_map( 'intval', $all ), $included ) );
		$min      = isset( $post['price_min'] ) && '' !== trim( (string) $post['price_min'] ) ? (float) wc_format_decimal( (string) $post['price_min'] ) : null;
		$max      = isset( $post['price_max'] ) && '' !== trim( (string) $post['price_max'] ) ? (float) wc_format_decimal( (string) $post['price_max'] ) : null;
		Camille_PS_Options::save_catalog_filters( $excluded, $min, $max );
		if ( empty( $post['then_sync'] ) ) {
			self::notice( 'success', __( 'Selection saved. Sync the catalog to update Camille.', 'camille-personal-shopper' ) );
			self::back( self::TAB_CATALOG, '#camille-ps-products' );
		}
		$result = Camille_PS_Catalog_Sync::start();
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
		} else {
			self::notice( 'success', __( 'Selection saved. Camille is updating her catalog.', 'camille-personal-shopper' ) );
		}
		self::back( self::TAB_CATALOG, '#camille-ps-catalog' );
	}

	/**
	 * Sends the billing details to Camille's service, which checks them. On errors the form comes back
	 * filled in, with the wrong fields marked.
	 */
	public static function handle_save_billing() {
		$post    = self::verified_post( 'save_billing' );
		$payload = array();
		foreach ( Camille_PS_Options::billing_fields() as $field ) {
			$payload[ $field ] = isset( $post[ $field ] ) ? sanitize_text_field( (string) $post[ $field ] ) : '';
		}
		$result = Camille_PS_API::signed( 'POST', '/v1/shop/billing', $payload );
		if ( ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['body']['billing'] ) && is_array( $result['body']['billing'] ) ) {
			Camille_PS_Options::save_billing( $result['body']['billing'] );
			Camille_PS_API::remember_status( array( 'hasBilling' => true ) );
			self::notice( 'success', __( 'Billing details saved.', 'camille-personal-shopper' ) );
			self::back( self::TAB_BILLING );
		}
		$errors = ! is_wp_error( $result ) && 400 === $result['code'] && isset( $result['body']['fields'] ) && is_array( $result['body']['fields'] ) ? $result['body']['fields'] : array();
		set_transient(
			self::BILLING_FORM . get_current_user_id(),
			array(
				'values' => $payload,
				'errors' => array_map( 'sanitize_key', $errors ),
			),
			5 * MINUTE_IN_SECONDS
		);
		self::notice( 'error', $errors ? __( 'Some billing details are missing or not valid. Check the marked fields.', 'camille-personal-shopper' ) : Camille_PS_API::error_message( $result ) );
		self::back( self::TAB_BILLING );
	}

	/**
	 * Starts the catalog sync.
	 */
	public static function handle_sync_start() {
		self::verified_post( 'sync_start' );
		$result = Camille_PS_Catalog_Sync::start();
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
		} else {
			self::notice( 'success', __( 'Catalog sync started. It runs in the background: you can leave this page.', 'camille-personal-shopper' ) );
		}
		self::back( self::TAB_CATALOG, '#camille-ps-catalog' );
	}

	/**
	 * Disconnects the shop: Camille disappears and the secret stops working.
	 */
	public static function handle_disconnect() {
		self::verified_post( 'disconnect' );
		Camille_PS_Catalog_Sync::cancel();
		$result = Camille_PS_API::signed( 'POST', '/v1/shop/disconnect' );
		Camille_PS_Options::forget_connection();
		delete_option( Camille_PS_API::STATUS_CACHE );
		if ( is_wp_error( $result ) || 200 !== $result['code'] ) {
			self::notice( 'warning', __( 'The shop was disconnected here, but Camille could not be told. Write to support@pinci.ai to close it on Pinci too.', 'camille-personal-shopper' ) );
		} else {
			self::notice( 'success', __( 'The shop is disconnected. Camille no longer appears on it.', 'camille-personal-shopper' ) );
		}
		self::back();
	}

	/* ---------------------------------------------------------------- page */

	/**
	 * Prints a form that posts to admin-post.php.
	 *
	 * @param string   $action Action name.
	 * @param callable $fields Prints the fields.
	 * @param string   $css_class Extra class of the form.
	 * @param bool     $has_file  Whether the form uploads a file.
	 */
	private static function form( $action, $fields, $css_class = '', $has_file = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $css_class ) . '"' . ( $has_file ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'camille_ps_' . $action ) . '">';
		wp_nonce_field( 'camille_ps_' . $action );
		$fields();
		echo '</form>';
	}

	/**
	 * The settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$connection = Camille_PS_Options::connection();
		$status     = array();
		$warning    = '';
		if ( $connection ) {
			$status = Camille_PS_API::refresh_status();
			if ( is_wp_error( $status ) ) {
				$warning = $status->get_error_message();
				$status  = Camille_PS_API::cached_status();
			}
		}
		echo '<div class="wrap camille-ps">';
		self::render_header( $connection ? $status : null );
		echo '<hr class="wp-header-end">';
		self::render_notice();
		if ( '' !== $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}
		if ( ! $connection ) {
			echo '<div class="camille-ps-connect">';
			self::render_connect();
			echo '</div></div>';
			return;
		}
		$tab = self::current_tab();
		echo '<div class="camille-ps-layout">';
		self::render_nav( $tab );
		echo '<div class="camille-ps-main">';
		switch ( $tab ) {
			case self::TAB_PERSONALIZE:
				self::render_persona();
				self::render_appearance();
				break;
			case self::TAB_VISIBILITY:
				self::render_visibility();
				break;
			case self::TAB_LIMITS:
				self::render_limits( $status );
				break;
			case self::TAB_BILLING:
				self::render_billing( $connection, $status );
				self::render_disconnect();
				break;
			case Camille_PS_Activity::TAB_CONVERSATIONS:
				Camille_PS_Activity::render_conversations();
				break;
			case Camille_PS_Activity::TAB_TRY_ONS:
				Camille_PS_Activity::render_try_ons();
				break;
			default:
				self::render_status( $connection, $status );
				self::render_catalog();
				self::render_products();
		}
		echo '</div></div></div>';
	}

	/* ---------------------------------------------------------------- tabs */

	const TAB_CATALOG     = 'catalog';
	const TAB_PERSONALIZE = 'personalize';
	const TAB_VISIBILITY  = 'visibility';
	const TAB_LIMITS      = 'limits';
	const TAB_BILLING     = 'billing';

	/**
	 * Sections of the page, grouped as in the side menu.
	 *
	 * @return array<string,array<string,string>> Group title => tab => label.
	 */
	private static function tabs() {
		return array(
			__( 'Settings', 'camille-personal-shopper' )       => array(
				self::TAB_CATALOG     => __( 'Status and catalog', 'camille-personal-shopper' ),
				self::TAB_PERSONALIZE => __( 'Personalisation', 'camille-personal-shopper' ),
				self::TAB_VISIBILITY  => __( 'Visibility', 'camille-personal-shopper' ),
				self::TAB_LIMITS      => __( 'Usage limits', 'camille-personal-shopper' ),
				self::TAB_BILLING     => __( 'Billing', 'camille-personal-shopper' ),
			),
			__( 'Your customers', 'camille-personal-shopper' ) => array(
				Camille_PS_Activity::TAB_CONVERSATIONS => __( 'Conversations', 'camille-personal-shopper' ),
				Camille_PS_Activity::TAB_TRY_ONS       => __( 'Virtual try-ons', 'camille-personal-shopper' ),
			),
		);
	}

	/**
	 * The open section, from the query string.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		foreach ( self::tabs() as $items ) {
			if ( isset( $items[ $tab ] ) ) {
				return $tab;
			}
		}
		return self::TAB_CATALOG;
	}

	/**
	 * URL of a section, with optional query arguments.
	 *
	 * @param string $tab  Section.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	public static function tab_url( $tab, array $args = array() ) {
		if ( self::TAB_CATALOG !== $tab ) {
			$args = array_merge( array( 'tab' => $tab ), $args );
		}
		return $args ? add_query_arg( $args, self::page_url() ) : self::page_url();
	}

	/**
	 * Camille's header: name, photo, whether she is on and the credits left.
	 *
	 * @param array|null $status Last known status, null when the shop is not connected.
	 */
	private static function render_header( $status ) {
		$photo = is_array( $status ) && ! empty( $status['personaAvatarUrl'] ) && 0 === strpos( (string) $status['personaAvatarUrl'], 'https://' )
			? (string) $status['personaAvatarUrl']
			: CAMILLE_PS_URL . 'assets/images/camille-avatar.jpg';
		echo '<header class="camille-ps-header">';
		echo '<div class="camille-ps-brand"><img src="' . esc_url( $photo ) . '" alt="" width="48" height="48">';
		echo '<h1><span class="camille-ps-wordmark">Camille</span><span class="camille-ps-kicker">' . esc_html__( 'AI personal shopper', 'camille-personal-shopper' ) . '</span></h1></div>';
		if ( is_array( $status ) ) {
			if ( ! empty( $status['isDisabled'] ) ) {
				$pill = array( 'is-paused', __( 'Paused', 'camille-personal-shopper' ) );
			} elseif ( ! empty( $status['isActive'] ) ) {
				$pill = array( 'is-on', __( 'On', 'camille-personal-shopper' ) );
			} else {
				$pill = array( 'is-off', __( 'Off', 'camille-personal-shopper' ) );
			}
			echo '<div class="camille-ps-header-facts">';
			echo '<a class="camille-ps-pill ' . esc_attr( $pill[0] ) . '" href="' . esc_url( self::tab_url( self::TAB_VISIBILITY ) ) . '">' . esc_html( $pill[1] ) . '</a>';
			if ( isset( $status['credits'] ) && is_numeric( $status['credits'] ) ) {
				echo '<div class="camille-ps-credits"><span class="camille-ps-number">' . esc_html( number_format_i18n( (float) $status['credits'] ) ) . '</span><span class="camille-ps-kicker">' . esc_html__( 'Credits left', 'camille-personal-shopper' ) . '</span></div>';
			}
			if ( ! empty( $status['creditsUrl'] ) ) {
				echo '<a class="button camille-ps-button-quiet" href="' . esc_url( $status['creditsUrl'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy credits', 'camille-personal-shopper' ) . '</a>';
			}
			echo '</div>';
		}
		echo '</header>';
	}

	/**
	 * Side menu of the sections (a row of tabs on small screens).
	 *
	 * @param string $current Open section.
	 */
	private static function render_nav( $current ) {
		echo '<nav class="camille-ps-nav" aria-label="' . esc_attr__( 'Camille sections', 'camille-personal-shopper' ) . '">';
		foreach ( self::tabs() as $group => $items ) {
			echo '<p class="camille-ps-kicker">' . esc_html( $group ) . '</p><ul>';
			foreach ( $items as $tab => $label ) {
				$is_current = $tab === $current;
				echo '<li><a href="' . esc_url( self::tab_url( $tab ) ) . '"' . ( $is_current ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</nav>';
	}

	/**
	 * Adds a class to the body of the Camille page, for its background.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			$classes .= ' camille-ps-screen';
		}
		return $classes;
	}

	/**
	 * Email and code steps.
	 */
	private static function render_connect() {
		$pending = Camille_PS_Options::pending();
		echo '<div class="camille-ps-card">';
		echo '<h2>' . esc_html__( 'Connect your shop', 'camille-personal-shopper' ) . '</h2>';
		if ( ! $pending ) {
			echo '<p>' . esc_html__( 'Camille works with a Pinci account. Enter the email of your account: we will send you a code to confirm it is yours.', 'camille-personal-shopper' ) . '</p>';
			self::form(
				'connect_start',
				static function () {
					echo '<p><label for="camille-ps-email">' . esc_html__( 'Pinci account email', 'camille-personal-shopper' ) . '</label><br>';
					echo '<input type="email" id="camille-ps-email" name="email" class="regular-text" required autocomplete="email" value="' . esc_attr( wp_get_current_user()->user_email ) . '"></p>';
					submit_button( __( 'Send the code', 'camille-personal-shopper' ), 'primary', 'submit', false );
				}
			);
			echo '<p class="description">' . wp_kses(
				sprintf(
					/* translators: %s: sign-up link. */
					__( 'No account yet? %s, it is free and includes 300 credits to try Camille.', 'camille-personal-shopper' ),
					'<a href="https://pinci.ai/signup" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create one on pinci.ai', 'camille-personal-shopper' ) . '</a>'
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			) . '</p>';
		} else {
			/* translators: %s: email address. */
			echo '<p>' . wp_kses( sprintf( __( 'Enter the 6-digit code sent to %s.', 'camille-personal-shopper' ), '<strong>' . esc_html( $pending['email'] ) . '</strong>' ), array( 'strong' => array() ) ) . '</p>';
			self::form(
				'connect_verify',
				static function () {
					echo '<p><label for="camille-ps-code">' . esc_html__( 'Code', 'camille-personal-shopper' ) . '</label><br>';
					echo '<input type="text" id="camille-ps-code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required class="camille-ps-code"></p>';
					submit_button( __( 'Connect the shop', 'camille-personal-shopper' ), 'primary', 'submit', false );
				}
			);
			self::form(
				'connect_cancel',
				static function () {
					submit_button( __( 'Use another email or send a new code', 'camille-personal-shopper' ), 'link', 'submit', false );
				},
				'camille-ps-inline-form'
			);
		}
		echo '</div>';
	}

	/**
	 * Credits, last sync, account.
	 *
	 * @param array $connection Connection.
	 * @param array $status     Last known status.
	 */
	private static function render_status( array $connection, array $status ) {
		echo '<div class="camille-ps-card">';
		echo '<h2>' . esc_html__( 'Status', 'camille-personal-shopper' ) . '</h2>';
		if ( ! empty( $status['isDisabled'] ) ) {
			echo '<p class="camille-ps-badge is-warning">' . esc_html__( 'Camille is temporarily paused by Pinci. Your shop keeps working normally.', 'camille-personal-shopper' ) . '</p>';
		}
		echo '<table class="camille-ps-facts"><tbody>';
		echo '<tr><th>' . esc_html__( 'Pinci account', 'camille-personal-shopper' ) . '</th><td>' . esc_html( $connection['email'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Camille', 'camille-personal-shopper' ) . '</th><td>' . ( ! empty( $status['isActive'] ) ? esc_html__( 'On', 'camille-personal-shopper' ) : esc_html__( 'Off', 'camille-personal-shopper' ) ) . '</td></tr>';
		$credits = isset( $status['credits'] ) && is_numeric( $status['credits'] ) ? number_format_i18n( (float) $status['credits'] ) : '–';
		echo '<tr><th>' . esc_html__( 'Credits left', 'camille-personal-shopper' ) . '</th><td>' . esc_html( $credits );
		if ( ! empty( $status['creditsUrl'] ) ) {
			echo ' · <a href="' . esc_url( $status['creditsUrl'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy credits', 'camille-personal-shopper' ) . '</a>';
		}
		echo '</td></tr>';
		if ( empty( $status['hasBilling'] ) ) {
			echo '<tr><th></th><td><span class="camille-ps-badge is-warning">' . esc_html__( 'Before buying credits, enter your billing details so we can send you the invoice.', 'camille-personal-shopper' ) . '</span> <a href="' . esc_url( self::tab_url( self::TAB_BILLING ) ) . '">' . esc_html__( 'Billing details', 'camille-personal-shopper' ) . '</a></td></tr>';
		}
		$last_sync = ! empty( $status['lastCatalogSync'] ) ? strtotime( (string) $status['lastCatalogSync'] ) : false;
		echo '<tr><th>' . esc_html__( 'Last catalog sync', 'camille-personal-shopper' ) . '</th><td>' . ( $last_sync ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) ) : esc_html__( 'Never', 'camille-personal-shopper' ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Text of a sync error code.
	 *
	 * @param string $code Error code from the service or from this plugin.
	 * @return string
	 */
	private static function sync_error_text( $code ) {
		$texts = array(
			'catalog_limit'       => __( 'Your catalog is over the 5,000 product limit.', 'camille-personal-shopper' ),
			'too_many_products'   => __( 'Your catalog is over the 5,000 product limit.', 'camille-personal-shopper' ),
			'nothing_to_import'   => __( 'No product could be sent: check that your products have a price and a photo.', 'camille-personal-shopper' ),
			'pinci_unavailable'   => __( 'Pinci was not reachable for a long time. Start the sync again.', 'camille-personal-shopper' ),
			'service_unreachable' => __( 'Camille was not reachable for a long time. Start the sync again.', 'camille-personal-shopper' ),
			'store_not_found'     => __( 'Camille does not find this shop any more. Disconnect and connect it again.', 'camille-personal-shopper' ),
			'abandoned'           => __( 'The sync stopped halfway. Start it again.', 'camille-personal-shopper' ),
			'run_closed'          => __( 'A newer sync replaced this one.', 'camille-personal-shopper' ),
		);
		return $texts[ $code ] ?? __( 'The sync stopped because of an error. Start it again; if it happens again write to support@pinci.ai.', 'camille-personal-shopper' );
	}

	/**
	 * Text of a rejected product reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_text( $reason ) {
		$texts = array(
			'missing_name'     => __( 'no name', 'camille-personal-shopper' ),
			'missing_price'    => __( 'no price', 'camille-personal-shopper' ),
			'missing_image'    => __( 'no photo', 'camille-personal-shopper' ),
			'invalid_url'      => __( 'product address not valid', 'camille-personal-shopper' ),
			'invalid_currency' => __( 'currency not valid', 'camille-personal-shopper' ),
		);
		return $texts[ $reason ] ?? __( 'data not valid', 'camille-personal-shopper' );
	}

	/**
	 * Catalog sync button and the progress panel (filled and kept up to date by assets/js/admin.js).
	 */
	private static function render_catalog() {
		echo '<div class="camille-ps-card" id="camille-ps-catalog">';
		echo '<h2>' . esc_html__( 'Catalog', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Camille only suggests products she knows. Sync again after adding products or changing prices, so she always shows what you sell.', 'camille-personal-shopper' ) . '</p>';
		echo '<div class="camille-ps-progress" hidden aria-live="polite">';
		echo '<img class="camille-ps-avatar" src="' . esc_url( CAMILLE_PS_URL . 'assets/images/camille-avatar.jpg' ) . '" alt="" width="56" height="56">';
		echo '<div class="camille-ps-progress-body">';
		echo '<ol class="camille-ps-steps"></ol>';
		echo '<p class="camille-ps-progress-title"></p>';
		echo '<p class="camille-ps-progress-line"></p>';
		echo '<div class="camille-ps-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span></span></div>';
		echo '<p class="camille-ps-progress-meta description"></p>';
		echo '<div class="camille-ps-progress-extra"></div>';
		echo '</div></div>';
		echo '<noscript><p class="description">' . esc_html__( 'Reload the page to see how the sync is going.', 'camille-personal-shopper' ) . '</p></noscript>';
		self::form(
			'sync_start',
			static function () {
				submit_button( Camille_PS_Catalog_Sync::is_running() ? __( 'Restart the sync', 'camille-personal-shopper' ) : __( 'Sync the catalog', 'camille-personal-shopper' ), 'primary', 'submit', false );
			}
		);
		echo '</div>';
	}

	/**
	 * Name, gender and photo of the personal shopper (the same choices as on Pinci for Shopify).
	 */
	private static function render_persona() {
		echo '<div class="camille-ps-card" id="camille-ps-persona">';
		echo '<h2>' . esc_html__( 'Customise Camille', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Give your personal shopper the name and the face that suit your shop. Customers see them in the chat and on the chat button.', 'camille-personal-shopper' ) . '</p>';
		$result = Camille_PS_API::signed( 'GET', '/v1/shop/persona' );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['persona'] ) || ! is_array( $result['body']['persona'] ) ) {
			echo '<p class="camille-ps-badge is-warning">' . esc_html__( 'The personal shopper settings cannot be read right now. Please try again in a few minutes.', 'camille-personal-shopper' ) . '</p></div>';
			return;
		}
		$persona = $result['body']['persona'];
		$name    = isset( $persona['name'] ) ? (string) $persona['name'] : 'Camille';
		$gender  = isset( $persona['gender'] ) && 'male' === $persona['gender'] ? 'male' : 'female';
		$current = isset( $persona['avatarPresetId'] ) ? (string) $persona['avatarPresetId'] : '';
		$avatars = isset( $persona['avatars'] ) && is_array( $persona['avatars'] ) ? $persona['avatars'] : array();
		$photo = '';
		foreach ( $avatars as $avatar ) {
			if ( is_array( $avatar ) && isset( $avatar['id'], $avatar['imageUrl'] ) && $current === (string) $avatar['id'] ) {
				$photo = (string) $avatar['imageUrl'];
			}
		}
		Camille_PS_API::remember_status(
			array(
				'personaName'      => $name,
				'personaAvatarUrl' => $photo,
			)
		);
		self::form(
			'save_persona',
			static function () use ( $name, $gender, $current, $avatars ) {
				echo '<table class="form-table" role="presentation"><tbody>';
				echo '<tr><th scope="row"><label for="camille-ps-persona-name">' . esc_html__( 'Name', 'camille-personal-shopper' ) . '</label></th><td><input type="text" id="camille-ps-persona-name" name="persona_name" class="regular-text" minlength="2" maxlength="30" required value="' . esc_attr( $name ) . '"></td></tr>';
				echo '<tr><th scope="row">' . esc_html__( 'Gender', 'camille-personal-shopper' ) . '</th><td class="camille-ps-gender">';
				echo '<label><input type="radio" name="persona_gender" value="female" ' . checked( $gender, 'female', false ) . '> ' . esc_html__( 'Woman', 'camille-personal-shopper' ) . '</label> ';
				echo '<label><input type="radio" name="persona_gender" value="male" ' . checked( $gender, 'male', false ) . '> ' . esc_html__( 'Man', 'camille-personal-shopper' ) . '</label>';
				echo '</td></tr>';
				echo '<tr><th scope="row">' . esc_html__( 'Photo', 'camille-personal-shopper' ) . '</th><td><div class="camille-ps-avatars">';
				foreach ( $avatars as $avatar ) {
					if ( ! is_array( $avatar ) || empty( $avatar['id'] ) || empty( $avatar['imageUrl'] ) ) {
						continue;
					}
					$id        = sanitize_key( (string) $avatar['id'] );
					$is_custom = ! empty( $avatar['isCustom'] );
					echo '<label class="camille-ps-avatar-option" data-gender="' . esc_attr( $is_custom ? 'any' : (string) $avatar['gender'] ) . '"><input type="radio" name="persona_avatar" value="' . esc_attr( $id ) . '" ' . checked( $current, $id, false ) . '><img src="' . esc_url( (string) $avatar['imageUrl'] ) . '" alt="" width="72" height="72" loading="lazy">';
					if ( $is_custom ) {
						echo '<span>' . esc_html__( 'Your photo', 'camille-personal-shopper' ) . '</span>';
					}
					echo '</label>';
				}
				echo '</div>';
				echo '<p><label for="camille-ps-persona-photo">' . esc_html__( 'Or upload your own photo', 'camille-personal-shopper' ) . '</label><br><input type="file" id="camille-ps-persona-photo" name="persona_photo" accept="image/jpeg,image/png,image/webp"></p>';
				echo '<p class="description">' . esc_html__( 'JPEG, PNG or WebP, up to 10 MB. It is cut to a square from the centre: choose a portrait with the face in the middle. Use only photos you have the rights to.', 'camille-personal-shopper' ) . '</p>';
				echo '</td></tr></tbody></table>';
				submit_button( __( 'Save the personal shopper', 'camille-personal-shopper' ) );
			},
			'',
			true
		);
		echo '</div>';
	}

	/**
	 * Which products Camille may suggest: categories, price range, single products.
	 */
	private static function render_products() {
		$filters    = Camille_PS_Options::catalog_filters();
		$categories = Camille_PS_Catalog_Filter::category_list();
		$excluded   = array_flip( $filters['excluded_categories'] );
		$index      = self::index();
		$counts     = array();
		$lowest     = null;
		$highest    = null;
		$by_hand    = 0;
		$parents    = wp_list_pluck( $categories, 'parent', 'id' );
		foreach ( (array) $index as $row ) {
			// Each product counts once in its categories and in all their parents.
			$seen = array();
			foreach ( $row[3] as $term_id ) {
				for ( $guard = 0; $term_id && ! isset( $seen[ $term_id ] ) && $guard < 20; ++$guard ) {
					$seen[ $term_id ] = true;
					$term_id          = (int) ( $parents[ $term_id ] ?? 0 );
				}
			}
			foreach ( array_keys( $seen ) as $term_id ) {
				$counts[ $term_id ] = ( $counts[ $term_id ] ?? 0 ) + 1;
			}
			if ( null !== $row[1] ) {
				$lowest = null === $lowest ? $row[1] : min( $lowest, $row[1] );
			}
			if ( null !== $row[2] ) {
				$highest = null === $highest ? $row[2] : max( $highest, $row[2] );
			}
			$by_hand += $row[4];
		}
		echo '<div class="camille-ps-card" id="camille-ps-products">';
		echo '<h2>' . esc_html__( 'Which products Camille suggests', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose what Camille may suggest to your customers. Products left out are removed from Camille at the next sync.', 'camille-personal-shopper' ) . '</p>';
		self::form(
			'save_filters',
			static function () use ( $filters, $categories, $excluded, $counts, $lowest, $highest, $by_hand, $index ) {
				echo '<h3>' . esc_html__( 'Categories', 'camille-personal-shopper' ) . '</h3>';
				if ( ! $categories ) {
					echo '<p class="description">' . esc_html__( 'Your shop has no product categories.', 'camille-personal-shopper' ) . '</p>';
				} else {
					echo '<ul class="camille-ps-tree">';
					foreach ( $categories as $category ) {
						$is_on = ! isset( $excluded[ $category['id'] ] );
						echo '<li style="--camille-ps-depth:' . (int) $category['depth'] . '"><label><input type="checkbox" name="included_categories[]" value="' . (int) $category['id'] . '" data-parent="' . (int) $category['parent'] . '" ' . checked( $is_on, true, false ) . '> ' . esc_html( $category['name'] ) . ' <span class="camille-ps-count">(' . esc_html( number_format_i18n( $counts[ $category['id'] ] ?? 0 ) ) . ')</span>';
						if ( Camille_PS_Catalog_Filter::is_unsuitable_name( $category['name'] ) ) {
							echo ' <span class="camille-ps-badge is-warning camille-ps-hint">' . esc_html__( 'Usually not suited to Camille', 'camille-personal-shopper' ) . '</span>';
						}
						echo '</label></li>';
					}
					echo '</ul>';
					echo '<p class="description">' . esc_html__( 'Leaving out a category also leaves out its subcategories. A product in more than one category stays while at least one of them is selected. Camille works best with clothing, shoes and accessories: perfumes, home items, gift cards and similar products rarely help her build outfits.', 'camille-personal-shopper' ) . '</p>';
				}
				echo '<h3>' . esc_html__( 'Price range', 'camille-personal-shopper' ) . '</h3>';
				$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
				echo '<p class="camille-ps-price"><label>' . esc_html__( 'From', 'camille-personal-shopper' ) . ' <input type="number" name="price_min" min="0" step="0.01" class="small-text" value="' . esc_attr( null === $filters['price_min'] ? '' : wc_format_localized_decimal( $filters['price_min'] ) ) . '"> ' . esc_html( $symbol ) . '</label> ';
				echo '<label>' . esc_html__( 'to', 'camille-personal-shopper' ) . ' <input type="number" name="price_max" min="0" step="0.01" class="small-text" value="' . esc_attr( null === $filters['price_max'] ? '' : wc_format_localized_decimal( $filters['price_max'] ) ) . '"> ' . esc_html( $symbol ) . '</label></p>';
				$range_help = __( 'Leave a field empty for no limit. A product with several prices (variations) stays when at least one price is in the range.', 'camille-personal-shopper' );
				if ( null !== $lowest && null !== $highest ) {
					/* translators: 1: lowest price, 2: highest price. */
					$range_help .= ' ' . sprintf( __( 'Prices in your shop go from %1$s to %2$s.', 'camille-personal-shopper' ), wp_strip_all_tags( wc_price( $lowest ) ), wp_strip_all_tags( wc_price( $highest ) ) );
				}
				echo '<p class="description">' . esc_html( $range_help ) . '</p>';
				echo '<h3>' . esc_html__( 'Single products', 'camille-personal-shopper' ) . '</h3>';
				echo '<p class="description">' . wp_kses(
					sprintf(
						/* translators: %s: link to the products list. */
						__( 'To leave out single products, tick "Do not show this product to Camille" on the product page (General tab), or select them in %s and use the bulk action "Hide from Camille".', 'camille-personal-shopper' ),
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products', 'camille-personal-shopper' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>';
				if ( $by_hand ) {
					/* translators: %s: number of products. */
					echo '<p>' . esc_html( sprintf( _n( '%s product left out by hand.', '%s products left out by hand.', $by_hand, 'camille-personal-shopper' ), number_format_i18n( $by_hand ) ) ) . '</p>';
				}
				if ( is_array( $index ) ) {
					/* translators: 1: products Camille will know, 2: products that can be sent. */
					echo '<p class="camille-ps-estimate"><strong>' . esc_html( sprintf( __( 'Camille will know %1$s of %2$s products.', 'camille-personal-shopper' ), number_format_i18n( Camille_PS_Catalog_Filter::count_allowed( $index ) ), number_format_i18n( count( $index ) ) ) ) . '</strong></p>';
				}
				echo '<p class="submit">';
				submit_button( __( 'Save the selection', 'camille-personal-shopper' ), 'secondary', 'submit', false );
				echo ' ';
				submit_button( __( 'Save and sync the catalog', 'camille-personal-shopper' ), 'primary', 'then_sync', false );
				echo '</p>';
			}
		);
		echo '</div>';
	}

	/**
	 * How much each visitor may use Camille. Saved on Camille's service, which enforces it.
	 *
	 * @param array $status Last known status.
	 */
	private static function render_limits( array $status ) {
		$limits     = isset( $status['shopperLimits'] ) && is_array( $status['shopperLimits'] ) ? $status['shopperLimits'] : null;
		$visibility = Camille_PS_Options::settings()['visibility'];
		echo '<div class="camille-ps-card" id="camille-ps-limits">';
		echo '<h2>' . esc_html__( 'Usage limits for your customers', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every virtual try-on uses credits. These limits keep a single visitor from using up your credits. Camille applies them herself and, when a limit is reached, explains it kindly in the chat.', 'camille-personal-shopper' ) . '</p>';
		if ( Camille_PS_Options::VISIBILITY_ADMINS === $visibility ) {
			echo '<p class="camille-ps-badge is-info">' . esc_html__( 'Camille is visible only to shop administrators now, and they have no limits. These limits apply once you show her to your customers.', 'camille-personal-shopper' ) . '</p>';
		}
		if ( '' === Camille_PS_Options::widget_key() && ! Camille_PS_API::ensure_widget_key() ) {
			echo '<p class="camille-ps-badge is-warning">' . esc_html__( 'Camille cannot recognise logged-in customers yet, so for now she treats everyone as a visitor without an account. Reload this page in a minute; if this message stays, write to support@pinci.ai.', 'camille-personal-shopper' ) . '</p>';
		}
		if ( ! $limits ) {
			echo '<p class="camille-ps-badge is-warning">' . esc_html__( 'The limits cannot be read right now. Please try again in a few minutes.', 'camille-personal-shopper' ) . '</p></div>';
			return;
		}
		self::form(
			'save_limits',
			static function () use ( $limits, $visibility ) {
				$guests_hidden = Camille_PS_Options::VISIBILITY_EVERYONE !== $visibility;
				echo '<table class="form-table" role="presentation"><tbody>';
				echo '<tr><th scope="row" colspan="2" class="camille-ps-subhead">' . esc_html__( 'Visitors without an account', 'camille-personal-shopper' ) . '</th></tr>';
				echo '<tr><th scope="row"><label for="camille-ps-guest-chats">' . esc_html__( 'Chats per day', 'camille-personal-shopper' ) . '</label></th><td>';
				echo '<input type="number" id="camille-ps-guest-chats" name="guest_chats_per_day" min="0" max="' . (int) $limits['maxGuestChatsPerDay'] . '" step="1" class="small-text" value="' . (int) $limits['guestChatsPerDay'] . '">';
				/* translators: %d: highest value allowed. */
				echo '<p class="description">' . esc_html( sprintf( __( 'New chats a visitor without an account may start each day, counted from the first message they write (at most %d). With 0 Camille asks them to sign in or register before chatting.', 'camille-personal-shopper' ), (int) $limits['maxGuestChatsPerDay'] ) ) . '</p></td></tr>';
				echo '<tr><th scope="row">' . esc_html__( 'Virtual try-ons', 'camille-personal-shopper' ) . '</th><td><label><input type="checkbox" name="guest_try_ons_enabled" value="1" ' . checked( $limits['guestTryOnsEnabled'], true, false ) . '> ' . esc_html__( 'Allow virtual try-ons to visitors without an account', 'camille-personal-shopper' ) . '</label>';
				echo '<p class="description">' . esc_html__( 'When this is off, Camille advises them in the chat and invites them to sign in or register to try the clothes on. A good way to get more customers to create an account.', 'camille-personal-shopper' ) . '</p></td></tr>';
				if ( $guests_hidden ) {
					echo '<tr><th></th><td><p class="description">' . esc_html__( 'With your current choice in "Who sees Camille", visitors without an account do not see her: these two settings apply only if you show her to all visitors.', 'camille-personal-shopper' ) . '</p></td></tr>';
				}
				echo '<tr><th scope="row" colspan="2" class="camille-ps-subhead">' . esc_html__( 'Customers logged in to their account', 'camille-personal-shopper' ) . '</th></tr>';
				echo '<tr><th scope="row"><label for="camille-ps-customer-tryons">' . esc_html__( 'Try-ons per day', 'camille-personal-shopper' ) . '</label></th><td>';
				echo '<input type="number" id="camille-ps-customer-tryons" name="try_ons_per_customer_per_day" min="0" max="' . (int) $limits['maxTryOnsPerCustomerPerDay'] . '" step="1" class="small-text" value="' . (int) $limits['tryOnsPerCustomerPerDay'] . '">';
				/* translators: %d: highest value allowed. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Virtual try-ons each customer may make in 24 hours, on any device (at most %d). Try-ons that fail are not counted. With 0 customers can chat but not try clothes on.', 'camille-personal-shopper' ), (int) $limits['maxTryOnsPerCustomerPerDay'] ) ) . '</p></td></tr>';
				echo '</tbody></table>';
				echo '<p class="description">' . esc_html__( 'Shop administrators and shop managers have no limits, so you can test Camille freely while logged in.', 'camille-personal-shopper' ) . '</p>';
				submit_button( __( 'Save the limits', 'camille-personal-shopper' ) );
			}
		);
		echo '</div>';
	}

	/**
	 * Who sees Camille.
	 */
	private static function render_visibility() {
		$settings = Camille_PS_Options::settings();
		$labels   = array(
			Camille_PS_Options::VISIBILITY_OFF       => array( __( 'Off', 'camille-personal-shopper' ), __( 'Camille does not appear and does not use credits.', 'camille-personal-shopper' ) ),
			Camille_PS_Options::VISIBILITY_ADMINS    => array( __( 'Only shop administrators', 'camille-personal-shopper' ), __( 'To try Camille while logged in to WordPress, before your customers see her.', 'camille-personal-shopper' ) ),
			Camille_PS_Options::VISIBILITY_CHOSEN    => array( __( 'Administrators and chosen customers', 'camille-personal-shopper' ), __( 'Only the customers you choose, once logged in: tick "This customer can see Camille" on their profile, or select them in Users and use the bulk action "Show Camille".', 'camille-personal-shopper' ) ),
			Camille_PS_Options::VISIBILITY_CUSTOMERS => array( __( 'Only logged-in customers', 'camille-personal-shopper' ), __( 'Customers with an account on your shop, once logged in.', 'camille-personal-shopper' ) ),
			Camille_PS_Options::VISIBILITY_EVERYONE  => array( __( 'All visitors', 'camille-personal-shopper' ), __( 'Everyone who visits your shop.', 'camille-personal-shopper' ) ),
		);
		echo '<div class="camille-ps-card">';
		echo '<h2>' . esc_html__( 'Who sees Camille', 'camille-personal-shopper' ) . '</h2>';
		self::form(
			'save_settings',
			static function () use ( $settings, $labels ) {
				echo '<fieldset class="camille-ps-visibility">';
				foreach ( $labels as $value => $text ) {
					echo '<label><input type="radio" name="visibility" value="' . esc_attr( $value ) . '" ' . checked( $settings['visibility'], $value, false ) . '> <strong>' . esc_html( $text[0] ) . '</strong><span class="description">' . esc_html( $text[1] );
					if ( Camille_PS_Options::VISIBILITY_CHOSEN === $value ) {
						$chosen = Camille_PS_Customers::count_chosen();
						/* translators: %s: number of customers. */
						echo ' <a href="' . esc_url( Camille_PS_Customers::list_url() ) . '">' . esc_html( sprintf( _n( '%s customer chosen', '%s customers chosen', $chosen, 'camille-personal-shopper' ), number_format_i18n( $chosen ) ) ) . '</a>';
					}
					echo '</span></label>';
				}
				echo '</fieldset>';
				submit_button( __( 'Save', 'camille-personal-shopper' ) );
			}
		);
		echo '</div>';
	}

	/**
	 * Where the chat button sits and its colour.
	 */
	private static function render_appearance() {
		$settings = Camille_PS_Options::settings();
		echo '<div class="camille-ps-card">';
		echo '<h2>' . esc_html__( 'Appearance', 'camille-personal-shopper' ) . '</h2>';
		self::form(
			'save_settings',
			static function () use ( $settings ) {
				echo '<table class="form-table" role="presentation"><tbody>';
				echo '<tr><th scope="row"><label for="camille-ps-position">' . esc_html__( 'Chat button', 'camille-personal-shopper' ) . '</label></th><td><select id="camille-ps-position" name="position">';
				echo '<option value="bottom-right" ' . selected( $settings['position'], 'bottom-right', false ) . '>' . esc_html__( 'Bottom right', 'camille-personal-shopper' ) . '</option>';
				echo '<option value="bottom-left" ' . selected( $settings['position'], 'bottom-left', false ) . '>' . esc_html__( 'Bottom left', 'camille-personal-shopper' ) . '</option>';
				echo '</select> <select name="launcher_placement" aria-label="' . esc_attr__( 'Height of the chat button', 'camille-personal-shopper' ) . '">';
				echo '<option value="bottom" ' . selected( $settings['launcher_placement'], 'bottom', false ) . '>' . esc_html__( 'At the bottom of the screen', 'camille-personal-shopper' ) . '</option>';
				echo '<option value="below_header" ' . selected( $settings['launcher_placement'], 'below_header', false ) . '>' . esc_html__( 'Below the site header', 'camille-personal-shopper' ) . '</option>';
				echo '</select></td></tr>';
				echo '<tr><th scope="row"><label for="camille-ps-color">' . esc_html__( 'Colour', 'camille-personal-shopper' ) . '</label></th><td><input type="text" id="camille-ps-color" name="primary_color" class="camille-ps-color" value="' . esc_attr( $settings['primary_color'] ) . '" data-default-color="#7c3aed"></td></tr>';
				echo '<tr><th scope="row">' . esc_html__( 'Product pages', 'camille-personal-shopper' ) . '</th><td><label><input type="checkbox" name="show_product_button" value="1" ' . checked( $settings['show_product_button'], true, false ) . '> ' . esc_html__( 'Show the "Try with Camille" button below "Add to cart"', 'camille-personal-shopper' ) . '</label></td></tr>';
				echo '</tbody></table>';
				echo '<p class="description">' . wp_kses(
					__( 'You can also place a Camille button anywhere with the <strong>Camille button</strong> block or the shortcode <code>[camille_button]</code>.', 'camille-personal-shopper' ),
					array(
						'strong' => array(),
						'code'   => array(),
					)
				) . '</p>';
				submit_button( __( 'Save', 'camille-personal-shopper' ) );
			}
		);
		echo '</div>';
	}

	/**
	 * Labels and help texts of the billing fields.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function billing_labels() {
		return array(
			'companyName'  => array( __( 'Company name', 'camille-personal-shopper' ), '' ),
			'vatNumber'    => array( __( 'VAT number', 'camille-personal-shopper' ), '' ),
			'taxCode'      => array( __( 'Tax code', 'camille-personal-shopper' ), __( 'Italy only, if different from the VAT number.', 'camille-personal-shopper' ) ),
			'address'      => array( __( 'Address', 'camille-personal-shopper' ), '' ),
			'postcode'     => array( __( 'Postcode', 'camille-personal-shopper' ), '' ),
			'city'         => array( __( 'City', 'camille-personal-shopper' ), '' ),
			'province'     => array( __( 'Province', 'camille-personal-shopper' ), __( 'For Italy, the two letters (for example BS).', 'camille-personal-shopper' ) ),
			'country'      => array( __( 'Country', 'camille-personal-shopper' ), '' ),
			'sdiCode'      => array( __( 'SDI recipient code', 'camille-personal-shopper' ), __( 'Italy only: 7 characters. If you do not have one, enter your PEC below.', 'camille-personal-shopper' ) ),
			'pec'          => array( __( 'PEC', 'camille-personal-shopper' ), __( 'Italy only: certified email address for electronic invoices.', 'camille-personal-shopper' ) ),
			'invoiceEmail' => array( __( 'Email for invoices', 'camille-personal-shopper' ), '' ),
		);
	}

	/**
	 * Error text of a billing field.
	 *
	 * @param string $field   Field name.
	 * @param string $problem required or invalid.
	 * @return string
	 */
	private static function billing_error_text( $field, $problem ) {
		if ( 'sdiCode' === $field && 'required' === $problem ) {
			return __( 'Enter the SDI code or the PEC.', 'camille-personal-shopper' );
		}
		return 'required' === $problem ? __( 'Required.', 'camille-personal-shopper' ) : __( 'Not valid, please check it.', 'camille-personal-shopper' );
	}

	/**
	 * Billing details for the invoices of credit purchases.
	 *
	 * @param array $connection Connection.
	 * @param array $status     Last known status.
	 */
	private static function render_billing( array $connection, array $status ) {
		$form_key = self::BILLING_FORM . get_current_user_id();
		$returned = get_transient( $form_key );
		delete_transient( $form_key );
		$errors = is_array( $returned ) && isset( $returned['errors'] ) && is_array( $returned['errors'] ) ? $returned['errors'] : array();
		if ( is_array( $returned ) && isset( $returned['values'] ) && is_array( $returned['values'] ) ) {
			$values = $returned['values'];
		} else {
			$values = Camille_PS_Options::billing();
			// Saved before (another install of the plugin): read them back once from the service.
			if ( ! $values && ! empty( $status['hasBilling'] ) ) {
				$result = Camille_PS_API::signed( 'GET', '/v1/shop/billing' );
				if ( ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['body']['billing'] ) && is_array( $result['body']['billing'] ) ) {
					Camille_PS_Options::save_billing( $result['body']['billing'] );
					$values = Camille_PS_Options::billing();
				}
			}
		}
		$values    = array_merge(
			array(
				'country'      => WC()->countries ? WC()->countries->get_base_country() : '',
				'invoiceEmail' => $connection['email'],
			),
			array_filter( array_map( 'strval', $values ), 'strlen' )
		);
		$countries = WC()->countries ? WC()->countries->get_countries() : array();
		$labels    = self::billing_labels();

		echo '<div class="camille-ps-card" id="camille-ps-billing">';
		echo '<h2>' . esc_html__( 'Billing details', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Credits are invoiced by Instamark S.r.l., the company behind Camille. We use these details only for the invoices of your purchases.', 'camille-personal-shopper' ) . '</p>';
		self::form(
			'save_billing',
			static function () use ( $values, $errors, $countries, $labels ) {
				echo '<table class="form-table" role="presentation"><tbody>';
				foreach ( $labels as $field => $text ) {
					$id    = 'camille-ps-billing-' . $field;
					$value = isset( $values[ $field ] ) ? (string) $values[ $field ] : '';
					$error = isset( $errors[ $field ] ) ? (string) $errors[ $field ] : '';
					echo '<tr' . ( $error ? ' class="camille-ps-field-error"' : '' ) . '><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $text[0] ) . '</label></th><td>';
					if ( 'country' === $field && $countries ) {
						echo '<select id="' . esc_attr( $id ) . '" name="country">';
						foreach ( $countries as $code => $name ) {
							echo '<option value="' . esc_attr( $code ) . '" ' . selected( $value, $code, false ) . '>' . esc_html( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) . '</option>';
						}
						echo '</select>';
					} else {
						$type = in_array( $field, array( 'pec', 'invoiceEmail' ), true ) ? 'email' : 'text';
						echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( $error ? ' aria-invalid="true"' : '' ) . '>';
					}
					if ( $error ) {
						echo '<p class="camille-ps-error-text">' . esc_html( self::billing_error_text( $field, $error ) ) . '</p>';
					}
					if ( '' !== $text[1] ) {
						echo '<p class="description">' . esc_html( $text[1] ) . '</p>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				submit_button( __( 'Save billing details', 'camille-personal-shopper' ) );
			}
		);
		echo '</div>';
	}

	/**
	 * Disconnect button.
	 */
	private static function render_disconnect() {
		echo '<div class="camille-ps-card camille-ps-danger">';
		echo '<h2>' . esc_html__( 'Disconnect', 'camille-personal-shopper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Camille disappears from your shop. Your Pinci account, credits and catalog stay: connect again with the same email to pick up where you left off.', 'camille-personal-shopper' ) . '</p>';
		self::form(
			'disconnect',
			static function () {
				submit_button(
					__( 'Disconnect the shop', 'camille-personal-shopper' ),
					'delete',
					'submit',
					false,
					array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Disconnect the shop from Camille?', 'camille-personal-shopper' ) ) . ');' )
				);
			}
		);
		echo '</div>';
	}
}
