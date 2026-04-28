<?php
/**
 * WPML compatibility helper.
 *
 * When WPML is active and a rule has `wpml_all_langs = 1`, this class
 * normalises product IDs and taxonomy term IDs to their original-language
 * equivalents before they are compared against a rule's stored object_ids.
 *
 * @package GiodcExtraFee
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Giodc_Fee_Wpml
 */
class Giodc_Fee_Wpml {

    /** @var self|null */
    private static $instance = null;

    /** @var string|null Default language code cached after first lookup. */
    private $default_language = null;

    // ---------------------------------------------------------------------------
    // Singleton
    // ---------------------------------------------------------------------------

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Check whether WPML is active and has its core functions available.
     *
     * @return bool
     */
    public function is_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_object_id' );
    }

    /**
     * Return the WPML default (original) language code.
     *
     * @return string  e.g. 'en', or '' when WPML is not active.
     */
    public function get_default_language(): string {
        if ( null === $this->default_language ) {
            $this->default_language = $this->is_active()
                ? (string) apply_filters( 'wpml_default_language', null )
                : '';
        }
        return $this->default_language;
    }

    /**
     * Convert a product (post) ID to its original-language equivalent.
     *
     * When WPML is not active, the original ID is returned unchanged.
     *
     * @param int $product_id  The product post ID in the current language.
     * @return int             The product post ID in the default language.
     */
    public function normalize_product_id( int $product_id ): int {
        if ( ! $this->is_active() ) {
            return $product_id;
        }

        $original = (int) apply_filters(
            'wpml_object_id',
            $product_id,
            'product',
            true,
            $this->get_default_language()
        );

        return $original > 0 ? $original : $product_id;
    }

    /**
     * Convert an array of taxonomy term IDs to their original-language equivalents.
     *
     * @param int[]  $term_ids  Term IDs in the current language.
     * @param string $taxonomy  The taxonomy slug (e.g. 'product_cat').
     * @return int[]            Term IDs in the default language.
     */
    public function normalize_term_ids( array $term_ids, string $taxonomy = 'product_cat' ): array {
        if ( ! $this->is_active() || empty( $term_ids ) ) {
            return $term_ids;
        }

        $default_lang = $this->get_default_language();
        $normalized   = [];

        foreach ( $term_ids as $term_id ) {
            $original = (int) apply_filters(
                'wpml_object_id',
                $term_id,
                $taxonomy,
                true,
                $default_lang
            );
            $normalized[] = $original > 0 ? $original : $term_id;
        }

        return array_values( array_unique( $normalized ) );
    }
}
