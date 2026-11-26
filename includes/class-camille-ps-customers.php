<?php
/**
 * Customers chosen by the merchant to see Camille before everyone else (visibility "Administrators
 * and chosen customers"). The choice is a user meta, set on the user profile or with the bulk
 * actions of the Users list, like a customer tag.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chosen customers: storage, profile checkbox, Users list column, view and bulk actions.
 */
class Camille_PS_Customers {

	/** User meta: 'yes' when the customer may see Camille. */
	const META = 'camille_ps_preview';

	/** Query argument of the Users list view that shows only the chosen customers. */
	const VIEW = 'camille_ps_chosen';

	/**
	 * Hooks the user screens.
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_notice' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'views_users', array( __CLASS__, 'add_view' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'filter_view' ) );
	}

	/**
	 * Whether a user was chosen to see Camille.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_chosen( $user_id ) {
		return $user_id > 0 && 'yes' === get_user_meta( (int) $user_id, self::META, true );
	}

	/**
	 * How many users were chosen.
	 *
	 * @return int
	 */
	public static function count_chosen() {
		$query = new WP_User_Query(
			array(
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin page only.
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin page only.
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * URL of the Users list showing only the chosen customers.
	 *
	 * @return string
	 */
	public static function list_url() {
		return add_query_arg( self::VIEW, '1', admin_url( 'users.php' ) );
	}

	/**
	 * Whether the current user may choose customers.
	 *
	 * @return bool
	 */
	private static function can_choose() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Checkbox on the user profile.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function render_profile_field( $user ) {
		if ( ! self::can_choose() || ! $user instanceof WP_User ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Camille', 'camille-personal-shopper' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Preview', 'camille-personal-shopper' ) . '</th><td>';
		wp_nonce_field( 'camille_ps_preview_' . $user->ID, 'camille_ps_preview_nonce' );
		echo '<label><input type="checkbox" name="camille_ps_preview" value="yes" ' . checked( self::is_chosen( $user->ID ), true, false ) . '> ' . esc_html__( 'This customer can see Camille', 'camille-personal-shopper' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Applies when Camille is visible to "Administrators and chosen customers" (WooCommerce > Camille).', 'camille-personal-shopper' ) . '</p>';
		echo '</td></tr>';
		self::render_activity_rows( $user->ID );
		echo '</table>';
	}

	/**
	 * Chats and try-ons of the customer, and the try-on block, on the profile.
	 *
	 * @param int $user_id User being edited.
	 */
	private static function render_activity_rows( $user_id ) {
		if ( ! Camille_PS_Options::connection() ) {
			return;
		}
		$summary = Camille_PS_Activity::customer_summary( $user_id );
		if ( null === $summary ) {
			echo '<tr><th scope="row">' . esc_html__( 'Activity', 'camille-personal-shopper' ) . '</th><td><span class="description">' . esc_html__( 'The chats of this customer cannot be read right now.', 'camille-personal-shopper' ) . '</span></td></tr>';
			return;
		}
		$chats = sprintf(
			/* translators: %s: number of chats. */
			_n( '%s conversation', '%s conversations', $summary['conversations'], 'camille-personal-shopper' ),
			number_format_i18n( $summary['conversations'] )
		);
		$try_ons = sprintf(
			/* translators: %s: number of virtual try-ons. */
			_n( '%s virtual try-on', '%s virtual try-ons', $summary['tryOns'], 'camille-personal-shopper' ),
			number_format_i18n( $summary['tryOns'] )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Activity', 'camille-personal-shopper' ) . '</th><td>';
		echo $summary['conversations'] ? '<a href="' . esc_url( Camille_PS_Activity::tab_url( Camille_PS_Activity::TAB_CONVERSATIONS, array( 'customer' => $user_id ) ) ) . '">' . esc_html( $chats ) . '</a>' : esc_html( $chats );
		echo ' · ';
		echo $summary['tryOns'] ? '<a href="' . esc_url( Camille_PS_Activity::tab_url( Camille_PS_Activity::TAB_TRY_ONS, array( 'customer' => $user_id ) ) ) . '">' . esc_html( $try_ons ) . '</a>' : esc_html( $try_ons );
		echo '<p class="description">' . esc_html__( 'Chats stay available for about 30 days.', 'camille-personal-shopper' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Virtual try-ons', 'camille-personal-shopper' ) . '</th><td>';
		echo '<input type="hidden" name="camille_ps_block_was" value="' . ( $summary['isBlocked'] ? '1' : '0' ) . '">';
		echo '<label><input type="checkbox" name="camille_ps_block" value="1" ' . checked( $summary['isBlocked'], true, false ) . '> ' . esc_html__( 'Block the virtual try-ons of this customer', 'camille-personal-shopper' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'For a customer who uses up your credits. The chat stays open.', 'camille-personal-shopper' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Saves the checkbox.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save_profile_field( $user_id ) {
		if ( ! self::can_choose() || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['camille_ps_preview_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['camille_ps_preview_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'camille_ps_preview_' . $user_id ) ) {
			return;
		}
		self::set_chosen( $user_id, isset( $_POST['camille_ps_preview'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['camille_ps_preview'] ) ) );
		if ( isset( $_POST['camille_ps_block_was'] ) ) {
			$was_blocked = '1' === sanitize_key( wp_unslash( $_POST['camille_ps_block_was'] ) );
			$is_blocked  = isset( $_POST['camille_ps_block'] ) && '1' === sanitize_key( wp_unslash( $_POST['camille_ps_block'] ) );
			if ( $was_blocked !== $is_blocked ) {
				$result = Camille_PS_Activity::set_try_on_blocked( $user_id, $is_blocked );
				if ( true !== $result ) {
					set_transient( 'camille_ps_profile_error_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
				}
			}
		}
	}

	/**
	 * Error of the last profile save (the block could not be changed), shown once.
	 */
	public static function profile_notice() {
		$key   = 'camille_ps_profile_error_' . get_current_user_id();
		$error = get_transient( $key );
		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}
		delete_transient( $key );
		/* translators: %s: reason. */
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Camille: the virtual try-ons of this customer could not be changed. %s', 'camille-personal-shopper' ), $error ) ) . '</p></div>';
	}

	/**
	 * Stores the choice.
	 *
	 * @param int  $user_id   User.
	 * @param bool $is_chosen Whether the user sees Camille.
	 */
	private static function set_chosen( $user_id, $is_chosen ) {
		if ( $is_chosen ) {
			update_user_meta( (int) $user_id, self::META, 'yes' );
		} else {
			delete_user_meta( (int) $user_id, self::META );
		}
	}

	/**
	 * Two bulk actions in Users.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		if ( self::can_choose() ) {
			$actions['camille_ps_choose']   = __( 'Show Camille', 'camille-personal-shopper' );
			$actions['camille_ps_unchoose'] = __( 'Stop showing Camille', 'camille-personal-shopper' );
		}
		return $actions;
	}

	/**
	 * Applies a bulk action.
	 *
	 * @param string $redirect Where WordPress goes next.
	 * @param string $action   Chosen action.
	 * @param int[]  $user_ids Selected users.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $user_ids ) {
		if ( ! in_array( $action, array( 'camille_ps_choose', 'camille_ps_unchoose' ), true ) || ! self::can_choose() ) {
			return $redirect;
		}
		$changed = 0;
		foreach ( array_map( 'intval', (array) $user_ids ) as $user_id ) {
			if ( current_user_can( 'edit_user', $user_id ) ) {
				self::set_chosen( $user_id, 'camille_ps_choose' === $action );
				++$changed;
			}
		}
		return add_query_arg(
			array(
				'camille_ps_users' => 'camille_ps_choose' === $action ? 'chosen' : 'unchosen',
				'camille_ps_count' => $changed,
			),
			$redirect
		);
	}

	/**
	 * Message after a bulk action.
	 */
	public static function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['camille_ps_users'] ) || ! isset( $_GET['camille_ps_count'] ) ) {
			return;
		}
		$count = absint( $_GET['camille_ps_count'] );
		$kind  = sanitize_key( wp_unslash( $_GET['camille_ps_users'] ) );
		// phpcs:enable
		$text = 'chosen' === $kind
			/* translators: %d: number of users. */
			? _n( '%d customer can now see Camille.', '%d customers can now see Camille.', $count, 'camille-personal-shopper' )
			/* translators: %d: number of users. */
			: _n( '%d customer no longer sees Camille.', '%d customers no longer see Camille.', $count, 'camille-personal-shopper' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $text, $count ) ) . '</p></div>';
	}

	/**
	 * "Camille" column in Users.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( self::can_choose() ) {
			$columns['camille_ps'] = __( 'Camille', 'camille-personal-shopper' );
		}
		return $columns;
	}

	/**
	 * Content of the "Camille" column.
	 *
	 * @param string $output  Current content.
	 * @param string $column  Column name.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function render_column( $output, $column, $user_id ) {
		if ( 'camille_ps' !== $column ) {
			return $output;
		}
		return self::is_chosen( $user_id ) ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Sees Camille', 'camille-personal-shopper' ) . '</span>' : '';
	}

	/**
	 * "Camille" view above the Users list.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function add_view( $views ) {
		if ( ! self::can_choose() ) {
			return $views;
		}
		$count = self::count_chosen();
		if ( ! $count ) {
			return $views;
		}
		$is_current          = ! empty( $_GET[ self::VIEW ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$views['camille_ps'] = '<a href="' . esc_url( self::list_url() ) . '"' . ( $is_current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Camille', 'camille-personal-shopper' ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a>';
		return $views;
	}

	/**
	 * Applies the "Camille" view.
	 *
	 * @param WP_User_Query $query Users query.
	 */
	public static function filter_view( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'users.php' !== $pagenow || empty( $_GET[ self::VIEW ] ) || ! self::can_choose() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
			return;
		}
		$query->set( 'meta_key', self::META );
		$query->set( 'meta_value', 'yes' );
	}
}
