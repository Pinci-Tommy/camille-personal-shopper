<?php
/**
 * Copies of the product photos kept by Camille. Each photo is read from the shop's own disk, shrunk
 * and sent once; the catalog then gives Camille the address of the copy. Virtual try-ons and the
 * study of the products never download photos from the shop's site, so no hosting firewall, CDN or
 * bot protection in front of it can block them. A photo that cannot be copied keeps its usual
 * address on the site.
 *
 * The copies are made in the background, after the catalog was sent: the sync stays quick whatever
 * the size of the shop. Product after product, the photos are copied (a few at a time) and the
 * product is sent again with them; Camille studies a product only once its photos are copied.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the photos, remembers their copies (attachment meta) and runs the background copy.
 */
class Camille_PS_Photo_Copies {

	/** Attachment meta: address of the copy and fingerprint of the file it was made from. */
	const META = '_camille_ps_photo';
	/** Attachment meta: a photo that could not be copied, tried again after a day. */
	const FAILED_META = '_camille_ps_photo_failed';
	/** Longest side sent: enough for the virtual try-on and the chat cards. */
	const MAX_SIDE = 1600;
	/** The service accepts about 2 MB of JSON: the base64 of 1.4 MB. */
	const MAX_BYTES = 1400000;
	/** Seconds allowed for one upload. */
	const REQUEST_TIMEOUT = 30;
	/** Photos sent at the same time. */
	const PARALLEL = 4;

	/** Action: one round of the background copy. */
	const HOOK = 'camille_ps_copy_photos';
	/** Action: checks whether Camille is ready, after the first copy. */
	const READY_HOOK = 'camille_ps_ready_check';
	/** Option: the background copy in progress (never autoloaded). */
	const JOB = 'camille_ps_photo_job';
	/** Option: when the service was told that Camille is ready (once per shop). */
	const READY_SENT = 'camille_ps_ready_sent';
	/** Seconds one round works before handing over to the next. */
	const RUN_SECONDS = 20;
	/** Products read per step while listing the work. */
	const PER_PAGE = 50;
	/** Products waiting for a live update above which the copy pauses, so the updates keep up. */
	const MAX_BACKLOG = 100;
	/** Ready checks before the service is told anyway (5 minutes apart: a day). */
	const MAX_READY_CHECKS = 288;

	/**
	 * Hooks the background actions.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::READY_HOOK, array( __CLASS__, 'ready_check' ) );
	}

	/* ---------------------------------------------------------------- state of one photo */

	/**
	 * Address of the copy of a photo when it is current, '' otherwise.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string
	 */
	public static function url( $attachment_id ) {
		$stored = get_post_meta( (int) $attachment_id, self::META, true );
		if ( ! is_array( $stored ) || empty( $stored['url'] ) || empty( $stored['sig'] ) ) {
			return '';
		}
		return hash_equals( (string) $stored['sig'], self::signature( (int) $attachment_id ) ) ? esc_url_raw( (string) $stored['url'] ) : '';
	}

