<?php
/**
 * Storefront: decides in PHP who sees Camille, then loads the chat button without slowing the page.
 * The storefront never calls the Camille service: it reads the status cached by the admin and cron.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chat button, product context, shortcode and block.
 */
class Camille_PS_Frontend {

	const SCRIPT = 'camille-ps-widget';

	/**
	 * Languages the Camille chat speaks. Others fall back to English.
	 *
	 * @var string[]
	 */
	private static $chat_languages = array( 'it', 'en', 'fr', 'de', 'es' );

	/**
	 * Hooks the storefront.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'camille_button', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Whether the current visitor sees Camille. Never throws: any doubt means "no".
	 *
	 * @return bool
	 */
	public static function is_visible() {
		static $is_visible = null;
		if ( null !== $is_visible ) {
			return $is_visible;
		}
		$is_visible = false;
		if ( is_admin() || ! Camille_PS_Options::connection() ) {
			return false;
		}
		$status = Camille_PS_API::cached_status();
		if ( ! empty( $status['isDisabled'] ) || empty( $status['isActive'] ) ) {
			return false;
		}
		switch ( Camille_PS_Options::settings()['visibility'] ) {
			case Camille_PS_Options::VISIBILITY_EVERYONE:
				$is_visible = true;
				break;
			case Camille_PS_Options::VISIBILITY_CUSTOMERS:
				$is_visible = is_user_logged_in();
				break;
			case Camille_PS_Options::VISIBILITY_ADMINS:
				$is_visible = current_user_can( 'manage_woocommerce' );
				break;
			case Camille_PS_Options::VISIBILITY_CHOSEN:
				$is_visible = current_user_can( 'manage_woocommerce' ) || Camille_PS_Customers::is_chosen( get_current_user_id() );
				break;
		}
		/**
		 * Filters whether the current visitor sees Camille.
		 *
		 * @param bool $is_visible Decision from the plugin settings.
		 */
		$is_visible = (bool) apply_filters( 'camille_ps_is_visible', $is_visible );
		return $is_visible;
	}

	/**
	 * Two-letter language of the page, for the chat and its button.
	 *
	 * @return string
	 */
	private static function page_language() {
		return strtolower( substr( determine_locale(), 0, 2 ) );
	}

	/**
	 * Loads camille-woo.js (deferred, in the footer) with its settings.
	 */
	public static function enqueue() {
		if ( ! self::is_visible() ) {
			return;
		}
		$connection = Camille_PS_Options::connection();
		$settings   = Camille_PS_Options::settings();
		$language   = self::page_language();
		$home_path  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$config     = array(
			'storeId'           => $connection['store_id'],
			'chatUrl'           => untrailingslashit( CAMILLE_PS_CHAT_URL ),
			'apiUrl'            => untrailingslashit( CAMILLE_PS_API_URL ),
			'position'          => $settings['position'],
			'primaryColor'      => $settings['primary_color'],
			'launcherPlacement' => $settings['launcher_placement'],
			'showProductButton' => (bool) $settings['show_product_button'],
			'language'          => $language,
			'chatLanguage'      => in_array( $language, self::$chat_languages, true ) ? $language : 'en',
			/**
			 * Filters the CSS selector of the theme header, used to place the chat button below it.
			 *
			 * @param string $selector Selector, '' to detect the header automatically.
			 */
			'headerCssSelector' => (string) apply_filters( 'camille_ps_header_selector', '' ),
			'storefront'        => array(
				'language'   => $language,
				'country'    => WC()->countries ? WC()->countries->get_base_country() : '',
				'currency'   => get_woocommerce_currency(),
				'rootPath'   => $home_path ? $home_path : '/',
				'host'       => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
				// Where shoppers sign in or register, for the chat's "sign in" links.
				'accountUrl' => wc_get_page_permalink( 'myaccount' ),
			),
			'currentProduct'    => self::current_product(),
			'shopper'           => self::shopper_proof(),
		);
		/**
		 * Filters the settings given to the Camille chat button.
		 *
		 * @param array $config Settings.
		 */
		$config = apply_filters( 'camille_ps_widget_config', $config );
		wp_register_script(
			self::SCRIPT,
			CAMILLE_PS_URL . 'assets/js/camille-woo.js',
			array(),
			CAMILLE_PS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( self::SCRIPT, 'window.CamilleWooConfig = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_enqueue_script( self::SCRIPT );
	}

	/**
	 * Tells Camille who the visitor is, signed with the shop's key so nobody can pretend to be
	 * someone else. Shop managers are recognised as the merchant: no limit applies to them.
	 * Customers are known by their numeric account id only (no name, no email). Guests get nothing.
	 * The page is personal for logged-in visitors, so page caches do not share these values.
	 *
	 * @return array<string,string>|null
	 */
	private static function shopper_proof() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$key = Camille_PS_Options::widget_key();
		if ( '' === $key ) {
			return null;
		}
		$now = (string) time();
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return array(
				'dts'  => $now,
				'dsig' => hash_hmac( 'sha256', 'camille-design-v1.' . $now, $key ),
			);
		}
		$user_id = (string) get_current_user_id();
		// Chosen customers carry the same mark as Shopify's camille-preview tag.
		$tag = Camille_PS_Customers::is_chosen( get_current_user_id() ) ? '1' : '0';
		return array(
			'customerId' => $user_id,
			'cid'        => $user_id,
			'tag'        => $tag,
			'ts'         => $now,
			'sig'        => hash_hmac( 'sha256', 'camille-proof-v1.' . $user_id . '.' . $tag . '.' . $now, $key ),
		);
	}

