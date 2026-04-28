<?php
/**
 * Admin interface: menu, list page, add/edit form, AJAX handlers.
 *
 * @package GiodcExtraFee
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Giodc_Fee_Admin
 */
class Giodc_Fee_Admin {

    /** @var self|null */
    private static $instance = null;

    /** @var string The slug used for the admin menu page. */
    const MENU_SLUG = 'giodc-fee-rules';

    /** @var string Nonce action used across the admin area. */
    const NONCE_ACTION = 'giodc_fee_admin';

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
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',             [ $this, 'handle_actions' ] );
        add_action( 'wp_ajax_giodc_fee_search_products',   [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_giodc_fee_search_categories', [ $this, 'ajax_search_categories' ] );
    }

    // ---------------------------------------------------------------------------
    // Menu
    // ---------------------------------------------------------------------------

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Quantity-Based Fee Rules', 'giodc-extra-fee' ),
            __( 'Qty Fee Rules', 'giodc-extra-fee' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ---------------------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, self::MENU_SLUG ) ) {
            return;
        }

        wp_enqueue_style(
            'giodc-fee-admin',
            GIODC_FEE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GIODC_FEE_VERSION
        );

        wp_enqueue_script(
            'giodc-fee-admin',
            GIODC_FEE_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            GIODC_FEE_VERSION,
            true
        );