	/**
	 * Whether a photo still has to be copied: on this disk, no current copy, not set aside.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function is_pending( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		return $attachment_id > 0 && '' !== self::signature( $attachment_id ) && '' === self::url( $attachment_id ) && ! self::recently_failed( $attachment_id );
	}

	/**
	 * Whether any photo of a product still has to be copied.
	 *
	 * @param WC_Product $product Simple or variable product.
	 * @return bool
	 */
	public static function has_pending( $product ) {
		if ( ! Camille_PS_Options::connection() ) {
			return false;
		}
		foreach ( Camille_PS_Product_Export::image_ids( $product ) as $attachment_id ) {
			if ( self::is_pending( $attachment_id ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------- background copy */

	/**
	 * After a full sync: lists the products with photos to copy and starts copying them.
	 */
	public static function start() {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! Camille_PS_Options::connection() ) {
			return;
		}
		self::unschedule();
		self::save_job(
			array(
				'phase'      => 'planning',
				'page'       => 1,
				'products'   => array(),
				'total'      => 0,
				'done'       => 0,
				'last_sent'  => 0,
				'started_at' => time(),
			)
		);
		as_enqueue_async_action( self::HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
	}

	/**
	 * A live update sent products whose photos are not copied yet (a new product, a new photo): they
	 * join the copy, which sends them again once their photos are there.
	 *
	 * @param int[] $product_ids Product ids.
	 */
	public static function add_products( array $product_ids ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! $product_ids ) {
			return;
		}
		$job = self::job();
		if ( $job && 'planning' === $job['phase'] ) {
			return; // The listing in progress finds them.
		}
		if ( ! $job || 'done' === $job['phase'] ) {
			$job = array(
				'phase'      => 'copying',
				'page'       => 0,
				'products'   => array(),
				'total'      => 0,
				'done'       => 0,
				'last_sent'  => 0,
				'started_at' => time(),
			);
		}
		$known = array_flip( $job['products'] );
		foreach ( array_map( 'intval', $product_ids ) as $product_id ) {
			if ( $product_id > 0 && ! isset( $known[ $product_id ] ) ) {
				$job['products'][]    = $product_id;
				$known[ $product_id ] = true;
				++$job['total'];
			}
		}
		self::save_job( $job );
		if ( ! as_has_scheduled_action( self::HOOK, null, Camille_PS_Catalog_Sync::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
		}
	}

	/**
	 * Action: one round of about RUN_SECONDS. Lists the work first, then copies the photos product
	 * after product and sends the finished products again through the live updates.
	 */
	public static function run() {
		$job = self::job();
		if ( ! $job || 'done' === $job['phase'] ) {
			return;
		}
		if ( ! Camille_PS_Options::connection() ) {
			self::cancel();
			return;
		}
		if ( Camille_PS_Catalog_Sync::is_running() ) {
			return; // A new sync starts its own copy when it ends.
		}
		$started = microtime( true );
		if ( 'planning' === $job['phase'] ) {
			$job = self::plan( $job, $started );
		}
		if ( 'copying' === $job['phase'] ) {
			$job = self::copy_products( $job, $started );
			if ( isset( $job['wait'] ) ) {
				$wait = (int) $job['wait'];
				unset( $job['wait'] );
				self::save_job( $job );
				as_schedule_single_action( time() + $wait, self::HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
				return;
			}
		}
		self::save_job( $job );
		if ( 'done' === $job['phase'] ) {
			self::schedule_ready_check( 0 );
			return;
		}
		as_enqueue_async_action( self::HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
	}

	/**
	 * Lists the products that have photos to copy, a page at a time.
	 *
	 * @param array $job     Job.
	 * @param float $started Start of the round.
	 * @return array
	 */
	private static function plan( array $job, $started ) {
		while ( microtime( true ) - $started < self::RUN_SECONDS ) {
			$query = Camille_PS_Product_Export::query_page( (int) $job['page'], self::PER_PAGE );
			foreach ( $query['products'] as $product ) {
				if ( Camille_PS_Product_Export::is_exportable( $product ) && self::has_pending( $product ) ) {
					$job['products'][] = (int) $product->get_id();
				}
			}
			if ( (int) $job['page'] >= (int) $query['pages'] ) {
				$job['products'] = array_values( array_unique( $job['products'] ) );
				$job['total']    = count( $job['products'] );
				$job['phase']    = $job['products'] ? 'copying' : 'done';
				break;
			}
			++$job['page'];
		}
		return $job;
	}

	/**
	 * Copies the photos of the listed products, in order, until the round is over.
	 *
	 * @param array $job     Job.
	 * @param float $started Start of the round.
	 * @return array The job; with 'wait' (seconds) when the next round must wait.
	 */
	private static function copy_products( array $job, $started ) {
		$finished = array();
		while ( $job['products'] && microtime( true ) - $started < self::RUN_SECONDS ) {
			if ( Camille_PS_Live_Sync::backlog() >= self::MAX_BACKLOG ) {
				$job['wait'] = 2 * MINUTE_IN_SECONDS; // The live updates catch up first.
				break;
			}
			$product_id = (int) $job['products'][0];
			$product    = wc_get_product( $product_id );
			$photos     = $product instanceof WC_Product ? array_values( array_filter( Camille_PS_Product_Export::image_ids( $product ), array( __CLASS__, 'is_pending' ) ) ) : array();
			$outcome    = self::copy_photos( $photos, $started );
			if ( 'unreachable' === $outcome ) {
				$job['wait'] = 5 * MINUTE_IN_SECONDS;
				break;
			}
			if ( 'stopped' === $outcome ) {
				break; // Same product in the next round: its copied photos are kept.
			}
			array_shift( $job['products'] );
			$job['done'] = min( (int) $job['total'], (int) $job['done'] + 1 );
			if ( $product instanceof WC_Product ) {
				$finished[] = $product_id;
			}
		}
		if ( $finished ) {
			Camille_PS_Live_Sync::remember_many( $finished );
			Camille_PS_Live_Sync::flush();
			$job['last_sent'] = time();
		}
		if ( ! $job['products'] && ! isset( $job['wait'] ) ) {
			$job['phase'] = 'done';
		}
		return $job;
	}

	/**
	 * Copies some photos, PARALLEL at a time.
	 *
	 * @param int[] $attachment_ids Photos to copy.
	 * @param float $started        Start of the round.
	 * @return string done, stopped (round over) or unreachable (service down).
	 */
	private static function copy_photos( array $attachment_ids, $started ) {
		foreach ( array_chunk( $attachment_ids, self::PARALLEL ) as $index => $chunk ) {
			if ( $index > 0 && microtime( true ) - $started >= self::RUN_SECONDS ) {
				return 'stopped';
			}
			if ( in_array( 'retry', self::copy_many( $chunk ), true ) ) {
				return 'unreachable';
			}
		}
		return 'done';
	}

	/**
	 * Where the background copy is, for the catalog panel; null when there is none.
	 *
	 * @return array{isListing:bool,done:int,total:int}|null
	 */
	public static function progress() {
		$job = self::job();
		if ( ! $job || 'done' === $job['phase'] ) {
			return null;
		}
		if ( 'copying' === $job['phase'] && $job['total'] < 1 ) {
			return null;
		}
		return array(
			'isListing' => 'planning' === $job['phase'],
			'done'      => (int) $job['done'],
			'total'     => (int) $job['total'],
		);
	}

	/**
	 * Stops the background copy (new sync, disconnection, deactivation).
	 */
	public static function cancel() {
		self::unschedule();
		delete_option( self::JOB );
	}

	/**
	 * Removes the planned rounds and ready checks.
	 */
	private static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
			as_unschedule_all_actions( self::READY_HOOK, array(), Camille_PS_Catalog_Sync::GROUP );
		}
	}

	/**
	 * The job in progress, null when there is none.
	 *
	 * @return array|null
	 */
	private static function job() {
		$job = get_option( self::JOB, null );
		if ( ! is_array( $job ) || ! isset( $job['phase'] ) || ! in_array( $job['phase'], array( 'planning', 'copying', 'done' ), true ) ) {
			return null;
		}
		$job['products'] = isset( $job['products'] ) && is_array( $job['products'] ) ? array_values( array_map( 'intval', $job['products'] ) ) : array();
		$job['page']     = max( 1, (int) ( $job['page'] ?? 1 ) );
		$job['total']    = (int) ( $job['total'] ?? 0 );
		$job['done']     = (int) ( $job['done'] ?? 0 );
		return $job;
	}

	/**
	 * Saves the job (never autoloaded).
	 *
	 * @param array $job Job.
	 */
	private static function save_job( array $job ) {
		update_option( self::JOB, $job, false );
	}

	/* ---------------------------------------------------------------- Camille is ready */

	/**
	 * Plans the next ready check.
	 *
	 * @param int $attempt Checks already made.
	 */
	private static function schedule_ready_check( $attempt ) {
		if ( get_option( self::READY_SENT ) ) {
			return;
		}
		as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::READY_HOOK, array( (int) $attempt ), Camille_PS_Catalog_Sync::GROUP );
	}

	/**
	 * Action: once the first copy is over, the last products sent and studied, tells the service that
	 * Camille is ready; the service emails the merchant (once per shop).
	 *
	 * @param int $attempt Checks already made.
	 */
	public static function ready_check( $attempt = 0 ) {
		if ( get_option( self::READY_SENT ) || ! Camille_PS_Options::connection() ) {
			return;
		}
		$job = self::job();
		if ( $job && 'done' !== $job['phase'] ) {
			return; // The copy plans a new check when it ends.
		}
		if ( self::is_studied( $job ? (int) $job['last_sent'] : 0 ) || (int) $attempt >= self::MAX_READY_CHECKS ) {
			$result = Camille_PS_API::signed( 'POST', '/v1/shop/catalog/ready', array( 'source' => 'photo_copies' ) );
			if ( ! is_wp_error( $result ) && 200 === $result['code'] ) {
				update_option( self::READY_SENT, time(), false );
				return;
			}
		}
		self::schedule_ready_check( (int) $attempt + 1 );
	}

	/**
	 * The catalog is imported, no live update is waiting and Camille studied what was last sent.
	 *
	 * @param int $last_sent When the copy last sent products (0: none).
	 * @return bool
	 */
	private static function is_studied( $last_sent ) {
		if ( Camille_PS_Live_Sync::backlog() > 0 ) {
			return false;
		}
		$progress = Camille_PS_Catalog_Sync::progress();
		if ( 'done' !== $progress['stage'] ) {
			return false;
		}
		if ( $last_sent < 1 ) {
			return true;
		}
		$status   = Camille_PS_API::refresh_status( true );
		$finished = is_array( $status ) && ! empty( $status['classification']['finishedAt'] ) ? (int) strtotime( (string) $status['classification']['finishedAt'] ) : 0;
		return $finished > $last_sent;
	}

	/* ---------------------------------------------------------------- sending photos */

	/**
	 * Sends several photos at once.
	 *
	 * @param int[] $attachment_ids Attachment ids.
	 * @return array<int,string> Per photo: done, failed (kept on the site address for a day) or retry (service unreachable).
	 */
	private static function copy_many( array $attachment_ids ) {
		$results    = array();
		$payloads   = array();
		$signatures = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( '' !== self::url( $attachment_id ) ) {
				$results[ $attachment_id ] = 'done';
				continue;
			}
			$signature = self::signature( $attachment_id );
			$bytes     = '' === $signature ? false : self::shrunk_bytes( (string) get_attached_file( $attachment_id ) );
			if ( false === $bytes ) {
				self::remember_failure( $attachment_id, $signature );
				$results[ $attachment_id ] = 'failed';
				continue;
			}
			$payloads[ $attachment_id ]   = array( 'imageBase64' => base64_encode( $bytes ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image sent as JSON.
			$signatures[ $attachment_id ] = $signature;
		}
		if ( ! $payloads ) {
			return $results;
		}
		foreach ( Camille_PS_API::signed_many( 'POST', '/v1/shop/catalog/photo', $payloads, self::REQUEST_TIMEOUT ) as $attachment_id => $result ) {
			$results[ $attachment_id ] = self::save_answer( (int) $attachment_id, $signatures[ $attachment_id ], $result );
		}
		return $results;
	}

	/**
	 * Keeps the address of a copy from the service's answer.
	 *
	 * @param int            $attachment_id Attachment id.
	 * @param string         $signature     Fingerprint of the file sent.
	 * @param array|WP_Error $result        Answer.
	 * @return string done, failed or retry.
	 */
	private static function save_answer( $attachment_id, $signature, $result ) {
		if ( is_wp_error( $result ) || 429 === $result['code'] || $result['code'] >= 500 ) {
			return 'retry';
		}
		$url = isset( $result['body']['url'] ) ? esc_url_raw( (string) $result['body']['url'] ) : '';
		if ( 200 !== $result['code'] || 0 !== strpos( $url, 'https://' ) ) {
			self::remember_failure( $attachment_id, $signature );
			return 'failed';
		}
		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'url' => $url,
				'sig' => $signature,
			)
		);
		delete_post_meta( $attachment_id, self::FAILED_META );
		return 'done';
	}

	/**
	 * The photo at most MAX_SIDE pixels on the longest side, small enough to send; false when it
	 * cannot be read. A JPEG stays a JPEG; a PNG, WebP or GIF (which may be cut out on a transparent
	 * background) goes as WebP or PNG, so Camille can put it on white instead of black.
	 *
	 * @param string $file Path of the original file.
	 * @return string|false
	 */
	private static function shrunk_bytes( $file ) {
		if ( '' === $file || ! is_readable( $file ) ) {
			return false; // Not on this disk (photos moved to an external CDN): the site address stays.
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$source = wp_check_filetype( $file );
		$mimes  = array( 'image/jpeg' );
		if ( 'image/jpeg' !== $source['type'] ) {
			$mimes = array_values(
				array_filter(
					array( 'image/webp', 'image/png', 'image/jpeg' ),
					static function ( $mime ) {
						return wp_image_editor_supports( array( 'mime_type' => $mime ) );
					}
				)
			);
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // Not loaded during cron.
		}
		foreach ( array( array( self::MAX_SIDE, 85 ), array( 1280, 72 ) ) as $try ) {
			$editor->resize( $try[0], $try[0], false ); // An error only means the photo is already smaller.
			$editor->set_quality( $try[1] );
			foreach ( $mimes as $mime ) {
				$bytes = self::encoded( $editor, $mime );
				if ( false !== $bytes && strlen( $bytes ) <= self::MAX_BYTES ) {
					return $bytes;
				}
			}
		}
		return false;
	}

	/**
	 * The current image of an editor in one format, through a temporary file.
	 *
	 * @param WP_Image_Editor $editor Image editor.
	 * @param string          $mime   Output type.
	 * @return string|false
	 */
	private static function encoded( $editor, $mime ) {
		$temp = wp_tempnam( 'camille-ps-product' );
		if ( ! $temp ) {
			return false;
		}
		$saved = $editor->save( $temp . '.' . wp_get_default_extension_for_mime_type( $mime ), $mime );
		wp_delete_file( $temp );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return false;
		}
		$bytes = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file.
		wp_delete_file( $saved['path'] );
		return $bytes;
	}

	/**
	 * Fingerprint of the file and of the shop's connection: a replaced photo, or a shop connected
	 * again as another store, is copied again. '' when the file is not on this disk.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string
	 */
	private static function signature( $attachment_id ) {
		$connection = Camille_PS_Options::connection();
		$file       = (string) get_attached_file( $attachment_id );
		if ( ! $connection || '' === $file || ! file_exists( $file ) ) {
			return '';
		}
		return md5( $connection['store_id'] . '|' . $file . '|' . (string) filesize( $file ) . '|' . (string) filemtime( $file ) . '|' . self::MAX_SIDE );
	}

	/**
	 * Whether this photo failed less than a day ago (and has not changed since).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	private static function recently_failed( $attachment_id ) {
		$failed = get_post_meta( $attachment_id, self::FAILED_META, true );
		if ( ! is_array( $failed ) || ! isset( $failed['at'], $failed['sig'] ) || time() - (int) $failed['at'] >= DAY_IN_SECONDS ) {
			return false;
		}
		return self::signature( $attachment_id ) === (string) $failed['sig'];
	}

	/**
	 * Remembers a photo that could not be copied, so it is not read again on every sync.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $signature     Fingerprint of the file.
	 */
	private static function remember_failure( $attachment_id, $signature ) {
		update_post_meta(
			$attachment_id,
			self::FAILED_META,
			array(
				'sig' => $signature,
				'at'  => time(),
			)
		);
	}
}
