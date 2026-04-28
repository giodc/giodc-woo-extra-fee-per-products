<?php
/**
 * Cart fee calculation engine.
 *
 * Hooks into `woocommerce_cart_calculate_fees` and adds one fee entry per
 * matching rule, using the step-function tier lookup from Giodc_Fee_Rules.
 *
 * @package GiodcExtraFee
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Giodc_Fee_Cart
 */
class Giodc_Fee_Cart {

    /** @var self|null */
    private static $instance = null;

    // ---------------------------------------------------------------------------
    // Singleton
    // ---------------------------------------------------------------------------

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'calculate_fees' ] );
    }

    // ---------------------------------------------------------------------------
    // Fee calculation
    // ---------------------------------------------------------------------------

    /**
     * Main callback for `woocommerce_cart_calculate_fees`.
     *
     * For each active rule, count the total quantity of matching products in the
     * cart, resolve the applicable fee tier and add the fee.
     *
     * @param \WC_Cart $cart
     * @return void
     */
    public function calculate_fees( \WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $rules_db = Giodc_Fee_Rules::get_instance();
        $wpml     = Giodc_Fee_Wpml::get_instance();
        $rules    = $rules_db->get_rules( true );

        if ( empty( $rules ) ) {
            return;
        }

        foreach ( $rules as $rule ) {
            $this->process_rule( $rule, $cart, $rules_db, $wpml );
        }
    }

    // ---------------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------------

    /**
     * Evaluate a single rule against the current cart and add a fee if applicable.
     *
     * @param array            $rule
     * @param \WC_Cart         $cart
     * @param Giodc_Fee_Rules  $rules_db
     * @param Giodc_Fee_Wpml   $wpml
     * @return void
     */
    private function process_rule(
        array $rule,
        \WC_Cart $cart,
        Giodc_Fee_Rules $rules_db,
        Giodc_Fee_Wpml $wpml
    ): void {
        $object_ids      = $rules_db->decode_object_ids( $rule );
        $tiers           = $rules_db->decode_fee_tiers( $rule );
        $use_wpml        = (bool) $rule['wpml_all_langs'];
        $rule_type       = $rule['rule_type'];

        if ( empty( $object_ids ) || empty( $tiers ) ) {
            return;
        }

        $total_qty = 0;

        foreach ( $cart->get_cart() as $item ) {
            $product_id = isset( $item['variation_id'] ) && $item['variation_id']
                ? (int) $item['variation_id']
                : (int) $item['product_id'];

            $parent_id = (int) $item['product_id'];
            $qty       = (int) $item['quantity'];

            if ( 'product' === $rule_type ) {
                $check_id        = $use_wpml ? $wpml->normalize_product_id( $product_id ) : $product_id;
                $check_parent_id = $use_wpml ? $wpml->normalize_product_id( $parent_id )  : $parent_id;

                if ( in_array( $check_id, $object_ids, true ) || in_array( $check_parent_id, $object_ids, true ) ) {
                    $total_qty += $qty;
                }
            } elseif ( 'category' === $rule_type ) {
                $category_ids = $this->get_product_category_ids( $parent_id );

                if ( $use_wpml ) {
                    $category_ids = $wpml->normalize_term_ids( $category_ids );
                }

                if ( ! empty( array_intersect( $category_ids, $object_ids ) ) ) {
                    $total_qty += $qty;
                }
            }
        }

        if ( $total_qty < 1 ) {
            return;
        }

        $fee_amount = $rules_db->get_fee_for_quantity( $tiers, $total_qty );

        if ( null === $fee_amount || $fee_amount <= 0 ) {
            return;
        }

        $label = '' !== trim( $rule['fee_label'] )
            ? $rule['fee_label']
            : $rule['rule_name'];

        $cart->add_fee(
            sanitize_text_field( $label ),
            (float) $fee_amount,
            (bool) $rule['taxable']
        );
    }

    /**
     * Return the flat list of category term IDs (ancestors included) for a product.
     *
     * Results are cached per product ID for the duration of the request.
     *
     * @param int $product_id  The parent product post ID.
     * @return int[]
     */
    private function get_product_category_ids( int $product_id ): array {
        static $cache = [];

        if ( isset( $cache[ $product_id ] ) ) {
            return $cache[ $product_id ];
        }

        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
            $cache[ $product_id ] = [];
            return [];
        }

        $ids = [];
        foreach ( $terms as $term ) {
            $ids[] = (int) $term->term_id;
            // Include ancestors so a rule set on a parent category also matches children.
            $ancestors = get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' );
            foreach ( $ancestors as $ancestor_id ) {
                $ids[] = (int) $ancestor_id;
            }
        }

        $cache[ $product_id ] = array_values( array_unique( $ids ) );
        return $cache[ $product_id ];
    }
}
