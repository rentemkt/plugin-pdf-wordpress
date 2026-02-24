<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PDFW_Plugin
{
    private static ?PDFW_Plugin $instance = null;

    private PDFW_Admin_Page $admin_page;

    private function __construct()
    {
        $this->admin_page = new PDFW_Admin_Page();
    }

    public static function instance(): PDFW_Plugin
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this->admin_page, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this->admin_page, 'enqueue_assets']);
        add_action('admin_post_pdfw_generate', [$this->admin_page, 'handle_generate']);
        add_action('wp_ajax_pdfw_import', [$this->admin_page, 'handle_import']);
        add_action('wp_ajax_pdfw_drive_scan', [$this->admin_page, 'handle_drive_scan']);
        add_action('wp_ajax_pdfw_drive_process', [$this->admin_page, 'handle_drive_process']);
        add_action('wp_ajax_pdfw_preview', [$this->admin_page, 'handle_preview']);
        add_action('wp_ajax_pdfw_projects', [$this->admin_page, 'handle_projects']);
    }
}