        wp_localize_script( 'giodc-fee-admin', 'giodcFeeAdmin', [
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( self::NONCE_ACTION ),
            'searchProductsAction'   => 'giodc_fee_search_products',
            'searchCategoriesAction' => 'giodc_fee_search_categories',
            'i18n'              => [
                'searchProducts'   => __( 'Search products…', 'giodc-extra-fee' ),
                'searchCategories' => __( 'Search categories…', 'giodc-extra-fee' ),
                'noResults'        => __( 'No results found.', 'giodc-extra-fee' ),
            ],
        ] );
    }

    // ---------------------------------------------------------------------------
    // Action dispatcher (runs on admin_init – before any output)
    // ---------------------------------------------------------------------------

    public function handle_actions(): void {
        if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        // ----- Save (POST) -------------------------------------------------
        if ( 'save_rule' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            check_admin_referer( self::NONCE_ACTION );

            $rules_db = Giodc_Fee_Rules::get_instance();
            $rule_id  = absint( $_POST['rule_id'] ?? 0 );

            $data = [
                'rule_name'      => wp_unslash( $_POST['rule_name'] ?? '' ),
                'rule_type'      => wp_unslash( $_POST['rule_type'] ?? 'product' ),
                'object_ids'     => array_map( 'absint', (array) ( $_POST['object_ids'] ?? [] ) ),
                'fee_tiers'      => $_POST['fee_tiers'] ?? [],
                'fee_label'      => wp_unslash( $_POST['fee_label'] ?? '' ),
                'taxable'        => ! empty( $_POST['taxable'] ),
                'wpml_all_langs' => ! empty( $_POST['wpml_all_langs'] ),
                'status'         => ! empty( $_POST['status'] ),
            ];

            if ( $rule_id > 0 ) {
                $rules_db->update_rule( $rule_id, $data );
                $notice = 'updated';
            } else {
                $rule_id = $rules_db->insert_rule( $data );
                $notice  = $rule_id ? 'added' : 'error';
            }

            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => self::MENU_SLUG, 'notice' => $notice ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        // ----- Delete (GET with nonce) ------------------------------------
        if ( 'delete_rule' === $action ) {
            $rule_id = absint( $_GET['rule_id'] ?? 0 );
            check_admin_referer( 'delete_rule_' . $rule_id );

            if ( $rule_id > 0 ) {
                Giodc_Fee_Rules::get_instance()->delete_rule( $rule_id );
            }

            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => self::MENU_SLUG, 'notice' => 'deleted' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        // ----- Toggle status (GET with nonce) -----------------------------
        if ( 'toggle_status' === $action ) {
            $rule_id = absint( $_GET['rule_id'] ?? 0 );
            check_admin_referer( 'toggle_status_' . $rule_id );

            $rules_db = Giodc_Fee_Rules::get_instance();
            $rule     = $rules_db->get_rule( $rule_id );

            if ( $rule ) {
                $rules_db->update_rule( $rule_id, array_merge( $rule, [
                    'status'         => $rule['status'] ? 0 : 1,
                    'object_ids'     => $rules_db->decode_object_ids( $rule ),
                    'fee_tiers'      => $rules_db->decode_fee_tiers( $rule ),
                ] ) );
            }

            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => self::MENU_SLUG, 'notice' => 'updated' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }
    }

    // ---------------------------------------------------------------------------
    // Page router
    // ---------------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'giodc-extra-fee' ) );
        }

        $action = sanitize_key( $_GET['action'] ?? 'list' );

        if ( 'add' === $action || 'edit' === $action ) {
            $rule_id = absint( $_GET['rule_id'] ?? 0 );
            $this->render_edit_page( $rule_id );
        } else {
            $this->render_list_page();
        }
    }

    // ---------------------------------------------------------------------------
    // List page
    // ---------------------------------------------------------------------------

    private function render_list_page(): void {
        $rules    = Giodc_Fee_Rules::get_instance()->get_rules();
        $notice   = sanitize_key( $_GET['notice'] ?? '' );
        $add_url  = add_query_arg( [ 'page' => self::MENU_SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) );
        ?>
        <div class="wrap giodc-fee-wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Quantity-Based Fee Rules', 'giodc-extra-fee' ); ?>
            </h1>
            <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action">
                <?php esc_html_e( '+ Add New Rule', 'giodc-extra-fee' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php $this->render_notice( $notice ); ?>

            <?php if ( empty( $rules ) ) : ?>
                <div class="giodc-fee-empty">
                    <p><?php esc_html_e( 'No fee rules found. Create your first rule to get started.', 'giodc-extra-fee' ); ?></p>
                    <a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Add Your First Rule', 'giodc-extra-fee' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped giodc-fee-list-table">
                    <thead>
                        <tr>
                            <th class="col-id"><?php esc_html_e( 'ID', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Rule Name', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Cart Label', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Tiers Defined', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'WPML', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'giodc-extra-fee' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'giodc-extra-fee' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rules as $rule ) :
                            $tiers       = Giodc_Fee_Rules::get_instance()->decode_fee_tiers( $rule );
                            $edit_url    = add_query_arg( [ 'page' => self::MENU_SLUG, 'action' => 'edit', 'rule_id' => $rule['id'] ], admin_url( 'admin.php' ) );
                            $delete_url  = wp_nonce_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'action' => 'delete_rule', 'rule_id' => $rule['id'] ], admin_url( 'admin.php' ) ), 'delete_rule_' . $rule['id'] );
                            $toggle_url  = wp_nonce_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'action' => 'toggle_status', 'rule_id' => $rule['id'] ], admin_url( 'admin.php' ) ), 'toggle_status_' . $rule['id'] );
                            $is_active   = (bool) $rule['status'];
                        ?>
                        <tr>
                            <td><?php echo absint( $rule['id'] ); ?></td>
                            <td><strong><?php echo esc_html( $rule['rule_name'] ); ?></strong></td>
                            <td>
                                <?php if ( 'category' === $rule['rule_type'] ) : ?>
                                    <span class="giodc-badge giodc-badge-cat"><?php esc_html_e( 'Category', 'giodc-extra-fee' ); ?></span>
                                <?php else : ?>
                                    <span class="giodc-badge giodc-badge-prod"><?php esc_html_e( 'Product', 'giodc-extra-fee' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $rule['fee_label'] ?: '—' ); ?></td>
                            <td><?php echo count( $tiers ); ?> / <?php echo GIODC_FEE_MAX_TIERS; ?></td>
                            <td>
                                <?php if ( $rule['wpml_all_langs'] ) : ?>
                                    <span class="giodc-badge giodc-badge-wpml">✓</span>
                                <?php else : ?>
                                    <span class="giodc-badge giodc-badge-off">✗</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $toggle_url ); ?>" class="giodc-status-toggle <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $is_active ? esc_html__( 'Active', 'giodc-extra-fee' ) : esc_html__( 'Inactive', 'giodc-extra-fee' ); ?>
                                </a>
                            </td>
                            <td class="giodc-actions">
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Edit', 'giodc-extra-fee' ); ?>
                                </a>
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   class="button button-small giodc-btn-delete"
                                   onclick="return confirm('<?php esc_attr_e( 'Delete this rule? This action cannot be undone.', 'giodc-extra-fee' ); ?>')">
                                    <?php esc_html_e( 'Delete', 'giodc-extra-fee' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------------
    // Edit / Add page
    // ---------------------------------------------------------------------------

    private function render_edit_page( int $rule_id = 0 ): void {
        $rules_db    = Giodc_Fee_Rules::get_instance();
        $rule        = $rule_id > 0 ? $rules_db->get_rule( $rule_id ) : null;
        $is_edit     = null !== $rule;

        // Normalise to a safe default array so array-access never hits null.
        if ( ! $is_edit ) {
            $rule = [
                'rule_name'      => '',
                'rule_type'      => 'product',
                'fee_label'      => '',
                'taxable'        => 0,
                'wpml_all_langs' => 1,
                'status'         => 1,
            ];
        }

        $object_ids  = $is_edit ? $rules_db->decode_object_ids( $rule ) : [];
        $tiers       = $is_edit ? $rules_db->decode_fee_tiers( $rule ) : [];
        $rule_type   = $rule['rule_type'];
        $notice      = sanitize_key( $_GET['notice'] ?? '' );
        $list_url    = add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
        $page_title  = $is_edit
            ? __( 'Edit Fee Rule', 'giodc-extra-fee' )
            : __( 'Add New Fee Rule', 'giodc-extra-fee' );

        // Pre-fetch selected product names for Select2 initial values.
        $selected_products = [];
        if ( 'product' === $rule_type && ! empty( $object_ids ) ) {
            foreach ( $object_ids as $pid ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $selected_products[ $pid ] = sprintf( '#%d – %s', $pid, $product->get_name() );
                }
            }
        }

        // Load all product categories for the category selector.
        $all_categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );
        if ( is_wp_error( $all_categories ) ) {
            $all_categories = [];
        }

        ?>
        <div class="wrap giodc-fee-wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="giodc-back-link">
                ← <?php esc_html_e( 'Back to rules list', 'giodc-extra-fee' ); ?>
            </a>

            <?php $this->render_notice( $notice ); ?>

            <form method="post" action="" id="giodc-fee-form">
                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                <input type="hidden" name="action"  value="save_rule">
                <input type="hidden" name="rule_id" value="<?php echo absint( $rule_id ); ?>">

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">

                        <!-- Main column -->
                        <div id="post-body-content">

                            <!-- Rule Settings -->
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle"><span><?php esc_html_e( 'Rule Settings', 'giodc-extra-fee' ); ?></span></h2>
                                <div class="inside">
                                    <table class="form-table giodc-form-table">
                                        <tr>
                                            <th><label for="giodc-rule-name"><?php esc_html_e( 'Rule Name', 'giodc-extra-fee' ); ?> <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="giodc-rule-name" name="rule_name"
                                                       value="<?php echo esc_attr( $rule['rule_name'] ?? '' ); ?>"
                                                       class="regular-text" required>
                                                <p class="description"><?php esc_html_e( 'Internal name for this rule (not shown to customers).', 'giodc-extra-fee' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Rule Type', 'giodc-extra-fee' ); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="radio" name="rule_type" value="product"
                                                               id="giodc-type-product"
                                                               <?php checked( 'product', $rule_type ); ?>>
                                                        <?php esc_html_e( 'Specific Products', 'giodc-extra-fee' ); ?>
                                                    </label>
                                                    &nbsp;&nbsp;
                                                    <label>
                                                        <input type="radio" name="rule_type" value="category"
                                                               id="giodc-type-category"
                                                               <?php checked( 'category', $rule_type ); ?>>
                                                        <?php esc_html_e( 'Product Categories', 'giodc-extra-fee' ); ?>
                                                    </label>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <?php
                                        $picker_cats = array_map( function( $t ) {
                                            return [ 'id' => (int) $t->term_id, 'text' => $t->name ];
                                        }, $all_categories );
                                        ?>
                                        <script>
                                        window.giodcPickerData = {
                                            selectedProducts:    <?php echo wp_json_encode( $selected_products ); ?>,
                                            allCategories:       <?php echo wp_json_encode( array_values( $picker_cats ) ); ?>,
                                            selectedCategoryIds: <?php echo wp_json_encode( 'category' === $rule_type ? $object_ids : [] ); ?>
                                        };
                                        </script>
                                        <!-- Product picker -->
                                        <tr id="giodc-row-products" <?php echo 'product' !== $rule_type ? 'style="display:none"' : ''; ?>>
                                            <th><label for="giodc-product-search"><?php esc_html_e( 'Select Products', 'giodc-extra-fee' ); ?></label></th>
                                            <td>
                                                <div class="giodc-picker" id="giodc-product-picker">
                                                    <div class="giodc-picker__tags"></div>
                                                    <input type="text"
                                                           id="giodc-product-search"
                                                           class="giodc-picker__search"
                                                           autocomplete="off"
                                                           placeholder="<?php esc_attr_e( 'Search products...', 'giodc-extra-fee' ); ?>">
                                                    <ul class="giodc-picker__results" style="display:none"></ul>
                                                </div>
                                                <p class="description"><?php esc_html_e( 'Search and select one or more products.', 'giodc-extra-fee' ); ?></p>
                                            </td>
                                        </tr>
                                        <!-- Category picker -->
                                        <tr id="giodc-row-categories" <?php echo 'category' !== $rule_type ? 'style="display:none"' : ''; ?>>
                                            <th><label for="giodc-category-search"><?php esc_html_e( 'Select Categories', 'giodc-extra-fee' ); ?></label></th>
                                            <td>
                                                <div class="giodc-picker" id="giodc-category-picker">
                                                    <div class="giodc-picker__tags"></div>
                                                    <input type="text"
                                                           id="giodc-category-search"
                                                           class="giodc-picker__search"
                                                           autocomplete="off"
                                                           placeholder="<?php esc_attr_e( 'Search categories...', 'giodc-extra-fee' ); ?>">
                                                    <ul class="giodc-picker__results" style="display:none"></ul>
                                                </div>
                                                <p class="description"><?php esc_html_e( 'Select one or more product categories.', 'giodc-extra-fee' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="giodc-fee-label"><?php esc_html_e( 'Cart Fee Label', 'giodc-extra-fee' ); ?></label></th>
                                            <td>
                                                <input type="text" id="giodc-fee-label" name="fee_label"
                                                       value="<?php echo esc_attr( $rule['fee_label'] ?? '' ); ?>"
                                                       class="regular-text"
                                                       placeholder="<?php esc_attr_e( 'e.g. Packaging surcharge', 'giodc-extra-fee' ); ?>">
                                                <p class="description"><?php esc_html_e( 'Label shown to customers on the cart/checkout page. Defaults to rule name if left empty.', 'giodc-extra-fee' ); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Fee Tiers Table -->
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle">
                                    <span><?php esc_html_e( 'Quantity → Fee Tiers', 'giodc-extra-fee' ); ?></span>
                                </h2>
                                <div class="inside">
                                    <p class="description">
                                        <?php esc_html_e( 'Enter the flat fee for each quantity level. Empty rows inherit the nearest lower defined amount (step-function logic).', 'giodc-extra-fee' ); ?>
                                        <?php esc_html_e( 'Quantities above 36 use the last defined tier.', 'giodc-extra-fee' ); ?>
                                    </p>
                                    <div class="giodc-tiers-wrap">
                                        <table class="giodc-tiers-table widefat">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e( 'Qty', 'giodc-extra-fee' ); ?></th>
                                                    <th><?php esc_html_e( 'Fee Amount', 'giodc-extra-fee' ); ?></th>
                                                    <th><?php esc_html_e( 'Qty', 'giodc-extra-fee' ); ?></th>
                                                    <th><?php esc_html_e( 'Fee Amount', 'giodc-extra-fee' ); ?></th>
                                                    <th><?php esc_html_e( 'Qty', 'giodc-extra-fee' ); ?></th>
                                                    <th><?php esc_html_e( 'Fee Amount', 'giodc-extra-fee' ); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $rows_per_col = 12;
                                                for ( $row = 0; $row < $rows_per_col; $row++ ) :
                                                    $col1 = $row + 1;
                                                    $col2 = $row + 1 + $rows_per_col;
                                                    $col3 = $row + 1 + ( 2 * $rows_per_col );
                                                    ?>
                                                    <tr class="<?php echo 0 === $row % 2 ? '' : 'alternate'; ?>">
                                                        <?php foreach ( [ $col1, $col2, $col3 ] as $qty ) : ?>
                                                            <td class="giodc-qty-cell"><strong><?php echo absint( $qty ); ?></strong></td>
                                                            <td class="giodc-fee-cell">
                                                                <input type="number"
                                                                       name="fee_tiers[<?php echo absint( $qty ); ?>]"
                                                                       value="<?php echo isset( $tiers[ $qty ] ) ? esc_attr( number_format( $tiers[ $qty ], 2, '.', '' ) ) : ''; ?>"
                                                                       step="0.01"
                                                                       min="0"
                                                                       placeholder="—"
                                                                       class="small-text giodc-tier-input">
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /#post-body-content -->

                        <!-- Sidebar column -->
                        <div id="postbox-container-1" class="postbox-container">

                            <!-- Publish / Save -->
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle"><span><?php esc_html_e( 'Save Rule', 'giodc-extra-fee' ); ?></span></h2>
                                <div class="inside">
                                    <div class="giodc-publish-row">
                                        <label class="giodc-switch-label">
                                            <input type="checkbox" name="status" value="1"
                                                   <?php checked( 1, (int) ( $rule['status'] ?? 1 ) ); ?>>
                                            <?php esc_html_e( 'Active', 'giodc-extra-fee' ); ?>
                                        </label>
                                    </div>
                                    <div class="giodc-publish-row">
                                        <label class="giodc-switch-label">
                                            <input type="checkbox" name="taxable" value="1"
                                                   <?php checked( 1, (int) ( $rule['taxable'] ?? 0 ) ); ?>>
                                            <?php esc_html_e( 'Taxable fee', 'giodc-extra-fee' ); ?>
                                        </label>
                                    </div>
                                    <div class="giodc-publish-actions">
                                        <a href="<?php echo esc_url( $list_url ); ?>" class="button">
                                            <?php esc_html_e( 'Cancel', 'giodc-extra-fee' ); ?>
                                        </a>
                                        <button type="submit" class="button button-primary">
                                            <?php echo $is_edit ? esc_html__( 'Update Rule', 'giodc-extra-fee' ) : esc_html__( 'Save Rule', 'giodc-extra-fee' ); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- WPML -->
                            <?php if ( class_exists( 'SitePress' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) : ?>
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle"><span><?php esc_html_e( 'WPML', 'giodc-extra-fee' ); ?></span></h2>
                                <div class="inside">
                                    <label class="giodc-switch-label">
                                        <input type="checkbox" name="wpml_all_langs" value="1"
                                               <?php checked( 1, (int) ( $rule['wpml_all_langs'] ?? 1 ) ); ?>>
                                        <?php esc_html_e( 'Apply to all language versions', 'giodc-extra-fee' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'When checked, the rule automatically applies to translated products/categories. IDs are normalised to the default language before comparison.', 'giodc-extra-fee' ); ?>
                                    </p>
                                </div>
                            </div>
                            <?php else : ?>
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle"><span><?php esc_html_e( 'WPML', 'giodc-extra-fee' ); ?></span></h2>
                                <div class="inside">
                                    <p class="description">
                                        <?php esc_html_e( 'WPML is not active. Install WPML to enable multilingual support.', 'giodc-extra-fee' ); ?>
                                        <input type="hidden" name="wpml_all_langs" value="0">
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Info -->
                            <div class="postbox giodc-postbox">
                                <h2 class="hndle"><span><?php esc_html_e( 'How it works', 'giodc-extra-fee' ); ?></span></h2>
                                <div class="inside">
                                    <ul class="giodc-help-list">
                                        <li><?php esc_html_e( 'The plugin counts the total quantity of matching products in the cart.', 'giodc-extra-fee' ); ?></li>
                                        <li><?php esc_html_e( 'It then finds the highest tier whose quantity is ≤ that total.', 'giodc-extra-fee' ); ?></li>
                                        <li><?php esc_html_e( 'The corresponding flat fee is added to the cart.', 'giodc-extra-fee' ); ?></li>
                                        <li><?php esc_html_e( 'Quantities above 36 use the last defined tier.', 'giodc-extra-fee' ); ?></li>
                                        <li><?php esc_html_e( 'Category rules also match products in child categories.', 'giodc-extra-fee' ); ?></li>
                                    </ul>
                                </div>
                            </div>

                        </div><!-- /#postbox-container-1 -->

                    </div><!-- /#post-body -->
                </div><!-- /#poststuff -->
            </form>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------------
    // Admin notices
    // ---------------------------------------------------------------------------

    private function render_notice( string $notice ): void {
        $messages = [
            'added'   => [ 'success', __( 'Rule added successfully.', 'giodc-extra-fee' ) ],
            'updated' => [ 'success', __( 'Rule updated successfully.', 'giodc-extra-fee' ) ],
            'deleted' => [ 'success', __( 'Rule deleted successfully.', 'giodc-extra-fee' ) ],
            'error'   => [ 'error',   __( 'An error occurred. Please try again.', 'giodc-extra-fee' ) ],
        ];

        if ( empty( $notice ) || ! isset( $messages[ $notice ] ) ) {
            return;
        }

        [ $type, $message ] = $messages[ $notice ];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    // ---------------------------------------------------------------------------
    // AJAX – Product search
    // ---------------------------------------------------------------------------

    public function ajax_search_products(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $term = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ( '' !== $term ) {
            $args['s'] = $term;
        }

        $query = new WP_Query( $args );

        $results = [];
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) {
                continue;
            }
            $sku  = $product->get_sku();
            $text = sprintf(
                '#%d – %s%s',
                $product->get_id(),
                $product->get_name(),
                $sku ? " ($sku)" : ''
            );
            $results[] = [
                'id'   => $product->get_id(),
                'text' => $text,
            ];
        }

        wp_send_json( $results );
    }

    // ---------------------------------------------------------------------------
    // AJAX – Category search
    // ---------------------------------------------------------------------------

    public function ajax_search_categories(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $term = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );

        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'search'     => $term,
            'number'     => 50,
            'orderby'    => 'name',
        ] );

        $results = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $cat ) {
                $results[] = [
                    'id'   => $cat->term_id,
                    'text' => $cat->name,
                ];
            }
        }

        wp_send_json( $results );
    }
}
