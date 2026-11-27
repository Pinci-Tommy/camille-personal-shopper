<?php
/**
 * Copies of the product photos kept by Camille. Each photo is read from the shop's own disk, shrunk
 * and sent once; the catalog then gives Camille the address of the copy. Virtual try-ons and the
 * chat never download photos from the shop's site, so no hosting firewall, CDN or bot protection in
 * front of it can block them. A photo that cannot be copied keeps its usual address on the site.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the photos and remembers their copies (attachment meta).
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
	 * Copies the photos that have no current copy, until the time budget is spent. When the service
	 * cannot be reached, the remaining photos wait for the next round.
	 *
	 * @param int[] $attachment_ids Attachment ids.
	 * @param int   $seconds        Time budget.
	 * @return array{handled:int,waiting:int} Photos copied or set aside in this round, photos still waiting.
	 */
	public static function ensure( array $attachment_ids, $seconds ) {
		$started     = microtime( true );
		$handled     = 0;
		$waiting     = 0;
		$unreachable = false;
		if ( ! Camille_PS_Options::connection() ) {
			return array(
				'handled' => 0,
				'waiting' => 0,
			);
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) as $attachment_id ) {
			if ( '' !== self::url( $attachment_id ) || self::recently_failed( $attachment_id ) ) {
				continue;
			}
			if ( $unreachable || microtime( true ) - $started > $seconds ) {
				++$waiting;
				continue;
			}
			if ( 'retry' === self::copy( $attachment_id ) ) {
				$unreachable = true;
				++$waiting;
				continue;
			}
			++$handled;
		}
		return array(
			'handled' => $handled,
			'waiting' => $waiting,
		);
	}

	/**
	 * Sends one photo.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string done, failed (kept on the site address for a day) or retry (service unreachable).
	 */
	private static function copy( $attachment_id ) {
		$signature = self::signature( $attachment_id );
		$bytes     = '' === $signature ? false : self::shrunk_bytes( (string) get_attached_file( $attachment_id ) );
		if ( false === $bytes ) {
			self::remember_failure( $attachment_id, $signature );
			return 'failed';
		}
		$result = Camille_PS_API::signed(
			'POST',
			'/v1/shop/catalog/photo',
			array( 'imageBase64' => base64_encode( $bytes ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image sent as JSON.
			self::REQUEST_TIMEOUT
		);
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
