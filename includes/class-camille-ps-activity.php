<?php
/**
 * What the shop's customers did with Camille: their chats and virtual try-ons, shown in the
 * Conversations and Virtual try-ons tabs of WooCommerce > Camille. Pinci keeps the chats (about
 * 30 days) and knows a customer only by the WordPress user id the plugin signed: names and
 * profile links are added here, from WordPress.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conversations and try-ons tabs, the customer summary of the user profile, try-on blocking.
 */
class Camille_PS_Activity {

	const TAB_CONVERSATIONS = 'conversations';
	const TAB_TRY_ONS       = 'try-ons';

	/** Rows per page of the lists. */
	const PAGE_SIZE = 20;

	/** Try-ons per page of the gallery. */
	const GALLERY_SIZE = 24;

	/** Native names of the chat languages. */
	const LANGUAGES = array(
		'it' => 'Italiano',
		'en' => 'English',
		'fr' => 'Français',
		'de' => 'Deutsch',
		'es' => 'Español',
	);

	/**
	 * Hooks the form that blocks a customer's try-ons.
	 */
	public static function init() {
		add_action( 'admin_post_camille_ps_try_on_block', array( __CLASS__, 'handle_try_on_block' ) );
	}

	/**
	 * URL of a tab, with optional arguments (chat, customer, paged).
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	public static function tab_url( $tab, array $args = array() ) {
		return Camille_PS_Admin::tab_url( $tab, $args );
	}

	/* ---------------------------------------------------------------- query */

