<?php
/**
 * Which products Camille may suggest: categories, a price range and single products left out by
 * the merchant. A product left out is not sent at the next sync, so Camille forgets it.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product selection rules, the product edit checkbox and the bulk actions of the products list.
 */
class Camille_PS_Catalog_Filter {

	/** Product meta: 'yes' when the merchant left the product out. */
	const META = '_camille_ps_excluded';

	/** Above this many products the admin page does not compute the live estimate. */
	const INDEX_LIMIT = 20000;

	/**
	 * Words that usually mark categories Camille cannot dress anyone with (it, en, fr, de, es).
	 * A trailing * matches any ending (profum* = profumi, profumeria); the others are whole words.
	 *
	 * @var string[]
	 */
	private static $unsuitable_words = array(
		'profum*', 'fragranz*', 'parfum*', 'perfum*', 'duft*', 'cosmet*', 'kosmetik*', 'beauty', 'make-up', 'makeup', 'trucco', 'skincare', 'maquillaje',
		'casa', 'home', 'maison', 'hogar', 'wohnen', 'arredo', 'candel*', 'candles?', 'bougies?', 'kerzen?', 'velas?',
		'gift ?cards?', 'carta regalo', 'carte cadeau', 'tarjeta regalo', 'buon[oi] regalo', 'vouchers?', 'gutschein*',
		'intimo', 'lingerie', 'underwear', 'unterwäsche', 'ropa interior',
		'bambin*', 'kids', 'enfants?', 'kinder*', 'niñ[oa]s?', 'infant*', 'neonat*', 'baby',
	);

	/**
	 * Hooks the product screens.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	/* ---------------------------------------------------------------- rules */

