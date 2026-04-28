<?php
/**
 * Plugin Name:       Giodc Custom Fee per Product or Category - Quantity based
 * Plugin URI:        https://github.com/giodc/giodc-woo-extra-fee-per-products
 * Description:       Adds a custom quantity-based fee to the WooCommerce cart for selected products or product categories. Supports up to 36 quantity tiers and is WPML-compatible.
 * Version:           1.0.0
 * Author:            Giodc
 * Text Domain:       giodc-extra-fee
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GiodcExtraFee
 */

defined( 'ABSPATH' ) || exit;

define( 'GIODC_FEE_VERSION',     '1.0.0' );
define( 'GIODC_FEE_PLUGIN_FILE', __FILE__ );
define( 'GIODC_FEE_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GIODC_FEE_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'GIODC_FEE_TABLE',       'giodc_fee_rules' );
define( 'GIODC_FEE_MAX_TIERS',   36 );

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, static function (): void {
    require_once GIODC_FEE_PLUGIN_DIR . 'includes/class-giodc-fee-rules.php';
    Giodc_Fee_Rules::create_table();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function (): void {
    flush_rewrite_rules();
} );

// ---------------------------------------------------------------------------
// Boot on plugins_loaded (after WooCommerce is available)
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', static function (): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'Giodc Custom Fee per Product or Category requires WooCommerce to be installed and active.', 'giodc-extra-fee' )
            );
        } );
        return;
    }

    load_plugin_textdomain( 'giodc-extra-fee', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    require_once GIODC_FEE_PLUGIN_DIR . 'includes/class-giodc-fee-rules.php';
    require_once GIODC_FEE_PLUGIN_DIR . 'includes/class-giodc-fee-wpml.php';
    require_once GIODC_FEE_PLUGIN_DIR . 'includes/class-giodc-fee-cart.php';
    require_once GIODC_FEE_PLUGIN_DIR . 'includes/class-giodc-fee-admin.php';

    Giodc_Fee_Rules::get_instance();
    Giodc_Fee_Wpml::get_instance();
    Giodc_Fee_Cart::get_instance();

    if ( is_admin() ) {
        Giodc_Fee_Admin::get_instance();
    }

    // Run DB upgrade when plugin version changes.
    if ( get_option( 'giodc_fee_db_version' ) !== GIODC_FEE_VERSION ) {
        Giodc_Fee_Rules::create_table();
        update_option( 'giodc_fee_db_version', GIODC_FEE_VERSION );
    }
}, 20 );

// ---------------------------------------------------------------------------
// Declare HPOS (High-Performance Order Storage) compatibility
// ---------------------------------------------------------------------------

add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