	/**
	 * Page number from the query string.
	 *
	 * @return int
	 */
	private static function paged() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
	}

	/**
	 * Customer filter from the query string (a WordPress user id), 0 for none.
	 *
	 * @return int
	 */
	private static function customer_filter() {
		return isset( $_GET['customer'] ) ? absint( $_GET['customer'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
	}

	/**
	 * Reads a list from the service.
	 *
	 * @param string $path     /v1/shop/conversations or /v1/shop/try-ons.
	 * @param int    $page     Page.
	 * @param int    $limit    Page size.
	 * @param int    $customer Customer filter, 0 for none.
	 * @return array|string The page, or the error message.
	 */
	private static function fetch_page( $path, $page, $limit, $customer ) {
		$args = array(
			'page'  => $page,
			'limit' => $limit,
		);
		if ( $customer > 0 ) {
			$args['customerId'] = $customer;
		}
		$result = Camille_PS_API::signed( 'GET', $path . '?' . http_build_query( $args, '', '&' ) );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['items'] ) || ! is_array( $result['body']['items'] ) ) {
			return Camille_PS_API::error_message( $result );
		}
		return array(
			'items' => array_values( array_filter( $result['body']['items'], 'is_array' ) ),
			'total' => isset( $result['body']['total'] ) ? (int) $result['body']['total'] : 0,
			'pages' => isset( $result['body']['pages'] ) ? (int) $result['body']['pages'] : 0,
		);
	}

	/**
	 * Summary of one customer (chats, try-ons, blocked), null when it cannot be read.
	 *
	 * @param int $customer WordPress user id.
	 * @return array|null
	 */
	public static function customer_summary( $customer ) {
		$result = Camille_PS_API::signed( 'GET', '/v1/shop/customers/' . absint( $customer ) );
		if ( is_wp_error( $result ) || 200 !== $result['code'] ) {
			return null;
		}
		return array(
			'conversations' => isset( $result['body']['conversationCount'] ) ? (int) $result['body']['conversationCount'] : 0,
			'tryOns'        => isset( $result['body']['tryOnCount'] ) ? (int) $result['body']['tryOnCount'] : 0,
			'isBlocked'     => ! empty( $result['body']['isTryOnBlocked'] ),
		);
	}

	/**
	 * Stops (or lets again) a customer get try-ons.
	 *
	 * @param int  $customer   WordPress user id.
	 * @param bool $is_blocked Block or unblock.
	 * @return true|string True, or the error message.
	 */
	public static function set_try_on_blocked( $customer, $is_blocked ) {
		$result = Camille_PS_API::signed( 'POST', '/v1/shop/customers/' . absint( $customer ) . '/try-on-block', array( 'blocked' => (bool) $is_blocked ) );
		if ( ! is_wp_error( $result ) && 200 === $result['code'] ) {
			return true;
		}
		if ( ! is_wp_error( $result ) && 409 === $result['code'] ) {
			return __( 'Too many customers are blocked already. Unblock someone first.', 'camille-personal-shopper' );
		}
		return Camille_PS_API::error_message( $result );
	}

	/* ---------------------------------------------------------------- formatting */

	/**
	 * Date and time in the site's format.
	 *
	 * @param mixed $iso ISO date from the service.
	 * @return string
	 */
	private static function when( $iso ) {
		$time = is_string( $iso ) ? strtotime( $iso ) : false;
		if ( ! $time ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Who a customer is: name and email from WordPress, linked to the profile.
	 *
	 * @param mixed $customer_id Customer id from the service (WordPress user id), null for a guest.
	 * @param bool  $with_email  Whether to add the email.
	 * @return string HTML.
	 */
	public static function customer_html( $customer_id, $with_email = true ) {
		$id = is_scalar( $customer_id ) ? absint( $customer_id ) : 0;
		if ( ! $id ) {
			return esc_html__( 'Guest', 'camille-personal-shopper' );
		}
		$user = get_userdata( $id );
		if ( ! $user ) {
			/* translators: %d: WordPress user id. */
			return esc_html( sprintf( __( 'Deleted customer (#%d)', 'camille-personal-shopper' ), $id ) );
		}
		$name = trim( $user->display_name ) !== '' ? $user->display_name : $user->user_login;
		$html = current_user_can( 'edit_user', $id )
			? '<a href="' . esc_url( get_edit_user_link( $id ) ) . '">' . esc_html( $name ) . '</a>'
			: esc_html( $name );
		if ( $with_email && $user->user_email ) {
			$html .= ' <span class="camille-ps-muted">' . esc_html( $user->user_email ) . '</span>';
		}
		return $html;
	}

	/**
	 * Text of a chat message: escaped, line breaks and **bold** kept.
	 *
	 * @param string $text Message.
	 * @return string HTML.
	 */
	private static function message_html( $text ) {
		$html = esc_html( trim( $text ) );
		$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
		return nl2br( (string) $html );
	}

	/**
	 * Whether a value is an image data URL (photos the customer sent in the chat).
	 *
	 * @param mixed $url Value.
	 * @return bool
	 */
	private static function is_image_data_url( $url ) {
		return is_string( $url ) && 1 === preg_match( '#^data:image/(jpeg|png|webp|gif);base64,[A-Za-z0-9+/=]+$#', $url );
	}

	/**
	 * Previous / next links of a list.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Pages.
	 */
	private static function render_pagination( $page, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages camille-ps-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * "Only the chats of X · Show all" above a filtered list.
	 *
	 * @param int    $customer Customer filter.
	 * @param string $tab      Current tab.
	 */
	private static function render_filter( $customer, $tab ) {
		if ( ! $customer ) {
			return;
		}
		$text = self::TAB_TRY_ONS === $tab
			/* translators: %s: customer name. */
			? __( 'Virtual try-ons of %s', 'camille-personal-shopper' )
			/* translators: %s: customer name. */
			: __( 'Conversations of %s', 'camille-personal-shopper' );
		echo '<p class="camille-ps-filter">' . wp_kses_post( sprintf( $text, self::customer_html( $customer ) ) ) . ' · <a href="' . esc_url( self::tab_url( $tab ) ) . '">' . esc_html__( 'Show all', 'camille-personal-shopper' ) . '</a></p>';
	}

	/* ---------------------------------------------------------------- conversations */

	/**
	 * Conversations tab: the list, or one chat.
	 */
	public static function render_conversations() {
		$chat = isset( $_GET['chat'] ) ? sanitize_text_field( wp_unslash( $_GET['chat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		if ( '' !== $chat && preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $chat ) ) {
			self::render_conversation( $chat );
			return;
		}
		$page     = self::paged();
		$customer = self::customer_filter();
		echo '<p class="camille-ps-intro">' . esc_html__( 'The chats of your customers with your personal shopper, newest first. They stay available for about 30 days.', 'camille-personal-shopper' ) . '</p>';
		self::render_filter( $customer, self::TAB_CONVERSATIONS );
		$list = self::fetch_page( '/v1/shop/conversations', $page, self::PAGE_SIZE, $customer );
		if ( is_string( $list ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $list ) . '</p></div>';
			return;
		}
		if ( ! $list['items'] ) {
			echo '<div class="camille-ps-card camille-ps-empty"><p>' . esc_html__( 'No conversations yet. When your customers chat with your personal shopper, the chats appear here.', 'camille-personal-shopper' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped camille-ps-chats"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Date', 'camille-personal-shopper' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Customer', 'camille-personal-shopper' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Messages', 'camille-personal-shopper' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Virtual try-ons', 'camille-personal-shopper' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Language', 'camille-personal-shopper' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $list['items'] as $item ) {
			$token = isset( $item['sessionToken'] ) ? (string) $item['sessionToken'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $token ) ) {
				continue;
			}
			$url      = self::tab_url( self::TAB_CONVERSATIONS, array( 'chat' => $token ) );
			$language = isset( $item['language'] ) && isset( self::LANGUAGES[ $item['language'] ] ) ? self::LANGUAGES[ $item['language'] ] : '';
			$count    = isset( $item['tryOnCount'] ) ? (int) $item['tryOnCount'] : 0;
			echo '<tr>';
			echo '<td><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( self::when( isset( $item['createdAt'] ) ? $item['createdAt'] : null ) ) . '</a>';
			echo '<div class="row-actions"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the chat', 'camille-personal-shopper' ) . '</a></div></td>';
			echo '<td>' . wp_kses_post( self::customer_html( isset( $item['customerId'] ) ? $item['customerId'] : null ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( isset( $item['messageCount'] ) ? (int) $item['messageCount'] : 0 ) ) . '</td>';
			echo '<td><span class="camille-ps-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
			foreach ( isset( $item['thumbnailUrls'] ) && is_array( $item['thumbnailUrls'] ) ? $item['thumbnailUrls'] : array() as $thumb ) {
				if ( is_string( $thumb ) && 0 === strpos( $thumb, 'https://' ) ) {
					echo '<a href="' . esc_url( $url ) . '"><img class="camille-ps-thumb" src="' . esc_url( $thumb ) . '" alt="" width="36" height="48" loading="lazy"></a>';
				}
			}
			echo '</td>';
			echo '<td>' . esc_html( $language ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		self::render_pagination( $page, $list['pages'] );
	}

	/**
	 * One chat, with the photos the customer sent and the try-ons Camille made.
	 *
	 * @param string $token Chat token.
	 */
	private static function render_conversation( $token ) {
		echo '<p><a href="' . esc_url( self::tab_url( self::TAB_CONVERSATIONS ) ) . '">&larr; ' . esc_html__( 'All conversations', 'camille-personal-shopper' ) . '</a></p>';
		$result = Camille_PS_API::signed( 'GET', '/v1/shop/conversations/' . rawurlencode( $token ) );
		if ( ! is_wp_error( $result ) && 404 === $result['code'] ) {
			echo '<div class="camille-ps-card"><p>' . esc_html__( 'This conversation is no longer available.', 'camille-personal-shopper' ) . '</p></div>';
			return;
		}
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['body']['messages'] ) || ! is_array( $result['body']['messages'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Camille_PS_API::error_message( $result ) ) . '</p></div>';
			return;
		}
		$chat        = $result['body'];
		$customer    = isset( $chat['customerId'] ) && is_scalar( $chat['customerId'] ) ? absint( $chat['customerId'] ) : 0;
		$agent       = isset( $chat['agentName'] ) && is_string( $chat['agentName'] ) && '' !== $chat['agentName'] ? $chat['agentName'] : 'Camille';
		$agent_photo = isset( $chat['agentAvatarUrl'] ) && is_string( $chat['agentAvatarUrl'] ) && 0 === strpos( $chat['agentAvatarUrl'], 'https://' ) ? $chat['agentAvatarUrl'] : '';
		$images      = array();
		foreach ( isset( $chat['generations'] ) && is_array( $chat['generations'] ) ? $chat['generations'] : array() as $generation ) {
			if ( is_array( $generation ) && ! empty( $generation['generationId'] ) && isset( $generation['imageUrl'] ) && 0 === strpos( (string) $generation['imageUrl'], 'https://' ) ) {
				$images[ (string) $generation['generationId'] ] = (string) $generation['imageUrl'];
			}
		}

		echo '<div class="camille-ps-card camille-ps-chat-head">';
		echo '<table class="camille-ps-facts"><tbody>';
		echo '<tr><th>' . esc_html__( 'Customer', 'camille-personal-shopper' ) . '</th><td>' . wp_kses_post( self::customer_html( $customer ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Started', 'camille-personal-shopper' ) . '</th><td>' . esc_html( self::when( isset( $chat['createdAt'] ) ? $chat['createdAt'] : null ) ) . '</td></tr>';
		if ( isset( $chat['language'] ) && isset( self::LANGUAGES[ $chat['language'] ] ) ) {
			echo '<tr><th>' . esc_html__( 'Language', 'camille-personal-shopper' ) . '</th><td>' . esc_html( self::LANGUAGES[ $chat['language'] ] ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Virtual try-ons', 'camille-personal-shopper' ) . '</th><td>' . esc_html( number_format_i18n( count( $images ) ) ) . '</td></tr>';
		echo '</tbody></table>';
		if ( $customer && get_userdata( $customer ) ) {
			echo '<p><a href="' . esc_url( self::tab_url( self::TAB_CONVERSATIONS, array( 'customer' => $customer ) ) ) . '">' . esc_html__( 'All chats of this customer', 'camille-personal-shopper' ) . '</a> · <a href="' . esc_url( self::tab_url( self::TAB_TRY_ONS, array( 'customer' => $customer ) ) ) . '">' . esc_html__( 'All try-ons of this customer', 'camille-personal-shopper' ) . '</a></p>';
			self::render_block_form( $customer, ! empty( $chat['isCustomerTryOnBlocked'] ), self::tab_url( self::TAB_CONVERSATIONS, array( 'chat' => $token ) ) );
		}
		echo '</div>';

		echo '<div class="camille-ps-card camille-ps-chat">';
		$shown = array();
		foreach ( $chat['messages'] as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}
			$is_customer = 'user' === $message['role'];
			echo '<div class="camille-ps-message ' . ( $is_customer ? 'is-customer' : 'is-agent' ) . '">';
			echo '<div class="camille-ps-message-who">';
			if ( ! $is_customer && $agent_photo ) {
				echo '<img src="' . esc_url( $agent_photo ) . '" alt="" width="24" height="24">';
			}
			echo esc_html( $is_customer ? __( 'Customer', 'camille-personal-shopper' ) : $agent );
			echo ' <span class="camille-ps-muted">' . esc_html( self::when( isset( $message['timestamp'] ) ? $message['timestamp'] : null ) ) . '</span></div>';
			$text = isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';
			if ( '' !== trim( $text ) ) {
				echo '<div class="camille-ps-message-text">' . wp_kses( self::message_html( $text ), array( 'strong' => array(), 'br' => array() ) ) . '</div>';
			}
			$pictures = array();
			foreach ( isset( $message['attachments'] ) && is_array( $message['attachments'] ) ? $message['attachments'] : array() as $attachment ) {
				if ( is_array( $attachment ) && isset( $attachment['url'] ) && self::is_image_data_url( $attachment['url'] ) ) {
					// A data URL: esc_url() would drop it, the pattern above already allows only base64 image data.
					$pictures[] = '<img src="' . esc_attr( $attachment['url'] ) . '" alt="' . esc_attr__( 'Photo sent by the customer', 'camille-personal-shopper' ) . '">';
				}
			}
			foreach ( isset( $message['generationIds'] ) && is_array( $message['generationIds'] ) ? $message['generationIds'] : array() as $generation_id ) {
				if ( is_string( $generation_id ) && isset( $images[ $generation_id ] ) && ! isset( $shown[ $generation_id ] ) ) {
					$shown[ $generation_id ] = true;
					$pictures[]              = '<a href="' . esc_url( $images[ $generation_id ] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $images[ $generation_id ] ) . '" alt="' . esc_attr__( 'Virtual try-on', 'camille-personal-shopper' ) . '" loading="lazy"></a>';
				}
			}
			if ( $pictures ) {
				echo '<div class="camille-ps-message-images">' . implode( '', $pictures ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			}
			echo '</div>';
		}
		$left = array_diff_key( $images, $shown );
		if ( $left ) {
			echo '<div class="camille-ps-message is-agent"><div class="camille-ps-message-who">' . esc_html__( 'Other try-ons of this chat', 'camille-personal-shopper' ) . '</div><div class="camille-ps-message-images">';
			foreach ( $left as $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Virtual try-on', 'camille-personal-shopper' ) . '" loading="lazy"></a>';
			}
			echo '</div></div>';
		}
		if ( ! $chat['messages'] && ! $images ) {
			echo '<p>' . esc_html__( 'This chat has no messages.', 'camille-personal-shopper' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Button that blocks (or allows again) the try-ons of a customer.
	 *
	 * @param int    $customer   WordPress user id.
	 * @param bool   $is_blocked Current state.
	 * @param string $back       Where to come back.
	 */
	public static function render_block_form( $customer, $is_blocked, $back ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="camille-ps-block">';
		echo '<input type="hidden" name="action" value="camille_ps_try_on_block">';
		echo '<input type="hidden" name="customer" value="' . esc_attr( (string) $customer ) . '">';
		echo '<input type="hidden" name="blocked" value="' . ( $is_blocked ? '0' : '1' ) . '">';
		echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		wp_nonce_field( 'camille_ps_try_on_block_' . $customer );
		if ( $is_blocked ) {
			echo '<span class="camille-ps-badge is-warning">' . esc_html__( 'Virtual try-ons are blocked for this customer. The chat stays open.', 'camille-personal-shopper' ) . '</span> ';
			submit_button( __( 'Allow try-ons again', 'camille-personal-shopper' ), 'secondary small', 'submit', false );
		} else {
			submit_button( __( 'Block try-ons for this customer', 'camille-personal-shopper' ), 'secondary small', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'For a customer who uses up your credits. The chat stays open.', 'camille-personal-shopper' ) . '</span>';
		}
		echo '</form>';
	}

	/**
	 * Saves the block button.
	 */
	public static function handle_try_on_block() {
		if ( ! current_user_can( Camille_PS_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'camille-personal-shopper' ), 403 );
		}
		$customer = isset( $_POST['customer'] ) ? absint( $_POST['customer'] ) : 0;
		check_admin_referer( 'camille_ps_try_on_block_' . $customer );
		$blocked = isset( $_POST['blocked'] ) && '1' === sanitize_key( wp_unslash( $_POST['blocked'] ) );
		$back    = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$back    = wp_validate_redirect( $back, self::tab_url( self::TAB_CONVERSATIONS ) );
		$result  = $customer ? self::set_try_on_blocked( $customer, $blocked ) : __( 'Unknown customer.', 'camille-personal-shopper' );
		if ( true === $result ) {
			$message = $blocked
				? __( 'Virtual try-ons blocked for this customer.', 'camille-personal-shopper' )
				: __( 'Virtual try-ons allowed again for this customer.', 'camille-personal-shopper' );
			Camille_PS_Admin::add_notice( 'success', esc_html( $message ) );
		} else {
			Camille_PS_Admin::add_notice( 'error', esc_html( $result ) );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------- try-ons */

	/**
	 * Virtual try-ons tab: the gallery.
	 */
	public static function render_try_ons() {
		$page     = self::paged();
		$customer = self::customer_filter();
		echo '<p class="camille-ps-intro">' . esc_html__( 'The virtual try-ons your personal shopper made for your customers, newest first.', 'camille-personal-shopper' ) . '</p>';
		self::render_filter( $customer, self::TAB_TRY_ONS );
		$list = self::fetch_page( '/v1/shop/try-ons', $page, self::GALLERY_SIZE, $customer );
		if ( is_string( $list ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $list ) . '</p></div>';
			return;
		}
		if ( ! $list['items'] ) {
			echo '<div class="camille-ps-card camille-ps-empty"><p>' . esc_html__( 'No virtual try-ons yet.', 'camille-personal-shopper' ) . '</p></div>';
			return;
		}
		echo '<ul class="camille-ps-gallery">';
		foreach ( $list['items'] as $item ) {
			$image = isset( $item['imageUrl'] ) ? (string) $item['imageUrl'] : '';
			if ( 0 !== strpos( $image, 'https://' ) ) {
				continue;
			}
			$token = isset( $item['sessionToken'] ) && is_string( $item['sessionToken'] ) && preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $item['sessionToken'] ) ? $item['sessionToken'] : '';
			echo '<li class="camille-ps-tryon">';
			echo '<a href="' . esc_url( $image ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $image ) . '" alt="' . esc_attr__( 'Virtual try-on', 'camille-personal-shopper' ) . '" loading="lazy"></a>';
			echo '<div class="camille-ps-tryon-meta">';
			echo '<span class="camille-ps-muted">' . esc_html( self::when( isset( $item['createdAt'] ) ? $item['createdAt'] : null ) ) . '</span><br>';
			echo wp_kses_post( self::customer_html( isset( $item['customerId'] ) ? $item['customerId'] : null, false ) );
			if ( $token ) {
				echo '<br><a href="' . esc_url( self::tab_url( self::TAB_CONVERSATIONS, array( 'chat' => $token ) ) ) . '">' . esc_html__( 'Open the chat', 'camille-personal-shopper' ) . '</a>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
		self::render_pagination( $page, $list['pages'] );
	}
}
