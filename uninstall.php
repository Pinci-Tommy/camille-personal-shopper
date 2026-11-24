<?php
/**
 * Uninstall: disconnects the shop from Camille and removes everything the plugin stored.
 * The Pinci account, its credits and the catalog on Pinci's side are not touched.
 *
 * @package CamillePersonalShopper
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'CAMILLE_PS_SERVICE_URL' ) ) {
	define( 'CAMILLE_PS_SERVICE_URL', 'https://woo.pinci.ai' );
}
if ( ! defined( 'CAMILLE_PS_VERSION' ) ) {
	define( 'CAMILLE_PS_VERSION', '0.1.0' );
}
require_once __DIR__ . '/includes/class-camille-ps-options.php';
require_once __DIR__ . '/includes/class-camille-ps-api.php';

// Best effort: Camille switches off for the shop and the secret stops working.
if ( Camille_PS_Options::connection() ) {
	Camille_PS_API::signed( 'POST', '/v1/shop/disconnect', null, 10 );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'camille_ps_export_page', array(), 'camille-personal-shopper' );
	as_unschedule_all_actions( 'camille_ps_export_finish', array(), 'camille-personal-shopper' );
}
wp_clear_scheduled_hook( 'camille_ps_refresh_status' );

foreach ( array( Camille_PS_Options::CONNECTION, Camille_PS_Options::PENDING, Camille_PS_Options::SETTINGS, Camille_PS_Options::SYNC, Camille_PS_Options::BILLING, Camille_PS_API::STATUS_CACHE ) as $camille_ps_option ) {
	delete_option( $camille_ps_option );
}
