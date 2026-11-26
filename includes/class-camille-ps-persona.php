<?php
/**
 * Local copy of the personal shopper's name and photo, so the visitors' browsers reach Pinci only
 * when they open the chat.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the name and a small copy of the photo of the personal shopper in the site's uploads.
 */
class Camille_PS_Persona {

	const OPTION     = 'camille_ps_persona';
	const FOLDER     = 'camille-personal-shopper';
	const MAX_BYTES  = 5242880;
	const IMAGE_SIZE = 160;
	const HOOK_COPY  = 'camille_ps_persona_copy';

	/**
	 * Hooks the first copy, for shops connected before this version.
	 */
	public static function init() {
		add_action( self::HOOK_COPY, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'schedule_first_copy' ) );
	}

	/**
	 * A connected shop without a copy gets one at the next cron run.
	 */
	public static function schedule_first_copy() {
		if ( false === get_option( self::OPTION ) && Camille_PS_Options::connection() && ! wp_next_scheduled( self::HOOK_COPY ) ) {
			wp_schedule_single_event( time(), self::HOOK_COPY );
		}
	}

	/**
	 * Name of the personal shopper, Camille until the shop chooses another one.
	 *
	 * @return string
	 */
	public static function name() {
		$copy = self::copy();
		if ( '' !== $copy['name'] ) {
			return $copy['name'];
		}
		$status = Camille_PS_API::cached_status();
		return ! empty( $status['personaName'] ) ? (string) $status['personaName'] : 'Camille';
	}

	/**
	 * Address of the local photo on this site, '' when there is none yet.
	 *
	 * @return string
	 */
	public static function avatar_url() {
		$copy = self::copy();
		if ( '' === $copy['file'] ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || ! file_exists( trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/' . $copy['file'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/' . rawurlencode( $copy['file'] );
	}

	/**
	 * Reads the current name and photo from the service and updates the copy.
	 *
	 * @return bool Whether the service answered.
	 */
	public static function refresh() {
		if ( ! Camille_PS_Options::connection() ) {
			return false;
		}
		$result = Camille_PS_API::signed( 'GET', '/v1/shop/persona' );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['persona'] ) || ! is_array( $result['body']['persona'] ) ) {
			if ( false === get_option( self::OPTION ) ) {
				// Tried: the hourly check tries again, page views do not.
				update_option( self::OPTION, array( 'checked_at' => time() ), false );
			}
			return false;
		}
		$persona = self::read_answer( $result['body']['persona'] );
		self::remember( $persona['name'], $persona['photo'] );
		return true;
	}

	/**
	 * Name, gender, chosen photo and gallery from the service's answer.
	 *
	 * @param array $persona Answer field "persona".
	 * @return array{name:string,gender:string,current:string,photo:string,avatars:array}
	 */
	public static function read_answer( array $persona ) {
		$current = isset( $persona['avatarPresetId'] ) ? (string) $persona['avatarPresetId'] : '';
		$avatars = isset( $persona['avatars'] ) && is_array( $persona['avatars'] ) ? $persona['avatars'] : array();
		$photo   = '';
		foreach ( $avatars as $avatar ) {
			if ( is_array( $avatar ) && isset( $avatar['id'], $avatar['imageUrl'] ) && $current === (string) $avatar['id'] ) {
				$photo = (string) $avatar['imageUrl'];
			}
		}
		return array(
			'name'    => isset( $persona['name'] ) ? sanitize_text_field( (string) $persona['name'] ) : 'Camille',
			'gender'  => isset( $persona['gender'] ) && 'male' === $persona['gender'] ? 'male' : 'female',
			'current' => $current,
			'photo'   => $photo,
			'avatars' => $avatars,
		);
	}

	/**
	 * Saves the name and copies the photo when it changed. The photo is downloaded once, shrunk and
	 * stored in the uploads folder; on any problem the previous copy stays.
	 *
	 * @param string $name  Name of the personal shopper.
	 * @param string $photo Address of the photo on Pinci, '' for none.
	 */
	public static function remember( $name, $photo ) {
		$copy         = self::copy();
		$copy['name'] = mb_substr( sanitize_text_field( (string) $name ), 0, 40 );
		$source       = self::source_key( $photo );
		if ( '' === $source ) {
			self::delete_file( $copy['file'] );
			$copy['file']   = '';
			$copy['source'] = '';
		} elseif ( $source !== $copy['source'] || '' === self::avatar_url() ) {
			$file = self::download( $photo );
			if ( '' !== $file ) {
				if ( $file !== $copy['file'] ) {
					self::delete_file( $copy['file'] );
				}
				$copy['file']   = $file;
				$copy['source'] = $source;
			}
		}
		$copy['checked_at'] = time();
		update_option( self::OPTION, $copy, false );
	}

	/**
	 * Removes the copy (disconnection).
	 */
	public static function forget() {
		$copy = self::copy();
		self::delete_file( $copy['file'] );
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK_COPY );
	}

	/**
	 * The stored copy.
	 *
	 * @return array{name:string,file:string,source:string,checked_at:int}
	 */
	private static function copy() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'name'       => isset( $stored['name'] ) ? (string) $stored['name'] : '',
			'file'       => isset( $stored['file'] ) ? sanitize_file_name( (string) $stored['file'] ) : '',
			'source'     => isset( $stored['source'] ) ? (string) $stored['source'] : '',
			'checked_at' => isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0,
		);
	}

	/**
	 * The photo address without its query string: signed addresses change at every read, the photo
	 * behind them does not.
	 *
	 * @param string $photo Address.
	 * @return string '' when the address cannot be used.
	 */
	private static function source_key( $photo ) {
		$photo = trim( (string) $photo );
		if ( '' === $photo || ! wp_http_validate_url( $photo ) ) {
			return '';
		}
		$scheme = wp_parse_url( $photo, PHP_URL_SCHEME );
		// Plain http only for a test setup whose service address is plain http too.
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && 0 === strpos( CAMILLE_PS_SERVICE_URL, 'http://' ) ) ) {
			return '';
		}
		$parts = explode( '?', $photo, 2 );
		return $parts[0];
	}

	/**
	 * Downloads the photo, shrinks it and saves it in the uploads folder.
	 *
	 * @param string $photo Address of the photo.
	 * @return string File name in the folder, '' on failure.
	 */
	private static function download( $photo ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$folder = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		if ( ! wp_mkdir_p( $folder ) ) {
			return '';
		}
		$tmp      = wp_tempnam( 'camille-ps-persona' );
		$response = wp_safe_remote_get(
			$photo,
			array(
				'timeout'             => 15,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		$name     = '';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$info = wp_getimagesize( $tmp );
			if ( is_array( $info ) && in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
				$editor = wp_get_image_editor( $tmp );
				if ( ! is_wp_error( $editor ) ) {
					$editor->resize( self::IMAGE_SIZE, self::IMAGE_SIZE, true );
					$editor->set_quality( 85 );
					$candidate = 'persona-' . substr( md5( $photo . wp_salt( 'auth' ) . microtime() ), 0, 12 ) . '.jpg';
					$saved     = $editor->save( $folder . '/' . $candidate, 'image/jpeg' );
					if ( ! is_wp_error( $saved ) && ! empty( $saved['file'] ) ) {
						$name = sanitize_file_name( (string) $saved['file'] );
					}
				}
			}
		}
		wp_delete_file( $tmp );
		return $name;
	}

	/**
	 * Deletes a copy file from the folder.
	 *
	 * @param string $file File name.
	 */
	private static function delete_file( $file ) {
		$file = sanitize_file_name( (string) $file );
		if ( '' === $file ) {
			return;
		}
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) ) {
			wp_delete_file( trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/' . $file );
		}
	}
}
