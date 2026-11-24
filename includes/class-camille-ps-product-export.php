<?php
/**
 * Turns WooCommerce products into the payload the Camille service reads (WooProduct in the
 * connector's src/catalog/wooProduct.ts). Only public data: what any visitor sees on the shop.
 *
 * @package CamillePersonalShopper
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the catalog payload.
 */
class Camille_PS_Product_Export {

	const MAX_VARIATIONS = 200;
	const MAX_IMAGES     = 20;

	/**
	 * Brand taxonomies of the common brand plugins, WooCommerce's own first.
	 *
	 * @var string[]
	 */
	private static $brand_taxonomies = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' );

	/**
	 * One page of published products, ordered by id so the pages never overlap.
	 *
	 * @param int $page     Page number, from 1.
	 * @param int $per_page Products per page.
	 * @return array{products:WC_Product[],pages:int}
	 */
	public static function query_page( $page, $per_page ) {
		$result = wc_get_products(
			array(
				'status'   => 'publish',
				'type'     => array( 'simple', 'variable' ),
				'limit'    => $per_page,
				'page'     => max( 1, (int) $page ),
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'paginate' => true,
			)
		);
		return array(
			'products' => $result->products,
			'pages'    => (int) $result->max_num_pages,
		);
	}

	/**
	 * Whether a product may reach Camille: visible in the shop, not behind a password.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_exportable( $product ) {
		if ( 'hidden' === $product->get_catalog_visibility() || '' !== (string) $product->get_post_password() ) {
			return false;
		}
		/**
		 * Filters whether a product is sent to Camille.
		 *
		 * @param bool       $is_exportable Default decision.
		 * @param WC_Product $product       Product.
		 */
		return (bool) apply_filters( 'camille_ps_is_product_exportable', true, $product );
	}

	/**
	 * Payload of one product.
	 *
	 * @param WC_Product $product Simple or variable product.
	 * @return array
	 */
	public static function build( $product ) {
		$id      = $product->get_id();
		$payload = array(
			'id'               => $id,
			'type'             => $product->is_type( 'variable' ) ? 'variable' : 'simple',
			'name'             => wp_strip_all_tags( $product->get_name() ),
			'description'      => (string) $product->get_description(),
			'shortDescription' => (string) $product->get_short_description(),
			'permalink'        => (string) get_permalink( $id ),
			'brand'            => self::brand( $id ),
			'categories'       => self::category_paths( $product->get_category_ids() ),
			'tags'             => self::term_names( $id, 'product_tag' ),
			'attributes'       => self::attributes( $product ),
			'price'            => self::money( $product->get_price() ),
			'regularPrice'     => self::money( $product->get_regular_price() ),
			'isInStock'        => $product->is_in_stock(),
			'imageUrls'        => self::image_urls( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ),
			'variations'       => $product->is_type( 'variable' ) ? self::variations( $product ) : array(),
		);
		/**
		 * Filters the data sent to Camille for a product.
		 *
		 * @param array      $payload Product payload.
		 * @param WC_Product $product Product.
		 */
		return apply_filters( 'camille_ps_product_payload', $payload, $product );
	}

	/**
	 * A price as a number, null when empty.
	 *
	 * @param mixed $raw Price as WooCommerce stores it.
	 * @return float|null
	 */
	private static function money( $raw ) {
		if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$value = (float) $raw;
		return $value > 0 ? round( $value, 2 ) : null;
	}

	/**
	 * Term names of a product.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return string[]
	 */
	private static function term_names( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( static fn( $term ) => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), $terms ) );
	}

	/**
	 * First brand found in the known brand taxonomies.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private static function brand( $product_id ) {
		foreach ( self::$brand_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$names = self::term_names( $product_id, $taxonomy );
				if ( $names ) {
					return $names[0];
				}
			}
		}
		/**
		 * Filters the brand of a product when the shop keeps it elsewhere (an attribute, a field).
		 *
		 * @param string $brand      Brand found, '' if none.
		 * @param int    $product_id Product id.
		 */
		return (string) apply_filters( 'camille_ps_product_brand', '', $product_id );
	}

	/**
	 * Each category as its path from the root: [ "Donna", "Abbigliamento", "Vestiti" ].
	 *
	 * @param int[] $category_ids Category ids.
	 * @return array<int,string[]>
	 */
	private static function category_paths( array $category_ids ) {
		$paths = array();
		foreach ( $category_ids as $category_id ) {
			$ids   = array_reverse( get_ancestors( (int) $category_id, 'product_cat', 'taxonomy' ) );
			$ids[] = (int) $category_id;
			$path  = array();
			foreach ( $ids as $id ) {
				$term = get_term( $id, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					$path[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			if ( $path ) {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * Product attributes with their display values.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int,array{slug:string,name:string,options:string[]}>
	 */
	private static function attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ( ! $attribute->get_visible() && ! $attribute->get_variation() ) ) {
				continue;
			}
			$slug    = (string) $attribute->get_name();
			$options = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $slug, array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$out[]   = array(
				'slug'    => $attribute->is_taxonomy() ? $slug : sanitize_title( (string) $key ),
				'name'    => wc_attribute_label( $slug, $product ),
				'options' => array_values( array_map( 'strval', is_array( $options ) ? $options : array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Image URLs, large size (good enough for the virtual try-on, light enough to process).
	 *
	 * @param array $image_ids Attachment ids.
	 * @return string[]
	 */
	private static function image_urls( array $image_ids ) {
		$urls = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $image_ids ) ) ) as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'large' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_IMAGES );
	}

	/**
	 * Published variations of a variable product.
	 *
	 * @param WC_Product_Variable $product Product.
	 * @return array
	 */
	private static function variations( $product ) {
		$out = array();
		foreach ( array_slice( $product->get_children(), 0, self::MAX_VARIATIONS ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$image_id = (int) $variation->get_image_id();
			$out[]    = array(
				'id'           => $variation->get_id(),
				'attributes'   => self::variation_attributes( $variation ),
				'price'        => self::money( $variation->get_price() ),
				'regularPrice' => self::money( $variation->get_regular_price() ),
				'isInStock'    => $variation->is_in_stock(),
				'imageUrl'     => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Attributes of a variation: slug, display value and the value WooCommerce reads from the URL.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @return array<int,array{slug:string,value:string,urlValue:string}>
	 */
	private static function variation_attributes( $variation ) {
		$out = array();
		foreach ( $variation->get_variation_attributes( false ) as $slug => $raw_value ) {
			$slug  = (string) $slug;
			$raw   = (string) $raw_value;
			$value = $raw;
			if ( '' !== $raw && taxonomy_exists( $slug ) ) {
				$term  = get_term_by( 'slug', $raw, $slug );
				$value = $term instanceof WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : $raw;
			}
			$out[] = array(
				'slug'     => $slug,
				'value'    => $value,
				'urlValue' => $raw,
			);
		}
		return $out;
	}
}
