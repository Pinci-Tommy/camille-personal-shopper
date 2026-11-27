<?php
/**
 * Keeps Camille's catalog up to date between full syncs: the products the merchant creates, edits,
 * sells out, hides or deletes reach Camille about a minute later, and every night a full sync
 * catches what slipped through (changes made by other plugins straight in the database, for
 * example). Everything runs in the background with Action Scheduler and never slows a page down.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Live updates of single products and the nightly full sync.
 */
class Camille_PS_Live_Sync {

	const HOOK_SEND    = 'camille_ps_live_update';
	const HOOK_NIGHTLY = 'camille_ps_nightly_sync';
	const QUEUE        = 'camille_ps_live_queue';
	const CHECKED      = 'camille_ps_nightly_checked';

	/** Products the service takes in one live update. */
	const PER_UPDATE = 20;

	/** Changes of the same minute travel together. */
	const DELAY = MINUTE_IN_SECONDS;

	/** While a full sync is going on, the live update waits this long and tries again. */
	const WAIT_FOR_SYNC = 5 * MINUTE_IN_SECONDS;

	/** Above this many changed products (a big import), a full sync is quicker than live updates. */
	const MAX_QUEUE = 500;

	const MAX_RETRIES = 5;

	/**
	 * Product ids changed during this request, saved once at the end of it.
	 *
	 * @var array<int,true>
	 */
	private static $changed = array();

	/**
	 * Hooks the product changes and the background actions. Called on every request, also outside
	 * the admin: stock changes with every order, and products also change through the REST API.
	 */
	public static function init() {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );

		add_action( self::HOOK_SEND, array( __CLASS__, 'send' ) );
		add_action( self::HOOK_NIGHTLY, array( __CLASS__, 'nightly' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_nightly' ) );
	}

	/* ---------------------------------------------------------------- collecting the changes */

	/**
	 * A product was created, saved or changed its stock status.
	 *
	 * @param int|mixed $product_id Product id.
	 */
	public static function on_product( $product_id ) {
		self::remember( (int) $product_id );
	}

	/**
	 * A variation changed: its product is sent again with all its colours.
	 *
	 * @param int|mixed $variation_id Variation id.
	 */
	public static function on_variation( $variation_id ) {
		self::remember( (int) wp_get_post_parent_id( (int) $variation_id ) );
	}

	/**
	 * The stock quantity changed (an order, a refund, a stock import).
	 *
	 * @param WC_Product|mixed $product Product or variation.
	 */
	public static function on_stock( $product ) {
		if ( $product instanceof WC_Product ) {
			self::remember( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() );
		}
	}

	/**
	 * Published, drafted, made private, trashed or restored.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post instanceof WP_Post && 'product' === $post->post_type ) {
			self::remember( (int) $post->ID );
		}
	}

	/**
	 * Deleted for good (a deleted variation changes its product).
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_delete( $post_id ) {
		$type = get_post_type( (int) $post_id );
		if ( 'product' === $type ) {
			self::remember( (int) $post_id );
		} elseif ( 'product_variation' === $type ) {
			self::remember( (int) wp_get_post_parent_id( (int) $post_id ) );
		}
	}

	/**
	 * Products hidden from Camille or shown again with the bulk action of the products list, which
	 * changes the products without saving them.
	 *
	 * @param int[] $product_ids Product ids.
	 */
	public static function remember_many( array $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			self::remember( (int) $product_id );
		}
	}

	/**
	 * Notes a changed product for the end of the request.
	 *
	 * @param int $product_id Product id.
	 */
	private static function remember( $product_id ) {
		if ( $product_id > 0 ) {
			self::$changed[ $product_id ] = true;
		}
	}