	/**
	 * Excluded categories plus all their subcategories.
	 *
	 * @return array<int,true>
	 */
	public static function excluded_category_set() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( Camille_PS_Options::catalog_filters()['excluded_categories'] as $term_id ) {
			$cache[ $term_id ] = true;
			$children          = get_term_children( $term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					$cache[ (int) $child ] = true;
				}
			}
		}
		return $cache;
	}

	/**
	 * Whether the selection lets this product reach Camille. A product in several categories stays
	 * while at least one of them is selected.
	 *
	 * @param WC_Product $product Simple or variable product.
	 * @return bool
	 */
	public static function allows( $product ) {
		if ( 'yes' === $product->get_meta( self::META, true ) ) {
			return false;
		}
		$excluded = self::excluded_category_set();
		if ( $excluded ) {
			$categories = array_map( 'intval', $product->get_category_ids() );
			if ( $categories && ! array_diff( $categories, array_keys( $excluded ) ) ) {
				return false;
			}
		}
		$filters = Camille_PS_Options::catalog_filters();
		if ( null === $filters['price_min'] && null === $filters['price_max'] ) {
			return true;
		}
		if ( $product->is_type( 'variable' ) ) {
			$low  = $product->get_variation_price( 'min' );
			$high = $product->get_variation_price( 'max' );
		} else {
			$low  = $product->get_price();
			$high = $low;
		}
		if ( ! is_numeric( $low ) || ! is_numeric( $high ) ) {
			return true; // No price: Camille's service leaves it out and says why.
		}
		return self::price_overlaps( (float) $low, (float) $high, $filters['price_min'], $filters['price_max'] );
	}

	/**
	 * Whether a product's price range touches the selected range (same rule as the admin estimate).
	 *
	 * @param float      $low  Lowest price of the product.
	 * @param float      $high Highest price of the product.
	 * @param float|null $min  Lowest price selected.
	 * @param float|null $max  Highest price selected.
	 * @return bool
	 */
	private static function price_overlaps( $low, $high, $min, $max ) {
		return ( null === $min || $high >= $min ) && ( null === $max || $low <= $max );
	}

	/**
	 * Whether a category name hints at products Camille cannot use for outfits.
	 *
	 * @param string $name Category name.
	 * @return bool
	 */
	public static function is_unsuitable_name( $name ) {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		foreach ( self::$unsuitable_words as $word ) {
			$is_prefix = '*' === substr( $word, -1 );
			$pattern   = '/(?<![\\p{L}])' . rtrim( $word, '*' ) . ( $is_prefix ? '' : '(?![\\p{L}])' ) . '/u';
			if ( preg_match( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------- admin data */

	/**
	 * Product categories as a flat list, parents before children.
	 *
	 * @return array<int,array{id:int,parent:int,name:string,depth:int}>
	 */
	public static function category_list() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		$list = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$list, $by_parent ) {
			foreach ( $by_parent[ $parent ] ?? array() as $term ) {
				$list[] = array(
					'id'     => (int) $term->term_id,
					'parent' => (int) $term->parent,
					'name'   => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'depth'  => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $list;
	}

	/**
	 * Compact list of the products a sync can send, for the live estimate on the settings page:
	 * [id, lowest price, highest price, category ids, left out by hand (1/0)].
	 * Hidden and password-protected products are not in it: they never reach Camille.
	 *
	 * @return array<int,array{0:int,1:float|null,2:float|null,3:int[],4:int}>|null Null for very large catalogs.
	 */
	public static function product_index() {
		global $wpdb;
		$ids = wc_get_products(
			array(
				'status' => 'publish',
				'type'   => array( 'simple', 'variable' ),
				'limit'  => self::INDEX_LIMIT + 1,
				'return' => 'ids',
			)
		);
		if ( ! is_array( $ids ) || count( $ids ) > self::INDEX_LIMIT ) {
			return null;
		}
		$hidden_terms = array();
		foreach ( array( 'exclude-from-catalog', 'exclude-from-search' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_visibility' );
			if ( $term ) {
				$hidden_terms[ (int) $term->term_id ] = $slug;
			}
		}
		$index = array();
		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			$in       = implode( ',', $chunk );
			$password = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) AND post_password <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integer ids only.
			$prices   = $wpdb->get_results( "SELECT product_id, min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integer ids only.
			$terms    = $wpdb->get_results( "SELECT tr.object_id, tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ('product_cat','product_visibility') AND tr.object_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integer ids only.
			$manual   = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes' AND post_id IN ($in)", self::META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integer ids only.
			$skip     = array_fill_keys( array_map( 'intval', $password ), true );
			$cats     = array();
			$flags    = array();
			foreach ( $terms as $row ) {
				$object = (int) $row->object_id;
				if ( 'product_cat' === $row->taxonomy ) {
					$cats[ $object ][] = (int) $row->term_id;
				} elseif ( isset( $hidden_terms[ (int) $row->term_id ] ) ) {
					$flags[ $object ][ $hidden_terms[ (int) $row->term_id ] ] = true;
				}
			}
			foreach ( $flags as $object => $set ) {
				if ( 2 === count( $set ) ) {
					$skip[ $object ] = true; // Visibility "hidden": out of the catalog and of search.
				}
			}
			$manual_set = array_fill_keys( array_map( 'intval', $manual ), true );
			foreach ( $chunk as $id ) {
				if ( isset( $skip[ $id ] ) ) {
					continue;
				}
				$low     = isset( $prices[ $id ] ) && is_numeric( $prices[ $id ]->min_price ) ? (float) $prices[ $id ]->min_price : null;
				$high    = isset( $prices[ $id ] ) && is_numeric( $prices[ $id ]->max_price ) ? (float) $prices[ $id ]->max_price : null;
				$index[] = array( $id, $low, $high, $cats[ $id ] ?? array(), isset( $manual_set[ $id ] ) ? 1 : 0 );
			}
		}
		return $index;
	}

	/**
	 * How many products of the index the current selection lets through.
	 *
	 * @param array $index From product_index().
	 * @return int
	 */
	public static function count_allowed( array $index ) {
		$excluded = self::excluded_category_set();
		$filters  = Camille_PS_Options::catalog_filters();
		$count    = 0;
		foreach ( $index as $row ) {
			if ( $row[4] ) {
				continue;
			}
			if ( $excluded && $row[3] && ! array_diff( $row[3], array_keys( $excluded ) ) ) {
				continue;
			}
			if ( null !== $row[1] && null !== $row[2] && ! self::price_overlaps( $row[1], $row[2], $filters['price_min'], $filters['price_max'] ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/* ---------------------------------------------------------------- product screens */

	/**
	 * Checkbox in the General tab of the product.
	 */
	public static function render_product_field() {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'Camille', 'camille-personal-shopper' ),
				'description' => __( 'Do not show this product to Camille (it is removed at the next catalog sync)', 'camille-personal-shopper' ),
				'cbvalue'     => 'yes',
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the checkbox. WooCommerce already checked the nonce and the capability.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_field( $product ) {
		$is_excluded = isset( $_POST[ self::META ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ self::META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before this hook.
		if ( $is_excluded ) {
			$product->update_meta_data( self::META, 'yes' );
		} else {
			$product->delete_meta_data( self::META );
		}
	}

	/**
	 * Two bulk actions in Products.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['camille_ps_exclude'] = __( 'Hide from Camille', 'camille-personal-shopper' );
		$actions['camille_ps_include'] = __( 'Show to Camille', 'camille-personal-shopper' );
		return $actions;
	}

	/**
	 * Applies a bulk action.
	 *
	 * @param string $redirect Where WordPress goes next.
	 * @param string $action   Chosen action.
	 * @param int[]  $post_ids Selected products.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'camille_ps_exclude', 'camille_ps_include' ), true ) || ! current_user_can( 'edit_products' ) ) {
			return $redirect;
		}
		$changed = 0;
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			if ( 'camille_ps_exclude' === $action ) {
				update_post_meta( $post_id, self::META, 'yes' );
			} else {
				delete_post_meta( $post_id, self::META );
			}
			++$changed;
		}
		return add_query_arg(
			array(
				'camille_ps_bulk'  => 'camille_ps_exclude' === $action ? 'hidden' : 'shown',
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
		if ( empty( $_GET['camille_ps_bulk'] ) || ! isset( $_GET['camille_ps_count'] ) ) {
			return;
		}
		$count = absint( $_GET['camille_ps_count'] );
		$kind  = sanitize_key( wp_unslash( $_GET['camille_ps_bulk'] ) );
		// phpcs:enable
		$text = 'hidden' === $kind
			/* translators: %d: number of products. */
			? _n( '%d product hidden from Camille. Sync the catalog to update her.', '%d products hidden from Camille. Sync the catalog to update her.', $count, 'camille-personal-shopper' )
			/* translators: %d: number of products. */
			: _n( '%d product shown to Camille again. Sync the catalog to update her.', '%d products shown to Camille again. Sync the catalog to update her.', $count, 'camille-personal-shopper' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $text, $count ) ) . ' <a href="' . esc_url( Camille_PS_Admin::page_url() . '#camille-ps-catalog' ) . '">' . esc_html__( 'Open Camille', 'camille-personal-shopper' ) . '</a></p></div>';
	}

	/**
	 * "Hidden from Camille" next to the title in the products list.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   Post.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		if ( $post instanceof WP_Post && 'product' === $post->post_type && 'yes' === get_post_meta( $post->ID, self::META, true ) ) {
			$states['camille_ps_excluded'] = __( 'Hidden from Camille', 'camille-personal-shopper' );
		}
		return $states;
	}
}
