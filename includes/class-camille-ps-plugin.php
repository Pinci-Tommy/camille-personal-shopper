<?php
/**
 * Boots the plugin. Any failure stays inside the plugin: the shop keeps working without Camille.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wiring of the parts and the hourly status check.
 */
class Camille_PS_Plugin {

	const STATUS_HOOK = 'camille_ps_refresh_status';

	/**
	 * Runs on plugins_loaded.
	 */
	public static function boot() {
		Camille_PS_Language::init();
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}
		Camille_PS_Catalog_Sync::init();
		Camille_PS_Frontend::init();
		Camille_PS_Privacy::init();
		if ( is_admin() ) {
			Camille_PS_Admin::init();
			Camille_PS_Catalog_Filter::init();
			Camille_PS_Customers::init();
			Camille_PS_Activity::init();
		}
		add_action( self::STATUS_HOOK, array( __CLASS__, 'refresh_status' ) );
		add_action( 'init', array( __CLASS__, 'schedule_status_check' ) );
	}

	/**
	 * Translations shipped with the plugin (the zip version; WordPress.org adds its own).
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'camille-personal-shopper', false, dirname( plugin_basename( CAMILLE_PS_FILE ) ) . '/languages' );
		Camille_PS_Language::reload();
	}

	/**
	 * Notice when WooCommerce is not active.
	 */
	public static function missing_woocommerce_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Camille – AI Personal Shopper needs WooCommerce. Install and activate WooCommerce to use it.', 'camille-personal-shopper' ) . '</p></div>';
		}
	}

	/**
	 * Hourly check of the status, so the storefront follows a pause decided on Pinci's side
	 * (credits used up, service paused) without ever calling the service itself.
	 */
	public static function schedule_status_check() {
		if ( Camille_PS_Options::connection() && ! wp_next_scheduled( self::STATUS_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::STATUS_HOOK );
		}
	}

	/**
	 * Cron: refreshes the cached status.
	 */
	public static function refresh_status() {
		if ( Camille_PS_Options::connection() ) {
			Camille_PS_API::refresh_status();
		}
	}

	/**
	 * Deactivation: stops the background tasks. The connection is kept for a later reactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::STATUS_HOOK );
		if ( class_exists( 'Camille_PS_Catalog_Sync' ) ) {
			Camille_PS_Catalog_Sync::cancel();
		}
	}
}
