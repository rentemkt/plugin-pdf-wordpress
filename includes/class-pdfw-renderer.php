<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Renderer
{
    public static function theme_options(): array
    {
        return [
            'ebook2-classic' => 'Ebook2 Clássico',
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
            'theme' => 'ebook2-classic',
            'drive_folder_url' => '',
            'cover_image' => '',
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
        $theme_key = (string) ($payload['theme'] ?? 'ebook2-classic');
        $theme = self::theme_palette($theme_key);

        $recipes = is_array($recipes_override)
            ? $recipes_override
            : self::parse_recipes((string) ($payload['recipes_raw'] ?? ''));
        if (! $recipes) {
            $recipes = self::parse_recipes(self::sample_recipes_raw());
        }

        $categories = self::categorize_recipes($recipes);

        $title_raw = (string) ($payload['title'] ?? 'Ebook de Receitas');
        $subtitle_raw = (string) ($payload['subtitle'] ?? 'Receitas práticas');
        $author_raw = (string) ($payload['author'] ?? 'Autor');

        $title = self::h($title_raw);
        $subtitle = self::h($subtitle_raw);
        $author = self::h($author_raw);

        $seal_tpl = (string) ($payload['seal'] ?? '');
        $seal_text_raw = str_replace(
            ['{title}', '{author}'],
            [$title_raw, $author_raw],
            $seal_tpl
        );
        $seal_text = self::h($seal_text_raw);
        $seal_css = str_replace(['\\', '"'], ['\\\\', '\\"'], strip_tags($seal_text_raw));

        $about_blocks = self::paragraphs((string) ($payload['about'] ?? ''));
        $tips = self::list_items((string) ($payload['tips'] ?? ''));
        $cover_media_html = self::cover_media_html((string) ($payload['cover_image'] ?? ''), $title_raw);

        $about_html = '';
        foreach ($about_blocks as $paragraph) {
            $about_html .= '<p>' . self::h($paragraph) . '</p>';
        }

        $quick_list_html = '';
        foreach (array_slice($tips, 0, 4) as $tip) {
            $quick_list_html .= '<li>' . self::h($tip) . '</li>';
        }
        if ($quick_list_html === '') {
            $quick_list_html = '<li>Inclua frutas, castanhas e fontes de proteína para maior saciedade.</li>';
        }

        $toc_html = '';
        $toc_html .= '<div class="toc-category">Abertura</div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Observação</span><span class="toc-dots"></span><span class="toc-page">3</span></div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Sugestões de Lanches</span><span class="toc-dots"></span><span class="toc-page">4</span></div>';

        $page_cursor = 5;
        $global_index = 1;
        foreach ($categories as $category) {
            $toc_html .= '<div class="toc-category">' . self::h($category['title']) . '</div>';
            $page_cursor++;
            foreach ($category['recipes'] as $recipe) {
                $toc_html .= '<div class="toc-entry"><span class="toc-name">'
                    . self::h($global_index . '. ' . (string) ($recipe['title'] ?? 'Receita'))
                    . '</span><span class="toc-dots"></span><span class="toc-page">'
                    . $page_cursor
                    . '</span></div>';
                $page_cursor += 2;
                $global_index++;
            }
        }

        $tips_page = $page_cursor;
        $about_page = $tips_page + 1;
        $toc_html .= '<div class="toc-category">Extras</div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Dicas finais</span><span class="toc-dots"></span><span class="toc-page">' . $tips_page . '</span></div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Sobre o Autor</span><span class="toc-dots"></span><span class="toc-page">' . $about_page . '</span></div>';

        $recipe_sections_html = '';
        $global_index = 1;
        foreach ($categories as $category_index => $category) {
            $roman = self::roman_numeral($category_index + 1);
            $recipe_sections_html .= '<div class="category-divider">'
                . '<div class="category-divider-num">' . self::h($roman) . '</div>'
                . '<h2>' . self::h(mb_strtoupper((string) $category['title'], 'UTF-8')) . '</h2>'
                . '<p class="category-divider-sub">' . self::h((string) $category['subtitle']) . '</p>'
                . '</div>';

            foreach ($category['recipes'] as $recipe) {
                $recipe_title_raw = (string) ($recipe['title'] ?? 'Receita');
                $recipe_title = self::h($recipe_title_raw);

                $ingredients_html = '';
                foreach ((array) ($recipe['ingredients'] ?? []) as $item) {
                    $ingredients_html .= '<li>' . self::h((string) $item) . '</li>';
                }

                $steps_html = '';
                foreach ((array) ($recipe['steps'] ?? []) as $step) {
                    $steps_html .= '<li>' . self::h((string) $step) . '</li>';
                }

                $tip_raw = trim((string) ($recipe['tip'] ?? ''));
                if ($tip_raw === '') {
                    $tip_raw = 'Ajuste as porções conforme sua necessidade e prefira ingredientes minimamente processados.';
                }

                $meta = self::estimate_recipe_meta($recipe);
                $nutri = self::estimate_nutrition($recipe);
                $description = self::recipe_description($recipe_title_raw);
                $media_html = self::recipe_media_html($recipe, $theme, $global_index, $recipe_title_raw);

                $recipe_sections_html .= '<div class="recipe-title-page">'
                    . '<div class="recipe-image-container">'
                    . $media_html
                    . '<div class="recipe-name-overlay"><h2><span class="recipe-badge">' . $global_index . '</span> ' . $recipe_title . '</h2></div>'
                    . '</div>'
                    . '<div class="recipe-meta">'
                    . '<div class="recipe-meta-item">Tempo: ' . self::h($meta['tempo']) . '</div>'
                    . '<div class="recipe-meta-item">Porções: ' . self::h($meta['porcoes']) . '</div>'
                    . '<div class="recipe-meta-item">Nível: ' . self::h($meta['nivel']) . '</div>'
                    . '</div>'
                    . '<p class="recipe-description">' . self::h($description) . '</p>'
                    . '</div>';

                $recipe_sections_html .= '<div class="recipe-content-page">'
                    . '<div class="recipe-columns">'
                    . '<div class="col-ing"><h3>Ingredientes</h3><ul>' . $ingredients_html . '</ul></div>'
                    . '<div class="col-step"><h3>Modo de preparo</h3><ol>' . $steps_html . '</ol></div>'
                    . '</div>'
                    . '<div class="tip-box"><div class="tip-title">Dica do Daniel</div><p>' . self::h($tip_raw) . '</p></div>'
                    . '<div class="nutri-box">'
                    . '<div class="nutri-title">Informação Nutricional (por porção aprox.)</div>'
                    . '<div class="nutri-grid">'
                    . '<div class="nutri-item"><span class="nutri-value">' . self::h($nutri['kcal']) . '</span><span class="nutri-label">Calorias</span></div>'
                    . '<div class="nutri-item"><span class="nutri-value">' . self::h($nutri['carb']) . '</span><span class="nutri-label">Carboidratos</span></div>'
                    . '<div class="nutri-item"><span class="nutri-value">' . self::h($nutri['prot']) . '</span><span class="nutri-label">Proteínas</span></div>'
                    . '<div class="nutri-item"><span class="nutri-value">' . self::h($nutri['fat']) . '</span><span class="nutri-label">Gorduras</span></div>'
                    . '<div class="nutri-item"><span class="nutri-value">' . self::h($nutri['fiber']) . '</span><span class="nutri-label">Fibras</span></div>'
                    . '</div></div>'
                    . '</div>';

                $global_index++;
            }
        }

        $tips_items_html = '';
        foreach ($tips as $idx => $tip) {
            $tips_items_html .= '<div class="tip-item">'
                . '<div class="tip-num">' . ($idx + 1) . '</div>'
                . '<div class="tip-content"><h3>Dica prática ' . ($idx + 1) . '</h3><p>' . self::h($tip) . '</p></div>'
                . '</div>';
        }

        $year = gmdate('Y');
        $author_logo = self::h(mb_strtoupper($author_raw, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} | {$author}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
@page {
  size: 148mm 210mm;
  margin: 15mm 12mm;
  background: {$theme['page_bg']};
  @bottom-left {
    content: "{$seal_css}";
    font-family: 'Noto Sans','Calibri',sans-serif;
    font-size: 6pt;
    color: {$theme['muted']};
  }
  @bottom-center {
    content: counter(page);
    font-family: 'Georgia','Noto Serif',serif;
    font-size: 8.5pt;
    color: {$theme['muted']};
  }
}
@page :first { @bottom-left { content:none; } @bottom-center { content:none; } }
@page cover { margin:0; @bottom-left { content:none; } @bottom-center { content:none; } }
@page category-divider { margin:0; @bottom-left { content:none; } @bottom-center { content:none; } }
@page back-cover { @bottom-left { content:none; } @bottom-center { content:none; } }

:root {
  --cover-bg: {$theme['cover_bg']};
  --cover-bg-2: {$theme['cover_bg_2']};
  --page-bg: {$theme['page_bg']};
  --heading: {$theme['heading']};
  --accent: {$theme['accent']};
  --text: {$theme['text']};
  --muted: {$theme['muted']};
  --card-bg: {$theme['card_bg']};
  --card-border: {$theme['card_border']};
  --tip-bg: {$theme['tip_bg']};
  --cover-sub: {$theme['cover_subtext']};
  --divider-sub: {$theme['divider_subtext']};
}

html,body { font-family:'Noto Sans','Calibri',sans-serif; font-size:10.5pt; line-height:1.45; color:var(--text); background:var(--page-bg); }
.toc,.intro,.quick-options,.recipe-title-page,.recipe-content-page,.tips-page,.back-cover{background:var(--page-bg);}
.cover{page:cover;page-break-before:always;width:148mm;height:210mm;background:linear-gradient(155deg,var(--cover-bg),var(--cover-bg-2));position:relative;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;overflow:hidden;color:#fff;}
.cover-media{position:absolute;inset:0;z-index:0;overflow:hidden;}
.cover-media img{width:100%;height:100%;object-fit:cover;display:block;}
.cover-media::after{content:"";position:absolute;inset:0;background:rgba(13,28,28,.42);}
.cover::before{content:"";position:absolute;top:-35mm;left:-40mm;width:130mm;height:130mm;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%);border-radius:50%;}
.cover::after{content:"";position:absolute;bottom:-45mm;right:-35mm;width:150mm;height:150mm;background:radial-gradient(circle,rgba(255,255,255,.14),transparent 70%);border-radius:50%;}
.cover-title{font-family:'Georgia','Noto Serif',serif;font-size:24pt;line-height:1.2;text-transform:uppercase;letter-spacing:1.6pt;position:relative;z-index:2;padding:0 12mm;}
.cover-subtitle{margin-top:3mm;color:var(--cover-sub);font-size:12pt;font-style:italic;position:relative;z-index:2;}
.cover-author{position:absolute;bottom:16mm;left:0;right:0;text-align:center;color:var(--cover-sub);font-size:10.5pt;letter-spacing:3pt;text-transform:uppercase;z-index:2;}
.toc{page-break-before:always;padding-top:4mm;}
.toc h1{font-family:'Georgia','Noto Serif',serif;font-size:18pt;color:var(--heading);text-align:center;margin-bottom:6mm;}
.toc-category{font-size:9.4pt;font-weight:700;text-transform:uppercase;letter-spacing:1pt;color:var(--heading);border-bottom:1px solid var(--accent);margin-top:4.5mm;margin-bottom:2mm;padding-bottom:1mm;}
.toc-entry{display:flex;align-items:baseline;justify-content:space-between;font-size:9.3pt;line-height:1.8;padding-left:2.6mm;}
.toc-dots{flex:1;border-bottom:1px dotted var(--accent);margin:0 2mm;min-width:12mm;}
.toc-page{min-width:8mm;text-align:right;color:var(--accent);font-weight:700;}
.intro,.quick-options,.tips-page{page-break-before:always;}
.intro h1,.quick-options h1,.tips-page h1{font-family:'Georgia','Noto Serif',serif;font-size:17pt;color:var(--heading);text-align:center;margin-bottom:4mm;}
.intro-line,.quick-line,.tips-line{width:32mm;height:1.6px;background:linear-gradient(90deg,transparent,var(--accent),transparent);margin:0 auto 5mm auto;}
.intro p{font-size:10pt;line-height:1.52;text-align:justify;margin-bottom:3.2mm;}
.quick-card{border:1px solid var(--card-border);border-radius:10px;background:var(--card-bg);padding:4mm;margin-bottom:4mm;}
.quick-card h3{font-size:10pt;text-transform:uppercase;letter-spacing:.8pt;color:var(--heading);margin-bottom:2.4mm;}
.quick-card ul{list-style:none;padding:0;}
.quick-card li{font-size:9.7pt;line-height:1.55;padding-left:3mm;position:relative;margin-bottom:1.4mm;}
.quick-card li::before{content:"•";color:var(--accent);position:absolute;left:0;}
.category-divider{page:category-divider;page-break-before:always;width:148mm;height:210mm;background:var(--cover-bg);color:#fff;position:relative;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;}
.category-divider::before{content:"";position:absolute;top:0;left:0;right:0;bottom:0;background:repeating-linear-gradient(-45deg,transparent,transparent 24mm,rgba(255,255,255,.08) 24mm,rgba(255,255,255,.08) 24.7mm);}
.category-divider-num{font-family:'Georgia','Noto Serif',serif;font-size:54pt;color:rgba(255,248,240,.20);z-index:1;}
.category-divider h2{z-index:1;font-family:'Georgia','Noto Serif',serif;font-size:18pt;letter-spacing:2.2pt;text-transform:uppercase;line-height:1.28;padding:0 8mm;}
.category-divider-sub{z-index:1;color:var(--divider-sub);margin-top:4mm;font-size:10pt;font-style:italic;}
.recipe-title-page{page-break-before:always;position:relative;}
.recipe-image-container{width:calc(100% + 24mm);height:106mm;margin:-15mm -12mm 0 -12mm;overflow:hidden;position:relative;}
.recipe-image-container img{width:100%;height:100%;object-fit:cover;display:block;}
.recipe-image-fallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.recipe-image-fallback span{font-family:'Georgia','Noto Serif',serif;color:rgba(255,255,255,.28);font-size:16pt;letter-spacing:1.3pt;text-transform:uppercase;padding:0 8mm;text-align:center;}
.recipe-name-overlay{position:absolute;left:0;right:0;bottom:0;background:linear-gradient(transparent,rgba(24,53,50,.88));color:#fff;padding:13mm 5mm 4.5mm 5mm;}
.recipe-name-overlay h2{font-family:'Georgia','Noto Serif',serif;font-size:14.2pt;line-height:1.2;}
.recipe-badge{display:inline-block;width:7mm;height:7mm;line-height:7mm;text-align:center;border-radius:50%;margin-right:2mm;background:var(--accent);color:#fff;font-size:8pt;font-weight:700;vertical-align:middle;}
.recipe-meta{display:flex;gap:5mm;margin-top:4mm;font-size:9.2pt;color:#555;}
.recipe-meta-item{background:#fff8f0;border:1px solid #e7d3c3;border-radius:4px;padding:1.3mm 2.8mm;}
.recipe-description{margin-top:4mm;border-left:3px solid var(--accent);padding-left:3mm;font-size:9.8pt;line-height:1.5;color:#444;font-style:italic;}
.recipe-content-page{page-break-before:always;font-size:9.4pt;}
.recipe-content-page h3{font-size:9pt;color:var(--heading);text-transform:uppercase;letter-spacing:.8pt;border-bottom:1px solid var(--accent);padding-bottom:1mm;margin-bottom:2mm;}
.recipe-columns{display:flex;gap:4mm;}
.col-ing{width:41%;}
.col-step{width:57%;}
.col-ing ul{list-style:none;padding:0;}
.col-ing li{position:relative;padding-left:3mm;margin-bottom:1.2mm;line-height:1.46;}
.col-ing li::before{content:"•";color:var(--accent);position:absolute;left:0;}
.col-step ol{list-style:none;padding-left:0;counter-reset:etapa;}
.col-step li{position:relative;padding-left:5mm;margin-bottom:1.4mm;line-height:1.46;counter-increment:etapa;}
.col-step li::before{content:counter(etapa) ".";color:var(--heading);position:absolute;left:0;font-weight:700;}
.tip-box{margin-top:4mm;background:var(--tip-bg);color:#fff;border-radius:8px;padding:3mm 4mm;page-break-inside:avoid;}
.tip-title{color:var(--divider-sub);text-transform:uppercase;font-weight:700;font-size:8.4pt;margin-bottom:1mm;letter-spacing:.5pt;}
.tip-box p{font-size:8.5pt;line-height:1.4;}
.nutri-box{margin-top:3mm;border:1px solid var(--accent);border-radius:6px;padding:2.5mm 3mm;background:#fff8f0;page-break-inside:avoid;}
.nutri-title{font-size:8pt;color:var(--heading);text-transform:uppercase;font-weight:700;margin-bottom:1.2mm;}
.nutri-grid{display:flex;justify-content:space-between;flex-wrap:wrap;}
.nutri-item{flex:1;min-width:18mm;text-align:center;}
.nutri-value{font-size:10.8pt;color:var(--accent);font-weight:700;display:block;}
.nutri-label{font-size:7pt;color:#666;text-transform:uppercase;}
.tip-item{display:flex;gap:3mm;margin-bottom:4mm;}
.tip-num{width:8mm;height:8mm;min-width:8mm;border-radius:50%;background:var(--heading);color:#fff;font-weight:700;text-align:center;line-height:8mm;}
.tip-content h3{font-size:9.8pt;color:var(--heading);margin-bottom:.8mm;}
.tip-content p{font-size:9.3pt;line-height:1.43;}
.back-cover{page:back-cover;page-break-before:always;min-height:170mm;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;}
.back-cover h2{font-family:'Georgia','Noto Serif',serif;font-size:16pt;color:var(--heading);margin-bottom:3mm;}
.back-line{width:26mm;height:1.6px;background:linear-gradient(90deg,transparent,var(--accent),transparent);margin:0 auto 5mm auto;}
.back-cover p{max-width:102mm;font-size:10pt;line-height:1.6;color:#444;margin:0 auto 3mm auto;}
.back-seal{margin-top:4mm;font-size:8.2pt;color:var(--muted);font-style:italic;}
.back-logo{margin-top:9mm;font-family:'Georgia','Noto Serif',serif;font-size:14pt;letter-spacing:3pt;color:var(--heading);font-weight:700;}
.back-year{margin-top:2mm;color:#888;font-size:9pt;}
</style>
</head>
<body>
<div class="cover">
  {$cover_media_html}
  <h1 class="cover-title">{$title}</h1>
  <p class="cover-subtitle">{$subtitle}</p>
  <p class="cover-author">{$author}</p>
</div>

<div class="toc">
  <h1>Sumário</h1>
  {$toc_html}
</div>

<div class="intro">
  <h1>Observação</h1>
  <div class="intro-line"></div>
  <p>{$title}</p>
  <p>Existem dias em que não é possível realizar uma refeição completa no horário habitual. Em outras situações, algumas pessoas simplesmente preferem algo mais leve nesse período.</p>
  <p>Nesses momentos, fazer boas escolhas é fundamental para manter a glicemia estável, evitar picos de açúcar no sangue e promover saciedade com equilíbrio nutricional.</p>
  <p>As opções a seguir foram selecionadas com foco em praticidade, controle glicêmico e qualidade nutricional, priorizando alimentos que auxiliam no controle da fome e oferecem melhor resposta metabólica.</p>
</div>

<div class="quick-options">
  <h1>Sugestões de Lanches</h1>
  <div class="quick-line"></div>
  <div class="quick-card">
    <h3>Sugestões rápidas</h3>
    <ul>{$quick_list_html}</ul>
  </div>
</div>

{$recipe_sections_html}

<div class="tips-page">
  <h1>Dicas finais</h1>
  <div class="tips-line"></div>
  {$tips_items_html}
</div>

<div class="back-cover">
  <h2>Sobre o Autor</h2>
  <div class="back-line"></div>
  {$about_html}
  <p class="back-seal">{$seal_text}</p>
  <div class="back-logo">{$author_logo}</div>
  <div class="back-year">&copy; {$year} {$author} — Todos os direitos reservados.</div>
</div>
</body>
</html>
HTML;
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

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @param array<int, array<string, mixed>> $image_entries
     * @return array<int, array<string, mixed>>
     */
    public static function apply_images(array $recipes, array $image_entries): array
    {
        $available = [];
        foreach ($image_entries as $entry) {
            $src = isset($entry['src']) ? trim((string) $entry['src']) : '';
            if ($src === '') {
                continue;
            }
            if (! empty($entry['is_cover_hint'])) {
                continue;
            }
            $available[] = [
                'src' => $src,
                'key' => self::normalize_media_key((string) ($entry['key'] ?? $entry['base'] ?? $entry['name'] ?? '')),
            ];
        }

        foreach ($recipes as $idx => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $existing_image = isset($recipe['image']) ? trim((string) $recipe['image']) : '';
            if ($existing_image !== '') {
                continue;
            }

            $title = (string) ($recipe['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $title_key = self::normalize_media_key($title);
            $title_tokens = self::title_tokens($title_key);

            $best_idx = -1;
            $best_score = 0;
            foreach ($available as $image_idx => $entry) {
                $image_key = (string) ($entry['key'] ?? '');
                if ($image_key === '') {
                    continue;
                }

                $score = 0;
                if ($image_key === $title_key) {
                    $score = 100;
                } elseif (strpos($image_key, $title_key) !== false || strpos($title_key, $image_key) !== false) {
                    $score = 80;
                } else {
                    $image_tokens = self::title_tokens($image_key);
                    $overlap = array_intersect($title_tokens, $image_tokens);
                    $score = count($overlap) * 10;
                }

                if ($score > $best_score) {
                    $best_score = $score;
                    $best_idx = $image_idx;
                }
            }

            if ($best_idx >= 0 && $best_score >= 20) {
                $recipes[$idx]['image'] = (string) ($available[$best_idx]['src'] ?? '');
                unset($available[$best_idx]);
                $available = array_values($available);
            }
        }

        return $recipes;
    }

    /**
     * @param array<int, array<string, mixed>> $image_entries
     */
    public static function pick_cover_image(array $image_entries): string
    {
        foreach ($image_entries as $entry) {
            if (! empty($entry['is_cover_hint']) && ! empty($entry['src'])) {
                return (string) $entry['src'];
            }
        }
        foreach ($image_entries as $entry) {
            if (! empty($entry['src'])) {
                return (string) $entry['src'];
            }
        }
        return '';
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

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<int, array<string, mixed>>
     */
    private static function categorize_recipes(array $recipes): array
    {
        $biomassa = [];
        $ovos = [];
        $other = [];

        foreach ($recipes as $recipe) {
            $title = (string) ($recipe['title'] ?? '');
            $normalized = self::normalize_for_match($title);

            if (strpos($normalized, 'biomassa') !== false) {
                $biomassa[] = $recipe;
                continue;
            }

            if (preg_match('/\b(ovo|ovos|omelete|omeleta)\b/u', $normalized)) {
                $ovos[] = $recipe;
                continue;
            }

            $other[] = $recipe;
        }

        $split = count($other) <= 2 ? count($other) : max(2, (int) ceil(count($other) * 0.35));
        $split = min($split, count($other));

        $rapidas = array_slice($other, 0, $split);
        $saudaveis = array_slice($other, $split);

        $categories = [];
        if ($rapidas) {
            $categories[] = self::build_category(
                'rapidas',
                'Receitas Rápidas',
                $rapidas,
                sprintf('%d %s práticas para o dia a dia', count($rapidas), count($rapidas) === 1 ? 'receita' : 'receitas')
            );
        }
        if ($biomassa) {
            $categories[] = self::build_category(
                'biomassa',
                'Receitas com Biomassa de Banana',
                $biomassa,
                sprintf('%d %s nutritivas com biomassa de banana verde', count($biomassa), count($biomassa) === 1 ? 'opção' : 'opções')
            );
        }
        if ($saudaveis) {
            $categories[] = self::build_category(
                'saudaveis',
                'Mais Receitas Saudáveis',
                $saudaveis,
                sprintf('%d %s variadas para manter o equilíbrio', count($saudaveis), count($saudaveis) === 1 ? 'opção' : 'opções')
            );
        }
        if ($ovos) {
            $categories[] = self::build_category(
                'ovos',
                'Receitas com Ovos',
                $ovos,
                sprintf('%d %s proteicas e de alta saciedade', count($ovos), count($ovos) === 1 ? 'receita' : 'receitas')
            );
        }

        if (! $categories) {
            $categories[] = self::build_category('receitas', 'Receitas', $recipes, sprintf('%d receitas organizadas automaticamente', count($recipes)));
        }

        return $categories;
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<string, mixed>
     */
    private static function build_category(string $id, string $title, array $recipes, string $subtitle): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'recipes' => array_values($recipes),
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array{tempo: string, porcoes: string, nivel: string}
     */
    private static function estimate_recipe_meta(array $recipe): array
    {
        $steps_count = count((array) ($recipe['steps'] ?? []));
        $ingredients_count = count((array) ($recipe['ingredients'] ?? []));

        if ($steps_count <= 4) {
            $tempo = '20 min';
        } elseif ($steps_count <= 8) {
            $tempo = '30 min';
        } else {
            $tempo = '45 min';
        }

        if ($ingredients_count <= 4) {
            $porcoes = '2 porções';
        } elseif ($ingredients_count <= 8) {
            $porcoes = '4 porções';
        } else {
            $porcoes = '6 porções';
        }

        if ($steps_count <= 5) {
            $nivel = 'Fácil';
        } elseif ($steps_count <= 9) {
            $nivel = 'Médio';
        } else {
            $nivel = 'Avançado';
        }

        return [
            'tempo' => $tempo,
            'porcoes' => $porcoes,
            'nivel' => $nivel,
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array{kcal: string, carb: string, prot: string, fat: string, fiber: string}
     */
    private static function estimate_nutrition(array $recipe): array
    {
        $ingredients_count = max(1, count((array) ($recipe['ingredients'] ?? [])));
        $steps_count = max(1, count((array) ($recipe['steps'] ?? [])));

        $kcal = 120 + ($ingredients_count * 20) + ($steps_count * 4);
        $carb = 8 + ($ingredients_count * 2);
        $prot = 6 + (int) round($ingredients_count * 1.3);
        $fat = 4 + (int) round($ingredients_count * 0.9);
        $fiber = 2 + (int) round($ingredients_count * 0.5);

        return [
            'kcal' => (string) max(120, min(420, $kcal)),
            'carb' => (string) max(8, min(55, $carb)) . 'g',
            'prot' => (string) max(6, min(30, $prot)) . 'g',
            'fat' => (string) max(4, min(24, $fat)) . 'g',
            'fiber' => (string) max(2, min(14, $fiber)) . 'g',
        ];
    }

    private static function recipe_description(string $title): string
    {
        $title_lc = mb_strtolower($title, 'UTF-8');
        if (strpos(self::normalize_for_match($title), 'biomassa') !== false) {
            return 'Receita com biomassa de banana verde, com foco em praticidade, saciedade e equilíbrio metabólico para o dia a dia.';
        }

        return 'Receita prática de ' . $title_lc . ', organizada para facilitar o preparo no dia a dia.';
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function recipe_media_html(array $recipe, array $theme, int $index, string $title): string
    {
        $image = isset($recipe['image']) ? trim((string) $recipe['image']) : '';
        if ($image !== '' && self::is_valid_image_src($image)) {
            return '<img src="' . self::h($image) . '" alt="' . self::h($title) . '">';
        }

        $style = self::recipe_cover_style($theme, $index);
        return '<div class="recipe-image-fallback" style="' . self::h($style) . '"><span>' . self::h($title) . '</span></div>';
    }

    private static function cover_media_html(string $image_src, string $title): string
    {
        $image_src = trim($image_src);
        if (! self::is_valid_image_src($image_src)) {
            return '';
        }
        return '<div class="cover-media"><img src="' . self::h($image_src) . '" alt="' . self::h($title) . '"></div>';
    }

    private static function is_valid_image_src(string $src): bool
    {
        if (filter_var($src, FILTER_VALIDATE_URL)) {
            return true;
        }
        return (bool) preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $src);
    }

    private static function recipe_cover_style(array $theme, int $index): string
    {
        $variants = [
            'linear-gradient(140deg, ' . $theme['cover_bg'] . ' 0%, ' . $theme['accent'] . ' 100%)',
            'linear-gradient(140deg, ' . $theme['cover_bg_2'] . ' 0%, ' . $theme['cover_bg'] . ' 100%)',
            'linear-gradient(140deg, ' . $theme['accent'] . ' 0%, ' . $theme['cover_bg_2'] . ' 100%)',
        ];

        $chosen = $variants[$index % count($variants)];
        return 'background:' . $chosen . ';';
    }

    private static function normalize_for_match(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = remove_accents($text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function normalize_media_key(string $text): string
    {
        $text = self::normalize_for_match($text);
        $text = str_replace(' ', '-', $text);
        $text = preg_replace('/^\\d+[-_]?/', '', (string) $text);
        return trim((string) $text, '-');
    }

    /**
     * @return array<int, string>
     */
    private static function title_tokens(string $key): array
    {
        $parts = preg_split('/[-\\s]+/', $key) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 3) {
                continue;
            }
            if (in_array($part, ['com', 'sem', 'para', 'de', 'da', 'do', 'dos', 'das', 'e'], true)) {
                continue;
            }
            $tokens[] = $part;
        }
        return array_values(array_unique($tokens));
    }

    private static function roman_numeral(int $number): string
    {
        $map = [
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1,
        ];

        $result = '';
        foreach ($map as $roman => $value) {
            while ($number >= $value) {
                $result .= $roman;
                $number -= $value;
            }
        }

        return $result === '' ? 'I' : $result;
    }

    private static function theme_palette(string $theme): array
    {
        $palettes = [
            'ebook2-classic' => [
                'cover_bg' => '#2D5B57',
                'cover_bg_2' => '#204745',
                'page_bg' => '#F2E6D8',
                'heading' => '#2D5B57',
                'accent' => '#C27A5A',
                'text' => '#2A2A2A',
                'muted' => '#777777',
                'card_bg' => '#FFF7EE',
                'card_border' => '#DCBCA3',
                'tip_bg' => '#2D5B57',
                'cover_subtext' => '#F5DEC9',
                'divider_subtext' => '#F2CFB2',
            ],
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
                'cover_subtext' => '#E6D7B8',
                'divider_subtext' => '#E6D7B8',
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
                'cover_subtext' => '#D5E5F2',
                'divider_subtext' => '#CFE8E7',
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
                'cover_subtext' => '#F6D8C5',
                'divider_subtext' => '#F2CCB0',
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
                'cover_subtext' => '#E8DCC5',
                'divider_subtext' => '#E8CDAE',
            ],
        ];

        return $palettes[$theme] ?? $palettes['ebook2-classic'];
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
