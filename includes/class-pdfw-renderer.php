<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Renderer
{
    public static function theme_options(): array
    {
        return [
            'grafite-dourado' => 'Grafite Dourado',
            'azul-mineral' => 'Azul Mineral',
            'terracota-moderna' => 'Terracota Moderna',
            'oliva-areia' => 'Oliva & Areia',
        ];
    }

    public static function default_payload(): array
    {
        return [
            'title' => 'Ebook de Receitas',
            'subtitle' => 'Versão automática',
            'author' => 'Daniel Cady',
            'seal' => 'Material exclusivo do curso {title} desenvolvido por {author}',
            'theme' => 'grafite-dourado',
            'drive_folder_url' => '',
            'import_mode' => 'append',
            'tips' => implode("\n", [
                'Monte um planejamento semanal de refeições.',
                'Prefira ingredientes frescos e minimamente processados.',
                'Ajuste porções conforme sua rotina e orientação profissional.',
            ]),
            'about' => implode("\n\n", [
                'Daniel Cady é bacharel em Nutrição há mais de 15 anos e fundador do Protocolo Reset.',
                'Ao longo da carreira, acompanhou pessoas a recuperarem saúde, disposição e qualidade de vida sem terrorismo alimentar.',
            ]),
            'recipes_raw' => self::sample_recipes_raw(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|null $recipes_override
     */
    public static function render(array $payload, ?array $recipes_override = null): string
    {
        $theme_key = $payload['theme'] ?? 'grafite-dourado';
        $theme = self::theme_palette($theme_key);
        $recipes = is_array($recipes_override) ? $recipes_override : self::parse_recipes((string) ($payload['recipes_raw'] ?? ''));
        if (! $recipes) {
            $recipes = self::parse_recipes(self::sample_recipes_raw());
        }

        $title = self::h((string) ($payload['title'] ?? 'Ebook'));
        $subtitle = self::h((string) ($payload['subtitle'] ?? 'Receitas práticas'));
        $author = self::h((string) ($payload['author'] ?? 'Autor'));

        $seal_tpl = (string) ($payload['seal'] ?? '');
        $seal = str_replace(
            ['{title}', '{author}'],
            [strip_tags($title), strip_tags($author)],
            $seal_tpl
        );
        $seal = self::h($seal);

        $about_blocks = self::paragraphs((string) ($payload['about'] ?? ''));
        $tips = self::list_items((string) ($payload['tips'] ?? ''));

        $recipes_html = '';
        foreach ($recipes as $idx => $recipe) {
            $n = $idx + 1;
            $ingredients_html = '';
            foreach ($recipe['ingredients'] as $item) {
                $ingredients_html .= '<li>' . self::h($item) . '</li>';
            }
            $steps_html = '';
            foreach ($recipe['steps'] as $step) {
                $steps_html .= '<li>' . self::h($step) . '</li>';
            }
            $tip = $recipe['tip'] !== '' ? $recipe['tip'] : 'Ajuste porções conforme sua necessidade.';

            $recipes_html .= '
<section class="recipe" aria-label="' . self::h($recipe['title']) . '">
  <div class="recipe-title">
    <span class="badge">' . $n . '</span>
    <h2>' . self::h($recipe['title']) . '</h2>
  </div>
  <div class="recipe-cols">
    <div>
      <h3>Ingredientes</h3>
      <ul>' . $ingredients_html . '</ul>
    </div>
    <div>
      <h3>Modo de preparo</h3>
      <ol>' . $steps_html . '</ol>
    </div>
  </div>
  <div class="tip"><strong>Dica:</strong> ' . self::h($tip) . '</div>
</section>';
        }

        $tips_html = '';
        foreach ($tips as $item) {
            $tips_html .= '<li>' . self::h($item) . '</li>';
        }
        $about_html = '';
        foreach ($about_blocks as $item) {
            $about_html .= '<p>' . self::h($item) . '</p>';
        }

        $year = gmdate('Y');

        return '<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>' . $title . '</title>
  <style>
    @page {
      size: A4;
      margin: 16mm 14mm;
      background: ' . $theme['page_bg'] . ';
      @bottom-left { content: "' . $seal . '"; font-size: 8pt; color: ' . $theme['muted'] . '; }
      @bottom-center { content: counter(page); font-size: 9pt; color: ' . $theme['muted'] . '; }
    }

    * { box-sizing: border-box; }
    body { margin: 0; color: ' . $theme['text'] . '; font: 11pt/1.45 "Segoe UI", Arial, sans-serif; }
    .cover {
      page: cover;
      margin: -16mm -14mm 8mm;
      min-height: 140mm;
      padding: 24mm 16mm;
      background: linear-gradient(145deg, ' . $theme['cover_bg'] . ', ' . $theme['cover_bg_2'] . ');
      color: #fff;
    }
    .cover h1 { margin: 0 0 6mm; font: 700 28pt/1.1 Georgia, serif; }
    .cover p { margin: 0 0 3mm; font-size: 12pt; }
    .cover .author { margin-top: 12mm; letter-spacing: 2px; text-transform: uppercase; font-size: 10pt; }
    .section-title {
      margin: 0 0 5mm;
      color: ' . $theme['heading'] . ';
      font: 700 19pt/1.2 Georgia, serif;
      border-bottom: 2px solid ' . $theme['accent'] . ';
      padding-bottom: 2mm;
    }
    .recipe {
      page-break-inside: avoid;
      margin: 0 0 6mm;
      background: ' . $theme['card_bg'] . ';
      border: 1px solid ' . $theme['card_border'] . ';
      border-radius: 10px;
      padding: 4mm;
    }
    .recipe-title { display: flex; align-items: center; gap: 2mm; margin-bottom: 2mm; }
    .recipe-title h2 { margin: 0; font-size: 13pt; color: ' . $theme['heading'] . '; }
    .badge {
      width: 7mm; height: 7mm; display: inline-flex; align-items: center; justify-content: center;
      border-radius: 999px; background: ' . $theme['accent'] . '; color: #fff; font-weight: 700; font-size: 8pt;
    }
    .recipe-cols { display: grid; grid-template-columns: 0.44fr 0.56fr; gap: 4mm; }
    h3 { margin: 0 0 1.2mm; color: ' . $theme['heading'] . '; font-size: 9.6pt; text-transform: uppercase; letter-spacing: .4px; }
    ul, ol { margin: 0; padding-left: 4mm; }
    li { margin-bottom: 1.2mm; }
    .tip {
      margin-top: 2.2mm;
      padding: 2mm 2.6mm;
      border-radius: 7px;
      background: ' . $theme['tip_bg'] . ';
      color: #fff;
      font-size: 9.5pt;
    }
    .tips, .about { margin-top: 8mm; }
    .tips ul { margin: 0; padding-left: 5mm; }
    .about p { margin: 0 0 2.5mm; }
    .footer-note { margin-top: 9mm; font-size: 9pt; color: ' . $theme['muted'] . '; }
  </style>
</head>
<body>
  <section class="cover">
    <h1>' . $title . '</h1>
    <p>' . $subtitle . '</p>
    <p class="author">' . $author . '</p>
  </section>

  <h1 class="section-title">Receitas</h1>
  ' . $recipes_html . '

  <section class="tips">
    <h1 class="section-title">Dicas</h1>
    <ul>' . $tips_html . '</ul>
  </section>

  <section class="about">
    <h1 class="section-title">Sobre o autor</h1>
    ' . $about_html . '
    <p class="footer-note">&copy; ' . $year . ' ' . $author . ' — Todos os direitos reservados.</p>
  </section>
</body>
</html>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recipes_from_raw(string $raw): array
    {
        return self::parse_recipes($raw);
    }

    /**
     * @param array<int, array<string, mixed>> $manual
     * @param array<int, array<string, mixed>> $imported
     * @return array<int, array<string, mixed>>
     */
    public static function merge_recipes(array $manual, array $imported): array
    {
        $all = array_merge($manual, $imported);
        $seen = [];
        $out = [];

        foreach ($all as $recipe) {
            $title = isset($recipe['title']) ? (string) $recipe['title'] : '';
            if ($title === '') {
                continue;
            }

            $normalized = mb_strtolower($title, 'UTF-8');
            $key = sanitize_title(remove_accents($normalized));
            $score = count((array) ($recipe['ingredients'] ?? [])) + count((array) ($recipe['steps'] ?? []));

            if (! isset($seen[$key])) {
                $seen[$key] = ['idx' => count($out), 'score' => $score];
                $out[] = $recipe;
                continue;
            }

            if ($score > (int) $seen[$key]['score']) {
                $idx = (int) $seen[$key]['idx'];
                $out[$idx] = $recipe;
                $seen[$key]['score'] = $score;
            }
        }

        return array_values($out);
    }

    private static function sample_recipes_raw(): string
    {
        return implode("\n", [
            'Panqueca de Banana',
            'Ingredientes:',
            '- 1 banana madura',
            '- 1 ovo',
            '- Canela a gosto',
            'Modo de preparo:',
            '1. Amasse a banana e misture com o ovo.',
            '2. Leve à frigideira antiaderente em fogo baixo.',
            '3. Doure dos dois lados e finalize com canela.',
            'Dica:',
            'Sirva com iogurte natural para aumentar proteínas.',
            '',
            '---',
            '',
            'Omelete com Legumes',
            'Ingredientes:',
            '- 2 ovos',
            '- 1/2 tomate picado',
            '- 2 colheres de espinafre picado',
            '- Sal e pimenta a gosto',
            'Modo de preparo:',
            '1. Bata os ovos e adicione os legumes.',
            '2. Tempere e cozinhe em frigideira antiaderente.',
            '3. Dobre a omelete quando estiver firme.',
            'Dica:',
            'Finalize com azeite extravirgem após o preparo.',
        ]);
    }

    private static function parse_recipes(string $raw): array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            return [];
        }

        $blocks = preg_split('/^\s*---+\s*$/m', $raw) ?: [];
        $recipes = [];

        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block))), static function ($line) {
                return $line !== '';
            }));
            if (! $lines) {
                continue;
            }

            $title = array_shift($lines);
            $section = '';
            $ingredients = [];
            $steps = [];
            $tip = '';

            foreach ($lines as $line) {
                $low = mb_strtolower($line);
                if (strpos($low, 'ingredientes') !== false) {
                    $section = 'ingredients';
                    continue;
                }
                if (strpos($low, 'modo de preparo') !== false || strpos($low, 'preparo') !== false) {
                    $section = 'steps';
                    continue;
                }
                if (strpos($low, 'dica') === 0) {
                    $section = 'tip';
                    $tip_text = trim(preg_replace('/^dica\s*:?\s*/iu', '', $line) ?? '');
                    if ($tip_text !== '') {
                        $tip = $tip_text;
                    }
                    continue;
                }

                if ($section === 'ingredients') {
                    $ingredients[] = ltrim(preg_replace('/^[-*]\s*/', '', $line) ?? $line);
                    continue;
                }
                if ($section === 'steps') {
                    $steps[] = ltrim(preg_replace('/^\d+[\).:-]?\s*/', '', $line) ?? $line);
                    continue;
                }
                if ($section === 'tip') {
                    $tip = trim($tip . ' ' . $line);
                }
            }

            if (! $ingredients) {
                $ingredients = ['Ingredientes conforme orientação nutricional.'];
            }
            if (! $steps) {
                $steps = ['Organize os ingredientes.', 'Prepare em fogo baixo.', 'Ajuste temperos e sirva.'];
            }

            $recipes[] = [
                'title' => $title,
                'ingredients' => $ingredients,
                'steps' => $steps,
                'tip' => $tip,
            ];
        }

        return $recipes;
    }

    private static function theme_palette(string $theme): array
    {
        $palettes = [
            'grafite-dourado' => [
                'cover_bg' => '#242A33',
                'cover_bg_2' => '#3A3F47',
                'page_bg' => '#F5F1E8',
                'heading' => '#242A33',
                'accent' => '#B89246',
                'text' => '#21262D',
                'muted' => '#6E727A',
                'card_bg' => '#FFFDF7',
                'card_border' => '#E2D4B2',
                'tip_bg' => '#242A33',
            ],
            'azul-mineral' => [
                'cover_bg' => '#1E3A5D',
                'cover_bg_2' => '#2E577D',
                'page_bg' => '#EEF3F7',
                'heading' => '#1E3A5D',
                'accent' => '#4AA3A1',
                'text' => '#1F2933',
                'muted' => '#5F6F80',
                'card_bg' => '#F8FCFF',
                'card_border' => '#BDD6E6',
                'tip_bg' => '#1E3A5D',
            ],
            'terracota-moderna' => [
                'cover_bg' => '#7A3E2B',
                'cover_bg_2' => '#9E5B40',
                'page_bg' => '#FAEEE6',
                'heading' => '#7A3E2B',
                'accent' => '#C9895C',
                'text' => '#2F241F',
                'muted' => '#7A6358',
                'card_bg' => '#FFF8F2',
                'card_border' => '#E9C7AB',
                'tip_bg' => '#7A3E2B',
            ],
            'oliva-areia' => [
                'cover_bg' => '#4A5D3A',
                'cover_bg_2' => '#647B4E',
                'page_bg' => '#F6F1E6',
                'heading' => '#4A5D3A',
                'accent' => '#C08A57',
                'text' => '#2D3127',
                'muted' => '#727860',
                'card_bg' => '#FFFBF2',
                'card_border' => '#DDD2B7',
                'tip_bg' => '#4A5D3A',
            ],
        ];

        return $palettes[$theme] ?? $palettes['grafite-dourado'];
    }

    private static function paragraphs(string $text): array
    {
        $parts = preg_split("/\n\s*\n/", trim(str_replace("\r\n", "\n", $text))) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static function ($item) {
            return $item !== '';
        }));
        return $parts ?: ['Conteúdo sobre o autor não preenchido.'];
    }

    private static function list_items(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $text))), static function ($line) {
            return $line !== '';
        }));
        return $lines ?: ['Dica não preenchida.'];
    }

    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
