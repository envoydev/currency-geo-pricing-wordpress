<?php
/**
 * Plugin Name: Currency Geo Pricing
 * Description: Adds per-product EUR and USD prices and switches the displayed currency by visitor location - UAH in Ukraine, EUR across Europe, USD for the rest of the world.
 * Version: 2.2.0
 * Author: envoydev
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1. Visitor country detection + target currency (memoized per request).
 * ---------------------------------------------------------------------- */

function cgp_get_visitor_country() {
	static $country = null;
	if ( null !== $country ) {
		return $country;
	}

	$country = '';

	// Cloudflare header - most reliable when the site sits behind Cloudflare.
	if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
		if ( 2 === strlen( $cc ) && 'XX' !== $cc ) {
			return $country = $cc;
		}
	}

	// WooCommerce built-in geolocation (MaxMind GeoLite2 DB + ip-api fallback).
	if ( class_exists( 'WC_Geolocation' ) ) {
		$geo = WC_Geolocation::geolocate_ip( '', true );
		if ( ! empty( $geo['country'] ) ) {
			return $country = strtoupper( $geo['country'] );
		}
	}

	return $country;
}

/**
 * Countries billed in EUR. Edit freely.
 * - UA is intentionally absent (always UAH).
 * - RU and BY are intentionally absent (they fall through to USD); add them if you want EUR there.
 * - GB, CH, NO and others use their own currency in reality but are billed EUR here under the 'Europe' rule.
 */
function cgp_eur_countries() {
	return apply_filters( 'cgp_eur_countries', array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
		'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'IS', 'LI', 'NO', 'CH', 'GB',
		'AD', 'MC', 'SM', 'VA', 'GI', 'FO', 'GG', 'IM', 'JE',
		'AL', 'BA', 'ME', 'MK', 'RS', 'XK', 'MD',
	) );
}

// Returns the currency for this visitor: 'UAH' (base, no change), 'EUR' or 'USD'.
function cgp_target_currency() {
	static $currency = null;
	if ( null !== $currency ) {
		return $currency;
	}

	// Never touch currency/prices in wp-admin, cron or WP-CLI. Frontend AJAX is allowed.
	if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return $currency = 'UAH';
	}

	// Master switch. Off keeps the whole store in UAH regardless of the
	// per-currency boxes - use it to stage EUR/USD prices before going live.
	if ( 'yes' !== get_option( 'eurgeo_geo_enabled', 'yes' ) ) {
		return $currency = 'UAH';
	}

	$country = cgp_get_visitor_country();

	// Ukraine always uses the base currency, which can never be turned off.
	if ( 'UA' === $country ) {
		return $currency = 'UAH';
	}

	// Pick the visitor's natural currency, then honour its on/off switch.
	// A disabled currency falls back to the base (UAH).
	if ( in_array( $country, cgp_eur_countries(), true ) ) {
		return $currency = ( 'yes' === get_option( 'eurgeo_eur_enabled', 'yes' ) ) ? 'EUR' : 'UAH';
	}

	// Rest of the world, plus any visitor whose country could not be determined.
	return $currency = ( 'yes' === get_option( 'eurgeo_usd_enabled', 'yes' ) ) ? 'USD' : 'UAH';
}

