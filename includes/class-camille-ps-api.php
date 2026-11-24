<?php
/**
 * Client of the Camille service. Every call is server to server; the shop's visitors never reach it.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Camille connector. Shop calls are signed with the shop's secret (HMAC-SHA256).
 */
class Camille_PS_API {

	const STATUS_CACHE = 'camille_ps_status';

	/**
	 * Public call (connection, before the shop has a secret).
	 *
	 * @param string $path    Path, e.g. /v1/connect/start.
	 * @param array  $payload JSON body.
	 * @return array{code:int,body:array}|WP_Error
	 */
	public static function post_public( $path, array $payload ) {
		return self::request( 'POST', $path, wp_json_encode( $payload ), array() );
	}

	/**
	 * Signed call for the connected shop.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path, e.g. /v1/shop/status.
	 * @param array|null $payload JSON body, null for none.
	 * @param int        $timeout Seconds.
	 * @return array{code:int,body:array}|WP_Error
	 */
	public static function signed( $method, $path, $payload = null, $timeout = 20 ) {
		$connection = Camille_PS_Options::connection();
		if ( ! $connection ) {
			return new WP_Error( 'camille_ps_not_connected', __( 'The shop is not connected to Camille.', 'camille-personal-shopper' ) );
		}
		$body      = null === $payload ? '' : wp_json_encode( $payload );
		$timestamp = (string) time();
		$base      = $timestamp . '.' . $method . '.' . $path . '.' . hash( 'sha256', $body );
		$headers   = array(
			'X-Camille-Store'     => $connection['store_id'],
			'X-Camille-Timestamp' => $timestamp,
			'X-Camille-Signature' => hash_hmac( 'sha256', $base, $connection['secret'] ),
		);
		return self::request( $method, $path, $body, $headers, $timeout );
	}

	/**
	 * Performs the request. A "service disabled" answer is remembered, so Camille hides at once.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path.
	 * @param string $body    Raw body.
	 * @param array  $headers Extra headers.
	 * @param int    $timeout Seconds.
	 * @return array{code:int,body:array}|WP_Error
	 */
	private static function request( $method, $path, $body, array $headers, $timeout = 20 ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'CamillePersonalShopper/' . CAMILLE_PS_VERSION . '; WordPress',
				),
				'' === $body ? array() : array( 'Content-Type' => 'application/json' ),
				$headers
			),
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( untrailingslashit( CAMILLE_PS_SERVICE_URL ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( 503 === $code && ! empty( $decoded['isDisabled'] ) ) {
			self::remember_status( array( 'isDisabled' => true ) );
		}
		return array(
			'code' => $code,
			'body' => $decoded,
		);
	}

	/**
	 * Reads the shop status from the service and keeps it for the storefront.
	 *
	 * @return array|WP_Error The status, or the error when the service could not answer.
	 */
	public static function refresh_status() {
		$result = self::signed( 'GET', '/v1/shop/status' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return new WP_Error( 'camille_ps_status', self::error_message( $result ), $result );
		}
		$status = array(
			'isDisabled'      => false,
			'isActive'        => ! empty( $result['body']['isActive'] ),
			'credits'         => isset( $result['body']['credits'] ) ? $result['body']['credits'] : null,
			'lastCatalogSync' => isset( $result['body']['lastCatalogSync'] ) ? $result['body']['lastCatalogSync'] : null,
			'creditsUrl'      => isset( $result['body']['creditsUrl'] ) ? esc_url_raw( (string) $result['body']['creditsUrl'] ) : '',
			'hasBilling'      => ! empty( $result['body']['hasBilling'] ),
		);
		self::remember_status( $status );
		return $status;
	}

	/**
	 * Last known status. The storefront reads only this: it never calls the service.
	 *
	 * @return array{isDisabled?:bool,isActive?:bool,credits?:mixed,lastCatalogSync?:mixed,creditsUrl?:string,hasBilling?:bool,checkedAt?:int}
	 */
	public static function cached_status() {
		$stored = get_option( self::STATUS_CACHE, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Merges values into the cached status.
	 *
	 * @param array $values New values.
	 */
	public static function remember_status( array $values ) {
		update_option( self::STATUS_CACHE, array_merge( self::cached_status(), $values, array( 'checkedAt' => time() ) ), true );
	}

	/**
	 * A short message for the merchant from a failed answer.
	 *
	 * @param array|WP_Error $result Answer or error.
	 * @return string
	 */
	public static function error_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return __( 'Camille could not be reached. Please try again in a few minutes.', 'camille-personal-shopper' );
		}
		if ( 503 === $result['code'] && ! empty( $result['body']['isDisabled'] ) ) {
			return __( 'Camille is temporarily paused. Your shop keeps working normally.', 'camille-personal-shopper' );
		}
		if ( 503 === $result['code'] || $result['code'] >= 500 ) {
			return __( 'Camille is not available right now. Please try again in a few minutes.', 'camille-personal-shopper' );
		}
		if ( 401 === $result['code'] ) {
			return __( 'Camille did not recognise this shop. Disconnect and connect it again.', 'camille-personal-shopper' );
		}
		if ( 429 === $result['code'] ) {
			return __( 'Too many attempts. Please wait a few minutes and try again.', 'camille-personal-shopper' );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Unexpected answer from Camille (code %d).', 'camille-personal-shopper' ), (int) $result['code'] );
	}
}
