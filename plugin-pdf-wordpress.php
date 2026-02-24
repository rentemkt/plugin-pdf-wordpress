<?php
/**
 * Plugin Name: PDF Ebook Studio
 * Description: Gera ebooks em HTML/PDF com temas visuais direto no WordPress.
 * Version: 0.2.0
 * Author: Rentemkt
 * Text Domain: pdf-ebook-studio
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PDFW_PLUGIN_VERSION', '0.2.0');
define('PDFW_PLUGIN_FILE', __FILE__);
define('PDFW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PDFW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-renderer.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-exporter.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-ingestor.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-admin-page.php';
require_once PDFW_PLUGIN_DIR . 'includes/class-pdfw-plugin.php';

PDFW_Plugin::instance()->boot();
