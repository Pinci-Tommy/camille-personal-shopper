<?php
/**
 * Language of the plugin screens, chosen by each shop manager.
 *
 * Only the plugin's own texts change: the rest of WordPress keeps its language, and the shop
 * (with Camille's chat) keeps following the language of each page.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-user language of the plugin in the admin.
 */
class Camille_PS_Language {

	const DOMAIN = 'camille-personal-shopper';

	/** User meta with the chosen language; absent = same as WordPress. */
	const META = 'camille_ps_admin_language';

	const ACTION = 'camille_ps_admin_language';

	/**
	 * Languages of the plugin: code => WordPress locale.
	 *
	 * @var array<string,string>
	 */
	const LOCALES = array(
		'it' => 'it_IT',
		'en' => 'en_US',
		'fr' => 'fr_FR',
		'de' => 'de_DE',
		'es' => 'es_ES',
	);

	/**
	 * Name of each language in that language, so that anyone finds their own.
	 *
	 * @var array<string,string>
	 */
	const NAMES = array(
		'it' => 'Italiano',
		'en' => 'English',
		'fr' => 'Français',
		'de' => 'Deutsch',
		'es' => 'Español',
	);

	/**
	 * Hooks the language choice.
	 */
	public static function init() {
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'mofile' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_choose' ) );
	}

	/**
	 * Language chosen by the current user for the plugin screens, '' for the WordPress one.
	 *
	 * @return string
	 */
	public static function chosen() {
		if ( ! is_admin() || ! function_exists( 'get_current_user_id' ) ) {
			return '';
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}
		$language = (string) get_user_meta( $user_id, self::META, true );
		return isset( self::LOCALES[ $language ] ) ? $language : '';
	}

	/**
	 * Language the plugin screens are shown in now.
	 *
	 * @return string
	 */
	public static function current() {
		$chosen = self::chosen();
		if ( '' !== $chosen ) {
			return $chosen;
		}
		$language = strtolower( substr( determine_locale(), 0, 2 ) );
		return isset( self::LOCALES[ $language ] ) ? $language : 'en';
	}

	/**
	 * Translations file of the plugin in the chosen language.
	 *
	 * @param string $mofile Path WordPress is about to load.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function mofile( $mofile, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $mofile;
		}
		$chosen = self::chosen();
		if ( '' === $chosen ) {
			return $mofile;
		}
		// English is the language of the code: a file that does not exist leaves the texts as they are.
		return CAMILLE_PS_DIR . 'languages/' . self::DOMAIN . '-' . self::LOCALES[ $chosen ] . '.mo';
	}

	/**
	 * Called on init: texts translated before the user was known are loaded again.
	 */
	public static function reload() {
		if ( '' !== self::chosen() ) {
			unload_textdomain( self::DOMAIN, true );
		}
	}

	/**
	 * Saves the language picked in the header and goes back to the same page.
	 */
	public static function handle_choose() {
		if ( ! current_user_can( Camille_PS_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'camille-personal-shopper' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		if ( isset( self::LOCALES[ $language ] ) ) {
			update_user_meta( get_current_user_id(), self::META, $language );
		} else {
			delete_user_meta( get_current_user_id(), self::META );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : Camille_PS_Admin::tab_url( Camille_PS_Admin::TAB_CATALOG ) );
		exit;
	}

	/**
	 * Language button of the header, with the list of languages.
	 */
	public static function render_switcher() {
		$current = self::current();
		$chosen  = self::chosen();
		$label   = __( 'Plugin language', 'camille-personal-shopper' );
		echo '<details class="camille-ps-language">';
		echo '<summary aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">';
		echo '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.6" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-8.6 9h17.2M12 3c2.4 2.5 3.6 5.5 3.6 9s-1.2 6.5-3.6 9c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>';
		echo '<span>' . esc_html( strtoupper( $current ) ) . '</span></summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<p class="camille-ps-kicker">' . esc_html( $label ) . '</p>';
		echo '<button type="submit" name="language" value=""' . ( '' === $chosen ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Same as WordPress', 'camille-personal-shopper' ) . '</button>';
		foreach ( self::NAMES as $code => $name ) {
			echo '<button type="submit" name="language" value="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $code === $chosen ? ' aria-current="true"' : '' ) . '>' . esc_html( $name ) . '</button>';
		}
		echo '</form></details>';
	}
}
