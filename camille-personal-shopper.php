<?php
/**
 * Plugin Name:       Camille – AI Personal Shopper for WooCommerce
 * Plugin URI:        https://camille-ai.com
 * Description:       Camille is an AI personal shopper for fashion stores: she advises your customers in chat, suggests outfits from your catalog and shows them on a virtual model.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Instamark S.r.l.
 * Author URI:        https://camille-ai.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       camille-personal-shopper
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

define( 'CAMILLE_PS_VERSION', '0.3.0' );
define( 'CAMILLE_PS_FILE', __FILE__ );
define( 'CAMILLE_PS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAMILLE_PS_URL', plugin_dir_url( __FILE__ ) );

/*
 * External service addresses. They can be changed in wp-config.php for a test setup only.
 * CAMILLE_PS_SERVICE_URL: the Camille connector (connection, catalog sync).
 * CAMILLE_PS_CHAT_URL:    the page shown in the chat window.
 * CAMILLE_PS_API_URL:     the public Pinci API read by the chat button (name and photo of Camille).
 */
if ( ! defined( 'CAMILLE_PS_SERVICE_URL' ) ) {
	define( 'CAMILLE_PS_SERVICE_URL', 'https://woo.pinci.ai' );
}
if ( ! defined( 'CAMILLE_PS_CHAT_URL' ) ) {
	define( 'CAMILLE_PS_CHAT_URL', 'https://pinci.ai' );
}
if ( ! defined( 'CAMILLE_PS_API_URL' ) ) {
	define( 'CAMILLE_PS_API_URL', 'https://api.pinci.ai' );
}

require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-options.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-api.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-catalog-filter.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-customers.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-product-export.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-catalog-sync.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-admin.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-frontend.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-privacy.php';
require_once CAMILLE_PS_DIR . 'includes/class-camille-ps-plugin.php';

register_deactivation_hook( __FILE__, array( 'Camille_PS_Plugin', 'deactivate' ) );

// Declares support for WooCommerce order tables (HPOS): the plugin never reads orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Camille_PS_Plugin', 'boot' ) );
