<?php
/**
 * Plugin Name: PDF Ebook Studio
 * Description: Gera ebooks educacionais em HTML/PDF com temas visuais direto no WordPress.
 * Version: 0.5.0
 * Author: Rentemkt
 * Text Domain: pdf-ebook-studio
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PDFW_PLUGIN_VERSION', '0.5.0');
define('PDFW_PLUGIN_FILE', __FILE__);
define('PDFW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PDFW_PLUGIN_URL', plugin_dir_url(__FILE__));

// Activation hook — check PHP version
register_activation_hook(__FILE__, static function (): void {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'PDF Ebook Studio requer PHP 7.4 ou superior. Versão atual: ' . PHP_VERSION,
            'Requisito não atendido',
            ['back_link' => true]
        );
    }
});

// Admin notice if mbstring is absent (only on plugin page)
add_action('admin_notices', static function (): void {
    if (extension_loaded('mbstring')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen === null || $screen->id !== 'toplevel_page_pdfw-studio') {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>PDF Ebook Studio:</strong> '
        . 'extensão <code>mbstring</code> não detectada. Caracteres especiais podem não funcionar corretamente.</p></div>';
});

require_once PDFW_PLUGIN_DIR . 'includes/pdfw-compat.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-renderer.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-exporter.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-ingestor.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-admin-page.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-plugin.php';

PDFW_Plugin::instance()->boot();
