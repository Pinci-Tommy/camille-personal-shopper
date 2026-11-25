<?php
/**
 * Suggested text for the shop's privacy policy (Settings > Privacy).
 *
 * The plugin keeps no personal data in WordPress: nothing to export or erase here. Chats and
 * photos are kept by Pinci, which answers access and erasure requests (privacy@pinci.ai).
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy policy content.
 */
class Camille_PS_Privacy {

	/**
	 * Hooks the privacy text.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_text' ) );
	}

	/**
	 * Adds the suggested text to the privacy policy guide.
	 */
	public static function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Camille is provided by Pinci (Instamark S.r.l.). The text below is a suggestion: adapt it to your shop.', 'camille-personal-shopper' ) . '</p>';
		$text .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'camille-personal-shopper' ) . ' </strong>';
		$text .= esc_html__( 'This shop offers Camille, an AI personal shopper provided by Pinci (Instamark S.r.l., Italy). When you open the chat, it is loaded from pinci.ai and what you write, the photos you choose to upload for the virtual try-on and the products you look at are processed by Pinci to answer you and to show the products on a virtual model. The chat does not receive your name, email or orders from this shop. If you are logged in to your account, it receives only the number of the account, so that the usage limits of this shop follow you on every device.', 'camille-personal-shopper' ) . '</p>';
		$text .= '<p>' . wp_kses(
			sprintf(
				/* translators: %s: link to the Pinci privacy policy. */
				__( 'How Pinci processes these data, how long it keeps them and how to ask for access or erasure: %s.', 'camille-personal-shopper' ),
				'<a href="https://pinci.ai/privacy#camille">pinci.ai/privacy</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
		wp_add_privacy_policy_content( __( 'Camille – AI Personal Shopper', 'camille-personal-shopper' ), wp_kses_post( $text ) );
	}
}