	/**
	 * End of the request: adds the changed products to the queue and plans the live update. Nothing
	 * is queued before the first full sync, which sends the whole catalog anyway.
	 */
	public static function flush() {
		if ( ! self::$changed ) {
			return;
		}
		$ids           = array_keys( self::$changed );
		self::$changed = array();
		try {
			if ( ! self::is_active() ) {
				return;
			}
			$queue = self::queue();
			foreach ( $ids as $id ) {
				$queue['ids'][ $id ] = true;
			}
			if ( count( $queue['ids'] ) > self::MAX_QUEUE ) {
				$queue = array(
					'ids'       => array(),
					'full_sync' => true,
				);
			}
			self::save_queue( $queue );
			if ( ! as_has_scheduled_action( self::HOOK_SEND, null, Camille_PS_Catalog_Sync::GROUP ) ) {
				as_schedule_single_action( time() + self::DELAY, self::HOOK_SEND, array( 0 ), Camille_PS_Catalog_Sync::GROUP );
			}
		} catch ( Throwable $e ) {
			// The shop never stops for Camille: the nightly sync brings these products anyway.
			return;
		}
	}

	/**
	 * Connected, with a first sync done, and Action Scheduler at hand.
	 *
	 * @return bool
	 */
	private static function is_active() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! Camille_PS_Options::connection() ) {
			return false;
		}
		$state = Camille_PS_Options::sync_state();
		return ! empty( $state['run_id'] );
	}

	/**
	 * How many products wait for a live update.
	 *
	 * @return int
	 */
	public static function backlog() {
		return count( self::queue()['ids'] );
	}

	/**
	 * The products waiting to be sent.
	 *
	 * @return array{ids:array<int,true>,full_sync:bool}
	 */
	private static function queue() {
		$stored = get_option( self::QUEUE, array() );
		$ids    = array();
		if ( is_array( $stored ) && isset( $stored['ids'] ) && is_array( $stored['ids'] ) ) {
			foreach ( array_keys( $stored['ids'] ) as $id ) {
				if ( (int) $id > 0 ) {
					$ids[ (int) $id ] = true;
				}
			}
		}
		return array(
			'ids'       => $ids,
			'full_sync' => is_array( $stored ) && ! empty( $stored['full_sync'] ),
		);
	}

	/**
	 * Saves the queue (never autoloaded).
	 *
	 * @param array $queue Queue.
	 */
	private static function save_queue( array $queue ) {
		if ( ! $queue['ids'] && empty( $queue['full_sync'] ) ) {
			delete_option( self::QUEUE );
			return;
		}
		update_option( self::QUEUE, $queue, false );
	}

	/* ---------------------------------------------------------------- sending */

	/**
	 * Action: sends up to 20 changed products. The products still offered to Camille travel with
	 * their new data; the others (sold out stays offered, as unavailable; hidden, drafted, trashed,
	 * deleted or left out of the selection go away) only by id.
	 *
	 * @param int $attempt Tries already made after a network error.
	 */
	public static function send( $attempt = 0 ) {
		if ( ! Camille_PS_Options::connection() ) {
			delete_option( self::QUEUE );
			return;
		}
		if ( Camille_PS_Catalog_Sync::is_running() ) {
			self::later( self::WAIT_FOR_SYNC, 0 );
			return;
		}
		$queue = self::queue();
		if ( $queue['full_sync'] ) {
			delete_option( self::QUEUE );
			Camille_PS_Catalog_Sync::start();
			return;
		}
		if ( ! $queue['ids'] ) {
			return;
		}
		$ids      = array_slice( array_keys( $queue['ids'] ), 0, self::PER_UPDATE );
		$products = array();
		$removed  = array();
		$waiting  = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && $product->get_parent_id() ) {
				$product = wc_get_product( $product->get_parent_id() );
			}
			if (
				$product instanceof WC_Product && 'publish' === $product->get_status() &&
				( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) &&
				Camille_PS_Product_Export::is_exportable( $product )
			) {
				$payload    = Camille_PS_Product_Export::build( $product );
				$products[] = $payload;
				if ( ! empty( $payload['photosPending'] ) ) {
					$waiting[] = (int) $product->get_id();
				}
			} else {
				$removed[] = $id;
			}
		}
		$result = Camille_PS_API::signed(
			'POST',
			'/v1/shop/catalog/products',
			array(
				'currency'   => get_woocommerce_currency(),
				'products'   => $products,
				'removedIds' => $removed,
			),
			60
		);
		$code   = is_wp_error( $result ) ? 0 : (int) $result['code'];
		$error  = ! is_wp_error( $result ) && isset( $result['body']['error'] ) ? (string) $result['body']['error'] : '';

		if ( 409 === $code && 'sync_running' === $error ) {
			// The full sync carries an older copy of these products: they go after it.
			self::later( self::WAIT_FOR_SYNC, 0 );
			return;
		}
		if ( is_wp_error( $result ) || 429 === $code || $code >= 500 ) {
			if ( (int) $attempt < self::MAX_RETRIES ) {
				self::later( MINUTE_IN_SECONDS * ( 2 ** (int) $attempt ), (int) $attempt + 1 );
				return;
			}
			// Still unreachable: the nightly sync brings these products.
		}

		if ( $waiting && 200 === $code ) {
			// New or replaced photos: copied in the background, then these products are sent again.
			Camille_PS_Photo_Copies::add_products( $waiting );
		}

		// Sent, refused or given up: these leave the queue. Changes made meanwhile stay in it.
		$queue = self::queue();
		foreach ( $ids as $id ) {
			unset( $queue['ids'][ $id ] );
		}
		self::save_queue( $queue );
		if ( $queue['ids'] || $queue['full_sync'] ) {
			as_enqueue_async_action( self::HOOK_SEND, array( 0 ), Camille_PS_Catalog_Sync::GROUP );
		}
	}

	/**
	 * Plans the next live update, keeping the queue.
	 *
	 * @param int $delay   Seconds.
	 * @param int $attempt Tries already made.
	 */
	private static function later( $delay, $attempt ) {
		as_schedule_single_action( time() + (int) $delay, self::HOOK_SEND, array( (int) $attempt ), Camille_PS_Catalog_Sync::GROUP );
	}

	/* ---------------------------------------------------------------- nightly full sync */

	/**
	 * Plans the nightly sync once a first sync was done. Checked at most once a day, on admin pages
	 * only, so the shop's pages never pay for it.
	 */
	public static function ensure_nightly() {
		if ( get_transient( self::CHECKED ) || ! self::is_active() ) {
			return;
		}
		set_transient( self::CHECKED, 1, DAY_IN_SECONDS );
		self::schedule_nightly();
	}

	/**
	 * Every night around 3 in the shop's time zone, at a minute of its own so the shops do not all
	 * sync at once.
	 */
	public static function schedule_nightly() {
		$connection = Camille_PS_Options::connection();
		if ( ! $connection || ! function_exists( 'as_schedule_recurring_action' ) || as_has_scheduled_action( self::HOOK_NIGHTLY, null, Camille_PS_Catalog_Sync::GROUP ) ) {
			return;
		}
		$minute = abs( crc32( $connection['store_id'] ) ) % 60;
		$next   = new DateTime( 'today', wp_timezone() );
		$next->setTime( 3, $minute );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::HOOK_NIGHTLY, array(), Camille_PS_Catalog_Sync::GROUP );
	}

	/**
	 * Removes the planned actions (deactivation, disconnection).
	 */
	public static function cancel() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_SEND, array(), Camille_PS_Catalog_Sync::GROUP );
			as_unschedule_all_actions( self::HOOK_NIGHTLY, array(), Camille_PS_Catalog_Sync::GROUP );
		}
		delete_option( self::QUEUE );
		delete_transient( self::CHECKED );
	}

	/**
	 * Action: the nightly full sync. It finds what the live updates could not see, and removes from
	 * Camille the products that are no longer in the shop. A sync already going on is left alone.
	 */
	public static function nightly() {
		if ( ! self::is_active() || Camille_PS_Catalog_Sync::is_running() ) {
			return;
		}
		$report = Camille_PS_Catalog_Sync::remote_report();
		if ( ! $report ) {
			return; // Service unreachable, or no sync ever: tomorrow.
		}
		if ( in_array( (string) ( $report['status'] ?? '' ), array( 'receiving', 'queued', 'sending', 'finishing' ), true ) ) {
			return;
		}
		if ( true === Camille_PS_Catalog_Sync::start() ) {
			// The full sync reads every product: the queued ones need no live update.
			delete_option( self::QUEUE );
		}
	}
}
