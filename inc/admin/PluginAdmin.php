<?php

/**
 * Description of PluginAdmin
 *
 * @author Camoo
 */

namespace Camoo\Enkap\WooCommerce\Admin;

defined('ABSPATH') || exit;

if (!class_exists('Camoo\Enkap\WooCommerce\Admin\PluginAdmin')):

    class PluginAdmin
    {
        protected static $instance = null;
        protected $mainMenuId;
        protected $author;
        protected $isRegistered;


        public static function instance()
        {
            if (!isset(self::$instance)) {
                self::$instance = new PluginAdmin();
            }
            return self::$instance;
        }

        public function __construct()
        {
            $this->mainMenuId = 'e-nkap';
            $this->author = 'Camoo';

            $this->isRegistered = false;
        }

        public function register()
        {
            if ($this->isRegistered) {
                return;
            }

            $this->isRegistered = true;

            add_action('admin_menu', array($this, 'onAdminMenu'), 1);
        }

        public function onAdminMenu()
        {
            add_menu_page(
                'E-nkap',
                'E-nkap',
                'manage_options',
                $this->mainMenuId,
                array(&$this, 'display'),
                plugins_url('assets/img/multi-shipping.png', dirname(__FILE__, 2)),
                26
            );

            add_submenu_page($this->mainMenuId, 'About', 'About', 'manage_options', $this->mainMenuId);
        }

        public function display()
        {
            echo 'Bonjour';
        }
    }

endif;
