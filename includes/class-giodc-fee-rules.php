<?php
/**
 * Database CRUD layer for fee rules.
 *
 * @package GiodcExtraFee
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Giodc_Fee_Rules
 *
 * Handles all interactions with the custom `{prefix}giodc_fee_rules` table.
 */
class Giodc_Fee_Rules {

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

    private function __construct() {}

    // ---------------------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------------------

    /**
     * Create (or upgrade) the database table.
     * Safe to call multiple times – uses dbDelta().
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . GIODC_FEE_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              MEDIUMINT(9)  NOT NULL AUTO_INCREMENT,
            rule_name       VARCHAR(255)  NOT NULL DEFAULT '',
            rule_type       VARCHAR(20)   NOT NULL DEFAULT 'product',
            object_ids      LONGTEXT      NOT NULL,
            fee_tiers       LONGTEXT      NOT NULL,
            fee_label       VARCHAR(255)  NOT NULL DEFAULT '',
            taxable         TINYINT(1)    NOT NULL DEFAULT 0,
            wpml_all_langs  TINYINT(1)    NOT NULL DEFAULT 1,
            status          TINYINT(1)    NOT NULL DEFAULT 1,
            created_at      DATETIME      NOT NULL,
            updated_at      DATETIME      NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ---------------------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------------------

    /**
     * Return all rules, optionally filtered to active ones only.
     *
     * @param bool $active_only
     * @return array[]
     */
    public function get_rules( bool $active_only = false ): array {
        global $wpdb;

        $table = $wpdb->prefix . GIODC_FEE_TABLE;
        $where = $active_only ? 'WHERE status = 1' : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return a single rule by its primary key.
     *
     * @param int $id
     * @return array|null
     */
    public function get_rule( int $id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . GIODC_FEE_TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    // ---------------------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------------------

    /**
     * Insert a new rule and return its new ID, or false on failure.
     *
     * @param array $data  Raw (unsanitized) data from the form.
     * @return int|false
     */
    public function insert_rule( array $data ) {
        global $wpdb;

        $table  = $wpdb->prefix . GIODC_FEE_TABLE;
        $now    = current_time( 'mysql' );
        $result = $wpdb->insert(
            $table,
            [
                'rule_name'      => sanitize_text_field( $data['rule_name'] ),
                'rule_type'      => $this->sanitize_rule_type( $data['rule_type'] ?? '' ),
                'object_ids'     => wp_json_encode( $this->sanitize_ids( $data['object_ids'] ?? [] ) ),
                'fee_tiers'      => wp_json_encode( $this->sanitize_tiers( $data['fee_tiers'] ?? [] ) ),
                'fee_label'      => sanitize_text_field( $data['fee_label'] ),
                'taxable'        => empty( $data['taxable'] ) ? 0 : 1,
                'wpml_all_langs' => empty( $data['wpml_all_langs'] ) ? 0 : 1,
                'status'         => empty( $data['status'] ) ? 0 : 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing rule.
     *
     * @param int   $id
     * @param array $data  Raw (unsanitized) data from the form.
     * @return bool
     */
    public function update_rule( int $id, array $data ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . GIODC_FEE_TABLE;
        $result = $wpdb->update(
            $table,
            [
                'rule_name'      => sanitize_text_field( $data['rule_name'] ),
                'rule_type'      => $this->sanitize_rule_type( $data['rule_type'] ?? '' ),
                'object_ids'     => wp_json_encode( $this->sanitize_ids( $data['object_ids'] ?? [] ) ),
                'fee_tiers'      => wp_json_encode( $this->sanitize_tiers( $data['fee_tiers'] ?? [] ) ),
                'fee_label'      => sanitize_text_field( $data['fee_label'] ),
                'taxable'        => empty( $data['taxable'] ) ? 0 : 1,
                'wpml_all_langs' => empty( $data['wpml_all_langs'] ) ? 0 : 1,
                'status'         => empty( $data['status'] ) ? 0 : 1,
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        return false !== $result;
    }

    /**
     * Delete a rule by ID.
     *
     * @param int $id
     * @return bool
     */
    public function delete_rule( int $id ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . GIODC_FEE_TABLE;
        $result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        return false !== $result;
    }

    // ---------------------------------------------------------------------------
    // Decoders (used by cart + admin)
    // ---------------------------------------------------------------------------

    /**
     * Decode JSON-encoded object_ids to a plain array of integers.
     *
     * @param array $rule  A raw DB row.
     * @return int[]
     */
    public function decode_object_ids( array $rule ): array {
        $ids = json_decode( $rule['object_ids'], true );
        return is_array( $ids ) ? array_map( 'absint', $ids ) : [];
    }

    /**
     * Decode JSON-encoded fee_tiers to an assoc array: qty(int) => amount(float).
     *
     * @param array $rule  A raw DB row.
     * @return float[]
     */
    public function decode_fee_tiers( array $rule ): array {
        $raw = json_decode( $rule['fee_tiers'], true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $clean = [];
        foreach ( $raw as $qty => $amount ) {
            $qty = (int) $qty;
            if ( $qty >= 1 && $qty <= GIODC_FEE_MAX_TIERS ) {
                $clean[ $qty ] = (float) $amount;
            }
        }
        return $clean;
    }

    /**
     * Resolve the applicable fee for a given quantity using a step-function lookup.
     *
     * The algorithm returns the fee amount associated with the highest defined
     * tier that is still <= $quantity (i.e., the "floor" tier).
     * Returns null when no applicable tier exists.
     *
     * @param float[] $tiers     Decoded tiers array (qty => amount).
     * @param int     $quantity  Total quantity of matching products in cart.
     * @return float|null
     */
    public function get_fee_for_quantity( array $tiers, int $quantity ): ?float {
        if ( empty( $tiers ) || $quantity < 1 ) {
            return null;
        }

        ksort( $tiers );
        $applicable = null;

        foreach ( $tiers as $tier_qty => $amount ) {
            if ( $tier_qty <= $quantity ) {
                $applicable = $amount;
            } else {
                break;
            }
        }

        return $applicable;
    }

    // ---------------------------------------------------------------------------
    // Sanitization helpers
    // ---------------------------------------------------------------------------

    /**
     * @param string $value
     * @return string  'product' or 'category'
     */
    private function sanitize_rule_type( string $value ): string {
        return in_array( $value, [ 'product', 'category' ], true ) ? $value : 'product';
    }

    /**
     * @param mixed $ids
     * @return int[]
     */
    private function sanitize_ids( $ids ): array {
        if ( ! is_array( $ids ) ) {
            return [];
        }
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    /**
     * Sanitize fee tiers: keep qty 1-36 with positive float amounts only.
     *
     * @param mixed $tiers
     * @return array
     */
    private function sanitize_tiers( $tiers ): array {
        if ( ! is_array( $tiers ) ) {
            return [];
        }
        $clean = [];
        for ( $qty = 1; $qty <= GIODC_FEE_MAX_TIERS; $qty++ ) {
            if ( isset( $tiers[ $qty ] ) && '' !== trim( (string) $tiers[ $qty ] ) ) {
                $amount = round( (float) $tiers[ $qty ], 4 );
                if ( $amount >= 0 ) {
                    $clean[ $qty ] = $amount;
                }
            }
        }
        return $clean;
    }
}
