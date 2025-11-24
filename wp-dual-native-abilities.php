<?php
/**
 * Plugin Name: Dual-Native Abilities Bridge
 * Description: Bridges Dual-Native Internal AI (DNI) with the Abilities API and the WordPress AI Client SDK. Registers content read/write/catalog/AI abilities and exposes REST fallbacks.
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Antun Jurkovikj & Contributors
 */

if (!defined('ABSPATH')) { exit; }

define('DNAB_VERSION', '0.1.1');
define('DNAB_DIR', plugin_dir_path(__FILE__));
define('DNAB_URL', plugin_dir_url(__FILE__));

require_once DNAB_DIR . 'includes/class-dnab-abilities-bridge.php';
require_once DNAB_DIR . 'includes/rest/class-dnab-rest.php';
require_once DNAB_DIR . 'includes/admin/class-dnab-admin.php';
require_once DNAB_DIR . 'includes/admin/class-dnab-chat.php';

add_action('plugins_loaded', function(){
    // Ensure DNI core is active before initializing bridge functionality
    if (!class_exists('DNI_MR') || !class_exists('DNI_CID')){
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p>Dual-Native Abilities Bridge requires the Dual-Native Internal AI plugin to be active.</p></div>';
        });
        return;
    }

    // REST fallback for testing and non-Abilities environments
    DNAB_REST::init();

    // Register abilities if Abilities API is available
    DNAB_Abilities_Bridge::maybe_init();

    // Admin console
    DNAB_Admin::init();

    // Admin chat (tools)
    DNAB_Chat::init();
});
