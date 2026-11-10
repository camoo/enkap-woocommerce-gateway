<?php

declare(strict_types=1);

namespace Camoo\Enkap\WooCommerce;

defined('ABSPATH') || exit;

class Install
{
    public const PLUGIN_MAIN_FILE = 'e-nkap-woocommerce-gateway/e-nkap-woocommerce-gateway.php';

    public function __construct()
    {
        add_action('wpmu_new_blog', [$this, 'add_table_on_create_blog'], 10, 1);
        add_filter('wpmu_drop_tables', [$this, 'remove_table_on_delete_blog']);
    }

    /**
     * Creating plugin tables
     *
     * @param $network_wide
     */
    public static function install($network_wide): void
    {
        self::create_table($network_wide);

        if (!is_admin()) {
            return;
        }
        self::upgrade();
        flush_rewrite_rules();
    }

    /**
     * Creating Table for New Blog in WordPress
     *
     * @param $blogId
     */
    public function add_table_on_create_blog($blogId): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (!is_plugin_active_for_network(self::PLUGIN_MAIN_FILE)) {
            return;
        }

        switch_to_blog($blogId);
        self::table_sql();
        restore_current_blog();
    }

    /**
     * Remove Table On Delete Blog Wordpress
     *
     * @param $tables
     */
    public function remove_table_on_delete_blog($tables): array
    {
        global $wpdb;
        $tbl = 'wc_enkap_payments';
        $tables[] = $wpdb->tb_prefix . $tbl;
        delete_option('wp_wc_enkap_db_version');
        delete_option('woocommerce_' . Plugin::WC_ENKAP_GATEWAY_ID . '_settings');

        return $tables;
    }

    /** Upgrade plugin requirements if needed */
    public static function upgrade(): void
    {
        $installedVersion = get_option('wp_wc_enkap_db_version');

        if ($installedVersion !== Plugin::WP_WC_ENKAP_DB_VERSION) {
            self::table_sql();
        }
    }

    protected static function create_table($network_wide): void
    {
        global $wpdb;

        if (is_multisite() && $network_wide) {
            $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);

                self::table_sql();

                restore_current_blog();
            }
        } else {
            self::table_sql();
        }
    }

    protected static function table_sql(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $wpdb->prefix . 'wc_enkap_payments';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wc_order_id BIGINT UNSIGNED NOT NULL,
        merchant_reference_id VARCHAR(128) NOT NULL DEFAULT '',
        order_transaction_id VARCHAR(128) NOT NULL DEFAULT '',
        status VARCHAR(50) DEFAULT NULL,
        status_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        remote_ip VARBINARY(64) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY wc_order_id (wc_order_id),
        KEY merchant_reference_id (merchant_reference_id)
    ) $charset_collate;";

        dbDelta($sql);

        update_option('wp_wc_enkap_db_version', Plugin::WP_WC_ENKAP_DB_VERSION);
    }
}

(new Install());
