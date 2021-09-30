<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\WooCommerce;

defined('ABSPATH') || exit;
if (!class_exists('\\Camoo\\Enkap\\WooCommerce\\Plugin')):

    class Plugin
    {
        public const DOMAIN_TEXT = 'wc-wp-enkap';
        protected $id;
        protected $mainMenuId;
        protected $adapterName;
        protected $title;
        protected $description;
        protected $optionKey;
        protected $settings;
        protected $adapterFile;
        protected $pluginPath;
        protected $version;
        protected $image_format = 'full';

        public function __construct($pluginPath, $adapterName, $adapterFile, $description = '', $version = null)
        {
            $this->id = basename($pluginPath, '.php');
            $this->pluginPath = $pluginPath;
            $this->adapterName = $adapterName;
            $this->adapterFile = $adapterFile;
            $this->description = $description;
            $this->version = $version;
            $this->optionKey = '';
            $this->settings = array(
                'live' => '1',
                'accountId' => '',
                'apiKey' => '',
                'notifyForStatus' => array(),
                'completeOrderForStatuses' => array()
            );

            $this->mainMenuId = 'admin.php';
            $this->title = __('E-nkap - Payment Gateway for WooCommerce', self::DOMAIN_TEXT);
        }

        private function test()
        {

        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/InstallEnkap.php';
            // do not register when WooCommerce is not enabled
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                return;
            }
            register_activation_hook($this->pluginPath, [InstallEnkap::class, 'install']);
            /*if (is_admin()) {
                add_action('admin_menu', array($this, 'onAdminMenu'));
                add_action('admin_init', array($this, 'dropdaySettingsInit'));
            }*/

            add_filter('woocommerce_payment_gateways', [$this, 'onAddGatewayClass']);
            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath), [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
            register_activation_hook($this->pluginPath, array($this, 'flush_rules'));
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_enkap_css_scripts']);

        }

        public static function enqueue_block_enkap_css_scripts(): void
        {
            wp_enqueue_style(
                'enkap_style',
                plugins_url('/assets/css/style.css', __FILE__)
            );
        }

        public function onAddGatewayClass($gateways)
        {
            $gateways[] = '\\Camoo\\Enkap\\WooCommerce\\WC_Enkap_Gateway';
            return $gateways;
        }

        public function onInit()
        {
            $this->loadGatewayClass();
            add_filter('init', [$this, 'rewrite_rules']);
            add_action('init', [__CLASS__, 'loadTextDomain']);
        }

        public function flush_rules()
        {
            $this->rewrite_rules();

            flush_rewrite_rules();
        }

        public function rewrite_rules()
        {
            add_rewrite_rule('e-nkap/return/(.+?)/?$',
                'index.php?wc-api=return_e_nkap&merchantReferenceId==$matches[1]', 'top');
            add_rewrite_tag('%merchantReferenceId%', '([^&]+)');

            add_rewrite_rule('e-nkap/notification/(.+?)/?$',
                'index.php?wc-api=notification_e_nkap&merchantReferenceId==$matches[1]', 'top');
            add_rewrite_tag('%merchantReferenceId%', '([^&]+)');
        }

        public function onPluginActionLinks($links)
        {
            $link = sprintf('<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=e_nkap'),
                __('Settings', self::DOMAIN_TEXT));
            array_unshift($links, $link);
            return $links;
        }

        public function loadGatewayClass()
        {
            if (class_exists('\\Camoo\\Enkap\\WooCommerce\\' . $this->adapterName)) {
                return;
            }
            include_once(dirname(__DIR__) . '/inc/Gateway.php');
            include_once(dirname(__DIR__) . '/vendor/autoload.php');
        }

        public static function get_webhook_url($endpoint)
        {
            return trailingslashit(get_home_url()) . 'e-nkap/' . $endpoint;
        }

        public static function getWcOrderIdByMerchantReferenceId($id_code)
        {

            global $wpdb;
            if (!wp_is_uuid($id_code)) {
                return null;
            }

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_enkap_payments` WHERE `merchant_reference_id` = %s", $id_code);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->wc_order_id;
        }

        public static function displayNotFoundPage()
        {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            get_template_part(404);
        }

        public static function getLanguageKey(): string
        {
            $local = get_locale();
            if (empty($local)) {
                return 'fr';
            }

            $localExploded = explode('_', $local);

            $lang = $localExploded[0];

            return in_array($lang, ['fr', 'en']) ? $lang : 'en';
        }

        public static function loadTextDomain(): void
        {
            load_plugin_textdomain(self::DOMAIN_TEXT, false,
                dirname(plugin_basename(__FILE__)) . '/languages');
        }
    }

endif;
