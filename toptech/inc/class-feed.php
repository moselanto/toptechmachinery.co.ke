<?php
/**
 * Google for WooCommerce (Google Listings & Ads) feed corrections.
 *
 * Keeps the brand / identifier attributes sent to Merchant Center honest and
 * consistent with the storefront:
 *  - Unbranded items ("Generic", "Unbranded", no brand term) are sent with no
 *    brand and identifierExists = false, instead of claiming a "Generic" brand.
 *  - Branded items send the brand from the product's brand taxonomy.
 *  - MPN is only sent when a real manufacturer part number is stored in the
 *    _mpn meta field. The store SKU is never passed off as an MPN.
 *  - identifierExists is true only when the product has a GTIN, or a brand
 *    plus a real MPN.
 *
 * Uses the documented woocommerce_gla_product_attribute_values filter. The
 * filter never fires when Google for WooCommerce is inactive.
 *
 * @package ToptechMachinery
 */

declare( strict_types = 1 );

namespace ToptechMachinery;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant Center feed attribute overrides.
 */
final class Feed {

	public function hooks(): void {
		add_filter( 'woocommerce_gla_product_attribute_values', array( $this, 'attributes' ), 20, 3 );
	}

	/**
	 * @param mixed $attributes Attribute overrides collected so far.
	 * @param mixed $wc_product WooCommerce product (simple or variation).
	 * @param mixed $adapter    GLA product adapter (unused).
	 * @return mixed
	 */
	public function attributes( $attributes, $wc_product, $adapter = null ) {
		if ( is_array( $attributes ) === false || $wc_product instanceof \WC_Product === false ) {
			return $attributes;
		}
		try {
			$source_id = $wc_product->get_parent_id() > 0 ? (int) $wc_product->get_parent_id() : (int) $wc_product->get_id();
			$brand     = $this->brand_name( $source_id );

			$mpn = trim( (string) get_post_meta( (int) $wc_product->get_id(), '_mpn', true ) );
			if ( '' === $mpn && $source_id !== (int) $wc_product->get_id() ) {
				$mpn = trim( (string) get_post_meta( $source_id, '_mpn', true ) );
			}

			$gtin = '';
			foreach ( array( '_wc_gla_gtin', '_gtin', '_global_unique_id' ) as $key ) {
				$value = trim( (string) get_post_meta( (int) $wc_product->get_id(), $key, true ) );
				if ( '' !== $value ) {
					$gtin = $value;
					break;
				}
			}

			if ( $this->is_unbranded( $brand ) ) {
				$attributes['brand']            = '';
				$attributes['identifierExists'] = '' !== $gtin;
				return $attributes;
			}

			$attributes['brand'] = $brand;
			if ( '' !== $mpn ) {
				$attributes['mpn'] = $mpn;
			}
			$attributes['identifierExists'] = ( '' !== $gtin || '' !== $mpn );
		} catch ( \Throwable $e ) {
			error_log( 'TopTech Machinery feed attributes failed: ' . $e->getMessage() );
		}
		return $attributes;
	}

	private function brand_name( int $product_id ): string {
		foreach ( array( 'product_brand', 'pwb-brand', 'product-brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'names' ) );
				if ( is_wp_error( $terms ) === false && empty( $terms ) === false ) {
					return (string) $terms[0];
				}
			}
		}
		return '';
	}

	private function is_unbranded( string $brand ): bool {
		$b = strtolower( trim( $brand ) );
		return in_array( $b, array( '', 'generic', 'unbranded', 'no brand', 'none', 'n/a', 'oem' ), true );
	}
}