/* -------------------------------------------------------------------------
 * 2. EUR + USD price fields - simple products.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_product_options_pricing', function () {
	woocommerce_wp_text_input( array(
		'id'          => '_price_eur',
		'label'       => __( 'EUR regular price (€)', 'eurgeo' ),
		'data_type'   => 'price',
		'desc_tip'    => true,
		'description' => __( 'Shown to visitors in Europe. Empty falls back to UAH or the static rate.', 'eurgeo' ),
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_sale_price_eur',
		'label'       => __( 'EUR sale price (€)', 'eurgeo' ),
		'data_type'   => 'price',
		'desc_tip'    => true,
		'description' => __( 'Optional. Lower than the EUR regular price. Reuses the Schedule dates above.', 'eurgeo' ),
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_price_usd',
		'label'       => __( 'USD regular price ($)', 'eurgeo' ),
		'data_type'   => 'price',
		'desc_tip'    => true,
		'description' => __( 'Shown to visitors outside Ukraine and Europe. Empty falls back to UAH or the static rate.', 'eurgeo' ),
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_sale_price_usd',
		'label'       => __( 'USD sale price ($)', 'eurgeo' ),
		'data_type'   => 'price',
		'desc_tip'    => true,
		'description' => __( 'Optional. Lower than the USD regular price. Reuses the Schedule dates above.', 'eurgeo' ),
	) );
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	foreach ( array( '_price_eur', '_sale_price_eur', '_price_usd', '_sale_price_usd' ) as $key ) {
		$product->update_meta_data( $key, isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '' );
	}
} );

/* -------------------------------------------------------------------------
 * 3. EUR + USD price fields - product variations.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_variation_options_pricing', function ( $loop, $variation_data, $variation ) {
	woocommerce_wp_text_input( array(
		'id'            => '_price_eur_' . $loop,
		'name'          => '_price_eur[' . $loop . ']',
		'value'         => get_post_meta( $variation->ID, '_price_eur', true ),
		'label'         => __( 'EUR regular price (€)', 'eurgeo' ),
		'data_type'     => 'price',
		'wrapper_class' => 'form-row form-row-first',
	) );
	woocommerce_wp_text_input( array(
		'id'            => '_sale_price_eur_' . $loop,
		'name'          => '_sale_price_eur[' . $loop . ']',
		'value'         => get_post_meta( $variation->ID, '_sale_price_eur', true ),
		'label'         => __( 'EUR sale price (€)', 'eurgeo' ),
		'data_type'     => 'price',
		'wrapper_class' => 'form-row form-row-last',
	) );
	woocommerce_wp_text_input( array(
		'id'            => '_price_usd_' . $loop,
		'name'          => '_price_usd[' . $loop . ']',
		'value'         => get_post_meta( $variation->ID, '_price_usd', true ),
		'label'         => __( 'USD regular price ($)', 'eurgeo' ),
		'data_type'     => 'price',
		'wrapper_class' => 'form-row form-row-first',
	) );
	woocommerce_wp_text_input( array(
		'id'            => '_sale_price_usd_' . $loop,
		'name'          => '_sale_price_usd[' . $loop . ']',
		'value'         => get_post_meta( $variation->ID, '_sale_price_usd', true ),
		'label'         => __( 'USD sale price ($)', 'eurgeo' ),
		'data_type'     => 'price',
		'wrapper_class' => 'form-row form-row-last',
	) );
}, 10, 3 );

add_action( 'woocommerce_save_product_variation', function ( $variation_id, $i ) {
	foreach ( array( '_price_eur', '_sale_price_eur', '_price_usd', '_sale_price_usd' ) as $key ) {
		$value = isset( $_POST[ $key ][ $i ] ) ? wc_clean( wp_unslash( $_POST[ $key ][ $i ] ) ) : '';
		update_post_meta( $variation_id, $key, $value );
	}
}, 10, 2 );

/* -------------------------------------------------------------------------
 * 4. Swap regular + sale price for the visitor's currency (display, cart, totals).
 * ---------------------------------------------------------------------- */

// Meta keys + fallback rate for a given target currency. The rate is the
// optional UAH->foreign figure set under WooCommerce > Settings > General;
// 0 (default) disables the fallback.
function cgp_currency_map( $currency ) {
	$map = array(
		'EUR' => array( 'regular' => '_price_eur', 'sale' => '_sale_price_eur', 'rate' => (float) get_option( 'eurgeo_uah_per_eur', 0 ) ),
		'USD' => array( 'regular' => '_price_usd', 'sale' => '_sale_price_usd', 'rate' => (float) get_option( 'eurgeo_uah_per_usd', 0 ) ),
	);
	return isset( $map[ $currency ] ) ? $map[ $currency ] : null;
}

// Regular price in the target currency, with optional UAH conversion fallback.
function cgp_target_regular( $product, $currency ) {
	$keys = cgp_currency_map( $currency );
	if ( ! $keys ) {
		return null;
	}
	$val = $product->get_meta( $keys['regular'], true );
	if ( '' !== $val && is_numeric( $val ) ) {
		return (float) $val;
	}
	$uah = get_post_meta( $product->get_id(), '_regular_price', true );
	if ( $keys['rate'] > 0 && is_numeric( $uah ) ) {
		return round( (float) $uah / $keys['rate'], 2 );
	}
	return null;
}

// Sale price in the target currency, with the same optional fallback.
function cgp_target_sale( $product, $currency ) {
	$keys = cgp_currency_map( $currency );
	if ( ! $keys ) {
		return null;
	}
	$val = $product->get_meta( $keys['sale'], true );
	if ( '' !== $val && is_numeric( $val ) ) {
		return (float) $val;
	}
	$uah = get_post_meta( $product->get_id(), '_sale_price', true );
	if ( '' !== $uah && $keys['rate'] > 0 && is_numeric( $uah ) ) {
		return round( (float) $uah / $keys['rate'], 2 );
	}
	return null;
}

// Sale is live only when below regular and inside the product's own Schedule window.
function cgp_sale_active( $product, $regular, $sale ) {
	if ( null === $sale || null === $regular || $sale >= $regular ) {
		return false;
	}
	$from = $product->get_date_on_sale_from( 'edit' );
	$to   = $product->get_date_on_sale_to( 'edit' );
	if ( $from && time() < $from->getTimestamp() ) {
		return false;
	}
	if ( $to && time() > $to->getTimestamp() ) {
		return false;
	}
	return true;
}