	/**
	 * The product shown on a product page, for "Try with Camille".
	 *
	 * @return array|null
	 */
	private static function current_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$image_ids = array_slice( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) ), 0, 3 );
		$images    = array_values( array_filter( array_map( static fn( $id ) => (string) wp_get_attachment_image_url( $id, 'large' ), $image_ids ) ) );
		$terms     = get_the_terms( $product->get_id(), 'product_cat' );
		return array(
			'id'               => $product->get_id(),
			'title'            => wp_strip_all_tags( $product->get_name() ),
			'price'            => wc_format_decimal( wc_get_price_to_display( $product ), wc_get_price_decimals() ),
			'currency'         => get_woocommerce_currency(),
			'image'            => $images ? $images[0] : '',
			'url'              => (string) get_permalink( $product->get_id() ),
			'type'             => is_array( $terms ) && $terms ? html_entity_decode( $terms[0]->name, ENT_QUOTES, 'UTF-8' ) : '',
			'productImageUrls' => $images,
		);
	}

	/**
	 * HTML of a button that opens Camille, empty when the visitor does not see her.
	 *
	 * @param string $label Button text, '' for the default.
	 * @param string $mode  tryon (with the product on product pages) or chat.
	 * @return string
	 */
	public static function button_html( $label = '', $mode = 'tryon' ) {
		if ( ! self::is_visible() ) {
			return '';
		}
		$mode  = 'chat' === $mode ? 'generic' : 'tryon';
		$status  = Camille_PS_API::cached_status();
		$persona = ! empty( $status['personaName'] ) ? (string) $status['personaName'] : 'Camille';
		/* translators: %s: name of the personal shopper (Camille unless the shop renamed her). */
		$label = '' !== trim( $label ) ? $label : sprintf( 'tryon' === $mode ? __( 'Try with %s', 'camille-personal-shopper' ) : __( 'Ask %s', 'camille-personal-shopper' ), $persona );
		return '<button type="button" class="camille-ps-open wp-element-button" data-camille-mode="' . esc_attr( $mode ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * [camille_button label="..." mode="tryon|chat"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => '',
				'mode'  => 'tryon',
			),
			is_array( $atts ) ? $atts : array(),
			'camille_button'
		);
		return self::button_html( (string) $atts['label'], (string) $atts['mode'] );
	}

	/**
	 * The "Camille button" block (block.json in blocks/camille-button).
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type( CAMILLE_PS_DIR . 'blocks/camille-button' );
		// Editor texts shipped in languages/ (the zip version; WordPress.org adds its own).
		if ( function_exists( 'generate_block_asset_handle' ) ) {
			wp_set_script_translations( generate_block_asset_handle( 'camille-personal-shopper/button', 'editorScript' ), 'camille-personal-shopper', CAMILLE_PS_DIR . 'languages' );
		}
	}
}
