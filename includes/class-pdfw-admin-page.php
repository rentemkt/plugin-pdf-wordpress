<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Admin_Page
{
    private const OPTION_KEY = 'pdfw_last_payload';
    private const WHISPER_OPTION_KEY = 'pdfw_whisper_url';
    private const NOTICE_KEY = 'pdfw_notice';
    private const PROJECTS_OPTION_KEY = 'pdfw_projects_store';
    private const PREVIEW_CACHE_PREFIX = 'pdfw_preview_cache_';
    private const PREVIEW_CACHE_TTL = 1800;

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
        $payload['whisper_url'] = $this->sanitize_whisper_url((string) ($payload['whisper_url'] ?? ''));
        if ($payload['whisper_url'] === '') {
            $payload['whisper_url'] = $this->sanitize_whisper_url((string) get_option(self::WHISPER_OPTION_KEY, ''));
        }
        if ($payload['whisper_url'] === '') {
            $payload['whisper_url'] = PDFW_Ingestor::whisper_default_url();
        }
        $notice = get_transient(self::NOTICE_KEY);
        if (is_string($notice) && $notice !== '') {
            delete_transient(self::NOTICE_KEY);
        } else {
            $notice = '';
        }

        $themes = PDFW_Renderer::theme_options();
        $action_url = admin_url('admin-post.php');
        $preview_nonce = wp_create_nonce('pdfw_preview');
        $projects_nonce = wp_create_nonce('pdfw_projects');
        $import_nonce = wp_create_nonce('pdfw_import');

        include PDFW_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function handle_import(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_import', 'nonce');

        $payload = $this->collect_payload_from_request();
        update_option(self::OPTION_KEY, $payload, false);

        $prepared = $this->prepare_render_data($payload);
        $prepared_payload = is_array($prepared['payload']) ? $prepared['payload'] : $payload;
        $prepared_recipes = is_array($prepared['recipes']) ? $prepared['recipes'] : [];
        $prepared_recipes_raw = $this->recipes_to_raw($prepared_recipes);

        $cover_image = (string) ($prepared_payload['cover_image'] ?? '');
        if (strpos($cover_image, 'data:image/') === 0) {
            $cover_image = '';
        }

        $audit_items = $this->sanitize_audit_items(is_array($prepared['audit_items'] ?? null) ? $prepared['audit_items'] : []);

        wp_send_json_success([
            'payload' => $prepared_payload,
            'prepared_recipes_raw' => $prepared_recipes_raw,
            'recipes_count' => count($prepared_recipes),
            'cover_image' => $cover_image,
            'notice' => (string) ($prepared['notice'] ?? ''),
            'audit_items' => $audit_items,
        ]);
    }

    public function handle_drive_scan(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_import', 'nonce');

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash((string) $_POST['url'])) : '';
        $result = PDFW_Ingestor::scan_drive_structure($url);
        if (! (bool) ($result['ok'] ?? false)) {
            $logs = is_array($result['logs'] ?? null) ? $result['logs'] : [];
            $message = trim(implode("\n", array_filter(array_map('strval', $logs))));
            if ($message === '') {
                $message = 'Não foi possível listar os itens do Drive.';
            }
            wp_send_json_error(['message' => $message], 400);
        }

        wp_send_json_success([
            'items' => is_array($result['items'] ?? null) ? array_values($result['items']) : [],
            'total' => count(is_array($result['items'] ?? null) ? $result['items'] : []),
            'logs' => is_array($result['logs'] ?? null) ? $result['logs'] : [],
        ]);
    }

    public function handle_drive_process(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_import', 'nonce');

        $raw_item = isset($_POST['item']) ? wp_unslash((string) $_POST['item']) : '';
        $item = json_decode($raw_item, true);
        if (! is_array($item)) {
            wp_send_json_error(['message' => 'Item inválido para processamento.'], 400);
        }

        $result = PDFW_Ingestor::process_single_drive_item($item);
        $audit = $this->sanitize_audit_items([
            is_array($result['audit'] ?? null) ? $result['audit'] : [],
        ]);
        $audit_item = $audit[0] ?? [
            'source' => 'drive',
            'name' => sanitize_text_field((string) ($item['name'] ?? 'arquivo')),
            'kind' => 'skip',
            'recipes_count' => 0,
            'note' => '',
        ];

        $response = [
            'success' => (bool) ($result['success'] ?? false),
            'type' => sanitize_key((string) ($result['type'] ?? 'content')),
            'logs' => is_array($result['logs'] ?? null) ? $result['logs'] : [],
            'audit' => $audit_item,
            'recipes' => [],
            'images' => [],
            'prepared_recipes_raw' => '',
        ];

        if ($response['success']) {
            if ($response['type'] === 'image') {
                $image = is_array($result['data'] ?? null) ? $result['data'] : [];
                if ($image) {
                    $response['images'][] = $image;
                }
            } else {
                $recipes = is_array($result['data'] ?? null) ? $result['data'] : [];
                $response['recipes'] = $recipes;
                $response['prepared_recipes_raw'] = $this->recipes_to_raw($recipes);
            }
        }

        wp_send_json_success($response);
    }

    public function handle_standalone_transcribe(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_import', 'nonce');

        $whisper_url_override = isset($_POST['whisper_url']) ? wp_unslash((string) $_POST['whisper_url']) : '';
        $whisper_url_override = $this->sanitize_whisper_url($whisper_url_override);
        if ($whisper_url_override !== '') {
            update_option(self::WHISPER_OPTION_KEY, $whisper_url_override, false);
        }

        if (! isset($_FILES['audio_file']) || ! is_array($_FILES['audio_file'])) {
            wp_send_json_error(['message' => 'Nenhum arquivo enviado.'], 400);
        }

        $file = $_FILES['audio_file'];
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $tmp_name = (string) ($file['tmp_name'] ?? '');
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $size = isset($file['size']) ? (int) $file['size'] : 0;

        if ($error !== UPLOAD_ERR_OK || $tmp_name === '' || ! is_uploaded_file($tmp_name)) {
            wp_send_json_error(['message' => 'Falha no upload do arquivo para transcrição.'], 400);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed_ext = ['mp3', 'wav', 'm4a', 'ogg', 'mp4', 'mpeg', 'webm', 'mkv'];
        if (! in_array($ext, $allowed_ext, true)) {
            wp_send_json_error(['message' => 'Formato não suportado para transcrição.'], 400);
        }
        if ($size <= 0) {
            wp_send_json_error(['message' => 'Arquivo enviado está vazio.'], 400);
        }

        $tmp_path = wp_tempnam($name !== '' ? $name : 'audio');
        if (! is_string($tmp_path) || $tmp_path === '') {
            wp_send_json_error(['message' => 'Não foi possível preparar arquivo temporário.'], 500);
        }
        if ($ext !== '' && substr($tmp_path, -strlen('.' . $ext)) !== '.' . $ext) {
            $tmp_with_ext = $tmp_path . '.' . $ext;
            @rename($tmp_path, $tmp_with_ext);
            $tmp_path = $tmp_with_ext;
        }
        if (@move_uploaded_file($tmp_name, $tmp_path) !== true) {
            @unlink($tmp_path);
            wp_send_json_error(['message' => 'Erro ao salvar arquivo temporário para transcrição.'], 500);
        }

        $logs = [];
        $outputs = [];
        try {
            $outputs = PDFW_Ingestor::transcribe_media_outputs($tmp_path, $logs);
        } catch (\Throwable $th) {
            $logs[] = 'Erro interno durante a transcrição.';
            $outputs = [];
        } finally {
            @unlink($tmp_path);
        }

        $text = is_array($outputs) ? (string) ($outputs['text'] ?? '') : '';
        $srt = is_array($outputs) ? (string) ($outputs['srt'] ?? '') : '';
        $vtt = is_array($outputs) ? (string) ($outputs['vtt'] ?? '') : '';
        $lipsync_json = is_array($outputs) ? (string) ($outputs['lipsync_json'] ?? '') : '';

        if (trim($text) === '') {
            $message = 'Falha na transcrição.';
            if ($logs) {
                $message .= ' ' . implode(' | ', array_map('sanitize_text_field', $logs));
            }
            wp_send_json_error(['message' => $message], 500);
        }

        wp_send_json_success([
            'text' => $text,
            'srt' => $srt,
            'vtt' => $vtt,
            'lipsync_json' => $lipsync_json,
            'logs' => array_values(array_map('sanitize_text_field', $logs)),
        ]);
    }

    public function handle_generate(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        check_admin_referer('pdfw_generate');

        $payload = $this->collect_payload_from_request();
        update_option(self::OPTION_KEY, $payload, false);

        $cached = $this->get_cached_html_if_valid($payload);
        if (is_array($cached)) {
            $html = (string) ($cached['html'] ?? '');
            $import_notice = (string) ($cached['notice'] ?? '');
        } else {
            $prepared = $this->prepare_render_data($payload, false);
            $payload = $prepared['payload'];
            $final_recipes = $prepared['recipes'];
            $import_notice = $prepared['notice'];
            $html = PDFW_Renderer::render($payload, $final_recipes);
        }

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
        $preview_mode = isset($_POST['preview_mode']) ? sanitize_key((string) $_POST['preview_mode']) : 'pdf';
        if (! in_array($preview_mode, ['pdf', 'html'], true)) {
            $preview_mode = 'pdf';
        }

        $prepared = $this->prepare_render_data($payload, false);
        $prepared_payload = $prepared['payload'];
        $html = PDFW_Renderer::render($prepared_payload, $prepared['recipes']);
        $cache_key = $this->save_preview_cache($prepared_payload, $html, (string) $prepared['notice']);

        $slug = sanitize_title((string) ($prepared_payload['title'] ?? 'ebook'));
        if ($slug === '') {
            $slug = 'ebook';
        }

        $response = [
            'filename' => $slug . '.pdf',
            'notice' => (string) $prepared['notice'],
            'preview_mode' => $preview_mode,
            'cache_key' => $cache_key,
            'prepared_recipes_raw' => $this->recipes_to_raw($prepared['recipes']),
        ];

        if ($preview_mode === 'html') {
            $response['html'] = $html;
            wp_send_json_success($response);
        }

        $result = PDFW_Exporter::html_to_pdf($html);
        if (! $result['ok']) {
            $msg = trim((string) ($result['error'] ?? 'Falha ao gerar PDF de pré-visualização.'));
            if ((string) $prepared['notice'] !== '') {
                $msg .= "\n\n" . (string) $prepared['notice'];
            }
            wp_send_json_error(['message' => $msg], 500);
        }

        $response['pdf_base64'] = base64_encode((string) $result['content']);
        wp_send_json_success($response);
    }

    public function handle_projects(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_projects', 'nonce');

        $op = isset($_POST['project_op']) ? sanitize_key((string) $_POST['project_op']) : 'list';
        if (! in_array($op, ['list', 'get', 'save', 'delete'], true)) {
            wp_send_json_error(['message' => 'Operação inválida.'], 400);
        }

        if ($op === 'list') {
            $projects = $this->list_projects();
            wp_send_json_success(['projects' => $projects]);
        }

        $project_id = isset($_POST['project_id']) ? sanitize_key((string) $_POST['project_id']) : '';
        if ($project_id === '') {
            wp_send_json_error(['message' => 'Projeto não informado.'], 400);
        }

        if ($op === 'get') {
            $projects_store = $this->projects_store();
            if (! isset($projects_store[$project_id])) {
                wp_send_json_error(['message' => 'Projeto não encontrado.'], 404);
            }
            wp_send_json_success(['project' => $projects_store[$project_id]]);
        }

        if ($op === 'delete') {
            $projects_store = $this->projects_store();
            if (isset($projects_store[$project_id])) {
                unset($projects_store[$project_id]);
                $this->save_projects_store($projects_store);
            }
            wp_send_json_success(['deleted' => true]);
        }

        $name = isset($_POST['project_name']) ? sanitize_text_field((string) $_POST['project_name']) : '';
        if ($name === '') {
            $name = 'Projeto sem nome';
        }

        $client = isset($_POST['project_client']) ? sanitize_text_field((string) $_POST['project_client']) : '';
        $payload_json = isset($_POST['project_payload']) ? wp_unslash((string) $_POST['project_payload']) : '';
        $decoded_payload = json_decode($payload_json, true);
        if (! is_array($decoded_payload)) {
            wp_send_json_error(['message' => 'Payload do projeto inválido.'], 400);
        }
        $payload = $this->sanitize_project_payload($decoded_payload);

        $projects_store = $this->projects_store();
        $now = gmdate('c');
        $existing = isset($projects_store[$project_id]) && is_array($projects_store[$project_id]) ? $projects_store[$project_id] : [];
        if ($project_id === 'new' || ! preg_match('/^p_[a-z0-9]+$/', $project_id)) {
            $project_id = 'p_' . strtolower(wp_generate_password(12, false, false));
            $existing = [];
        }

        $projects_store[$project_id] = [
            'id' => $project_id,
            'name' => $name,
            'client' => $client,
            'payload' => $payload,
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];
        $this->save_projects_store($projects_store);

        wp_send_json_success([
            'project_id' => $project_id,
            'project' => $projects_store[$project_id],
            'projects' => $this->list_projects(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   payload: array<string, mixed>,
     *   recipes: array<int, array<string, mixed>>,
     *   notice: string,
     *   audit_items: array<int, array<string, mixed>>
     * }
     */
    private function prepare_render_data(array $payload, bool $run_ingestion = true): array
    {
        $imported = $run_ingestion
            ? PDFW_Ingestor::ingest(
                is_array($_FILES['source_files'] ?? null) ? $_FILES['source_files'] : [],
                (string) ($payload['drive_folder_url'] ?? '')
            )
            : [
                'recipes' => [],
                'logs' => [],
                'imported_files' => 0,
                'image_entries' => [],
                'cover_image' => '',
                'audit_items' => [],
            ];
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
            'audit_items' => is_array($imported['audit_items'] ?? null) ? $imported['audit_items'] : [],
        ];
    }

    private function collect_payload_from_request(): array
    {
        $theme = isset($_POST['theme']) ? sanitize_key((string) $_POST['theme']) : 'ebook2-classic';
        if (! array_key_exists($theme, PDFW_Renderer::theme_options())) {
            $theme = 'ebook2-classic';
        }

        $recipes_raw = isset($_POST['recipes_raw']) ? wp_unslash((string) $_POST['recipes_raw']) : '';
        $categories_raw = isset($_POST['categories_raw']) ? wp_unslash((string) $_POST['categories_raw']) : '';
        $about_raw = isset($_POST['about']) ? wp_unslash((string) $_POST['about']) : '';
        $tips_raw = isset($_POST['tips']) ? wp_unslash((string) $_POST['tips']) : '';
        $drive_folder_url = isset($_POST['drive_folder_url']) ? wp_unslash((string) $_POST['drive_folder_url']) : '';
        $cover_image = isset($_POST['cover_image']) ? wp_unslash((string) $_POST['cover_image']) : '';
        $whisper_url = isset($_POST['whisper_url']) ? wp_unslash((string) $_POST['whisper_url']) : '';
        $import_mode = isset($_POST['import_mode']) ? sanitize_key((string) $_POST['import_mode']) : 'append';
        if (! in_array($import_mode, ['append', 'replace'], true)) {
            $import_mode = 'append';
        }

        $whisper_url = $this->sanitize_whisper_url($whisper_url);
        if ($whisper_url === '') {
            $whisper_url = PDFW_Ingestor::whisper_default_url();
        }
        update_option(self::WHISPER_OPTION_KEY, $whisper_url, false);

        return [
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Ebook')),
            'subtitle' => sanitize_text_field((string) ($_POST['subtitle'] ?? 'Receitas práticas')),
            'author' => sanitize_text_field((string) ($_POST['author'] ?? 'Daniel Cady')),
            'seal' => sanitize_text_field((string) ($_POST['seal'] ?? 'Material exclusivo desenvolvido por {author}')),
            'theme' => $theme,
            'recipes_raw' => trim($recipes_raw),
            'categories_raw' => $this->sanitize_categories_raw($categories_raw),
            'about' => trim($about_raw),
            'tips' => trim($tips_raw),
            'drive_folder_url' => esc_url_raw(trim($drive_folder_url)),
            'cover_image' => $this->sanitize_image_source($cover_image),
            'whisper_url' => $whisper_url,
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
        if (strpos($value, 'file://') === 0 || strpos($value, '/') === 0) {
            $local_src = $this->sanitize_local_image_source($value);
            if ($local_src !== '') {
                return $local_src;
            }
        }
        return esc_url_raw($value);
    }

    private function sanitize_whisper_url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $clean = esc_url_raw($value);
        if ($clean === '') {
            return '';
        }

        if (preg_match('#^https?://[^\\s]+$#i', $clean) !== 1) {
            return '';
        }

        return $clean;
    }

    private function sanitize_local_image_source(string $value): string
    {
        $path = trim($value);
        if ($path === '') {
            return '';
        }

        if (strpos($path, 'file://') === 0) {
            $path = (string) preg_replace('#^file:/+#i', '/', $path);
            $path = rawurldecode($path);
        }

        $path = wp_normalize_path($path);
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        if ($base_dir === '') {
            return '';
        }

        $base_prefix = trailingslashit($base_dir);
        if ($path !== $base_dir && strpos($path, $base_prefix) !== 0) {
            return '';
        }

        return $this->path_to_file_uri($path);
    }

    private function path_to_file_uri(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $trimmed = ltrim($normalized, '/');
        $segments = array_map('rawurlencode', explode('/', $trimmed));
        return 'file:///' . implode('/', $segments);
    }

    private function sanitize_categories_raw(string $value): string
    {
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\0", '', $value);
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, 60000, 'UTF-8');
        }

        return substr($value, 0, 60000);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{source: string, name: string, kind: string, recipes_count: int, note: string}>
     */
    private function sanitize_audit_items(array $items): array
    {
        $output = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $source = sanitize_key((string) ($item['source'] ?? 'upload'));
            if (! in_array($source, ['upload', 'drive'], true)) {
                $source = 'upload';
            }

            $kind = sanitize_key((string) ($item['kind'] ?? 'skip'));
            if (! in_array($kind, ['recipe', 'generic', 'image', 'skip', 'error'], true)) {
                $kind = 'skip';
            }

            $name = sanitize_text_field((string) ($item['name'] ?? 'arquivo'));
            if ($name === '') {
                $name = 'arquivo';
            }

            $recipes_count = max(0, (int) ($item['recipes_count'] ?? 0));
            $note = sanitize_text_field((string) ($item['note'] ?? ''));

            $output[] = [
                'source' => $source,
                'name' => $name,
                'kind' => $kind,
                'recipes_count' => $recipes_count,
                'note' => $note,
            ];
        }

        return array_slice($output, 0, 400);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function sanitize_project_payload(array $input): array
    {
        $payload = [
            'title' => sanitize_text_field((string) ($input['title'] ?? 'Ebook')),
            'subtitle' => sanitize_text_field((string) ($input['subtitle'] ?? 'Receitas práticas')),
            'author' => sanitize_text_field((string) ($input['author'] ?? 'Daniel Cady')),
            'seal' => sanitize_text_field((string) ($input['seal'] ?? 'Material exclusivo desenvolvido por {author}')),
            'theme' => sanitize_key((string) ($input['theme'] ?? 'ebook2-classic')),
            'recipes_raw' => trim((string) ($input['recipes_raw'] ?? '')),
            'categories_raw' => $this->sanitize_categories_raw((string) ($input['categories_raw'] ?? '')),
            'about' => trim((string) ($input['about'] ?? '')),
            'tips' => trim((string) ($input['tips'] ?? '')),
            'drive_folder_url' => esc_url_raw(trim((string) ($input['drive_folder_url'] ?? ''))),
            'cover_image' => $this->sanitize_image_source((string) ($input['cover_image'] ?? '')),
            'whisper_url' => $this->sanitize_whisper_url((string) ($input['whisper_url'] ?? '')),
            'import_mode' => sanitize_key((string) ($input['import_mode'] ?? 'append')),
        ];

        if (! array_key_exists($payload['theme'], PDFW_Renderer::theme_options())) {
            $payload['theme'] = 'ebook2-classic';
        }
        if (! in_array($payload['import_mode'], ['append', 'replace'], true)) {
            $payload['import_mode'] = 'append';
        }
        if ($payload['whisper_url'] === '') {
            $payload['whisper_url'] = $this->sanitize_whisper_url((string) get_option(self::WHISPER_OPTION_KEY, ''));
        }
        if ($payload['whisper_url'] === '') {
            $payload['whisper_url'] = PDFW_Ingestor::whisper_default_url();
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function projects_store(): array
    {
        $stored = get_option(self::PROJECTS_OPTION_KEY, []);
        if (! is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $id => $project) {
            if (! is_string($id) || ! is_array($project)) {
                continue;
            }
            $out[$id] = $project;
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $projects
     */
    private function save_projects_store(array $projects): void
    {
        uasort($projects, static function ($a, $b) {
            $a_time = isset($a['updated_at']) ? strtotime((string) $a['updated_at']) : 0;
            $b_time = isset($b['updated_at']) ? strtotime((string) $b['updated_at']) : 0;
            if ($a_time === $b_time) {
                return 0;
            }
            return $a_time > $b_time ? -1 : 1;
        });
        update_option(self::PROJECTS_OPTION_KEY, $projects, false);
    }

    /**
     * @return array<int, array{id: string, name: string, client: string, updated_at: string}>
     */
    private function list_projects(): array
    {
        $projects = $this->projects_store();
        $list = [];
        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }
            $id = isset($project['id']) ? sanitize_key((string) $project['id']) : '';
            if ($id === '') {
                continue;
            }

            $list[] = [
                'id' => $id,
                'name' => sanitize_text_field((string) ($project['name'] ?? 'Projeto sem nome')),
                'client' => sanitize_text_field((string) ($project['client'] ?? '')),
                'updated_at' => sanitize_text_field((string) ($project['updated_at'] ?? '')),
            ];
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{html: string, notice: string}|null
     */
    private function get_cached_html_if_valid(array $payload): ?array
    {
        $cache_key = isset($_POST['preview_cache_key']) ? sanitize_key((string) $_POST['preview_cache_key']) : '';
        if ($cache_key === '' || strpos($cache_key, self::PREVIEW_CACHE_PREFIX) !== 0) {
            return null;
        }

        $cached = get_transient($cache_key);
        if (! is_array($cached)) {
            return null;
        }

        $cached_user_id = isset($cached['user_id']) ? (int) $cached['user_id'] : 0;
        if ($cached_user_id !== get_current_user_id()) {
            return null;
        }

        $cached_fingerprint = isset($cached['fingerprint']) ? (string) $cached['fingerprint'] : '';
        $current_fingerprint = $this->payload_fingerprint($payload);
        if ($cached_fingerprint === '' || ! hash_equals($cached_fingerprint, $current_fingerprint)) {
            return null;
        }

        $html = isset($cached['html']) && is_string($cached['html']) ? $cached['html'] : '';
        if ($html === '') {
            return null;
        }

        $notice = isset($cached['notice']) && is_string($cached['notice']) ? $cached['notice'] : '';
        return [
            'html' => $html,
            'notice' => $notice,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function save_preview_cache(array $payload, string $html, string $notice): string
    {
        $cache_key = $this->make_preview_cache_key();
        $cache_data = [
            'user_id' => get_current_user_id(),
            'fingerprint' => $this->payload_fingerprint($payload),
            'html' => $html,
            'notice' => $notice,
            'created_at' => time(),
        ];
        set_transient($cache_key, $cache_data, self::PREVIEW_CACHE_TTL);
        return $cache_key;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payload_fingerprint(array $payload): string
    {
        $base = [
            'payload' => $payload,
            'source_files' => $this->source_files_signature(),
        ];
        $json = wp_json_encode($base);
        if (! is_string($json) || $json === '') {
            $json = serialize($base);
        }
        return hash('sha256', $json);
    }

    /**
     * @return array<int, array{name: string, type: string, size: int, error: int}>
     */
    private function source_files_signature(): array
    {
        $files = $_FILES['source_files'] ?? null;
        if (! is_array($files)) {
            return [];
        }

        $names = isset($files['name']) && is_array($files['name']) ? $files['name'] : [];
        $types = isset($files['type']) && is_array($files['type']) ? $files['type'] : [];
        $sizes = isset($files['size']) && is_array($files['size']) ? $files['size'] : [];
        $errors = isset($files['error']) && is_array($files['error']) ? $files['error'] : [];

        $signature = [];
        foreach ($names as $index => $name) {
            $error = isset($errors[$index]) ? (int) $errors[$index] : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $signature[] = [
                'name' => sanitize_file_name((string) $name),
                'type' => sanitize_mime_type((string) ($types[$index] ?? '')),
                'size' => isset($sizes[$index]) ? (int) $sizes[$index] : 0,
                'error' => $error,
            ];
        }

        return $signature;
    }

    private function make_preview_cache_key(): string
    {
        $random = strtolower(wp_generate_password(20, false, false));
        return self::PREVIEW_CACHE_PREFIX . get_current_user_id() . '_' . $random;
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     */
    private function recipes_to_raw(array $recipes): string
    {
        $blocks = [];

        foreach ($recipes as $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $title = trim((string) ($recipe['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $category = trim((string) ($recipe['category'] ?? ''));
            $description = trim((string) ($recipe['description'] ?? ''));
            $tempo = trim((string) ($recipe['tempo'] ?? ''));
            $porcoes = trim((string) ($recipe['porcoes'] ?? ''));
            $dificuldade = trim((string) ($recipe['dificuldade'] ?? ''));
            $image = trim((string) ($recipe['image'] ?? ''));

            $ingredients = [];
            foreach ((array) ($recipe['ingredients'] ?? []) as $item) {
                $line = trim((string) $item);
                if ($line !== '') {
                    $ingredients[] = '- ' . $line;
                }
            }

            $steps = [];
            $step_count = 1;
            foreach ((array) ($recipe['steps'] ?? []) as $step) {
                $line = trim((string) $step);
                if ($line !== '') {
                    $steps[] = $step_count . '. ' . $line;
                    $step_count++;
                }
            }

            $tip = trim((string) ($recipe['tip'] ?? ''));
            $nutrition = is_array($recipe['nutrition'] ?? null) ? $recipe['nutrition'] : [];
            $nutrition_kcal = trim((string) ($nutrition['kcal'] ?? $nutrition['calorias'] ?? ''));
            $nutrition_carb = trim((string) ($nutrition['carb'] ?? $nutrition['carboidratos'] ?? ''));
            $nutrition_prot = trim((string) ($nutrition['prot'] ?? $nutrition['proteinas'] ?? $nutrition['proteínas'] ?? ''));
            $nutrition_fat = trim((string) ($nutrition['fat'] ?? $nutrition['gorduras'] ?? ''));
            $nutrition_fiber = trim((string) ($nutrition['fiber'] ?? $nutrition['fibras'] ?? ''));

            $block = [$title];
            if ($category !== '') {
                $block[] = 'Categoria: ' . $category;
            }
            if ($description !== '') {
                $block[] = 'Descrição: ' . $description;
            }
            if ($tempo !== '') {
                $block[] = 'Tempo: ' . $tempo;
            }
            if ($porcoes !== '') {
                $block[] = 'Porções: ' . $porcoes;
            }
            if ($dificuldade !== '') {
                $block[] = 'Dificuldade: ' . $dificuldade;
            }
            if ($image !== '') {
                $block[] = 'Imagem: ' . $image;
            }

            $is_generic_flag = ! empty($recipe['isGeneric']) || ! empty($recipe['is_generic']);
            $has_recipe_data = ! empty($ingredients)
                || ! empty($steps)
                || $tempo !== ''
                || $porcoes !== ''
                || $dificuldade !== '';

            if (! $is_generic_flag && $has_recipe_data) {
                $block[] = 'Ingredientes:';
                if ($ingredients) {
                    $block = array_merge($block, $ingredients);
                } else {
                    $block[] = '- Ingredientes conforme orientação nutricional.';
                }

                $block[] = 'Modo de preparo:';
                if ($steps) {
                    $block = array_merge($block, $steps);
                } else {
                    $block[] = '1. Organize os ingredientes.';
                    $block[] = '2. Faça o preparo conforme orientação.';
                }
            }

            if ($tip !== '') {
                $block[] = 'Dica:';
                $block[] = $tip;
            }

            if (
                ! $is_generic_flag
                && ($nutrition_kcal !== '' || $nutrition_carb !== '' || $nutrition_prot !== '' || $nutrition_fat !== '' || $nutrition_fiber !== '')
            ) {
                $block[] = 'Informação Nutricional:';
                if ($nutrition_kcal !== '') {
                    $block[] = 'Calorias: ' . $nutrition_kcal;
                }
                if ($nutrition_carb !== '') {
                    $block[] = 'Carboidratos: ' . $nutrition_carb;
                }
                if ($nutrition_prot !== '') {
                    $block[] = 'Proteínas: ' . $nutrition_prot;
                }
                if ($nutrition_fat !== '') {
                    $block[] = 'Gorduras: ' . $nutrition_fat;
                }
                if ($nutrition_fiber !== '') {
                    $block[] = 'Fibras: ' . $nutrition_fiber;
                }
            }

            $blocks[] = implode("\n", $block);
        }

        return implode("\n\n---\n\n", $blocks);
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
