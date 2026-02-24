<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Ingestor
{
    private const MAX_FILES = 80;
    private const MAX_DEPTH = 4;
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

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
    ];

    /** @var array<string, bool> */
    private static array $supported_image_ext_map = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'webp' => true,
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
        if ($ext === '' || (! isset(self::$supported_text_ext_map[$ext]) && ! isset(self::$supported_image_ext_map[$ext]))) {
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

        $recipes = self::extract_recipes_from_text($text, pathinfo($name, PATHINFO_FILENAME));
        if (! $recipes) {
            $logs[] = "Sem receita detectada em: {$name}";
            return [
                'recipes' => [],
                'image_entry' => null,
                'audit' => [
                    'name' => $name,
                    'kind' => 'skip',
                    'recipes_count' => 0,
                    'note' => 'Sem receita detectada',
                ],
            ];
        }

        return [
            'recipes' => $recipes,
            'image_entry' => null,
            'audit' => [
                'name' => $name,
                'kind' => 'recipe',
                'recipes_count' => count($recipes),
                'note' => count($recipes) === 1 ? '1 receita importada' : (count($recipes) . ' receitas importadas'),
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
                    $logs
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
                    $recipes = array_merge($recipes, $recipes_from_file);
                    $imported_files++;
                    $audit_items[] = [
                        'source' => 'drive',
                        'name' => $download['name'],
                        'kind' => 'recipe',
                        'recipes_count' => count($recipes_from_file),
                        'note' => count($recipes_from_file) === 1 ? '1 receita importada' : (count($recipes_from_file) . ' receitas importadas'),
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
            } elseif (strpos($ctype, 'image/jpeg') !== false || strpos($ctype, 'image/jpg') !== false) {
                $ext = 'jpg';
                $name .= '.jpg';
            } elseif (strpos($ctype, 'image/png') !== false) {
                $ext = 'png';
                $name .= '.png';
            } elseif (strpos($ctype, 'image/webp') !== false) {
                $ext = 'webp';
                $name .= '.webp';
            } else {
                $ext = 'txt';
                $name .= '.txt';
            }
        }

        if (! isset(self::$supported_text_ext_map[$ext]) && ! isset(self::$supported_image_ext_map[$ext])) {
            return null;
        }
        if (strlen($body) > self::MAX_FILE_BYTES) {
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

        $text = self::extract_text_from_blob($content, $ext, $logs);
        if (trim($text) === '') {
            return [];
        }

        return self::extract_recipes_from_text($text, pathinfo($name, PATHINFO_FILENAME));
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

        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return self::build_image_entry_from_blob($contents, $name, $ext, $logs);
    }

    /**
     * @param array<int, string> $logs
     * @return array<string, mixed>|null
     */
    private static function build_image_entry_from_blob(string $content, string $name, string $ext, array &$logs): ?array
    {
        if (strlen($content) > self::MAX_FILE_BYTES) {
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
