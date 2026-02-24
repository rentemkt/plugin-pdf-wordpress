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
    private const TRANSCRIBE_PROGRESS_PREFIX = 'pdfw_transcribe_progress_';
    private const TRANSCRIBE_PROGRESS_TTL = 7200;
    private const TRANSCRIBE_RESUME_PREFIX = 'pdfw_transcribe_resume_';
    private const TRANSCRIBE_RESUME_TTL = 86400;

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

        $job_id_raw = isset($_POST['job_id']) ? wp_unslash((string) $_POST['job_id']) : '';
        $job_id = $this->sanitize_transcribe_job_id($job_id_raw);
        if ($job_id === '') {
            $job_id = 'job_' . strtolower(wp_generate_password(14, false, false));
        }

        $resume_token_raw = isset($_POST['resume_token']) ? wp_unslash((string) $_POST['resume_token']) : '';
        $resume_token = $this->sanitize_transcribe_resume_token($resume_token_raw);
        $resume_entry = [];
        $has_uploaded_file = isset($_FILES['audio_file'])
            && is_array($_FILES['audio_file'])
            && (string) ($_FILES['audio_file']['tmp_name'] ?? '') !== '';
        $is_resume_request = ($resume_token !== '' && ! $has_uploaded_file);
        $source_path = '';
        $name = '';
        $ext = '';
        $resume_state = [];

        if ($is_resume_request) {
            $resume_entry = $this->get_transcribe_resume_state($resume_token);
            $source_path = $this->normalize_transcribe_source_path((string) ($resume_entry['file_path'] ?? ''));
            if ($source_path === '' || ! is_file($source_path) || ! is_readable($source_path)) {
                $this->delete_transcribe_resume_state($resume_token, true);
                wp_send_json_error([
                    'message' => 'Estado de retomada expirado ou inválido. Faça novo upload para reiniciar.',
                ], 410);
            }

            $name = sanitize_file_name((string) ($resume_entry['file_name'] ?? basename($source_path)));
            $ext = strtolower(pathinfo($name !== '' ? $name : $source_path, PATHINFO_EXTENSION));
            $resume_state = is_array($resume_entry['resume_state'] ?? null) ? $resume_entry['resume_state'] : [];
        } else {
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

            if ($resume_token === '') {
                $resume_token = $this->create_transcribe_resume_token();
            }

            $source_path = $this->store_uploaded_transcribe_source($tmp_name, $name, $ext, $resume_token);
            if ($source_path === '') {
                wp_send_json_error(['message' => 'Erro ao salvar arquivo temporário para transcrição.'], 500);
            }

            $resume_entry = [
                'file_path' => $source_path,
                'file_name' => $name,
                'resume_state' => [],
                'processed_parts' => 0,
                'total_parts' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            $this->set_transcribe_resume_state($resume_token, $resume_entry);
        }

        $this->set_transcribe_progress($job_id, [
            'stage' => 'queued',
            'percent' => 1,
            'message' => $is_resume_request
                ? 'Retomada solicitada. Preparando continuação da transcrição...'
                : 'Arquivo recebido. Preparando transcrição...',
            'current' => 0,
            'total' => 0,
            'chunk_index' => 0,
            'chunk_text' => '',
            'last_chunk_index' => 0,
            'last_chunk_text' => '',
            'updated_at' => time(),
        ]);

        $logs = [];
        $outputs = [];
        $this->set_transcribe_progress($job_id, [
            'stage' => 'starting',
            'percent' => 3,
            'message' => $is_resume_request
                ? 'Retomando processamento do arquivo...'
                : 'Iniciando processamento do arquivo...',
            'current' => 0,
            'total' => 0,
            'chunk_index' => 0,
            'chunk_text' => '',
            'last_chunk_index' => 0,
            'last_chunk_text' => '',
            'updated_at' => time(),
        ]);
        try {
            $progress_cb = function (array $progress) use ($job_id, $resume_token, $source_path, $name, &$resume_entry): void {
                $stage = sanitize_key((string) ($progress['stage'] ?? 'working'));
                $percent = max(0, min(100, (int) ($progress['percent'] ?? 0)));
                $message = sanitize_text_field((string) ($progress['message'] ?? 'Processando...'));
                $current = max(0, (int) ($progress['current'] ?? 0));
                $total = max(0, (int) ($progress['total'] ?? 0));
                $chunk_index = max(0, (int) ($progress['chunk_index'] ?? 0));
                $chunk_text = trim((string) ($progress['chunk_text'] ?? ''));
                $last_chunk_index = max(0, (int) ($progress['last_chunk_index'] ?? 0));
                $last_chunk_text = trim((string) ($progress['last_chunk_text'] ?? ''));
                if ($chunk_text !== '' && function_exists('mb_substr')) {
                    $chunk_text = (string) mb_substr($chunk_text, 0, 20000, 'UTF-8');
                } elseif ($chunk_text !== '') {
                    $chunk_text = substr($chunk_text, 0, 20000);
                }
                if ($last_chunk_text !== '' && function_exists('mb_substr')) {
                    $last_chunk_text = (string) mb_substr($last_chunk_text, 0, 20000, 'UTF-8');
                } elseif ($last_chunk_text !== '') {
                    $last_chunk_text = substr($last_chunk_text, 0, 20000);
                }
                $this->set_transcribe_progress($job_id, [
                    'stage' => $stage,
                    'percent' => $percent,
                    'message' => $message,
                    'current' => $current,
                    'total' => $total,
                    'chunk_index' => $chunk_index,
                    'chunk_text' => $chunk_text,
                    'last_chunk_index' => $last_chunk_index,
                    'last_chunk_text' => $last_chunk_text,
                    'updated_at' => time(),
                ]);

                $resume_checkpoint = is_array($progress['resume_state'] ?? null) ? $progress['resume_state'] : [];
                if ($resume_token !== '' && $source_path !== '' && is_file($source_path) && $resume_checkpoint) {
                    $created_at = (int) ($resume_entry['created_at'] ?? time());
                    $this->set_transcribe_resume_state($resume_token, [
                        'file_path' => $source_path,
                        'file_name' => $name !== '' ? $name : basename($source_path),
                        'resume_state' => $resume_checkpoint,
                        'processed_parts' => $current,
                        'total_parts' => $total,
                        'created_at' => $created_at,
                        'updated_at' => time(),
                    ]);
                    $resume_entry['resume_state'] = $resume_checkpoint;
                    $resume_entry['processed_parts'] = $current;
                    $resume_entry['total_parts'] = $total;
                }
            };
            $outputs = PDFW_Ingestor::transcribe_media_outputs($source_path, $logs, $progress_cb, $resume_state);
        } catch (\Throwable $th) {
            $logs[] = 'Erro interno durante a transcrição.';
            $outputs = [];
            $this->set_transcribe_progress($job_id, [
                'stage' => 'error',
                'percent' => 100,
                'message' => 'Falha interna durante a transcrição.',
                'current' => 0,
                'total' => 0,
                'chunk_index' => 0,
                'chunk_text' => '',
                'last_chunk_index' => 0,
                'last_chunk_text' => '',
                'updated_at' => time(),
            ]);
        }

        $text = is_array($outputs) ? (string) ($outputs['text'] ?? '') : '';
        $srt = is_array($outputs) ? (string) ($outputs['srt'] ?? '') : '';
        $vtt = is_array($outputs) ? (string) ($outputs['vtt'] ?? '') : '';
        $lipsync_json = is_array($outputs) ? (string) ($outputs['lipsync_json'] ?? '') : '';
        $partial = is_array($outputs) ? (bool) ($outputs['partial'] ?? false) : false;
        $failed_part = is_array($outputs) ? max(0, (int) ($outputs['failed_part'] ?? 0)) : 0;
        $processed_parts = is_array($outputs) ? max(0, (int) ($outputs['processed_parts'] ?? 0)) : 0;
        $total_parts = is_array($outputs) ? max(0, (int) ($outputs['total_parts'] ?? 0)) : 0;
        $resume_state_out = is_array($outputs) && is_array($outputs['resume_state'] ?? null)
            ? $outputs['resume_state']
            : [];
        $resume_next_part = $failed_part > 0 ? $failed_part : ($processed_parts > 0 ? ($processed_parts + 1) : 1);
        if ($total_parts > 0 && $resume_next_part > ($total_parts + 1)) {
            $resume_next_part = $total_parts + 1;
        }
        $existing_progress = get_transient($this->transcribe_progress_key($job_id));
        $existing_last_chunk_index = is_array($existing_progress) ? max(0, (int) ($existing_progress['last_chunk_index'] ?? 0)) : 0;
        $existing_last_chunk_text = is_array($existing_progress) ? trim((string) ($existing_progress['last_chunk_text'] ?? '')) : '';

        if (trim($text) === '') {
            $message = 'Falha na transcrição.';
            if ($logs) {
                $message .= ' ' . implode(' | ', array_map('sanitize_text_field', $logs));
            }
            $this->set_transcribe_progress($job_id, [
                'stage' => 'error',
                'percent' => 100,
                'message' => $message,
                'current' => $processed_parts,
                'total' => $total_parts,
                'chunk_index' => 0,
                'chunk_text' => '',
                'last_chunk_index' => 0,
                'last_chunk_text' => '',
                'updated_at' => time(),
            ]);
            if ($resume_token !== '' && $source_path !== '' && is_file($source_path)) {
                $this->set_transcribe_resume_state($resume_token, [
                    'file_path' => $source_path,
                    'file_name' => $name !== '' ? $name : basename($source_path),
                    'resume_state' => $resume_state_out ?: $resume_state,
                    'processed_parts' => $processed_parts,
                    'total_parts' => $total_parts,
                    'updated_at' => time(),
                    'created_at' => (int) ($resume_entry['created_at'] ?? time()),
                ]);
            }
            wp_send_json_error([
                'message' => $message,
                'resume_token' => $resume_token,
                'failed_part' => $failed_part,
                'processed_parts' => $processed_parts,
                'total_parts' => $total_parts,
                'resume_next_part' => max(1, $resume_next_part),
            ], 500);
        }

        if ($partial) {
            $this->set_transcribe_progress($job_id, [
                'stage' => 'partial',
                'percent' => 97,
                'message' => "Transcrição parcial disponível ({$processed_parts}/{$total_parts} partes).",
                'current' => $processed_parts,
                'total' => $total_parts,
                'chunk_index' => 0,
                'chunk_text' => '',
                'last_chunk_index' => max($existing_last_chunk_index, $processed_parts),
                'last_chunk_text' => $existing_last_chunk_text,
                'updated_at' => time(),
            ]);
            if ($resume_token !== '' && $source_path !== '' && is_file($source_path)) {
                $this->set_transcribe_resume_state($resume_token, [
                    'file_path' => $source_path,
                    'file_name' => $name !== '' ? $name : basename($source_path),
                    'resume_state' => $resume_state_out ?: $resume_state,
                    'processed_parts' => $processed_parts,
                    'total_parts' => $total_parts,
                    'updated_at' => time(),
                    'created_at' => (int) ($resume_entry['created_at'] ?? time()),
                ]);
            }
        } else {
            $this->set_transcribe_progress($job_id, [
                'stage' => 'done',
                'percent' => 100,
                'message' => 'Transcrição concluída.',
                'current' => $processed_parts,
                'total' => $total_parts,
                'chunk_index' => 0,
                'chunk_text' => '',
                'last_chunk_index' => max($existing_last_chunk_index, $processed_parts),
                'last_chunk_text' => $existing_last_chunk_text,
                'updated_at' => time(),
            ]);
            if ($resume_token !== '') {
                $this->delete_transcribe_resume_state($resume_token, true);
            }
        }

        wp_send_json_success([
            'text' => $text,
            'srt' => $srt,
            'vtt' => $vtt,
            'lipsync_json' => $lipsync_json,
            'partial' => $partial,
            'failed_part' => $failed_part,
            'processed_parts' => $processed_parts,
            'total_parts' => $total_parts,
            'job_id' => $job_id,
            'resume_token' => $partial ? $resume_token : '',
            'resume_next_part' => $partial ? max(1, $resume_next_part) : 0,
            'logs' => array_values(array_map('sanitize_text_field', $logs)),
        ]);
    }

    public function handle_transcribe_progress(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }

        check_ajax_referer('pdfw_import', 'nonce');

        $job_id_raw = isset($_POST['job_id']) ? wp_unslash((string) $_POST['job_id']) : '';
        $job_id = $this->sanitize_transcribe_job_id($job_id_raw);
        if ($job_id === '') {
            wp_send_json_error(['message' => 'Job inválido.'], 400);
        }

        $progress = get_transient($this->transcribe_progress_key($job_id));
        if (! is_array($progress)) {
            wp_send_json_success([
                'stage' => 'idle',
                'percent' => 0,
                'message' => 'Sem progresso ativo para este job.',
                'current' => 0,
                'total' => 0,
                'chunk_index' => 0,
                'chunk_text' => '',
                'last_chunk_index' => 0,
                'last_chunk_text' => '',
            ]);
            return;
        }

        $chunk_text = trim((string) ($progress['chunk_text'] ?? ''));
        $last_chunk_text = trim((string) ($progress['last_chunk_text'] ?? ''));

        wp_send_json_success([
            'stage' => sanitize_key((string) ($progress['stage'] ?? 'working')),
            'percent' => max(0, min(100, (int) ($progress['percent'] ?? 0))),
            'message' => sanitize_text_field((string) ($progress['message'] ?? 'Processando...')),
            'current' => max(0, (int) ($progress['current'] ?? 0)),
            'total' => max(0, (int) ($progress['total'] ?? 0)),
            'chunk_index' => max(0, (int) ($progress['chunk_index'] ?? 0)),
            'chunk_text' => $chunk_text,
            'last_chunk_index' => max(0, (int) ($progress['last_chunk_index'] ?? 0)),
            'last_chunk_text' => $last_chunk_text,
            'updated_at' => max(0, (int) ($progress['updated_at'] ?? 0)),
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

        // Warn if user has pending file uploads
        if ($this->has_pending_uploads()) {
            set_transient(self::NOTICE_KEY, 'Arquivos pendentes detectados. Importe o conteúdo primeiro na aba Importação antes de gerar o PDF.', 90);
            wp_safe_redirect(admin_url('admin.php?page=pdfw-studio'));
            exit;
        }

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

        // Warn if user has pending file uploads
        $pending_notice = '';
        if ($this->has_pending_uploads()) {
            $pending_notice = 'Arquivos pendentes detectados. Importe o conteúdo primeiro na aba Importação.';
        }

        $prepared = $this->prepare_render_data($payload, false);
        $prepared_payload = $prepared['payload'];
        $html = PDFW_Renderer::render($prepared_payload, $prepared['recipes']);
        $notice = (string) $prepared['notice'];
        if ($pending_notice !== '') {
            $notice = $pending_notice . ($notice !== '' ? "\n" . $notice : '');
        }
        $cache_key = $this->save_preview_cache($prepared_payload, $html, $notice);

        $slug = sanitize_title((string) ($prepared_payload['title'] ?? 'ebook'));
        if ($slug === '') {
            $slug = 'ebook';
        }

        $response = [
            'filename' => $slug . '.pdf',
            'notice' => $notice,
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
            // Limpa payload salvo para que itens do projeto excluído não persistam no reload
            update_option(self::OPTION_KEY, PDFW_Renderer::default_payload(), false);
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

        // Sync last_payload so page reload reflects the saved project
        update_option(self::OPTION_KEY, $payload, false);

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

    private function has_pending_uploads(): bool
    {
        // Check local file uploads
        $files = $_FILES['source_files'] ?? null;
        if (is_array($files) && ! empty($files['name'])) {
            $names = is_array($files['name']) ? $files['name'] : [];
            $errors = is_array($files['error'] ?? null) ? $files['error'] : [];
            foreach ($names as $idx => $name) {
                if ((string) $name !== '' && (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    return true;
                }
            }
        }

        // Check Drive URL filled but content not yet imported
        $drive_url = isset($_POST['drive_folder_url']) ? trim((string) $_POST['drive_folder_url']) : '';
        if ($drive_url !== '') {
            $recipes_raw = isset($_POST['recipes_raw']) ? trim((string) $_POST['recipes_raw']) : '';
            if ($recipes_raw === '' || $recipes_raw === trim(PDFW_Renderer::default_payload()['recipes_raw'])) {
                return true;
            }
        }

        return false;
    }

    private function sanitize_image_source(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'data:image/') === 0) {
            // Reject data URIs larger than 4 MB to prevent bloated payloads
            if (strlen($value) > 4 * 1024 * 1024) {
                return '';
            }
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

    private function sanitize_transcribe_job_id(string $value): string
    {
        $raw = strtolower(trim($value));
        if ($raw === '') {
            return '';
        }

        $clean = preg_replace('/[^a-z0-9_-]/', '', $raw);
        if (! is_string($clean) || $clean === '') {
            return '';
        }

        return substr($clean, 0, 64);
    }

    private function sanitize_transcribe_resume_token(string $value): string
    {
        $raw = strtolower(trim($value));
        if ($raw === '') {
            return '';
        }

        $clean = preg_replace('/[^a-z0-9_-]/', '', $raw);
        if (! is_string($clean) || $clean === '') {
            return '';
        }

        return substr($clean, 0, 64);
    }

    private function create_transcribe_resume_token(): string
    {
        return 'rsm_' . strtolower(wp_generate_password(16, false, false));
    }

    private function transcribe_resume_key(string $resume_token): string
    {
        return self::TRANSCRIBE_RESUME_PREFIX . $resume_token;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_transcribe_resume_state(string $resume_token): array
    {
        if ($resume_token === '') {
            return [];
        }
        $state = get_transient($this->transcribe_resume_key($resume_token));
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function set_transcribe_resume_state(string $resume_token, array $state): void
    {
        if ($resume_token === '') {
            return;
        }

        $file_path = $this->normalize_transcribe_source_path((string) ($state['file_path'] ?? ''));
        if ($file_path === '' || ! is_file($file_path) || ! is_readable($file_path)) {
            return;
        }

        $file_name = sanitize_file_name((string) ($state['file_name'] ?? basename($file_path)));
        if ($file_name === '') {
            $file_name = basename($file_path);
        }

        $resume_state = is_array($state['resume_state'] ?? null) ? $state['resume_state'] : [];
        $processed_parts = max(0, (int) ($state['processed_parts'] ?? 0));
        $total_parts = max(0, (int) ($state['total_parts'] ?? 0));

        $payload = [
            'file_path' => $file_path,
            'file_name' => $file_name,
            'resume_state' => $resume_state,
            'processed_parts' => $processed_parts,
            'total_parts' => $total_parts,
            'created_at' => max(0, (int) ($state['created_at'] ?? time())),
            'updated_at' => time(),
        ];

        set_transient($this->transcribe_resume_key($resume_token), $payload, self::TRANSCRIBE_RESUME_TTL);
    }

    private function delete_transcribe_resume_state(string $resume_token, bool $delete_file = false): void
    {
        if ($resume_token === '') {
            return;
        }

        $existing = $this->get_transcribe_resume_state($resume_token);
        delete_transient($this->transcribe_resume_key($resume_token));

        if (! $delete_file) {
            return;
        }

        $file_path = $this->normalize_transcribe_source_path((string) ($existing['file_path'] ?? ''));
        if ($file_path !== '' && is_file($file_path)) {
            @unlink($file_path);
        }
    }

    private function ensure_transcribe_temp_dir(): string
    {
        $uploads = wp_get_upload_dir();
        $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        if ($base_dir === '') {
            return '';
        }

        $temp_dir = wp_normalize_path(trailingslashit($base_dir) . 'pdfw-temp');
        if (! is_dir($temp_dir) && ! wp_mkdir_p($temp_dir)) {
            return '';
        }

        return $temp_dir;
    }

    private function normalize_transcribe_source_path(string $path): string
    {
        $path = wp_normalize_path(trim($path));
        if ($path === '') {
            return '';
        }

        $temp_dir = $this->ensure_transcribe_temp_dir();
        if ($temp_dir === '') {
            return '';
        }

        $allowed_prefix = trailingslashit($temp_dir);
        if ($path !== $temp_dir && strpos($path, $allowed_prefix) !== 0) {
            return '';
        }

        return $path;
    }

    private function store_uploaded_transcribe_source(string $tmp_name, string $name, string $ext, string $resume_token): string
    {
        $temp_dir = $this->ensure_transcribe_temp_dir();
        if ($temp_dir === '') {
            return '';
        }

        $base = sanitize_file_name((string) pathinfo($name, PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'audio';
        }

        $clean_ext = strtolower(preg_replace('/[^a-z0-9]/', '', $ext));
        if ($clean_ext === '') {
            $clean_ext = 'bin';
        }

        $filename_seed = sprintf('transcribe-%s-%s.%s', substr($resume_token, 0, 16), substr($base, 0, 60), $clean_ext);
        $filename = wp_unique_filename($temp_dir, $filename_seed);
        $target_path = wp_normalize_path(trailingslashit($temp_dir) . $filename);

        if (@move_uploaded_file($tmp_name, $target_path) !== true) {
            return '';
        }

        return $target_path;
    }

    private function transcribe_progress_key(string $job_id): string
    {
        return self::TRANSCRIBE_PROGRESS_PREFIX . $job_id;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function set_transcribe_progress(string $job_id, array $progress): void
    {
        if ($job_id === '') {
            return;
        }

        set_transient($this->transcribe_progress_key($job_id), $progress, self::TRANSCRIBE_PROGRESS_TTL);
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

            $tmp_names = isset($files['tmp_name']) && is_array($files['tmp_name']) ? $files['tmp_name'] : [];
            $tmp = isset($tmp_names[$index]) ? (string) $tmp_names[$index] : '';
            $content_hash = '';
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                $content_hash = hash_file('sha256', $tmp) ?: '';
            }

            $signature[] = [
                'name' => sanitize_file_name((string) $name),
                'type' => sanitize_mime_type((string) ($types[$index] ?? '')),
                'size' => isset($sizes[$index]) ? (int) $sizes[$index] : 0,
                'error' => $error,
                'hash' => $content_hash,
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

            // Educational fields
            $edu_duration = trim((string) ($recipe['duration'] ?? ''));
            $edu_level = trim((string) ($recipe['level'] ?? ''));
            $edu_body = trim((string) ($recipe['body'] ?? ''));
            $edu_summary = trim((string) ($recipe['summary'] ?? ''));
            $edu_key_points = is_array($recipe['keyPoints'] ?? null) ? $recipe['keyPoints'] : [];

            if ($is_generic_flag || ! $has_recipe_data) {
                // Serialize educational fields
                if ($edu_duration !== '') {
                    $block[] = 'Duração: ' . $edu_duration;
                }
                if ($edu_level !== '') {
                    $block[] = 'Nível: ' . $edu_level;
                }
                if ($edu_body !== '') {
                    $block[] = '';
                    $block[] = $edu_body;
                }
                if ($edu_key_points) {
                    $block[] = '';
                    $block[] = 'Pontos-chave:';
                    foreach ($edu_key_points as $kp) {
                        $kp_text = trim((string) $kp);
                        if ($kp_text !== '') {
                            $block[] = '- ' . $kp_text;
                        }
                    }
                }
                if ($edu_summary !== '') {
                    $block[] = '';
                    $block[] = 'Resumo: ' . $edu_summary;
                }
            } else {
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
