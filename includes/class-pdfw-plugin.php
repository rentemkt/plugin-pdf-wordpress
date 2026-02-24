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
    }
}
