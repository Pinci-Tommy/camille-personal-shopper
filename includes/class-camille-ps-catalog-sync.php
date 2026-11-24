<?php
/**
 * Catalog sync in the background with Action Scheduler (bundled with WooCommerce): one action per
 * page of 50 products, so a large catalog never slows the admin or the shop down.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the catalog to Camille: start → one batch per page → finish.
 */
class Camille_PS_Catalog_Sync {

	const HOOK_PAGE   = 'camille_ps_export_page';
	const HOOK_FINISH = 'camille_ps_export_finish';
	const GROUP       = 'camille-personal-shopper';
	const PER_PAGE    = 50;
	const MAX_RETRIES = 5;

	/**
	 * Hooks the background actions.
	 */
	public static function init() {
		add_action( self::HOOK_PAGE, array( __CLASS__, 'export_page' ), 10, 3 );
		add_action( self::HOOK_FINISH, array( __CLASS__, 'finish' ), 10, 2 );
	}

	/**
	 * Whether a sync is running on this side (pages still being sent).
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = Camille_PS_Options::sync_state();
		return isset( $state['phase'] ) && 'exporting' === $state['phase'];
	}

	/**
	 * Starts a full sync. A sync already running is replaced.
	 *
	 * @return true|WP_Error
	 */
	public static function start() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'camille_ps_no_scheduler', __( 'WooCommerce background tasks (Action Scheduler) are not available. Update WooCommerce and try again.', 'camille-personal-shopper' ) );
		}
		self::cancel();
		$result = Camille_PS_API::signed( 'POST', '/v1/shop/catalog/start', array( 'currency' => get_woocommerce_currency() ) );
		if ( is_wp_error( $result ) || 201 !== $result['code'] || empty( $result['body']['runId'] ) ) {
			return new WP_Error( 'camille_ps_sync_start', Camille_PS_API::error_message( $result ) );
		}
		$run_id = sanitize_text_field( (string) $result['body']['runId'] );
		Camille_PS_Options::save_sync_state(
			array(
				'run_id'        => $run_id,
				'phase'         => 'exporting',
				'started_at'    => time(),
				'sent_products' => 0,
				'error'         => '',
			)
		);
		as_enqueue_async_action( self::HOOK_PAGE, array( $run_id, 1, 0 ), self::GROUP );
		return true;
	}

	/**
	 * Removes the pending background actions of this plugin.
	 */
	public static function cancel() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PAGE, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_FINISH, array(), self::GROUP );
		}
	}

	/**
	 * Action: sends one page of products.
	 *
	 * @param string $run_id  Sync id given by the service.
	 * @param int    $page    Page number.
	 * @param int    $attempt Tries already made for this page.
	 */
	public static function export_page( $run_id, $page, $attempt ) {
		if ( ! self::is_current( $run_id ) ) {
			return;
		}
		$query    = Camille_PS_Product_Export::query_page( (int) $page, self::PER_PAGE );
		$payloads = array();
		foreach ( $query['products'] as $product ) {
			if ( Camille_PS_Product_Export::is_exportable( $product ) ) {
				$payloads[] = Camille_PS_Product_Export::build( $product );
			}
		}
		if ( $payloads ) {
			$result = Camille_PS_API::signed(
				'POST',
				'/v1/shop/catalog/batch',
				array(
					'runId'    => $run_id,
					'products' => $payloads,
				),
				60
			);
			if ( self::is_temporary_failure( $result ) ) {
				self::retry( self::HOOK_PAGE, array( $run_id, (int) $page ), (int) $attempt );
				return;
			}
			if ( 200 !== $result['code'] ) {
				self::stop( isset( $result['body']['error'] ) ? (string) $result['body']['error'] : 'rejected' );
				return;
			}
			$state                  = Camille_PS_Options::sync_state();
			$state['sent_products'] = (int) ( $state['sent_products'] ?? 0 ) + count( $payloads );
			Camille_PS_Options::save_sync_state( $state );
		}
		if ( (int) $page < $query['pages'] ) {
			as_enqueue_async_action( self::HOOK_PAGE, array( $run_id, (int) $page + 1, 0 ), self::GROUP );
			return;
		}
		as_enqueue_async_action( self::HOOK_FINISH, array( $run_id, 0 ), self::GROUP );
	}

	/**
	 * Action: tells the service every page was sent. Camille's side then imports the products.
	 *
	 * @param string $run_id  Sync id.
	 * @param int    $attempt Tries already made.
	 */
	public static function finish( $run_id, $attempt ) {
		if ( ! self::is_current( $run_id ) ) {
			return;
		}
		$result = Camille_PS_API::signed( 'POST', '/v1/shop/catalog/finish', array( 'runId' => $run_id ) );
		if ( self::is_temporary_failure( $result ) ) {
			self::retry( self::HOOK_FINISH, array( $run_id ), (int) $attempt );
			return;
		}
		if ( 202 !== $result['code'] ) {
			self::stop( isset( $result['body']['status'] ) ? (string) $result['body']['status'] : 'rejected' );
			return;
		}
		$state          = Camille_PS_Options::sync_state();
		$state['phase'] = 'sent';
		Camille_PS_Options::save_sync_state( $state );
	}

	/**
	 * Whether the action belongs to the sync in progress (a newer sync makes older actions void).
	 *
	 * @param string $run_id Sync id.
	 * @return bool
	 */
	private static function is_current( $run_id ) {
		$state = Camille_PS_Options::sync_state();
		return isset( $state['run_id'], $state['phase'] ) && $state['run_id'] === $run_id && 'exporting' === $state['phase'];
	}

	/**
	 * Network error, service busy or unavailable: worth trying again later.
	 *
	 * @param array|WP_Error $result Answer.
	 * @return bool
	 */
	private static function is_temporary_failure( $result ) {
		return is_wp_error( $result ) || 429 === $result['code'] || $result['code'] >= 500;
	}

	/**
	 * Tries the action again in 1, 2, 4, 8, 16 minutes, then stops the sync.
	 *
	 * @param string $hook    Action hook.
	 * @param array  $args    Action arguments without the attempt.
	 * @param int    $attempt Tries already made.
	 */
	private static function retry( $hook, array $args, $attempt ) {
		if ( $attempt >= self::MAX_RETRIES ) {
			self::stop( 'service_unreachable' );
			return;
		}
		$args[] = $attempt + 1;
		as_schedule_single_action( time() + MINUTE_IN_SECONDS * ( 2 ** $attempt ), $hook, $args, self::GROUP );
	}

	/**
	 * Ends the sync on this side with an error code, shown on the settings page.
	 *
	 * @param string $error Error code.
	 */
	private static function stop( $error ) {
		$state          = Camille_PS_Options::sync_state();
		$state['phase'] = 'failed';
		$state['error'] = sanitize_key( $error );
		Camille_PS_Options::save_sync_state( $state );
	}

	/**
	 * Report of the last sync from the service (counts, rejected products), or null.
	 *
	 * @return array|null
	 */
	public static function remote_report() {
		$result = Camille_PS_API::signed( 'GET', '/v1/shop/catalog/status' );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['sync'] ) || ! is_array( $result['body']['sync'] ) ) {
			return null;
		}
		return $result['body']['sync'];
	}
}
