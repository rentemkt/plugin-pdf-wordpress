<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Admin_Page
{
    private const OPTION_KEY = 'pdfw_last_payload';
    private const NOTICE_KEY = 'pdfw_notice';

    public function register_menu(): void
    {
        add_menu_page(
            'PDF Ebook Studio',
            'PDF Ebook Studio',
            'manage_options',
            'pdfw-studio',
            [$this, 'render_page'],
            'dashicons-media-document',
            58
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_pdfw-studio') {
            return;
        }

        wp_enqueue_style(
            'pdfw-admin',
            PDFW_PLUGIN_URL . 'assets/css/admin.css',
            [],
            PDFW_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'pdfw-admin',
            PDFW_PLUGIN_URL . 'assets/js/admin.js',
            [],
            PDFW_PLUGIN_VERSION,
            true
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        $defaults = PDFW_Renderer::default_payload();
        $saved = get_option(self::OPTION_KEY, []);
        $payload = wp_parse_args(is_array($saved) ? $saved : [], $defaults);
        $notice = get_transient(self::NOTICE_KEY);
        if (is_string($notice) && $notice !== '') {
            delete_transient(self::NOTICE_KEY);
        } else {
            $notice = '';
        }

        $themes = PDFW_Renderer::theme_options();
        $action_url = admin_url('admin-post.php');
        $preview_nonce = wp_create_nonce('pdfw_preview');

        include PDFW_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function handle_generate(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        check_admin_referer('pdfw_generate');

        $payload = $this->collect_payload_from_request();
        update_option(self::OPTION_KEY, $payload, false);

        $prepared = $this->prepare_render_data($payload);
        $payload = $prepared['payload'];
        $final_recipes = $prepared['recipes'];
        $import_notice = $prepared['notice'];

        $html = PDFW_Renderer::render($payload, $final_recipes);
        $slug = sanitize_title($payload['title']);
        if ($slug === '') {
            $slug = 'ebook';
        }

        $output = isset($_POST['pdfw_output']) ? sanitize_key((string) $_POST['pdfw_output']) : 'pdf';
        if ($output === 'html') {
            $this->download_html($html, $slug . '.html');
        }

        $result = PDFW_Exporter::html_to_pdf($html);
        if (! $result['ok']) {
            $msg = trim($result['error']);
            if ($import_notice !== '') {
                $msg .= "\n\n" . $import_notice;
            }
            set_transient(self::NOTICE_KEY, $msg, 90);
            wp_safe_redirect(admin_url('admin.php?page=pdfw-studio'));
            exit;
        }

        $this->download_pdf((string) $result['content'], $slug . '.pdf');
    }

    public function handle_preview(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_preview', 'nonce');

        $payload = $this->collect_payload_from_request();
        update_option(self::OPTION_KEY, $payload, false);

        $prepared = $this->prepare_render_data($payload);
        $html = PDFW_Renderer::render($prepared['payload'], $prepared['recipes']);
        $result = PDFW_Exporter::html_to_pdf($html);

        if (! $result['ok']) {
            $msg = trim((string) ($result['error'] ?? 'Falha ao gerar PDF de pré-visualização.'));
            if ($prepared['notice'] !== '') {
                $msg .= "\n\n" . $prepared['notice'];
            }
            wp_send_json_error(['message' => $msg], 500);
        }

        $slug = sanitize_title((string) ($prepared['payload']['title'] ?? 'ebook'));
        if ($slug === '') {
            $slug = 'ebook';
        }

        wp_send_json_success([
            'pdf_base64' => base64_encode((string) $result['content']),
            'filename' => $slug . '.pdf',
            'notice' => $prepared['notice'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, recipes: array<int, array<string, mixed>>, notice: string}
     */
    private function prepare_render_data(array $payload): array
    {
        $imported = PDFW_Ingestor::ingest(
            is_array($_FILES['source_files'] ?? null) ? $_FILES['source_files'] : [],
            (string) ($payload['drive_folder_url'] ?? '')
        );
        $manual_recipes = PDFW_Renderer::recipes_from_raw((string) ($payload['recipes_raw'] ?? ''));
        $imported_recipes = is_array($imported['recipes'] ?? null) ? $imported['recipes'] : [];

        if (($payload['import_mode'] ?? 'append') === 'replace' && ! empty($imported_recipes)) {
            $final_recipes = $imported_recipes;
        } else {
            $final_recipes = PDFW_Renderer::merge_recipes($manual_recipes, $imported_recipes);
        }

        $image_entries = is_array($imported['image_entries'] ?? null) ? $imported['image_entries'] : [];
        $final_recipes = PDFW_Renderer::apply_images($final_recipes, $image_entries);

        $cover_image = (string) ($payload['cover_image'] ?? '');
        if ($cover_image === '') {
            $cover_image = (string) ($imported['cover_image'] ?? '');
        }
        if ($cover_image === '') {
            $cover_image = PDFW_Renderer::pick_cover_image($image_entries);
        }
        $payload['cover_image'] = $this->sanitize_image_source($cover_image);

        return [
            'payload' => $payload,
            'recipes' => $final_recipes,
            'notice' => PDFW_Ingestor::logs_to_notice(is_array($imported['logs'] ?? null) ? $imported['logs'] : []),
        ];
    }

    private function collect_payload_from_request(): array
    {
        $theme = isset($_POST['theme']) ? sanitize_key((string) $_POST['theme']) : 'ebook2-classic';
        if (! array_key_exists($theme, PDFW_Renderer::theme_options())) {
            $theme = 'ebook2-classic';
        }

        $recipes_raw = isset($_POST['recipes_raw']) ? wp_unslash((string) $_POST['recipes_raw']) : '';
        $about_raw = isset($_POST['about']) ? wp_unslash((string) $_POST['about']) : '';
        $tips_raw = isset($_POST['tips']) ? wp_unslash((string) $_POST['tips']) : '';
        $drive_folder_url = isset($_POST['drive_folder_url']) ? wp_unslash((string) $_POST['drive_folder_url']) : '';
        $cover_image = isset($_POST['cover_image']) ? wp_unslash((string) $_POST['cover_image']) : '';
        $import_mode = isset($_POST['import_mode']) ? sanitize_key((string) $_POST['import_mode']) : 'append';
        if (! in_array($import_mode, ['append', 'replace'], true)) {
            $import_mode = 'append';
        }

        return [
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Ebook')),
            'subtitle' => sanitize_text_field((string) ($_POST['subtitle'] ?? 'Receitas práticas')),
            'author' => sanitize_text_field((string) ($_POST['author'] ?? 'Daniel Cady')),
            'seal' => sanitize_text_field((string) ($_POST['seal'] ?? 'Material exclusivo desenvolvido por {author}')),
            'theme' => $theme,
            'recipes_raw' => trim($recipes_raw),
            'about' => trim($about_raw),
            'tips' => trim($tips_raw),
            'drive_folder_url' => esc_url_raw(trim($drive_folder_url)),
            'cover_image' => $this->sanitize_image_source($cover_image),
            'import_mode' => $import_mode,
        ];
    }

    private function sanitize_image_source(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'data:image/') === 0) {
            return $value;
        }
        return esc_url_raw($value);
    }

    private function download_html(string $html, string $filename): void
    {
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit;
    }

    private function download_pdf(string $pdf_content, string $filename): void
    {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $pdf_content;
        exit;
    }
}
