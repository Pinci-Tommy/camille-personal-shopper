<?php
/**
 * Everything the plugin stores, in a few options (none autoloaded except the display settings and the cached status).
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin options.
 */
class Camille_PS_Options {

	const CONNECTION = 'camille_ps_connection';
	const PENDING    = 'camille_ps_pending_connection';
	const SETTINGS   = 'camille_ps_settings';
	const SYNC       = 'camille_ps_sync';
	const BILLING    = 'camille_ps_billing';
	const WIDGET_KEY = 'camille_ps_widget_key';
	const FILTERS    = 'camille_ps_catalog_filters';

	const VISIBILITY_OFF       = 'off';
	const VISIBILITY_ADMINS    = 'admins';
	const VISIBILITY_CHOSEN    = 'chosen';
	const VISIBILITY_CUSTOMERS = 'customers';
	const VISIBILITY_EVERYONE  = 'everyone';

	/**
	 * Who can see Camille, in the order shown on the settings page.
	 *
	 * @return string[]
	 */
	public static function visibility_values() {
		return array( self::VISIBILITY_OFF, self::VISIBILITY_ADMINS, self::VISIBILITY_CHOSEN, self::VISIBILITY_CUSTOMERS, self::VISIBILITY_EVERYONE );
	}

	/**
	 * Display settings with their defaults. Camille starts visible to administrators only, so the
	 * merchant tries her before the customers do.
	 *
	 * @return array{visibility:string,position:string,primary_color:string,show_product_button:bool,launcher_placement:string}
	 */
	public static function settings() {
		$defaults = array(
			'visibility'          => self::VISIBILITY_ADMINS,
			'position'            => 'bottom-right',
			'primary_color'       => '#7c3aed',
			'show_product_button' => true,
			'launcher_placement'  => 'bottom',
		);
		$stored   = get_option( self::SETTINGS, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Cleans and saves the display settings.
	 *
	 * @param array $raw Values from the settings form.
	 * @return array The saved settings.
	 */
	public static function save_settings( array $raw ) {
		$current  = self::settings();
		$color    = isset( $raw['primary_color'] ) ? sanitize_hex_color( (string) $raw['primary_color'] ) : null;
		$settings = array(
			'visibility'          => isset( $raw['visibility'] ) && in_array( $raw['visibility'], self::visibility_values(), true ) ? $raw['visibility'] : $current['visibility'],
			'position'            => isset( $raw['position'] ) && 'bottom-left' === $raw['position'] ? 'bottom-left' : 'bottom-right',
			'primary_color'       => $color ? $color : $current['primary_color'],
			'show_product_button' => ! empty( $raw['show_product_button'] ),
			'launcher_placement'  => isset( $raw['launcher_placement'] ) && 'below_header' === $raw['launcher_placement'] ? 'below_header' : 'bottom',
		);
		update_option( self::SETTINGS, $settings, true );
		return $settings;
	}

	/**
	 * The shop's connection to Camille, or null when not connected.
	 *
	 * @return array{store_id:string,secret:string,email:string,connected_at:int}|null
	 */
	public static function connection() {
		$stored = get_option( self::CONNECTION, null );
		if ( ! is_array( $stored ) || empty( $stored['store_id'] ) || empty( $stored['secret'] ) ) {
			return null;
		}
		$secret = self::decrypt( (string) $stored['secret'] );
		if ( '' === $secret ) {
			return null;
		}
		return array(
			'store_id'     => (string) $stored['store_id'],
			'secret'       => $secret,
			'email'        => isset( $stored['email'] ) ? (string) $stored['email'] : '',
			'connected_at' => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : 0,
		);
	}

	/**
	 * Saves the connection. The secret is encrypted with the site's own keys.
	 *
	 * @param string $store_id Camille store id.
	 * @param string $secret   Signing secret, given once by the service.
	 * @param string $email    Email of the Pinci account.
	 */
	public static function save_connection( $store_id, $secret, $email ) {
		update_option(
			self::CONNECTION,
			array(
				'store_id'     => $store_id,
				'secret'       => self::encrypt( $secret ),
				'email'        => $email,
				'connected_at' => time(),
			),
			false
		);
		delete_option( self::PENDING );
	}

	/**
	 * Forgets the connection and the sync state (the catalog stays on Camille's side).
	 */
	public static function forget_connection() {
		delete_option( self::CONNECTION );
		delete_option( self::PENDING );
		delete_option( self::SYNC );
		delete_option( self::BILLING );
		delete_option( self::WIDGET_KEY );
	}

	/**
	 * Key that signs who the logged-in visitor is, so Camille can apply the per-customer limits.
	 * Given by Camille's service once; kept encrypted, never autoloaded, never sent to the browser.
	 *
	 * @return string 64 hex characters, or '' when the shop does not have it yet.
	 */
	public static function widget_key() {
		$stored = get_option( self::WIDGET_KEY, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		$key = self::decrypt( $stored );
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $key ) ? $key : '';
	}

	/**
	 * Saves the visitor signing key.
	 *
	 * @param string $key 64 hex characters.
	 */
	public static function save_widget_key( $key ) {
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $key ) ) {
			update_option( self::WIDGET_KEY, self::encrypt( (string) $key ), false );
		}
	}

