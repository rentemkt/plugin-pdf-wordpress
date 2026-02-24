<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Ingestor
{
    private const MAX_FILES = 80;
    private const MAX_DEPTH = 4;
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const MAX_AUDIO_FILE_BYTES = 200 * 1024 * 1024;
    private const IMAGE_INLINE_MAX_BYTES = 500 * 1024;
    private const IMAGE_INLINE_MAX_COUNT = 16;
    private const IMAGE_INLINE_TOTAL_BYTES = 6 * 1024 * 1024;
    private const TEMP_IMAGE_TTL = 604800;
    private const TRANSCRIBE_TIMEOUT = 600;
    private const MAX_TRANSCRIBE_RESPONSE_BYTES = 32 * 1024 * 1024;
    private const TRANSCRIBE_STREAM_CHUNK_BYTES = 65536;
    private const DEFAULT_WHISPER_URL = 'https://transcrever.rente.com.br/v1/audio/transcriptions';

    private static int $inline_image_count = 0;
    private static int $inline_image_total_bytes = 0;
    private static bool $temp_cleanup_done = false;

    /** @var array<string, bool> */
    private static array $skip_name_map = [
        'estrutura' => true,
        'sumario' => true,
        'sumário' => true,
        'plano' => true,
        'documentacao' => true,
        'documentação' => true,
        'readme' => true,
        'identidade' => true,
    ];

    /** @var array<string, bool> */
    private static array $supported_text_ext_map = [
        'txt' => true,
        'md' => true,
        'html' => true,
        'htm' => true,
        'docx' => true,
        'pdf' => true,
        'pptx' => true,
    ];

    /** @var array<string, bool> */
    private static array $supported_image_ext_map = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'webp' => true,
    ];

    /** @var array<string, bool> */
    private static array $supported_audio_ext_map = [
        'mp3' => true,
        'wav' => true,
        'm4a' => true,
        'ogg' => true,
        'mp4' => true,
        'mpeg' => true,
        'webm' => true,
        'mkv' => true,
    ];

    /**
     * @param array<string, mixed> $uploaded_files
     * @return array{
     *   recipes: array<int, array<string, mixed>>,
     *   logs: array<int, string>,
     *   imported_files: int,
     *   image_entries: array<int, array<string, mixed>>,
     *   cover_image: string,
     *   audit_items: array<int, array<string, mixed>>
     * }
     */
    public static function ingest(array $uploaded_files, string $drive_folder_url = ''): array
    {
        self::reset_image_runtime_state();

        $logs = [];
        $recipes = [];
        $imported_files = 0;
        $image_entries = [];
        $cover_image = '';
        $audit_items = [];

        $local_files = self::normalize_uploaded_files($uploaded_files);
        if ($local_files) {
            foreach ($local_files as $file) {
                $name = (string) $file['name'];
                $tmp = (string) $file['tmp_name'];
                if (! is_file($tmp)) {
                    $logs[] = "Arquivo ignorado (tmp ausente): {$name}";
                    continue;
                }

                $parsed = self::parse_file_from_path($tmp, $name, $logs);
                if (is_array($parsed['audit'] ?? null)) {
                    $audit = $parsed['audit'];
                    $audit['source'] = 'upload';
                    $audit_items[] = $audit;
                }
                if (! empty($parsed['recipes'])) {
                    $imported_files++;
                    $recipes = array_merge($recipes, $parsed['recipes']);
                }
                if (! empty($parsed['image_entry'])) {
                    $image_entries[] = $parsed['image_entry'];
                    if ($cover_image === '' && self::is_cover_image_name($name)) {
                        $cover_image = (string) $parsed['image_entry']['src'];
                    }
                }
            }
        }

        $drive_folder_url = trim($drive_folder_url);
        if ($drive_folder_url !== '') {
            $drive_result = self::ingest_from_drive_folder($drive_folder_url);
            $logs = array_merge($logs, $drive_result['logs']);
            $imported_files += (int) $drive_result['imported_files'];
            $recipes = array_merge($recipes, $drive_result['recipes']);
            $image_entries = array_merge($image_entries, $drive_result['image_entries']);
            $audit_items = array_merge($audit_items, is_array($drive_result['audit_items'] ?? null) ? $drive_result['audit_items'] : []);
            if ($cover_image === '' && (string) ($drive_result['cover_image'] ?? '') !== '') {
                $cover_image = (string) $drive_result['cover_image'];
            }
        }

        $recipes = self::dedupe_recipes($recipes);
        $image_entries = self::unique_image_entries($image_entries);
        return [
            'recipes' => $recipes,
            'logs' => $logs,
            'imported_files' => $imported_files,
            'image_entries' => $image_entries,
            'cover_image' => $cover_image,
            'audit_items' => $audit_items,
        ];
    }

    public static function whisper_default_url(): string
    {
        return self::DEFAULT_WHISPER_URL;
    }

    /**
     * @param array<int, string> $logs
     */
    public static function transcribe_media(string $file_path, array &$logs): string
    {
        $outputs = self::transcribe_media_outputs($file_path, $logs);
        return (string) ($outputs['text'] ?? '');
    }

    /**
     * @param array<int, string> $logs
     * @return array{text: string, srt: string, vtt: string, lipsync_json: string}
     */
    public static function transcribe_media_outputs(string $file_path, array &$logs): array
    {
        $outputs = [
            'text' => '',
            'srt' => '',
            'vtt' => '',
            'lipsync_json' => '',
        ];

        $verbose_raw = self::transcribe_audio($file_path, $logs, 'verbose_json', true);
        if ($verbose_raw !== '') {
            $verbose_decoded = json_decode($verbose_raw, true);
            if (is_array($verbose_decoded)) {
                if (! empty($verbose_decoded['text']) && is_string($verbose_decoded['text'])) {
                    $outputs['text'] = self::normalize_text((string) $verbose_decoded['text']);
                }
                $outputs['lipsync_json'] = self::build_lipsync_payload($verbose_decoded, $outputs['text']);
            }
        }

        if ($outputs['text'] === '') {
            $outputs['text'] = self::transcribe_audio($file_path, $logs, 'text');
        }

        $outputs['srt'] = self::transcribe_audio($file_path, $logs, 'srt', true);
        $outputs['vtt'] = self::transcribe_audio($file_path, $logs, 'vtt', true);

        if ($outputs['lipsync_json'] === '') {
            $outputs['lipsync_json'] = self::build_lipsync_payload([], $outputs['text']);
        }

        return $outputs;
    }

    /**
     * @return array<int, array{name: string, tmp_name: string}>
     */
    private static function normalize_uploaded_files(array $uploaded_files): array
    {
        if (! isset($uploaded_files['name']) || ! is_array($uploaded_files['name'])) {
            return [];
        }

        $names = $uploaded_files['name'];
        $tmp_names = is_array($uploaded_files['tmp_name'] ?? null) ? $uploaded_files['tmp_name'] : [];
        $errors = is_array($uploaded_files['error'] ?? null) ? $uploaded_files['error'] : [];

        $result = [];
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $name = isset($names[$i]) ? (string) $names[$i] : '';
            $tmp = isset($tmp_names[$i]) ? (string) $tmp_names[$i] : '';
            $err = isset($errors[$i]) ? (int) $errors[$i] : UPLOAD_ERR_NO_FILE;
            if ($name === '' || $tmp === '' || $err !== UPLOAD_ERR_OK) {
                continue;
            }
            $result[] = [
                'name' => $name,
                'tmp_name' => $tmp,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, string> $logs
     * @return array{
     *   recipes: array<int, array<string, mixed>>,
     *   image_entry: array<string, mixed>|null,
     *   audit: array<string, mixed>
     * }
     */
    private static function parse_file_from_path(string $path, string $name, array &$logs): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (
            $ext === ''
            || (
                ! isset(self::$supported_text_ext_map[$ext])
                && ! isset(self::$supported_image_ext_map[$ext])
                && ! isset(self::$supported_audio_ext_map[$ext])
            )
        ) {
            $logs[] = "Formato não suportado: {$name}";
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Formato não suportado',
                ],
            ];
        }

        if (isset(self::$supported_image_ext_map[$ext])) {
            $image_entry = self::build_image_entry_from_path($path, $name, $ext, $logs);
            return [
                'recipes' => [],
                'image_entry' => $image_entry,
                'audit' => [
                    'name' => $name,
                    'kind' => $image_entry ? 'image' : 'error',
                    'recipes_count' => 0,
                    'note' => $image_entry ? 'Imagem importada' : 'Falha ao processar imagem',
                ],
            ];
        }

        if (isset(self::$supported_audio_ext_map[$ext])) {
            $size = @filesize($path);
            if (is_int($size) && $size > self::MAX_AUDIO_FILE_BYTES) {
                $logs[] = "Áudio muito grande ignorado ({$name})";
                return [
                    'recipes' => [],
                    'image_entry' => null,
                    'audit' => [
                        'name' => $name,
                        'kind' => 'skip',
                        'recipes_count' => 0,
                        'note' => 'Áudio muito grande',
                    ],
                ];
            }

            $text = self::transcribe_audio($path, $logs);
            if (trim($text) === '') {
                $logs[] = "Não foi possível transcrever áudio: {$name}";
                return [
                    'recipes' => [],
                    'image_entry' => null,
                    'audit' => [
                        'name' => $name,
                        'kind' => 'error',
                        'recipes_count' => 0,
                        'note' => 'Falha na transcrição de áudio',
                    ],
                ];
            }

            $parsed = self::classify_text_content($text, $name, $logs);
            if (! $parsed['recipes']) {
                return [
                    'recipes' => [],
                    'image_entry' => null,
                    'audit' => [
                        'name' => $name,
                        'kind' => 'skip',
                        'recipes_count' => 0,
                        'note' => 'Sem conteúdo detectável',
                    ],
                ];
            }

            return [
                'recipes' => $parsed['recipes'],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => $parsed['kind'],
                    'recipes_count' => count($parsed['recipes']),
                    'note' => $parsed['note'],
                ],
            ];
        }

        if (self::should_skip_by_name($name)) {
            $logs[] = "Arquivo não-receita ignorado: {$name}";
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Arquivo não-receita ignorado',
                ],
            ];
        }

        $size = @filesize($path);
        if (is_int($size) && $size > self::MAX_FILE_BYTES) {
            $logs[] = "Arquivo muito grande ignorado ({$name})";
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Arquivo muito grande',
                ],
            ];
        }

        $text = self::extract_text_from_path($path, $ext, $logs);
        if (trim($text) === '') {
            $logs[] = "Não foi possível extrair texto: {$name}";
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'error',
                    'recipes_count' => 0,
                    'note' => 'Não foi possível extrair texto',
                ],
            ];
        }

        $parsed = self::classify_text_content($text, $name, $logs);
        if (! $parsed['recipes']) {
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Sem conteúdo detectável',
                ],
            ];
        }

        return [
            'recipes' => $parsed['recipes'],
            'image_entry' => null,
            'audit' => [
                'name' => $name,
                'kind' => $parsed['kind'],
                'recipes_count' => count($parsed['recipes']),
                'note' => $parsed['note'],
            ],
        ];
    }

    /**
     * @return array{
     *   recipes: array<int, array<string, mixed>>,
     *   logs: array<int, string>,
     *   imported_files: int,
     *   image_entries: array<int, array<string, mixed>>,
     *   cover_image: string,
     *   audit_items: array<int, array<string, mixed>>
     * }
     */
    private static function ingest_from_drive_folder(string $folder_url): array
    {
        $logs = [];
        $recipes = [];
        $imported_files = 0;
        $image_entries = [];
        $cover_image = '';
        $audit_items = [];

        $folder_id = self::extract_drive_folder_id($folder_url);
        if ($folder_id === '') {
            return [
                'recipes' => [],
                'logs' => ['Link de pasta do Google Drive inválido.'],
                'imported_files' => 0,
                'image_entries' => [],
                'cover_image' => '',
                'audit_items' => [],
            ];
        }

        $items = [];
        $visited = [];
        self::crawl_drive_folder($folder_id, 0, $visited, $items, $logs);

        if (! $items) {
            $logs[] = 'Nenhum arquivo elegível encontrado no Google Drive.';
            return [
                'recipes' => [],
                'logs' => $logs,
                'imported_files' => 0,
                'image_entries' => [],
                'cover_image' => '',
                'audit_items' => [],
            ];
        }

        $count = 0;
        foreach ($items as $item) {
            if ($count >= self::MAX_FILES) {
                $logs[] = 'Limite de arquivos do Drive atingido. Parte do conteúdo foi ignorada.';
                break;
            }

            $item_name = trim((string) ($item['name'] ?? 'arquivo-drive'));
            $download = self::download_drive_item($item, $logs);
            if (! $download) {
                $audit_items[] = [
                    'source' => 'drive',
                    'name' => $item_name,
                    'kind' => 'error',
                    'recipes_count' => 0,
                    'note' => 'Falha no download ou arquivo indisponível',
                ];
                continue;
            }

            if (isset(self::$supported_image_ext_map[$download['ext']])) {
                $image_entry = self::build_image_entry_from_blob(
                    $download['content'],
                    $download['name'],
                    $download['ext'],
                    $logs,
                    '',
                    true
                );
                if ($image_entry) {
                    $image_entries[] = $image_entry;
                    if ($cover_image === '' && self::is_cover_image_name($download['name'])) {
                        $cover_image = (string) $image_entry['src'];
                    }
                    $imported_files++;
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => 'image',
                        'recipes_count' => 0,
                        'note' => 'Imagem importada',
                    ];
                } else {
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => 'error',
                        'recipes_count' => 0,
                        'note' => 'Falha ao processar imagem',
                    ];
                }
            } else {
                if (self::should_skip_by_name($download['name'])) {
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => 'skip',
                        'recipes_count' => 0,
                        'note' => 'Arquivo não-receita ignorado',
                    ];
                    $count++;
                    continue;
                }

                $recipes_from_file = self::parse_file_blob(
                    $download['name'],
                    $download['ext'],
                    $download['content'],
                    $logs
                );
                if ($recipes_from_file) {
                    $first_recipe = is_array($recipes_from_file[0] ?? null) ? $recipes_from_file[0] : [];
                    $is_generic = ! empty($first_recipe['isGeneric']) || ! empty($first_recipe['is_generic']);
                    $kind = $is_generic ? 'generic' : 'recipe';
                    $note = $is_generic
                        ? (count($recipes_from_file) === 1 ? '1 item genérico importado' : (count($recipes_from_file) . ' itens genéricos importados'))
                        : (count($recipes_from_file) === 1 ? '1 receita importada' : (count($recipes_from_file) . ' receitas importadas'));
                    $recipes = array_merge($recipes, $recipes_from_file);
                    $imported_files++;
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => $kind,
                        'recipes_count' => count($recipes_from_file),
                        'note' => $note,
                    ];
                } else {
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => 'skip',
                        'recipes_count' => 0,
                        'note' => 'Sem receita detectada',
                    ];
                }
            }
            $count++;
        }

        return [
            'recipes' => $recipes,
            'logs' => $logs,
            'imported_files' => $imported_files,
            'image_entries' => self::unique_image_entries($image_entries),
            'cover_image' => $cover_image,
            'audit_items' => $audit_items,
        ];
    }

    /**
     * Apenas lista os itens elegíveis da pasta do Drive para processamento em lote no frontend.
     *
     * @return array{ok: bool, items: array<int, array<string, string>>, logs: array<int, string>}
     */
    public static function scan_drive_structure(string $folder_url): array
    {
        $logs = [];
        $items = [];
        $folder_id = self::extract_drive_folder_id($folder_url);

        if ($folder_id === '') {
            return [
                'ok' => false,
                'items' => [],
                'logs' => ['Link de pasta do Google Drive inválido.'],
            ];
        }

        $visited = [];
        self::crawl_drive_folder($folder_id, 0, $visited, $items, $logs);

        if (count($items) > self::MAX_FILES) {
            $items = array_slice($items, 0, self::MAX_FILES);
            $logs[] = 'Limite de arquivos do Drive atingido. Parte do conteúdo foi ignorada.';
        }

        return [
            'ok' => true,
            'items' => array_values($items),
            'logs' => $logs,
        ];
    }

    /**
     * Processa um item específico do Drive (chamado em loop via AJAX).
     *
     * @param array<string, string> $item
     * @return array{
     *   success: bool,
     *   type?: string,
     *   data?: array<string, mixed>|array<int, array<string, mixed>>,
     *   logs: array<int, string>,
     *   audit: array<string, mixed>
     * }
     */
    public static function process_single_drive_item(array $item): array
    {
        self::reset_image_runtime_state();

        $logs = [];
        $name = trim((string) ($item['name'] ?? 'arquivo-drive'));
        if ($name === '') {
            $name = 'arquivo-drive';
        }

        if (self::should_skip_by_name($name)) {
            return [
                'success' => true,
                'type' => 'content',
                'data' => [],
                'logs' => $logs,
                'audit' => [
                    'source' => 'drive',
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Arquivo não-receita ignorado',
                ],
            ];
        }

        $name_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (
            $name_ext !== ''
            && ! isset(self::$supported_text_ext_map[$name_ext])
            && ! isset(self::$supported_image_ext_map[$name_ext])
            && ! isset(self::$supported_audio_ext_map[$name_ext])
        ) {
            return [
                'success' => true,
                'type' => 'content',
                'data' => [],
                'logs' => $logs,
                'audit' => [
                    'source' => 'drive',
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Formato não suportado',
                ],
            ];
        }

        $download = self::download_drive_item($item, $logs);
        if (! $download) {
            return [
                'success' => false,
                'logs' => $logs,
                'audit' => [
                    'source' => 'drive',
                    'name' => $name,
                    'kind' => 'error',
                    'recipes_count' => 0,
                    'note' => 'Falha no download ou arquivo indisponível',
                ],
            ];
        }

        if (isset(self::$supported_image_ext_map[$download['ext']])) {
            $image_entry = self::build_image_entry_from_blob(
                $download['content'],
                $download['name'],
                $download['ext'],
                $logs,
                '',
                true
            );
            $ok = (bool) $image_entry;
            return [
                'success' => $ok,
                'type' => 'image',
                'data' => $image_entry ?: [],
                'logs' => $logs,
                'audit' => [
                    'source' => 'drive',
                    'name' => $download['name'],
                    'kind' => $ok ? 'image' : 'error',
                    'recipes_count' => 0,
                    'note' => $ok ? 'Imagem importada' : 'Falha ao processar imagem',
                ],
            ];
        }

        $recipes = self::parse_file_blob($download['name'], $download['ext'], $download['content'], $logs);
        $count = count($recipes);
        $first = is_array($recipes[0] ?? null) ? $recipes[0] : [];
        $is_generic = ! empty($first['isGeneric']) || ! empty($first['is_generic']);

        $kind = 'skip';
        $note = 'Sem conteúdo detectável';
        if ($count > 0) {
            if ($is_generic) {
                $kind = 'generic';
                $note = $count === 1 ? '1 item genérico importado' : ($count . ' itens genéricos importados');
            } else {
                $kind = 'recipe';
                $note = $count === 1 ? '1 receita importada' : ($count . ' receitas importadas');
            }
        }

        return [
            'success' => true,
            'type' => 'content',
            'data' => $recipes,
            'logs' => $logs,
            'audit' => [
                'source' => 'drive',
                'name' => $download['name'],
                'kind' => $kind,
                'recipes_count' => $count,
                'note' => $note,
            ],
        ];
    }

    private static function extract_drive_folder_id(string $url): string
    {
        if (preg_match('#drive/folders/([A-Za-z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * @param array<string, bool> $visited
     * @param array<int, array<string, string>> $items
     * @param array<int, string> $logs
     */
    private static function crawl_drive_folder(
        string $folder_id,
        int $depth,
        array &$visited,
        array &$items,
        array &$logs
    ): void {
        if ($depth > self::MAX_DEPTH) {
            $logs[] = 'Limite de profundidade de subpastas do Drive atingido.';
            return;
        }
        if (isset($visited[$folder_id])) {
            return;
        }
        $visited[$folder_id] = true;

        $url = 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder_id) . '#list';
        $response = wp_remote_get($url, [
            'timeout' => 25,
            'redirection' => 5,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            $logs[] = 'Falha ao acessar pasta do Drive: ' . $response->get_error_message();
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $logs[] = 'Falha ao acessar pasta do Drive (HTTP ' . $code . ').';
            return;
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            $logs[] = 'Pasta do Drive vazia ou sem acesso público.';
            return;
        }

        $links = self::extract_drive_links_from_html($html);
        foreach ($links['files'] as $file_item) {
            $items[] = $file_item;
        }

        foreach ($links['folders'] as $subfolder) {
            if (! empty($subfolder['id'])) {
                self::crawl_drive_folder((string) $subfolder['id'], $depth + 1, $visited, $items, $logs);
            }
        }
    }

    /**
     * @return array{files: array<int, array<string, string>>, folders: array<int, array<string, string>>}
     */
    private static function extract_drive_links_from_html(string $html): array
    {
        $files = [];
        $folders = [];

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//a[@href]');
        if (! $nodes) {
            return ['files' => [], 'folders' => []];
        }

        foreach ($nodes as $node) {
            $href = html_entity_decode((string) $node->attributes->getNamedItem('href')->nodeValue, ENT_QUOTES);
            $name = trim((string) $node->textContent);

            if (preg_match('#https?://drive\.google\.com/drive/folders/([A-Za-z0-9_-]+)#', $href, $m)) {
                $folders[] = [
                    'kind' => 'folder',
                    'id' => $m[1],
                    'name' => $name !== '' ? $name : ('folder-' . $m[1]),
                ];
                continue;
            }

            if (preg_match('#https?://drive\.google\.com/file/d/([A-Za-z0-9_-]+)#', $href, $m)) {
                $files[] = [
                    'kind' => 'file',
                    'id' => $m[1],
                    'name' => $name !== '' ? $name : ('file-' . $m[1]),
                ];
                continue;
            }

            if (preg_match('#https?://docs\.google\.com/document/d/([A-Za-z0-9_-]+)#', $href, $m)) {
                $doc_name = $name !== '' ? $name : ('document-' . $m[1]);
                if (strtolower(pathinfo($doc_name, PATHINFO_EXTENSION)) === '') {
                    $doc_name .= '.txt';
                }
                $files[] = [
                    'kind' => 'gdoc',
                    'id' => $m[1],
                    'name' => $doc_name,
                ];
                continue;
            }

            if (preg_match('#https?://docs\.google\.com/presentation/d/([A-Za-z0-9_-]+)#', $href, $m)) {
                $slide_name = $name !== '' ? $name : ('presentation-' . $m[1]);
                if (strtolower(pathinfo($slide_name, PATHINFO_EXTENSION)) === '') {
                    $slide_name .= '.pptx';
                }
                $files[] = [
                    'kind' => 'gslides',
                    'id' => $m[1],
                    'name' => $slide_name,
                ];
            }
        }

        return [
            'files' => self::unique_items($files),
            'folders' => self::unique_items($folders),
        ];
    }

    /**
     * @param array<int, array<string, string>> $items
     * @return array<int, array<string, string>>
     */
    private static function unique_items(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $key = ($item['kind'] ?? '') . ':' . ($item['id'] ?? '');
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string, string> $item
     * @param array<int, string> $logs
     * @return array{name: string, ext: string, content: string}|null
     */
    private static function download_drive_item(array $item, array &$logs): ?array
    {
        $kind = $item['kind'] ?? '';
        $id = $item['id'] ?? '';
        $name = trim((string) ($item['name'] ?? ''));
        if ($kind === '' || $id === '') {
            return null;
        }

        if ($kind === 'gdoc') {
            $url = 'https://docs.google.com/document/d/' . rawurlencode($id) . '/export?format=txt';
        } elseif ($kind === 'gslides') {
            $url = 'https://docs.google.com/presentation/d/' . rawurlencode($id) . '/export/pptx';
        } else {
            $url = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($id);
        }

        $response = wp_remote_get($url, [
            'timeout' => 35,
            'redirection' => 5,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            $logs[] = 'Falha ao baixar arquivo do Drive: ' . $response->get_error_message();
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $logs[] = 'Falha no download de arquivo do Drive (HTTP ' . $code . ').';
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $header_cd = (string) wp_remote_retrieve_header($response, 'content-disposition');
        if ($header_cd !== '' && preg_match('/filename\\*?=(?:UTF-8\'\')?\"?([^\";]+)\"?/i', $header_cd, $m)) {
            $name = rawurldecode(trim($m[1]));
        }
        if ($name === '') {
            $name = $kind . '-' . $id . '.txt';
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' && $kind === 'gdoc') {
            $ext = 'txt';
            $name .= '.txt';
        }
        if ($ext === '' && $kind === 'gslides') {
            $ext = 'pptx';
            $name .= '.pptx';
        }
        if ($ext === '') {
            $ctype = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
            if (strpos($ctype, 'html') !== false) {
                $ext = 'html';
                $name .= '.html';
            } elseif (strpos($ctype, 'pdf') !== false) {
                $ext = 'pdf';
                $name .= '.pdf';
            } elseif (strpos($ctype, 'wordprocessingml') !== false) {
                $ext = 'docx';
                $name .= '.docx';
            } elseif (strpos($ctype, 'presentationml.presentation') !== false || strpos($ctype, 'vnd.ms-powerpoint') !== false) {
                $ext = 'pptx';
                $name .= '.pptx';
            } elseif (strpos($ctype, 'image/jpeg') !== false || strpos($ctype, 'image/jpg') !== false) {
                $ext = 'jpg';
                $name .= '.jpg';
            } elseif (strpos($ctype, 'image/png') !== false) {
                $ext = 'png';
                $name .= '.png';
            } elseif (strpos($ctype, 'image/webp') !== false) {
                $ext = 'webp';
                $name .= '.webp';
            } elseif (strpos($ctype, 'audio/mpeg') !== false || strpos($ctype, 'audio/mp3') !== false) {
                $ext = 'mp3';
                $name .= '.mp3';
            } elseif (strpos($ctype, 'audio/wav') !== false || strpos($ctype, 'audio/x-wav') !== false) {
                $ext = 'wav';
                $name .= '.wav';
            } elseif (
                strpos($ctype, 'audio/mp4') !== false
                || strpos($ctype, 'audio/x-m4a') !== false
                || strpos($ctype, 'audio/m4a') !== false
            ) {
                $ext = 'm4a';
                $name .= '.m4a';
            } elseif (strpos($ctype, 'audio/ogg') !== false) {
                $ext = 'ogg';
                $name .= '.ogg';
            } elseif (strpos($ctype, 'audio/webm') !== false || strpos($ctype, 'video/webm') !== false) {
                $ext = 'webm';
                $name .= '.webm';
            } elseif (strpos($ctype, 'video/mp4') !== false) {
                $ext = 'mp4';
                $name .= '.mp4';
            } elseif (strpos($ctype, 'video/mpeg') !== false) {
                $ext = 'mpeg';
                $name .= '.mpeg';
            } elseif (strpos($ctype, 'video/x-matroska') !== false || strpos($ctype, 'video/mkv') !== false) {
                $ext = 'mkv';
                $name .= '.mkv';
            } else {
                $ext = 'txt';
                $name .= '.txt';
            }
        }

        if (
            ! isset(self::$supported_text_ext_map[$ext])
            && ! isset(self::$supported_image_ext_map[$ext])
            && ! isset(self::$supported_audio_ext_map[$ext])
        ) {
            return null;
        }
        $max_allowed = isset(self::$supported_audio_ext_map[$ext]) ? self::MAX_AUDIO_FILE_BYTES : self::MAX_FILE_BYTES;
        if (strlen($body) > $max_allowed) {
            $logs[] = "Arquivo do Drive muito grande ignorado: {$name}";
            return null;
        }

        return [
            'name' => $name,
            'ext' => $ext,
            'content' => $body,
        ];
    }

    /**
     * @param array<int, string> $logs
     * @return array<int, array<string, mixed>>
     */
    private static function parse_file_blob(string $name, string $ext, string $content, array &$logs): array
    {
        if (self::should_skip_by_name($name)) {
            return [];
        }

        if (isset(self::$supported_audio_ext_map[$ext])) {
            if (strlen($content) > self::MAX_AUDIO_FILE_BYTES) {
                $logs[] = "Áudio muito grande ignorado ({$name})";
                return [];
            }

            $suffix = '.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
            if ($suffix === '.') {
                $suffix = '.audio';
            }
            $tmp = self::write_temp_file($content, $suffix);
            if ($tmp === '') {
                $logs[] = "Não foi possível preparar áudio temporário para transcrição ({$name})";
                return [];
            }

            $text = self::transcribe_audio($tmp, $logs);
            @unlink($tmp);
            if (trim($text) === '') {
                return [];
            }

            $parsed = self::classify_text_content($text, $name, $logs);
            return $parsed['recipes'];
        }

        $text = self::extract_text_from_blob($content, $ext, $logs);
        if (trim($text) === '') {
            return [];
        }

        $parsed = self::classify_text_content($text, $name, $logs);
        return $parsed['recipes'];
    }

    /**
     * @param array<int, string> $logs
     * @return array{recipes: array<int, array<string, mixed>>, kind: string, note: string}
     */
    private static function classify_text_content(string $text, string $name, array &$logs): array
    {
        $fallback_title = pathinfo($name, PATHINFO_FILENAME);
        $recipes = self::extract_recipes_from_text($text, $fallback_title);
        if ($recipes) {
            return [
                'recipes' => $recipes,
                'kind' => 'recipe',
                'note' => count($recipes) === 1 ? '1 receita importada' : (count($recipes) . ' receitas importadas'),
            ];
        }

        $recipes = self::extract_generic_content($text, $fallback_title);
        if ($recipes) {
            $logs[] = "Conteúdo genérico detectado em: {$name}";
            return [
                'recipes' => $recipes,
                'kind' => 'generic',
                'note' => count($recipes) === 1 ? '1 item genérico importado' : (count($recipes) . ' itens genéricos importados'),
            ];
        }

        $logs[] = "Sem conteúdo detectável em: {$name}";
        return [
            'recipes' => [],
            'kind' => 'skip',
            'note' => 'Sem conteúdo detectável',
        ];
    }

    private static function whisper_api_url(): string
    {
        $saved = get_option('pdfw_whisper_url', '');
        $saved = is_string($saved) ? trim($saved) : '';
        $clean = self::sanitize_whisper_url($saved);
        if ($clean !== '') {
            return $clean;
        }
        return self::DEFAULT_WHISPER_URL;
    }

    private static function sanitize_whisper_url(string $value): string
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

    /**
     * @param array<int, string> $logs
     */
    private static function transcribe_audio(string $file_path, array &$logs, string $response_format = 'text', bool $optional = false): string
    {
        $response_format = strtolower(trim($response_format));
        if (! in_array($response_format, ['text', 'srt', 'vtt', 'verbose_json'], true)) {
            if (! $optional) {
                $logs[] = 'Formato de resposta da transcrição não suportado: ' . sanitize_text_field($response_format);
            }
            return '';
        }

        if (! is_file($file_path) || ! is_readable($file_path)) {
            if (! $optional) {
                $logs[] = 'Arquivo de áudio não encontrado para transcrição.';
            }
            return '';
        }

        $size = @filesize($file_path);
        if (is_int($size) && $size > self::MAX_AUDIO_FILE_BYTES) {
            if (! $optional) {
                $logs[] = 'Arquivo de áudio excede o limite suportado para transcrição.';
            }
            return '';
        }

        if (! function_exists('curl_init') || ! function_exists('curl_file_create')) {
            if (! $optional) {
                $logs[] = 'Extensão cURL não disponível no servidor para transcrição.';
            }
            return '';
        }

        $url = self::whisper_api_url();
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime = self::guess_audio_mime($file_path, $ext);
        $curl_file = curl_file_create($file_path, $mime, basename($file_path));

        $ch = curl_init($url);
        if ($ch === false) {
            $logs[] = 'Não foi possível inicializar conexão de transcrição.';
            return '';
        }

        $stream_response = ($response_format === 'verbose_json');
        $response_tmp_path = '';
        $response_tmp_handle = null;
        $raw = '';

        $payload = [
            'file' => $curl_file,
            'model' => 'medium',
            'language' => 'pt',
            'response_format' => $response_format,
        ];
        if ($response_format === 'verbose_json') {
            $payload['timestamp_granularities[0]'] = 'word';
            $payload['timestamp_granularities[1]'] = 'segment';
        }

        if ($stream_response) {
            $tmp = wp_tempnam('pdfw-whisper-response');
            if (! is_string($tmp) || $tmp === '') {
                if (! $optional) {
                    $logs[] = 'Não foi possível criar arquivo temporário para resposta de transcrição.';
                }
                curl_close($ch);
                return '';
            }
            $response_tmp_path = $tmp;
            $handle = @fopen($response_tmp_path, 'wb');
            if (! is_resource($handle)) {
                if (! $optional) {
                    $logs[] = 'Não foi possível abrir arquivo temporário para streaming da transcrição.';
                }
                @unlink($response_tmp_path);
                curl_close($ch);
                return '';
            }
            $response_tmp_handle = $handle;
        }

        try {
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => self::TRANSCRIBE_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/plain, application/json',
                ],
            ];

            if ($stream_response && is_resource($response_tmp_handle)) {
                $opts[CURLOPT_RETURNTRANSFER] = false;
                $opts[CURLOPT_FILE] = $response_tmp_handle;
            } else {
                $opts[CURLOPT_RETURNTRANSFER] = true;
            }

            curl_setopt_array($ch, $opts);

            $response_body = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($response_body === false) {
                if (! $optional) {
                    $logs[] = 'Falha ao chamar API de transcrição: ' . $curl_error;
                }
                return '';
            }

            if ($stream_response) {
                if (! is_resource($response_tmp_handle)) {
                    if (! $optional) {
                        $logs[] = 'Falha no streaming da resposta de transcrição.';
                    }
                    return '';
                }
                @fflush($response_tmp_handle);
                @fclose($response_tmp_handle);
                $response_tmp_handle = null;
                $raw = self::read_temp_response_limited($response_tmp_path, $logs, $optional);
            } else {
                $raw = trim((string) $response_body);
            }
        } finally {
            if (is_resource($response_tmp_handle)) {
                @fclose($response_tmp_handle);
            }
            if ($response_tmp_path !== '') {
                @unlink($response_tmp_path);
            }
            curl_close($ch);
        }

        if ($http_code < 200 || $http_code >= 300) {
            $snippet = mb_substr($raw, 0, 300);
            if (! $optional) {
                $logs[] = 'API de transcrição retornou HTTP ' . $http_code . ' (' . $response_format . ')' . ($snippet !== '' ? (': ' . $snippet) : '.');
            }
            return '';
        }

        if ($raw === '') {
            if (! $optional) {
                $logs[] = 'API de transcrição retornou conteúdo vazio (' . $response_format . ').';
            }
            return '';
        }

        if ($raw[0] === '{' || $raw[0] === '[') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                if ($response_format === 'verbose_json') {
                    return (string) wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (! empty($json['text']) && is_string($json['text'])) {
                    return $response_format === 'text'
                        ? self::normalize_text((string) $json['text'])
                        : trim((string) $json['text']);
                }
                if (! empty($json['error']) && is_array($json['error']) && ! empty($json['error']['message'])) {
                    if (! $optional) {
                        $logs[] = 'Erro da API de transcrição: ' . sanitize_text_field((string) $json['error']['message']);
                    }
                    return '';
                }
            }
        }

        if ($response_format === 'text') {
            return self::normalize_text($raw);
        }
        if ($response_format === 'vtt') {
            $body = preg_replace("/\r\n?/", "\n", $raw);
            $body = trim((string) $body);
            if ($body === '') {
                return '';
            }
            if (strpos(strtoupper($body), 'WEBVTT') !== 0) {
                return "WEBVTT\n\n" . $body;
            }
            return $body;
        }

        return trim((string) preg_replace("/\r\n?/", "\n", $raw));
    }

    /**
     * @param array<int, string> $logs
     */
    private static function read_temp_response_limited(string $file_path, array &$logs, bool $optional): string
    {
        if (! is_file($file_path) || ! is_readable($file_path)) {
            if (! $optional) {
                $logs[] = 'Resposta de transcrição não pôde ser lida do arquivo temporário.';
            }
            return '';
        }

        $handle = @fopen($file_path, 'rb');
        if (! is_resource($handle)) {
            if (! $optional) {
                $logs[] = 'Falha ao abrir resposta temporária da transcrição.';
            }
            return '';
        }

        $buffer = '';
        $total = 0;
        $truncated = false;

        while (! feof($handle)) {
            $chunk = fread($handle, self::TRANSCRIBE_STREAM_CHUNK_BYTES);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }

            $chunk_len = strlen($chunk);
            $total += $chunk_len;

            if ($total > self::MAX_TRANSCRIBE_RESPONSE_BYTES) {
                $overflow = $total - self::MAX_TRANSCRIBE_RESPONSE_BYTES;
                $allowed = $chunk_len - $overflow;
                if ($allowed > 0) {
                    $buffer .= substr($chunk, 0, $allowed);
                }
                $truncated = true;
                break;
            }

            $buffer .= $chunk;
        }

        @fclose($handle);

        if ($truncated) {
            if (! $optional) {
                $logs[] = 'Resposta da API de transcrição excedeu o limite de memória seguro.';
            }
            return '';
        }

        return trim((string) preg_replace("/\r\n?/", "\n", $buffer));
    }

    /**
     * @param array<string, mixed> $verbose_payload
     */
    private static function build_lipsync_payload(array $verbose_payload, string $fallback_text): string
    {
        $cues = [];
        $timing_source = 'none';

        $words = is_array($verbose_payload['words'] ?? null) ? $verbose_payload['words'] : [];
        if ($words) {
            foreach ($words as $word) {
                if (! is_array($word)) {
                    continue;
                }
                $token = trim((string) ($word['word'] ?? $word['text'] ?? ''));
                if ($token === '') {
                    continue;
                }
                $start = isset($word['start']) ? (float) $word['start'] : 0.0;
                $end = isset($word['end']) ? (float) $word['end'] : $start + 0.24;
                if ($end <= $start) {
                    $end = $start + 0.24;
                }
                $cues[] = [
                    'type' => 'word',
                    'text' => $token,
                    'start' => round($start, 3),
                    'end' => round($end, 3),
                ];
            }
            if ($cues) {
                $timing_source = 'word';
            }
        }

        if (! $cues) {
            $segments = is_array($verbose_payload['segments'] ?? null) ? $verbose_payload['segments'] : [];
            foreach ($segments as $segment) {
                if (! is_array($segment)) {
                    continue;
                }
                $text = trim((string) ($segment['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $start = isset($segment['start']) ? (float) $segment['start'] : 0.0;
                $end = isset($segment['end']) ? (float) $segment['end'] : $start + 1.2;
                if ($end <= $start) {
                    $end = $start + 1.2;
                }
                $cues[] = [
                    'type' => 'segment',
                    'text' => $text,
                    'start' => round($start, 3),
                    'end' => round($end, 3),
                ];
            }
            if ($cues) {
                $timing_source = 'segment';
            }
        }

        $text = trim((string) ($verbose_payload['text'] ?? ''));
        if ($text === '') {
            $text = self::normalize_text($fallback_text);
        }

        if (! $cues && $text !== '') {
            $tokens = preg_split('/\s+/u', $text) ?: [];
            $cursor = 0.0;
            foreach ($tokens as $token) {
                $clean = trim((string) $token);
                if ($clean === '') {
                    continue;
                }
                $length = mb_strlen($clean);
                $duration = max(0.14, min(0.6, 0.18 + ($length * 0.02)));
                $cues[] = [
                    'type' => 'word',
                    'text' => $clean,
                    'start' => round($cursor, 3),
                    'end' => round($cursor + $duration, 3),
                ];
                $cursor += $duration;
            }
            if ($cues) {
                $timing_source = 'heuristic';
            }
        }

        $payload = [
            'format' => 'pdfw_lipsync_v1',
            'config' => [
                'engine' => 'faster-whisper',
                'language' => 'pt',
                'timing_source' => $timing_source,
                'time_unit' => 'seconds',
                'fps_hint' => 30,
            ],
            'text' => $text,
            'cues' => $cues,
        ];

        return (string) wp_json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private static function guess_audio_mime(string $file_path, string $ext): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($file_path);
            if (
                is_string($detected)
                && (strpos($detected, 'audio/') === 0 || strpos($detected, 'video/') === 0)
            ) {
                return $detected;
            }
        }

        $ext = strtolower($ext);
        if ($ext === 'mp3') {
            return 'audio/mpeg';
        }
        if ($ext === 'wav') {
            return 'audio/wav';
        }
        if ($ext === 'm4a') {
            return 'audio/mp4';
        }
        if ($ext === 'ogg') {
            return 'audio/ogg';
        }
        if ($ext === 'mp4') {
            return 'video/mp4';
        }
        if ($ext === 'webm') {
            return 'video/webm';
        }
        if ($ext === 'mpeg') {
            return 'video/mpeg';
        }
        if ($ext === 'mkv') {
            return 'video/x-matroska';
        }

        return 'application/octet-stream';
    }

    private static function should_skip_by_name(string $name): bool
    {
        $normalized = remove_accents(mb_strtolower($name));
        foreach (array_keys(self::$skip_name_map) as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $logs
     */
    private static function extract_text_from_path(string $path, string $ext, array &$logs): string
    {
        $contents = (string) file_get_contents($path);
        if ($contents === '') {
            return '';
        }
        return self::extract_text_from_blob($contents, $ext, $logs, $path);
    }

    /**
     * @param array<int, string> $logs
     */
    private static function extract_text_from_blob(string $contents, string $ext, array &$logs, string $path_hint = ''): string
    {
        if ($ext === 'txt' || $ext === 'md') {
            return self::normalize_text($contents);
        }

        if ($ext === 'html' || $ext === 'htm') {
            $txt = preg_replace('/<\\s*br\\s*\\/?>/i', "\n", $contents);
            $txt = preg_replace('/<\\s*\\/p\\s*>/i', "\n", (string) $txt);
            return self::normalize_text(wp_strip_all_tags((string) $txt));
        }

        if ($ext === 'docx') {
            $tmp = $path_hint !== '' ? $path_hint : self::write_temp_file($contents, '.docx');
            if ($tmp === '') {
                return '';
            }
            $text = self::extract_docx_text($tmp);
            if ($path_hint === '') {
                @unlink($tmp);
            }
            return self::normalize_text($text);
        }

        if ($ext === 'pptx') {
            $tmp = $path_hint !== '' ? $path_hint : self::write_temp_file($contents, '.pptx');
            if ($tmp === '') {
                return '';
            }
            $text = self::extract_pptx_text($tmp);
            if ($path_hint === '') {
                @unlink($tmp);
            }
            return self::normalize_text($text);
        }

        if ($ext === 'pdf') {
            if (! class_exists('\\Smalot\\PdfParser\\Parser')) {
                $fallback_text = self::extract_pdf_text_with_python($contents, $logs, $path_hint);
                if (trim($fallback_text) !== '') {
                    return self::normalize_text($fallback_text);
                }
                $logs[] = 'PDF encontrado, mas parser PDF não está instalado no servidor.';
                return '';
            }
            $tmp = $path_hint !== '' ? $path_hint : self::write_temp_file($contents, '.pdf');
            if ($tmp === '') {
                return '';
            }
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($tmp);
                $text = $pdf->getText();
            } catch (Throwable $e) {
                $logs[] = 'Falha ao ler PDF: ' . $e->getMessage();
                $text = '';
            }
            if ($path_hint === '') {
                @unlink($tmp);
            }
            return self::normalize_text($text);
        }

        return '';
    }

    /**
     * @param array<int, string> $logs
     */
    private static function extract_pdf_text_with_python(string $contents, array &$logs, string $path_hint = ''): string
    {
        if (! function_exists('shell_exec')) {
            return '';
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled !== '' && stripos($disabled, 'shell_exec') !== false) {
            return '';
        }

        if (! defined('PDFW_PLUGIN_DIR')) {
            return '';
        }

        $script = PDFW_PLUGIN_DIR . 'lib/pypdf_vendor/pdf_extract.py';
        if (! is_file($script)) {
            return '';
        }

        $tmp = $path_hint !== '' ? $path_hint : self::write_temp_file($contents, '.pdf');
        if ($tmp === '') {
            return '';
        }

        $created_tmp = $path_hint === '';
        $python_binaries = ['python3', '/usr/bin/python3', '/usr/local/bin/python3'];
        $output = '';

        foreach ($python_binaries as $python_bin) {
            $cmd = escapeshellarg($python_bin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
            $result = shell_exec($cmd);
            if (is_string($result) && trim($result) !== '') {
                $output = $result;
                break;
            }
        }

        if ($created_tmp) {
            @unlink($tmp);
        }

        if ($output !== '') {
            $logs[] = 'PDF lido com fallback Python embarcado.';
        }

        return $output;
    }

    private static function write_temp_file(string $content, string $suffix): string
    {
        $tmp = wp_tempnam('pdfw');
        if (! is_string($tmp) || $tmp === '') {
            return '';
        }
        if ($suffix !== '' && substr($tmp, -strlen($suffix)) !== $suffix) {
            $tmp2 = $tmp . $suffix;
            @rename($tmp, $tmp2);
            $tmp = $tmp2;
        }
        file_put_contents($tmp, $content);
        return $tmp;
    }

    private static function extract_docx_text(string $path): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            return '';
        }

        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />'], ["\n", "\n", "\n"], $xml);
        $xml = preg_replace('/<w:tab\\s*\\/?\\s*>/i', ' ', (string) $xml);
        $text = wp_strip_all_tags((string) $xml);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function extract_pptx_text(string $path): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $slide_files = [];
        $num_files = (int) $zip->numFiles;
        for ($i = 0; $i < $num_files; $i++) {
            $entry_name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $entry_name, $match) !== 1) {
                continue;
            }
            $slide_files[(int) ($match[1] ?? 0)] = $entry_name;
        }

        if (! $slide_files) {
            $zip->close();
            return '';
        }

        ksort($slide_files, SORT_NUMERIC);
        $chunks = [];

        foreach ($slide_files as $slide_no => $slide_file) {
            $xml = $zip->getFromName($slide_file);
            if (! is_string($xml) || $xml === '') {
                continue;
            }

            $xml = str_replace(['</a:p>', '</a:br>', '<a:br/>', '<a:br />'], ["\n", "\n", "\n", "\n"], $xml);
            $text = wp_strip_all_tags((string) $xml);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace("/\\n{3,}/", "\n\n", $text));
            if ($text === '') {
                continue;
            }

            $chunks[] = 'Slide ' . $slide_no . ":\n" . $text;
        }

        $zip->close();
        return implode("\n\n", $chunks);
    }

    private static function normalize_text(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\\r\\n?/", "\n", $text);
        $text = preg_replace("/[\\t\\x{00A0}]+/u", ' ', (string) $text);
        $text = preg_replace("/\\n{3,}/", "\n\n", (string) $text);
        return trim((string) $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extract_recipes_from_text(string $text, string $fallback_title): array
    {
        $has_recipe_markers = (bool) preg_match('/\\bingredientes?\\b|\\bmodo\\s+de\\s+preparo\\b|\\bpreparo\\b/iu', $text);
        if ($has_recipe_markers) {
            $raw_recipes = PDFW_Renderer::recipes_from_raw($text);
            if (! empty($raw_recipes)) {
                return $raw_recipes;
            }
        }

        $lines = preg_split('/\\n+/', self::normalize_text($text)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));

        if (! $lines) {
            return [];
        }

        $title = self::pick_title($lines, $fallback_title);
        $ing_idx = self::find_line_index($lines, '/\\bingredientes?\\b/iu');
        $prep_idx = self::find_line_index($lines, '/\\b(modo\\s+de\\s+preparo|modo\\s+de\\s+fazer|preparo)\\b/iu');

        if ($ing_idx < 0 || $prep_idx < 0 || $prep_idx <= $ing_idx) {
            $inline = self::extract_inline_recipe_from_lines($lines, $title);
            return $inline ? [$inline] : [];
        }

        $ingredients = [];
        for ($i = $ing_idx + 1; $i < $prep_idx; $i++) {
            $item = self::clean_item($lines[$i]);
            if ($item !== '' && ! self::looks_like_heading($item)) {
                $ingredients[] = $item;
            }
        }

        $steps = [];
        $tip = '';
        for ($i = $prep_idx + 1, $n = count($lines); $i < $n; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(dica|obs\\.?|observa[cç][aã]o)\\s*:?/iu', $line)) {
                $tip_text = trim((string) preg_replace('/^(dica|obs\\.?|observa[cç][aã]o)\\s*:?/iu', '', $line));
                $tip = trim($tip . ' ' . $tip_text);
                continue;
            }
            if (self::is_break_heading($line)) {
                break;
            }
            $steps[] = self::clean_item($line);
        }

        $ingredients = array_values(array_filter($ingredients, static function ($x) {
            return $x !== '';
        }));
        $steps = array_values(array_filter($steps, static function ($x) {
            return $x !== '';
        }));

        if (! $ingredients || ! $steps) {
            return [];
        }

        return [[
            'title' => $title,
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tip' => trim($tip),
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extract_generic_content(string $text, string $fallback_title): array
    {
        $text = self::normalize_text($text);
        if ($text === '') {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static function ($line) {
            return $line !== '';
        }));
        if (! $lines) {
            return [];
        }

        $title = trim($fallback_title) !== '' ? trim($fallback_title) : 'Conteúdo';
        $body_lines = $lines;
        $first = (string) ($lines[0] ?? '');

        if (
            mb_strlen($first) >= 3
            && mb_strlen($first) <= 140
            && ! preg_match('/^(ingredientes?|modo\\s+de\\s+preparo|preparo|dica|categoria|descri(?:c|ç)(?:a|ã)o|tempo|por(?:c|ç)(?:o|õ)es?|dificuldade|imagem|informa(?:c|ç)(?:a|ã)o nutricional|calorias?|carboidratos?|prote(?:i|í)nas?|gorduras?|fibras?)\\b/iu', $first)
        ) {
            $title = $first;
            $body_lines = array_slice($lines, 1);
        }

        $body = trim(implode("\n", $body_lines));
        if ($body === '') {
            $body = $text;
        }

        return [[
            'title' => $title,
            'category' => 'Geral',
            'description' => $body,
            'tempo' => '',
            'porcoes' => '',
            'dificuldade' => '',
            'image' => '',
            'ingredients' => [],
            'steps' => [],
            'tip' => '',
            'nutrition' => [
                'kcal' => '',
                'carb' => '',
                'prot' => '',
                'fat' => '',
                'fiber' => '',
            ],
            'isGeneric' => true,
            'is_generic' => true,
        ]];
    }

    /**
     * @param array<int, string> $lines
     * @return array<string, mixed>|null
     */
    private static function extract_inline_recipe_from_lines(array $lines, string $title): ?array
    {
        if (count($lines) < 4) {
            return null;
        }

        $ingredients = [];
        $step_lines = [];
        $tip = '';
        $in_steps = false;

        $max = min(count($lines), 80);
        for ($i = 1; $i < $max; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(dica|obs\\.?|observa[cç][aã]o)\\s*:?/iu', $line)) {
                $tip_text = trim((string) preg_replace('/^(dica|obs\\.?|observa[cç][aã]o)\\s*:?/iu', '', $line));
                if ($tip_text !== '') {
                    $tip = trim($tip . ' ' . $tip_text);
                }
                continue;
            }

            if (! $in_steps && self::looks_like_inline_step($line)) {
                $in_steps = true;
                $step_lines[] = $line;
                continue;
            }

            if (! $in_steps) {
                if (! self::looks_like_ingredient_line($line)) {
                    continue;
                }
                $ingredients[] = self::clean_item($line);
                continue;
            }

            if (self::is_break_heading($line)) {
                break;
            }
            $step_lines[] = $line;
        }

        $ingredients = array_values(array_filter($ingredients, static function ($x) {
            return $x !== '';
        }));
        $steps = self::explode_steps_from_lines($step_lines);

        if (count($ingredients) < 2 || ! $steps) {
            return null;
        }

        return [
            'title' => $title,
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tip' => trim($tip),
        ];
    }

    private static function looks_like_ingredient_line(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        if (mb_strlen($line) > 120) {
            return false;
        }
        if (preg_match('/\\.$/u', $line)) {
            return false;
        }
        if (preg_match('/^(bater|misturar|adicionar|colocar|assar|cozinhar|fritar|esquentar|refogar|lavar|cortar|hidratar|temperar|unte|preaque[çc]a|levar)\\b/iu', $line)) {
            return false;
        }
        if (preg_match('/^(\\d+[\\.,]?\\d*|\\d+\\/\\d+|[¼½¾])\\s*/u', $line)) {
            return true;
        }
        if (preg_match('/\\b(g|kg|ml|l|colher|x[ií]cara|x[ií]caras|ovo|ovos)\\b/iu', $line)) {
            return true;
        }
        if (strpos($line, ',') !== false && mb_strlen($line) <= 90) {
            return true;
        }
        return (bool) preg_match('/^[\\p{L}\\s\\-]+$/u', $line);
    }

    private static function looks_like_inline_step(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        if (preg_match('/^(\\d+[\\).:\\-]|passo\\s*\\d+)/iu', $line)) {
            return true;
        }
        if (preg_match('/^(bater|misturar|adicionar|colocar|assar|cozinhar|fritar|esquentar|refogar|lavar|cortar|hidratar|temperar|unte|preaque[çc]a|levar|deixar|sirva|disponha|retire)\\b/iu', $line)) {
            return true;
        }
        return mb_strlen($line) > 80 && (strpos($line, '.') !== false || strpos($line, ';') !== false);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function explode_steps_from_lines(array $lines): array
    {
        $joined = trim(implode(' ', array_map('trim', $lines)));
        if ($joined === '') {
            return [];
        }

        $parts = preg_split('/(?<=[\\.!?;])\\s+/u', $joined) ?: [];
        $steps = [];
        foreach ($parts as $part) {
            $clean = self::clean_item(trim($part));
            $clean = rtrim($clean, '.;:');
            if ($clean === '') {
                continue;
            }
            $steps[] = $clean;
        }

        if (! $steps) {
            $single = self::clean_item($joined);
            if ($single !== '') {
                $steps[] = $single;
            }
        }

        return $steps;
    }

    /**
     * @param array<int, string> $lines
     */
    private static function pick_title(array $lines, string $fallback): string
    {
        $generic = [
            'ingredientes',
            'modo de preparo',
            'preparo',
            'receitas',
            'sumario',
            'sumário',
            'estrutura',
        ];
        foreach (array_slice($lines, 0, 8) as $line) {
            $low = remove_accents(mb_strtolower(trim($line)));
            if (in_array($low, $generic, true)) {
                continue;
            }
            if (mb_strlen($line) < 3) {
                continue;
            }
            return $line;
        }
        return $fallback !== '' ? $fallback : 'Receita';
    }

    /**
     * @param array<int, string> $lines
     */
    private static function find_line_index(array $lines, string $pattern): int
    {
        foreach ($lines as $idx => $line) {
            if (preg_match($pattern, $line)) {
                return $idx;
            }
        }
        return -1;
    }

    private static function clean_item(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^[\\-\\*•]+\\s*/u', '', $line);
        $line = preg_replace('/^\\d+[\\)\\.\\-:\\s]+/u', '', (string) $line);
        $line = preg_replace('/\\s+/u', ' ', (string) $line);
        return trim((string) $line);
    }

    private static function looks_like_heading(string $line): bool
    {
        return (bool) preg_match('/^(ingredientes?|modo\\s+de\\s+preparo|preparo|dicas?)\\s*:?$/iu', $line);
    }

    private static function is_break_heading(string $line): bool
    {
        return (bool) preg_match(
            '/^(informa[cç][aã]o nutricional|tabela nutricional|valores nutricionais|rendimento|receita|energia|por[cç][aã]o)\\b/iu',
            trim($line)
        );
    }

    /**
     * @param array<int, string> $logs
     * @return array<string, mixed>|null
     */
    private static function build_image_entry_from_path(string $path, string $name, string $ext, array &$logs): ?array
    {
        $size = @filesize($path);
        if (is_int($size) && $size > self::MAX_FILE_BYTES) {
            $logs[] = "Imagem muito grande ignorada ({$name})";
            return null;
        }

        if (is_int($size) && self::should_store_image_as_temp($size)) {
            $mime_from_path = self::guess_image_mime_from_path($path, $ext);
            if (strpos($mime_from_path, 'image/') === 0) {
                $temp_src = self::store_temp_image_from_path($path, $name, $ext, $logs);
                if ($temp_src !== '') {
                    $base = pathinfo($name, PATHINFO_FILENAME);
                    if ($base === '') {
                        $base = 'imagem';
                    }
                    return [
                        'name' => $name,
                        'base' => $base,
                        'key' => self::normalize_image_key($base),
                        'src' => $temp_src,
                        'is_cover_hint' => self::is_cover_image_name($name),
                    ];
                }
            }
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return self::build_image_entry_from_blob($contents, $name, $ext, $logs, $path);
    }

    /**
     * @param array<int, string> $logs
     * @return array<string, mixed>|null
     */
    private static function build_image_entry_from_blob(
        string $content,
        string $name,
        string $ext,
        array &$logs,
        string $source_path = '',
        bool $prefer_temp = false
    ): ?array
    {
        $content_size = strlen($content);
        if ($content_size > self::MAX_FILE_BYTES) {
            $logs[] = "Imagem muito grande ignorada ({$name})";
            return null;
        }

        $mime = self::guess_image_mime($ext, $content);
        if (strpos($mime, 'image/') !== 0) {
            $logs[] = "Arquivo de imagem inválido ignorado ({$name})";
            return null;
        }

        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'imagem';
        }

        if ($prefer_temp || self::should_store_image_as_temp($content_size)) {
            $temp_src = '';
            if ($source_path !== '' && is_file($source_path)) {
                $temp_src = self::store_temp_image_from_path($source_path, $name, $ext, $logs);
            }
            if ($temp_src === '') {
                $temp_src = self::store_temp_image_from_blob($content, $name, $ext, $logs);
            }
            if ($temp_src !== '') {
                return [
                    'name' => $name,
                    'base' => $base,
                    'key' => self::normalize_image_key($base),
                    'src' => $temp_src,
                    'is_cover_hint' => self::is_cover_image_name($name),
                ];
            }
        }

        self::register_inline_image_usage($content_size);

        return [
            'name' => $name,
            'base' => $base,
            'key' => self::normalize_image_key($base),
            'src' => 'data:' . $mime . ';base64,' . base64_encode($content),
            'is_cover_hint' => self::is_cover_image_name($name),
        ];
    }

    private static function guess_image_mime(string $ext, string $content): string
    {
        $mime = '';
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($content);
            if (is_array($info) && ! empty($info['mime'])) {
                $mime = strtolower((string) $info['mime']);
            }
        }

        if ($mime !== '') {
            return $mime;
        }

        $ext = strtolower($ext);
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 'image/jpeg';
        }
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }

    private static function guess_image_mime_from_path(string $path, string $ext): string
    {
        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info) && ! empty($info['mime'])) {
                return strtolower((string) $info['mime']);
            }
        }

        return self::guess_image_mime($ext, '');
    }

    private static function should_store_image_as_temp(int $bytes): bool
    {
        if ($bytes > self::IMAGE_INLINE_MAX_BYTES) {
            return true;
        }
        if (self::$inline_image_count >= self::IMAGE_INLINE_MAX_COUNT) {
            return true;
        }
        return (self::$inline_image_total_bytes + $bytes) > self::IMAGE_INLINE_TOTAL_BYTES;
    }

    private static function register_inline_image_usage(int $bytes): void
    {
        self::$inline_image_count++;
        self::$inline_image_total_bytes += max(0, $bytes);
    }

    private static function reset_image_runtime_state(): void
    {
        self::$inline_image_count = 0;
        self::$inline_image_total_bytes = 0;
        self::$temp_cleanup_done = false;
    }

    /**
     * @param array<int, string> $logs
     */
    private static function ensure_temp_image_dir(array &$logs): string
    {
        if (! function_exists('wp_get_upload_dir')) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        if ($base_dir === '') {
            return '';
        }

        $temp_dir = wp_normalize_path(trailingslashit($base_dir) . 'pdfw-temp');
        if (! is_dir($temp_dir) && ! wp_mkdir_p($temp_dir)) {
            $logs[] = 'Falha ao criar pasta temporária de imagens no uploads.';
            return '';
        }

        if (! self::$temp_cleanup_done) {
            self::cleanup_temp_image_dir($temp_dir);
            self::$temp_cleanup_done = true;
        }

        return $temp_dir;
    }

    private static function cleanup_temp_image_dir(string $temp_dir): void
    {
        $entries = glob($temp_dir . '/*');
        if (! is_array($entries) || ! $entries) {
            return;
        }

        $cutoff = time() - self::TEMP_IMAGE_TTL;
        foreach ($entries as $entry) {
            if (! is_file($entry)) {
                continue;
            }
            $mtime = @filemtime($entry);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($entry);
            }
        }
    }

    /**
     * @param array<int, string> $logs
     */
    private static function store_temp_image_from_path(string $source_path, string $name, string $ext, array &$logs): string
    {
        if (! is_file($source_path)) {
            return '';
        }

        $temp_dir = self::ensure_temp_image_dir($logs);
        if ($temp_dir === '') {
            return '';
        }

        $target_path = self::build_temp_image_path($temp_dir, $name, $ext);
        if (@copy($source_path, $target_path) !== true) {
            return '';
        }

        return self::path_to_file_uri($target_path);
    }

    /**
     * @param array<int, string> $logs
     */
    private static function store_temp_image_from_blob(string $content, string $name, string $ext, array &$logs): string
    {
        $temp_dir = self::ensure_temp_image_dir($logs);
        if ($temp_dir === '') {
            return '';
        }

        $target_path = self::build_temp_image_path($temp_dir, $name, $ext);
        if (@file_put_contents($target_path, $content, LOCK_EX) === false) {
            return '';
        }

        return self::path_to_file_uri($target_path);
    }

    private static function build_temp_image_path(string $temp_dir, string $name, string $ext): string
    {
        $safe_base = sanitize_file_name(pathinfo($name, PATHINFO_FILENAME));
        if ($safe_base === '') {
            $safe_base = 'imagem';
        }

        $safe_ext = strtolower((string) preg_replace('/[^a-z0-9]/', '', $ext));
        if ($safe_ext === '') {
            $safe_ext = 'img';
        }

        $rand = wp_generate_password(8, false, false);
        return wp_normalize_path(
            trailingslashit($temp_dir)
            . $safe_base
            . '-'
            . $rand
            . '.'
            . $safe_ext
        );
    }

    private static function path_to_file_uri(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $trimmed = ltrim($normalized, '/');
        $segments = array_map('rawurlencode', explode('/', $trimmed));
        return 'file:///' . implode('/', $segments);
    }

    private static function normalize_image_key(string $text): string
    {
        $text = remove_accents(mb_strtolower($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');
        $text = preg_replace('/^\\d+[-_]?/', '', (string) $text);
        return trim((string) $text, '-');
    }

    private static function is_cover_image_name(string $name): bool
    {
        $normalized = self::normalize_image_key(pathinfo($name, PATHINFO_FILENAME));
        if ($normalized === '') {
            return false;
        }
        return (bool) preg_match('/\\b(capa|cover|front|header|hero)\\b/', str_replace('-', ' ', $normalized));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function unique_image_entries(array $entries): array
    {
        $seen = [];
        $out = [];
        foreach ($entries as $entry) {
            $src = isset($entry['src']) ? (string) $entry['src'] : '';
            if ($src === '') {
                continue;
            }
            $key = md5($src);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<int, array<string, mixed>>
     */
    private static function dedupe_recipes(array $recipes): array
    {
        $out = [];
        foreach ($recipes as $recipe) {
            $title = isset($recipe['title']) ? (string) $recipe['title'] : '';
            if ($title === '') {
                continue;
            }
            $key = sanitize_title(remove_accents(mb_strtolower($title)));
            $score = count((array) ($recipe['ingredients'] ?? [])) + count((array) ($recipe['steps'] ?? []));
            if (! isset($out[$key])) {
                $out[$key] = ['score' => $score, 'recipe' => $recipe];
                continue;
            }
            if ($score > (int) $out[$key]['score']) {
                $out[$key] = ['score' => $score, 'recipe' => $recipe];
            }
        }

        $recipes_out = [];
        foreach ($out as $entry) {
            $recipes_out[] = $entry['recipe'];
        }
        return $recipes_out;
    }

    /**
     * @param array<int, string> $logs
     */
    public static function logs_to_notice(array $logs): string
    {
        $logs = array_values(array_filter(array_map('trim', $logs), static function ($line) {
            return $line !== '';
        }));
        if (! $logs) {
            return '';
        }
        return "Importação automática:\n- " . implode("\n- ", array_slice($logs, 0, 20));
    }
}
