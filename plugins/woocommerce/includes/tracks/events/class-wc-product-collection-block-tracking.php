<?php
/**
 * WooCommerce Product Collection Block Tracking
 *
 * @package WooCommerce\Tracks
 */

defined( 'ABSPATH' ) || exit;
use Automattic\WooCommerce\Blocks\Templates\SingleProductTemplate;
use Automattic\WooCommerce\Blocks\Templates\CartTemplate;
use Automattic\WooCommerce\Blocks\Templates\MiniCartTemplate;
use Automattic\WooCommerce\Blocks\Templates\CheckoutTemplate;
use Automattic\WooCommerce\Blocks\Templates\ProductCatalogTemplate;
use Automattic\WooCommerce\Blocks\Templates\ProductAttributeTemplate;
use Automattic\WooCommerce\Blocks\Templates\OrderConfirmationTemplate;

/**
 * This class adds actions to track usage of the Product Collection Block.
 */
class WC_Product_Collection_Block_Tracking {

	/**
	 * Init Tracking.
	 */
	public function init() {
		add_action( 'save_post', array( $this, 'track_collection_instances' ), 10, 2 );
	}

	/**
	 * Track feature usage of the Product Collection block within the site editor.
	 *
	 * @param int      $post_id  The post ID.
	 * @param \WP_Post $post     The post object.
	 *
	 * @return void
	 */
	public function track_collection_instances( $post_id, $post ) {

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! wc_current_theme_is_fse_theme() ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Don't track autosaves and drafts.
		$post_status = $post->post_status;
		if ( 'publish' !== $post_status ) {
			return;
		}

		// Important: Only track instances within specific types.
		$post_type = $post->post_type;
		if ( ! in_array( $post_type, array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block' ), true ) ) {
			return;
		}

		if ( ! has_block( 'woocommerce/product-collection', $post ) && ! has_block( 'core/template-part', $post ) && ! has_block( 'core/block', $post ) ) {
			return;
		}

		$blocks = parse_blocks( $post->post_content );
		if ( empty( $blocks ) ) {
			return;
		}

		$instances = $this->parse_blocks_track_data( $blocks );
		if ( empty( $instances ) ) {
			return;
		}

		// Count orders.
		// Hint: Product count included in Track event. See WC_Tracks::get_blog_details().
		$order_count = 0;
		foreach ( wc_get_order_statuses() as $status_slug => $status_name ) {
			$order_count += wc_orders_count( $status_slug );
		}
		$additional_data = array(
			'editor_context' => $this->parse_editor_location_context( $post ),
			'order_count'    => $order_count,
		);

		foreach ( $instances as $instance ) {

			$event_properties = array_merge(
				$additional_data,
				$instance
			);

			\WC_Tracks::record_event(
				'product_collection_instance',
				$event_properties
			);
		}
	}

	/**
	 * Track usage of the Product Collection block within the given blocks.
	 *
	 * @param array $blocks                The parsed blocks to check.
	 * @param bool  $is_in_single_product  Whether we are in a single product container (used for keeping state in the recurring process).
	 *
	 * @return array Parsed instances of the Product Collection block.
	 */
	private function parse_blocks_track_data( $blocks, $in_single = false, $in_part = false, $in_synced = false ) {

		$instances = array();

		foreach ( $blocks as $block ) {
			if ( 'woocommerce/product-collection' === $block['blockName'] ) {
				$instances[] = array(
					'collection'        => $block['attrs']['collection'] ?? 'product-catalog',
					'in-single-product' => $in_single ? 'yes' : 'no',
					'in-template-part'  => $in_part ? 'yes' : 'no',
					'in-synced-pattern' => $in_synced ? 'yes' : 'no',
					'filters'           => $this->get_query_filters_usage_data( $block ),
				);
			}

			// Track instances within single product container.
			$local_in_single = $in_single;
			if ( 'woocommerce/single-product' === $block['blockName'] ) {
				$local_in_single = true;
			}

			// Track instances within template part.
			if ( 'core/template-part' === $block['blockName'] ) {
				$template_part = get_block_template( $block['attrs']['theme'] . '//' . $block['attrs']['slug'], 'wp_template_part' );
				if ( $template_part instanceof WP_Block_Template && ! empty( $template_part->content ) ) {
					$instances = array_merge( $instances, $this->parse_blocks_track_data( parse_blocks( $template_part->content ), $local_in_single, true, $in_synced ) );
				}
			}

			// Track instances within synced block.
			if ( 'core/block' === $block['blockName'] ) {
				$synced_pattern = get_post( $block['attrs']['ref'] );
				if ( $synced_pattern instanceof WP_Post && ! empty( $synced_pattern->post_content ) ) {
					$instances = array_merge( $instances, $this->parse_blocks_track_data( parse_blocks( $synced_pattern->post_content ), $local_in_single, $in_part, true ) );
				}
			}

			// Recursive.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$instances = array_merge( $instances, $this->parse_blocks_track_data( $block['innerBlocks'], $local_in_single, $in_part, $in_synced ) );
			}
		}

		return $instances;
	}