function cgp_get_regular_price( $price, $product ) {
	$currency = cgp_target_currency();
	if ( 'UAH' === $currency ) {
		return $price;
	}
	$reg = cgp_target_regular( $product, $currency );
	return null === $reg ? $price : $reg;
}
add_filter( 'woocommerce_product_get_regular_price', 'cgp_get_regular_price', 10, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price', 'cgp_get_regular_price', 10, 2 );

function cgp_get_sale_price( $price, $product ) {
	$currency = cgp_target_currency();
	if ( 'UAH' === $currency ) {
		return $price;
	}
	$sale = cgp_target_sale( $product, $currency );
	return null === $sale ? '' : $sale;
}
add_filter( 'woocommerce_product_get_sale_price', 'cgp_get_sale_price', 10, 2 );
add_filter( 'woocommerce_product_variation_get_sale_price', 'cgp_get_sale_price', 10, 2 );

function cgp_get_active_price( $price, $product ) {
	$currency = cgp_target_currency();
	if ( 'UAH' === $currency ) {
		return $price;
	}
	$reg = cgp_target_regular( $product, $currency );
	if ( null === $reg ) {
		return $price;
	}
	$sale = cgp_target_sale( $product, $currency );
	return cgp_sale_active( $product, $reg, $sale ) ? $sale : $reg;
}
add_filter( 'woocommerce_product_get_price', 'cgp_get_active_price', 10, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'cgp_get_active_price', 10, 2 );

// Rebuild the variable-product price cache separately per currency, otherwise the
// first cached currency leaks to every later visitor.
add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash ) {
	$hash[] = cgp_target_currency();
	return $hash;
} );

/* -------------------------------------------------------------------------
 * 5. Switch the store currency (code + symbol) for the visitor.
 *    Returning 'EUR' or 'USD' makes WooCommerce render € or $ automatically.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_currency', function ( $currency ) {
	$target = cgp_target_currency();
	return 'UAH' === $target ? $currency : $target;
} );

/* -------------------------------------------------------------------------
 * 6. On/off switch under WooCommerce > Settings > General.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_get_settings_general', function ( $settings ) {
	$extra = array(
		array(
			'title' => __( 'Currency geo pricing', 'eurgeo' ),
			'type'  => 'title',
			'desc'  => __( 'Choose which currencies to offer. The currency shown is picked by visitor location; a disabled currency falls back to UAH.', 'eurgeo' ),
			'id'    => 'eurgeo_options',
		),
		array(
			'title'   => __( 'Show prices by location', 'eurgeo' ),
			'desc'    => __( 'Master switch. When off, every visitor sees UAH - use this to enter EUR/USD prices first, then turn it on.', 'eurgeo' ),
			'id'      => 'eurgeo_geo_enabled',
			'type'    => 'checkbox',
			'default' => 'yes',
		),
		array(
			'title'             => __( 'Enabled currencies', 'eurgeo' ),
			'desc'              => __( 'UAH - Ukraine (always on)', 'eurgeo' ),
			'id'                => 'eurgeo_uah_enabled',
			'type'              => 'checkbox',
			'default'           => 'yes',
			'checkboxgroup'     => 'start',
			'custom_attributes' => array( 'disabled' => 'disabled' ),
		),
		array(
			'desc'          => __( 'EUR - Europe', 'eurgeo' ),
			'id'            => 'eurgeo_eur_enabled',
			'type'          => 'checkbox',
			'default'       => 'yes',
			'checkboxgroup' => '',
		),
		array(
			'desc'          => __( 'USD - rest of the world', 'eurgeo' ),
			'id'            => 'eurgeo_usd_enabled',
			'type'          => 'checkbox',
			'default'       => 'yes',
			'checkboxgroup' => 'end',
		),
		array(
			'title'             => __( 'UAH per 1 EUR', 'eurgeo' ),
			'desc'              => __( 'Optional fallback rate. When a product has no EUR price, its UAH price is divided by this number. Set to 0 to disable (the raw UAH number is then shown under €).', 'eurgeo' ),
			'id'                => 'eurgeo_uah_per_eur',
			'type'              => 'number',
			'default'           => '0',
			'desc_tip'          => true,
			'custom_attributes' => array( 'min' => '0', 'step' => '0.0001' ),
		),
		array(
			'title'             => __( 'UAH per 1 USD', 'eurgeo' ),
			'desc'              => __( 'Optional fallback rate. When a product has no USD price, its UAH price is divided by this number. Set to 0 to disable (the raw UAH number is then shown under $).', 'eurgeo' ),
			'id'                => 'eurgeo_uah_per_usd',
			'type'              => 'number',
			'default'           => '0',
			'desc_tip'          => true,
			'custom_attributes' => array( 'min' => '0', 'step' => '0.0001' ),
		),
		array(
			'type' => 'sectionend',
			'id'   => 'eurgeo_options',
		),
	);

	return array_merge( $settings, $extra );
} );

// UAH is the base currency and can never be switched off; keep its box ticked.
add_filter( 'option_eurgeo_uah_enabled', function () {
	return 'yes';
} );