	/**
	 * Which products Camille may suggest. Empty means the whole catalog.
	 *
	 * @return array{excluded_categories:int[],price_min:float|null,price_max:float|null}
	 */
	public static function catalog_filters() {
		$stored = get_option( self::FILTERS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'excluded_categories' => isset( $stored['excluded_categories'] ) && is_array( $stored['excluded_categories'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $stored['excluded_categories'] ) ) ) ) : array(),
			'price_min'           => isset( $stored['price_min'] ) && is_numeric( $stored['price_min'] ) ? (float) $stored['price_min'] : null,
			'price_max'           => isset( $stored['price_max'] ) && is_numeric( $stored['price_max'] ) ? (float) $stored['price_max'] : null,
		);
	}

	/**
	 * Cleans and saves the product selection.
	 *
	 * @param int[]      $excluded_categories Category ids left out.
	 * @param float|null $price_min           Lowest price, null for no limit.
	 * @param float|null $price_max           Highest price, null for no limit.
	 * @return array The saved selection.
	 */
	public static function save_catalog_filters( array $excluded_categories, $price_min, $price_max ) {
		$min = null !== $price_min && $price_min > 0 ? round( (float) $price_min, 2 ) : null;
		$max = null !== $price_max && $price_max > 0 ? round( (float) $price_max, 2 ) : null;
		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		update_option(
			self::FILTERS,
			array(
				'excluded_categories' => array_values( array_unique( array_filter( array_map( 'absint', $excluded_categories ) ) ) ),
				'price_min'           => $min,
				'price_max'           => $max,
			),
			false
		);
		return self::catalog_filters();
	}

	/**
	 * The connection waiting for its email code.
	 *
	 * @return array{attempt_id:string,email:string,expires_at:int}|null
	 */
	public static function pending() {
		$stored = get_option( self::PENDING, null );
		if ( ! is_array( $stored ) || empty( $stored['attempt_id'] ) || (int) $stored['expires_at'] < time() ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Remembers the code request.
	 *
	 * @param string $attempt_id Id of the code request.
	 * @param string $email      Email the code went to.
	 * @param int    $expires_in Seconds the code is valid.
	 */
	public static function save_pending( $attempt_id, $email, $expires_in ) {
		update_option(
			self::PENDING,
			array(
				'attempt_id' => $attempt_id,
				'email'      => $email,
				'expires_at' => time() + max( 60, (int) $expires_in ),
			),
			false
		);
	}

	/**
	 * Forgets the code request.
	 */
	public static function clear_pending() {
		delete_option( self::PENDING );
	}

	/**
	 * The running export, or an empty array.
	 *
	 * @return array{run_id?:string,started_at?:int,sent_products?:int,error?:string}
	 */
	public static function sync_state() {
		$stored = get_option( self::SYNC, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Saves the export state.
	 *
	 * @param array $state New state.
	 */
	public static function save_sync_state( array $state ) {
		update_option( self::SYNC, $state, false );
	}

	/**
	 * Billing fields, in the order of the form. Camille's service checks them; this is only the shape.
	 *
	 * @return string[]
	 */
	public static function billing_fields() {
		return array( 'companyName', 'vatNumber', 'taxCode', 'address', 'postcode', 'city', 'province', 'country', 'sdiCode', 'pec', 'invoiceEmail' );
	}

	/**
	 * Billing details last saved on Camille's service (a copy to fill in the form), or an empty array.
	 *
	 * @return array<string,string>
	 */
	public static function billing() {
		$stored = get_option( self::BILLING, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Keeps a copy of the billing details the service accepted.
	 *
	 * @param array $billing Details as the service returned them.
	 */
	public static function save_billing( array $billing ) {
		$clean = array();
		foreach ( self::billing_fields() as $field ) {
			$clean[ $field ] = isset( $billing[ $field ] ) ? sanitize_text_field( (string) $billing[ $field ] ) : '';
		}
		update_option( self::BILLING, $clean, false );
	}

	/**
	 * 32-byte key from the site's secret keys (wp-config.php).
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', 'camille-ps|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypts with libsodium (bundled with PHP 7.2+, and with WordPress as a fallback).
	 *
	 * @param string $plain Text to protect.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'plain:' . $plain;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'sbox:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored in an option.
	}

	/**
	 * Decrypts a value written by encrypt(). Returns '' when it cannot (the site keys changed).
	 *
	 * @param string $packed Stored value.
	 * @return string
	 */
	private static function decrypt( $packed ) {
		if ( 0 === strpos( $packed, 'plain:' ) ) {
			return substr( $packed, 6 );
		}
		if ( 0 !== strpos( $packed, 'sbox:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $packed, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		return false === $plain ? '' : $plain;
	}
}