	/**
	 * Parse editor's location context from WP Post.
	 *
	 * Possible contexts:
	 * - post
	 * - page
	 * - single-product
	 * - product-archive
	 * - cart
	 * - checkout
	 * - product-catalog
	 * - order-confirmation
	 *
	 * @param WP_Post $post The Post instance.
	 *
	 * @return string Returns the context.
	 */
	private function parse_editor_location_context( $post ) {
		$context = 'other';

		if ( ! $post instanceof \WP_Post ) {
			return $context;
		}

		$post_type = $post->post_type;
		if ( ! in_array( $post_type, array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block' ), true ) ) {
			return $context;
		}

		if ( in_array( $post_type, array( 'wp_block', 'wp_template_part' ), true ) ) {
			$context = 'isolated-'.$post_type;
		}

		if ( 'wp_template' === $post_type ) {
			$name = $post->post_name;
			if ( false !== strpos( $name, SingleProductTemplate::SLUG ) ) {
				$context = 'single-product';
			} elseif ( ProductAttributeTemplate::SLUG === $name ) {
				$context = 'product-archive';
			} elseif ( false !== strpos( $name, 'taxonomy-' ) ) { // Including the '-' in the check to avoid false positives.
				$taxonomy           = str_replace( 'taxonomy-', '', $name );
				$product_taxonomies = get_object_taxonomies( 'product', 'names' );
				if ( in_array( $taxonomy, $product_taxonomies, true ) ) {
					$context = 'product-archive';
				}
			} elseif ( in_array( $name, array( CartTemplate::SLUG, MiniCartTemplate::SLUG ), true ) ) {
				$context = 'cart';
			} elseif ( CheckoutTemplate::SLUG === $name ) {
				$context = 'checkout';
			} elseif ( ProductCatalogTemplate::SLUG === $name ) {
				$context = 'product-catalog';
			} elseif ( OrderConfirmationTemplate::SLUG === $name ) {
				$context = 'order-confirmation';
			}
		}

		if ( 'page' === $post_type ) {
			$context = 'page';
		}
		if ( 'post' === $post_type ) {
			$context = 'post';
		}

		return $context;
	}

	/**
	 * Parse the collection query filters from the query attributes.
	 *
	 * @param array $block The parsed block.
	 * @return array The filters data for tracking.
	 */
	private function get_query_filters_usage_data( $block ) {

		if ( ! isset( $block['attrs'] ) ) {
			return array();
		}

		$query_attrs = $block['attrs']['query'] ?? array();
		$filters     = array(
			'on-sale'      => 0,
			'stock-status' => 0,
			'handpicked'   => 0,
			'keyword'      => 0,
			'attributes'   => 0,
			'category'     => 0,
			'tag'          => 0,
			'featured'     => 0,
			'created'      => 0,
			'price'        => 0,
		);

		if ( ! empty( $query_attrs['woocommerceOnSale'] ) ) {
			$filters['on-sale'] = 1;
		}

		if ( ! empty( $query_attrs['woocommerceStockStatus'] ) ) {
			$stock_statuses = wc_get_product_stock_status_options();
			$default_values = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ? array_diff_key( $stock_statuses, array( 'outofstock' => '' ) ) : $stock_statuses;
			$default_diff   = array_diff( array_keys( $default_values ), $query_attrs['woocommerceStockStatus'] );
			if ( ! empty( $default_diff ) ) {
				$filters['stock-status'] = 1;
			}
		}

		if ( ! empty( $query_attrs['woocommerceAttributes'] ) ) {
			$filters['attributes'] = 1;
		}

		if ( ! empty( $query_attrs['timeFrame'] ) ) {
			$filters['created'] = 1;
		}

		if ( ! empty( $query_attrs['taxQuery'] ) ) {

			if ( ! empty( $query_attrs['taxQuery']['product_cat'] ) ) {
				$filters['category'] = 1;
			}

			if ( ! empty( $query_attrs['taxQuery']['product_tag'] ) ) {
				$filters['tag'] = 1;
			}
		}

		if ( ! empty( $query_attrs['woocommerceHandPickedProducts'] ) ) {
			$filters['handpicked'] = 1;
		}

		if ( ! empty( $query_attrs['search'] ) ) {
			$filters['keyword'] = 1;
		}

		if ( ! empty( $query_attrs['featured'] ) ) {
			$filters['featured'] = 1;
		}

		if ( ! empty( $query_attrs['priceRange'] ) ) {
			$filters['price'] = 1;
		}

		return $filters;
	}
}