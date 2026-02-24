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
            'title' => 'Ebook Educacional',
            'subtitle' => '',
            'author' => '',
            'seal' => 'Material exclusivo do curso {title} desenvolvido por {author}',
            'theme' => 'ebook2-classic',
            'drive_folder_url' => '',
            'cover_image' => '',
            'import_mode' => 'append',
            'categories_raw' => self::sample_categories_raw(),
            'tips' => implode("\n", [
                'Revise os pontos-chave ao final de cada módulo.',
                'Anote dúvidas durante a leitura para revisão posterior.',
                'Aplique os conceitos em situações práticas do seu dia a dia.',
            ]),
            'about' => '',
            'recipes_raw' => self::sample_items_raw(),
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
            $recipes = self::parse_recipes(self::sample_items_raw());
        }

        $categories = self::categorize_recipes($recipes, (string) ($payload['categories_raw'] ?? ''));

        $title_raw = (string) ($payload['title'] ?? 'Ebook Educacional');
        $subtitle_raw = (string) ($payload['subtitle'] ?? '');
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
            $quick_list_html = '<li>Revise os pontos-chave ao final de cada módulo.</li>';
        }

        $toc_html = '';
        $toc_html .= '<div class="toc-category">Abertura</div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Apresentação</span><span class="toc-dots"></span><span class="toc-page">3</span></div>';
        $toc_html .= '<div class="toc-entry"><span class="toc-name">Destaques</span><span class="toc-dots"></span><span class="toc-page">4</span></div>';

        $page_cursor = 5;
        $global_index = 1;
        foreach ($categories as $category) {
            $toc_html .= '<div class="toc-category">' . self::h($category['title']) . '</div>';
            $page_cursor++;
            foreach ($category['recipes'] as $recipe) {
                $is_generic = ! empty($recipe['is_generic']) || ! empty($recipe['isGeneric']);
                $toc_html .= '<div class="toc-entry"><span class="toc-name">'
                    . self::h($global_index . '. ' . (string) ($recipe['title'] ?? 'Item'))
                    . '</span><span class="toc-dots"></span><span class="toc-page">'
                    . $page_cursor
                    . '</span></div>';
                $page_cursor += $is_generic ? 1 : 2;
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
            $category_media_html = self::category_divider_media_html((string) ($category['image'] ?? ''), (string) ($category['title'] ?? 'Categoria'));
            $category_class = $category_media_html !== '' ? 'category-divider has-media' : 'category-divider';

            $recipe_sections_html .= '<div class="' . $category_class . '">'
                . $category_media_html
                . '<div class="category-divider-num">' . self::h($roman) . '</div>'
                . '<h2>' . self::h(mb_strtoupper((string) $category['title'], 'UTF-8')) . '</h2>'
                . '<p class="category-divider-sub">' . self::h((string) $category['subtitle']) . '</p>'
                . '</div>';

            foreach ($category['recipes'] as $recipe) {
                $recipe_title_raw = (string) ($recipe['title'] ?? 'Item');
                $recipe_title = self::h($recipe_title_raw);
                $is_generic = ! empty($recipe['is_generic']) || ! empty($recipe['isGeneric']);

                if ($is_generic) {
                    $edu_description = trim((string) ($recipe['description'] ?? ''));
                    $edu_body_raw = trim((string) ($recipe['body'] ?? ''));
                    $edu_duration = trim((string) ($recipe['duration'] ?? ''));
                    $edu_level = trim((string) ($recipe['level'] ?? ''));
                    $edu_summary = trim((string) ($recipe['summary'] ?? ''));
                    $edu_key_points = is_array($recipe['keyPoints'] ?? null) ? $recipe['keyPoints'] : [];
                    $tip_raw = trim((string) ($recipe['tip'] ?? ''));
                    $category_label = trim((string) ($recipe['category'] ?? ''));
                    $image_src_raw = trim((string) ($recipe['image'] ?? ''));

                    // Hero image
                    $edu_media_html = '';
                    $image_src = self::normalize_image_src_for_output($image_src_raw);
                    if ($image_src !== '') {
                        $edu_media_html = '<div class="edu-hero-media"><img src="' . self::h($image_src) . '" alt="' . $recipe_title . '"></div>';
                    }

                    // Meta bar (duration + level)
                    $edu_meta_html = '';
                    if ($edu_duration !== '' || $edu_level !== '') {
                        $edu_meta_html = '<div class="edu-meta">';
                        if ($edu_duration !== '') {
                            $edu_meta_html .= '<div class="edu-meta-item">Duração: ' . self::h($edu_duration) . '</div>';
                        }
                        if ($edu_level !== '') {
                            $edu_meta_html .= '<div class="edu-meta-item">Nível: ' . self::h($edu_level) . '</div>';
                        }
                        $edu_meta_html .= '</div>';
                    }

                    // Description (short summary in italics)
                    $edu_desc_html = '';
                    if ($edu_description !== '') {
                        $edu_desc_html = '<p class="edu-description">' . self::h($edu_description) . '</p>';
                    }

                    // Body content with markdown-light rendering
                    $edu_body_html = '';
                    $body_source = $edu_body_raw !== '' ? $edu_body_raw : ($edu_description !== '' ? '' : (string) ($recipe['description'] ?? ''));
                    if ($body_source !== '') {
                        $edu_body_html = '<div class="edu-body">' . self::render_educational_body($body_source) . '</div>';
                    }

                    // Key points
                    $edu_kp_html = '';
                    if ($edu_key_points) {
                        $kp_items = '';
                        foreach ($edu_key_points as $kp) {
                            $kp_text = trim((string) $kp);
                            if ($kp_text !== '') {
                                $kp_items .= '<li>' . self::h($kp_text) . '</li>';
                            }
                        }
                        if ($kp_items !== '') {
                            $edu_kp_html = '<div class="edu-keypoints">'
                                . '<div class="edu-keypoints-title">Pontos-chave</div>'
                                . '<ul>' . $kp_items . '</ul>'
                                . '</div>';
                        }
                    }

                    // Summary
                    $edu_summary_html = '';
                    if ($edu_summary !== '') {
                        $edu_summary_html = '<div class="edu-summary">'
                            . '<div class="edu-summary-title">Resumo</div>'
                            . '<p>' . self::h($edu_summary) . '</p>'
                            . '</div>';
                    }

                    // Tip / author note
                    $edu_note_html = '';
                    if ($tip_raw !== '') {
                        $edu_note_html = '<div class="edu-note"><strong>Nota:</strong> ' . self::h($tip_raw) . '</div>';
                    }

                    $recipe_sections_html .= '<div class="edu-content-page">'
                        . $edu_media_html
                        . '<h2><span class="recipe-badge">' . $global_index . '</span> ' . $recipe_title . '</h2>'
                        . ($category_label !== '' ? '<div class="edu-category">' . self::h($category_label) . '</div>' : '')
                        . $edu_meta_html
                        . $edu_desc_html
                        . $edu_body_html
                        . $edu_kp_html
                        . $edu_summary_html
                        . $edu_note_html
                        . '</div>';

                    $global_index++;
                    continue;
                }

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
                $description = self::recipe_description($recipe_title_raw, (string) ($recipe['description'] ?? ''));
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
                    . '<div class="tip-box"><div class="tip-title">Dica do Autor</div><p>' . self::h($tip_raw) . '</p></div>'
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
.toc,.intro,.quick-options,.recipe-title-page,.recipe-content-page,.generic-content-page,.tips-page,.back-cover{background:var(--page-bg);}
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
.category-divider{page:category-divider;page-break-before:always;width:148mm;height:210mm;background:var(--cover-bg);color:#fff;position:relative;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;overflow:hidden;}
.category-divider::before{content:"";position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(rgba(14,32,31,.50),rgba(14,32,31,.50));z-index:1;}
.category-divider:not(.has-media)::after{content:"";position:absolute;top:0;left:0;right:0;bottom:0;background:repeating-linear-gradient(-45deg,transparent,transparent 24mm,rgba(255,255,255,.08) 24mm,rgba(255,255,255,.08) 24.7mm);z-index:1;}
.category-divider-media{position:absolute;top:0;left:0;right:0;bottom:0;z-index:0;}
.category-divider-media img{width:100%;height:100%;object-fit:cover;display:block;}
.category-divider-num{font-family:'Georgia','Noto Serif',serif;font-size:54pt;color:rgba(255,248,240,.20);z-index:2;}
.category-divider h2{z-index:2;font-family:'Georgia','Noto Serif',serif;font-size:18pt;letter-spacing:2.2pt;text-transform:uppercase;line-height:1.28;padding:0 8mm;}
.category-divider-sub{z-index:2;color:var(--divider-sub);margin-top:4mm;font-size:10pt;font-style:italic;}
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
.edu-content-page{page-break-before:always;font-size:10pt;line-height:1.62;}
.edu-hero-media{width:calc(100% + 24mm);height:72mm;margin:-15mm -12mm 5mm -12mm;overflow:hidden;}
.edu-hero-media img{width:100%;height:100%;object-fit:cover;display:block;}
.edu-content-page h2{font-family:'Georgia','Noto Serif',serif;font-size:15pt;color:var(--heading);margin-bottom:2mm;}
.edu-category{display:inline-block;background:var(--card-bg);border:1px solid var(--card-border);border-radius:99px;padding:1mm 3.5mm;font-size:8.4pt;text-transform:uppercase;letter-spacing:.5pt;margin-bottom:2.5mm;}
.edu-meta{display:flex;gap:4mm;margin-bottom:3mm;font-size:9pt;color:var(--muted);}
.edu-meta-item{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;padding:1.2mm 2.8mm;}
.edu-description{border-left:3px solid var(--accent);padding-left:3mm;font-style:italic;font-size:9.6pt;color:#555;margin-bottom:3mm;}
.edu-body p{margin-bottom:2.8mm;text-align:justify;color:#373737;}
.edu-subheading{font-family:'Georgia','Noto Serif',serif;font-size:11pt;color:var(--heading);margin:4mm 0 2mm;border-bottom:1px solid var(--accent);padding-bottom:1mm;}
.edu-quote{border-left:3px solid var(--accent);padding:2mm 3mm;margin:3mm 0;background:var(--card-bg);font-style:italic;color:#555;}
.edu-keypoints{margin-top:3.5mm;background:var(--tip-bg);color:#fff;border-radius:8px;padding:3mm 4mm;page-break-inside:avoid;}
.edu-keypoints-title{color:var(--divider-sub);text-transform:uppercase;font-weight:700;font-size:8.4pt;letter-spacing:.5pt;margin-bottom:1.5mm;}
.edu-keypoints ul{list-style:none;padding:0;}
.edu-keypoints li{font-size:9pt;line-height:1.5;padding-left:4mm;position:relative;margin-bottom:1mm;}
.edu-keypoints li::before{content:"\2713";position:absolute;left:0;color:var(--divider-sub);}
.edu-summary{margin-top:3mm;border:1px solid var(--accent);border-radius:6px;padding:2.5mm 3mm;background:var(--card-bg);page-break-inside:avoid;}
.edu-summary-title{font-size:8pt;text-transform:uppercase;font-weight:700;margin-bottom:1.2mm;color:var(--heading);}
.edu-summary p{font-size:9.4pt;line-height:1.5;color:#444;}
.edu-note{margin-top:2.5mm;background:#fff8f0;border:1px solid #e8d8c7;border-radius:7px;padding:2.4mm 3mm;font-size:9pt;color:#4a3a2f;line-height:1.52;}
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
  <h1>Apresentação</h1>
  <div class="intro-line"></div>
  <p><strong>{$title}</strong></p>
  <p>Este material foi desenvolvido para servir como guia de estudo e aprofundamento nos temas abordados ao longo do curso.</p>
  <p>Cada módulo apresenta conceitos, reflexões e exercícios práticos que auxiliam no processo de aprendizado e transformação pessoal.</p>
  <p>Recomendamos que você avance no seu ritmo, revisitando os conteúdos sempre que necessário e anotando suas percepções ao longo do caminho.</p>
</div>

<div class="quick-options">
  <h1>Destaques</h1>
  <div class="quick-line"></div>
  <div class="quick-card">
    <h3>Dicas de estudo</h3>
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
            $score = self::recipe_total_score($recipe);

            if (! isset($seen[$key])) {
                $seen[$key] = ['idx' => count($out), 'score' => $score];
                $out[] = $recipe;
                continue;
            }

            $idx = (int) $seen[$key]['idx'];
            $current = is_array($out[$idx] ?? null) ? $out[$idx] : [];
            $current_score = (int) ($seen[$key]['score'] ?? 0);

            if ($score > $current_score) {
                $primary = $recipe;
                $secondary = $current;
            } else {
                $primary = $current;
                $secondary = $recipe;
            }

            $merged = self::merge_recipe_pair($primary, $secondary);
            $out[$idx] = $merged;
            $seen[$key]['score'] = self::recipe_total_score($merged);
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

    private static function sample_categories_raw(): string
    {
        $categories = [
            ['name' => 'Módulo 1 — Fundamentos', 'subtitle' => 'Conceitos essenciais para iniciar.', 'image' => ''],
            ['name' => 'Módulo 2 — Aprofundamento', 'subtitle' => 'Técnicas e práticas avançadas.', 'image' => ''],
            ['name' => 'Módulo 3 — Aplicação', 'subtitle' => 'Exercícios e estudos de caso.', 'image' => ''],
        ];

        $json = wp_json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return is_string($json) ? $json : '';
    }

    private static function sample_items_raw(): string
    {
        return implode("\n", [
            'Introdução ao Tema',
            'Descrição: Nesta aula, apresentamos os conceitos fundamentais do curso.',
            'Duração: 45 min',
            'Nível: Iniciante',
            'Categoria: Módulo 1 — Fundamentos',
            '',
            'Nesta primeira aula, abordaremos os pilares essenciais que servirão de base para todo o curso.',
            '',
            'Pontos-chave:',
            '- Conceito fundamental A',
            '- Conceito fundamental B',
            '- Relação entre teoria e prática',
            '',
            'Resumo: Esta aula estabeleceu as bases conceituais necessárias para avançar nos módulos seguintes.',
            '',
            '---',
            '',
            'Estudo de Caso Prático',
            'Descrição: Análise de um caso real aplicando os conceitos do módulo anterior.',
            'Duração: 30 min',
            'Nível: Intermediário',
            'Categoria: Módulo 2 — Aprofundamento',
            '',
            'Neste estudo de caso, aplicamos os conceitos aprendidos a uma situação prática do cotidiano.',
            '',
            'Pontos-chave:',
            '- Identificação do problema',
            '- Aplicação da técnica',
            '- Resultado esperado',
            '',
            'Resumo: A prática mostrou como os conceitos se aplicam em contextos reais.',
        ]);
    }

    private static function parse_recipes(string $raw): array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        // Strip BOM and invisible characters that break header detection
        $raw = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{00AD}]/u', '', $raw) ?? $raw;
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
            $category = '';
            $description = '';
            $description_lines = [];
            $tempo = '';
            $porcoes = '';
            $dificuldade = '';
            $image = '';
            $has_recipe_marker = false;
            $duration = '';
            $level = '';
            $key_points = [];
            $summary = '';
            $body_lines = [];
            $nutrition = [
                'kcal' => '',
                'carb' => '',
                'prot' => '',
                'fat' => '',
                'fiber' => '',
            ];

            foreach ($lines as $line) {
                $low = mb_strtolower($line);
                if (preg_match('/^categoria\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $candidate = trim((string) ($match[1] ?? ''));
                    if ($candidate !== '') {
                        $category = $candidate;
                    }
                    continue;
                }
                if (preg_match('/^descri(?:c|ç)(?:a|ã)o\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $description = trim((string) ($match[1] ?? ''));
                    if ($description !== '') {
                        $description_lines[] = $description;
                    }
                    $section = 'description';
                    continue;
                }
                if (preg_match('/^conte(?:u|ú)do\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $content_line = trim((string) ($match[1] ?? ''));
                    if ($content_line !== '') {
                        $description_lines[] = $content_line;
                    }
                    $section = 'description';
                    continue;
                }
                if (preg_match('/^tempo\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $tempo = trim((string) ($match[1] ?? ''));
                    continue;
                }
                if (preg_match('/^por(?:c|ç)(?:o|õ)es?\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $porcoes = trim((string) ($match[1] ?? ''));
                    continue;
                }
                if (preg_match('/^dificuldade\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $dificuldade = trim((string) ($match[1] ?? ''));
                    continue;
                }
                if (preg_match('/^imagem(?:\s+da\s+receita)?\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $image = trim((string) ($match[1] ?? ''));
                    continue;
                }
                // Educational headers
                if (preg_match('/^dura(?:c|ç)(?:a|ã)o\\s*:?\\s*(.+)$/iu', $line, $match) === 1) {
                    $duration = trim((string) ($match[1] ?? ''));
                    continue;
                }
                if (preg_match('/^n(?:i|í)vel\\s*:?\\s*(.+)$/iu', $line, $match) === 1) {
                    $level = trim((string) ($match[1] ?? ''));
                    continue;
                }
                if (preg_match('/^pontos?[- ]chave\\s*:?\\s*(.*)$/iu', $line, $match) === 1) {
                    $section = 'keypoints';
                    $kp = trim((string) ($match[1] ?? ''));
                    if ($kp !== '') {
                        $key_points[] = $kp;
                    }
                    continue;
                }
                if (preg_match('/^resumo\\s*:?\\s*(.+)$/iu', $line, $match) === 1) {
                    $summary = trim((string) ($match[1] ?? ''));
                    $section = 'summary';
                    continue;
                }

                if (preg_match('/^ingredientes?(?:\\s*[:\\-].*)?$/iu', $line) === 1) {
                    $section = 'ingredients';
                    $has_recipe_marker = true;
                    continue;
                }
                if (preg_match('/^(modo\\s+de\\s+(preparo|fazer)|como\\s+preparar|preparo|passo\\s+a\\s+passo|instru[çc][õo]es)(?:\\s*[:\\-].*)?$/iu', $line) === 1) {
                    $section = 'steps';
                    $has_recipe_marker = true;
                    continue;
                }
                if (preg_match('/^informa(?:c|ç)(?:a|ã)o\\s+nutricional(?:\\s*[:\\-].*)?$/iu', $line) === 1) {
                    $section = 'nutrition';
                    $has_recipe_marker = true;
                    continue;
                }
                if (preg_match('/^dica(?:\\s+do\\s+chef)?\\s*:?\\s*(.*)$/iu', $line, $match) === 1) {
                    $section = 'tip';
                    $tip_text = trim((string) ($match[1] ?? ''));
                    if ($tip_text !== '') {
                        $tip = trim($tip . ' ' . $tip_text);
                    }
                    continue;
                }
                if (preg_match('/^calorias?\\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $nutrition['kcal'] = trim((string) ($match[1] ?? ''));
                    $section = 'nutrition';
                    continue;
                }
                if (preg_match('/^carboidratos?\\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $nutrition['carb'] = trim((string) ($match[1] ?? ''));
                    $section = 'nutrition';
                    continue;
                }
                if (preg_match('/^prote(?:i|í)nas?\\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $nutrition['prot'] = trim((string) ($match[1] ?? ''));
                    $section = 'nutrition';
                    continue;
                }
                if (preg_match('/^gorduras?\\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $nutrition['fat'] = trim((string) ($match[1] ?? ''));
                    $section = 'nutrition';
                    continue;
                }
                if (preg_match('/^fibras?\\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $nutrition['fiber'] = trim((string) ($match[1] ?? ''));
                    $section = 'nutrition';
                    continue;
                }

                if ($section === 'ingredients') {
                    // Detect prep sub-sections that got mixed into ingredients
                    if (preg_match('/^(modo\\s+de\\s+(preparo|fazer)|como\\s+preparar|preparo|passo\\s+a\\s+passo|instru[çc][õo]es|massa|recheio|montagem|cobertura)\\s*:?\\s*$/iu', $line) === 1) {
                        $section = 'steps';
                        $has_recipe_marker = true;
                        // Include sub-section name as a step header if not a generic prep header
                        if (preg_match('/^(massa|recheio|montagem|cobertura)\\s*:?\\s*$/iu', $line)) {
                            $steps[] = '--- ' . trim($line) . ' ---';
                        }
                        continue;
                    }
                    $ingredients[] = ltrim(preg_replace('/^[-*]\s*/', '', $line) ?? $line);
                    continue;
                }
                if ($section === 'steps') {
                    $steps[] = ltrim(preg_replace('/^\d+[\).:-]?\s*/', '', $line) ?? $line);
                    continue;
                }
                if ($section === 'tip') {
                    $tip = trim($tip . ' ' . $line);
                    continue;
                }
                if ($section === 'keypoints') {
                    $key_points[] = ltrim(preg_replace('/^[-*]\\s*/', '', $line) ?? $line);
                    continue;
                }
                if ($section === 'summary') {
                    $summary = trim($summary . ' ' . $line);
                    continue;
                }
                if ($section === 'description') {
                    $description_lines[] = trim($line);
                } elseif ($section === '') {
                    $body_lines[] = trim($line);
                }
            }

            if ($description_lines) {
                $description = trim(implode("\n", array_values(array_filter($description_lines, static function ($line) {
                    return trim((string) $line) !== '';
                }))));
            }

            $nutrition = self::normalize_nutrition($nutrition);
            $has_nutrition = ! empty($nutrition['kcal'])
                || ! empty($nutrition['carb'])
                || ! empty($nutrition['prot'])
                || ! empty($nutrition['fat'])
                || ! empty($nutrition['fiber']);
            $is_generic = ! $has_recipe_marker
                && ! $ingredients
                && ! $steps
                && $tempo === ''
                && $porcoes === ''
                && $dificuldade === ''
                && ! $has_nutrition;

            if (! $is_generic && ! $ingredients) {
                $ingredients = ['(sem ingredientes detectados)'];
            }
            if (! $is_generic && $steps) {
                $steps = self::expand_step_lines($steps);
            }

            $recipes[] = [
                'title' => $title,
                'category' => $category,
                'description' => $description,
                'body' => implode("\n", array_filter($body_lines, static function ($l) { return trim($l) !== ''; })),
                'duration' => $duration,
                'level' => $level,
                'keyPoints' => array_values(array_filter($key_points, static function ($v) { return trim($v) !== ''; })),
                'summary' => $summary,
                'tempo' => $tempo,
                'porcoes' => $porcoes,
                'dificuldade' => $dificuldade,
                'image' => $image,
                'ingredients' => $ingredients,
                'steps' => $steps,
                'tip' => $tip,
                'nutrition' => $nutrition,
                'is_generic' => $is_generic,
                'isGeneric' => $is_generic,
            ];
        }

        return $recipes;
    }

    /**
     * @param array<int, string> $steps
     * @return array<int, string>
     */
    private static function expand_step_lines(array $steps): array
    {
        $expanded = [];
        foreach ($steps as $step) {
            $line = trim((string) $step);
            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) > 120 && (strpos($line, '.') !== false || strpos($line, ';') !== false)) {
                $parts = preg_split('/(?<=[\\.!?;])\\s+/u', $line) ?: [];
                foreach ($parts as $part) {
                    $piece = trim((string) $part);
                    $piece = preg_replace('/^\\d+[\\).:-]?\\s*/u', '', (string) $piece);
                    $piece = trim((string) $piece, " \t\n\r\0\x0B.;:");
                    if ($piece !== '') {
                        $expanded[] = $piece;
                    }
                }
                continue;
            }

            $line = preg_replace('/^\\d+[\\).:-]?\\s*/u', '', $line);
            $line = trim((string) $line, " \t\n\r\0\x0B.;:");
            if ($line !== '') {
                $expanded[] = $line;
            }
        }

        return $expanded ?: $steps;
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<int, array<string, mixed>>
     */
    private static function categorize_recipes(array $recipes, string $categories_raw = ''): array
    {
        $category_defs = self::parse_categories_raw($categories_raw);

        $configured_order = [];
        $configured_map = [];
        foreach ($category_defs as $definition) {
            $key = self::category_key((string) ($definition['title'] ?? ''));
            if ($key === '' || isset($configured_map[$key])) {
                continue;
            }

            $configured_order[] = $key;
            $configured_map[$key] = [
                'id' => (string) ($definition['id'] ?? $key),
                'title' => (string) ($definition['title'] ?? 'Categoria'),
                'subtitle' => (string) ($definition['subtitle'] ?? ''),
                'image' => (string) ($definition['image'] ?? ''),
                'recipes' => [],
            ];
        }

        $dynamic_groups = [];
        $dynamic_order = [];
        $uncategorized = [];

        foreach ($recipes as $recipe) {
            $category_title = trim((string) ($recipe['category'] ?? ''));
            if ($category_title === '') {
                $uncategorized[] = $recipe;
                continue;
            }

            $key = self::category_key($category_title);
            if ($key === '') {
                $uncategorized[] = $recipe;
                continue;
            }

            if (isset($configured_map[$key])) {
                $configured_map[$key]['recipes'][] = $recipe;
                continue;
            }

            if (! isset($dynamic_groups[$key])) {
                $dynamic_groups[$key] = [
                    'id' => $key,
                    'title' => $category_title,
                    'subtitle' => '',
                    'image' => '',
                    'recipes' => [],
                ];
                $dynamic_order[] = $key;
            }
            $dynamic_groups[$key]['recipes'][] = $recipe;
        }

        $categories = [];

        foreach ($configured_order as $key) {
            $group = $configured_map[$key];
            $group_recipes = is_array($group['recipes']) ? $group['recipes'] : [];
            $count = count($group_recipes);
            $custom_subtitle = trim((string) ($group['subtitle'] ?? ''));

            $categories[] = self::build_category(
                (string) ($group['id'] ?? $key),
                (string) ($group['title'] ?? 'Categoria'),
                $group_recipes,
                self::format_category_subtitle($custom_subtitle, $count),
                (string) ($group['image'] ?? '')
            );
        }

        foreach ($dynamic_order as $key) {
            $group = $dynamic_groups[$key];
            $group_recipes = is_array($group['recipes']) ? $group['recipes'] : [];
            $count = count($group_recipes);

            $categories[] = self::build_category(
                (string) ($group['id'] ?? $key),
                (string) ($group['title'] ?? 'Categoria'),
                $group_recipes,
                self::format_category_subtitle('', $count),
                (string) ($group['image'] ?? '')
            );
        }

        if ($uncategorized) {
            $categories = array_merge($categories, self::categorize_recipes_auto($uncategorized));
        }

        if ($categories) {
            return $categories;
        }

        return self::categorize_recipes_auto($recipes);
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<int, array<string, mixed>>
     */
    private static function categorize_recipes_auto(array $recipes): array
    {
        $total = count($recipes);
        if ($total <= 5) {
            return [self::build_category('geral', 'Conteúdo', $recipes, sprintf('%d %s', $total, $total === 1 ? 'item' : 'itens'))];
        }

        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'];
        $per_cat = max(3, (int) ceil($total / 3));
        $chunks = array_chunk($recipes, $per_cat);
        $categories = [];

        foreach ($chunks as $i => $chunk) {
            $num = $roman[$i] ?? (string) ($i + 1);
            $c = count($chunk);
            $categories[] = self::build_category(
                'parte-' . ($i + 1),
                'Parte ' . $num,
                $chunk,
                sprintf('%d %s', $c, $c === 1 ? 'item' : 'itens')
            );
        }

        return $categories;
    }

    /**
     * @param array<int, array<string, mixed>> $recipes
     * @return array<string, mixed>
     */
    private static function build_category(string $id, string $title, array $recipes, string $subtitle, string $image = ''): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'image' => $image,
            'recipe_count' => count($recipes),
            'recipes' => array_values($recipes),
        ];
    }

    /**
     * @return array<int, array{id: string, title: string, subtitle: string, image: string}>
     */
    private static function parse_categories_raw(string $raw): array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            return [];
        }

        $from_json = self::parse_categories_raw_json($raw);
        if ($from_json) {
            return $from_json;
        }

        return self::parse_categories_raw_lines($raw);
    }

    /**
     * @return array<int, array{id: string, title: string, subtitle: string, image: string}>
     */
    private static function parse_categories_raw_json(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $seen = [];
        $categories = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['name'] ?? $item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $key = self::category_key($title);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $subtitle = trim((string) ($item['subtitle'] ?? ''));
            $image = trim((string) ($item['image'] ?? ''));
            if (! self::is_valid_image_src($image)) {
                $image = '';
            }

            $categories[] = [
                'id' => $key,
                'title' => $title,
                'subtitle' => $subtitle,
                'image' => $image,
            ];
        }

        return $categories;
    }

    /**
     * @return array<int, array{id: string, title: string, subtitle: string, image: string}>
     */
    private static function parse_categories_raw_lines(string $raw): array
    {
        $blocks = preg_split('/^\s*---+\s*$/m', $raw) ?: [];
        $categories = [];
        $seen = [];

        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block))), static function ($line) {
                return $line !== '';
            }));
            if (! $lines) {
                continue;
            }

            $title = '';
            $subtitle = '';
            $image = '';

            foreach ($lines as $idx => $line) {
                if ($idx === 0) {
                    $title = trim((string) preg_replace('/^(categoria\s*:?\s*)/iu', '', $line));
                    continue;
                }

                if (preg_match('/^(subt[ií]tulo|descri(?:c|ç)(?:a|ã)o)\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $subtitle = trim((string) ($match[2] ?? ''));
                    continue;
                }

                if (preg_match('/^imagem\s*:?\s*(.+)$/iu', $line, $match) === 1) {
                    $image = trim((string) ($match[1] ?? ''));
                    continue;
                }
            }

            if ($title === '') {
                continue;
            }

            $key = self::category_key($title);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (! self::is_valid_image_src($image)) {
                $image = '';
            }

            $categories[] = [
                'id' => $key,
                'title' => $title,
                'subtitle' => $subtitle,
                'image' => $image,
            ];
        }

        return $categories;
    }

    private static function category_key(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        return sanitize_title(remove_accents(mb_strtolower($name, 'UTF-8')));
    }

    private static function format_category_subtitle(string $custom_subtitle, int $count): string
    {
        $count_label = sprintf('%d %s nesta categoria', $count, $count === 1 ? 'item' : 'itens');
        if ($custom_subtitle === '') {
            return $count_label;
        }
        return $custom_subtitle . ' • ' . $count_label;
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array{tempo: string, porcoes: string, nivel: string}
     */
    private static function estimate_recipe_meta(array $recipe): array
    {
        $tempo_custom = trim((string) ($recipe['tempo'] ?? ''));
        $porcoes_custom = trim((string) ($recipe['porcoes'] ?? ''));
        $nivel_custom = trim((string) ($recipe['dificuldade'] ?? ''));

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
            'tempo' => $tempo_custom !== '' ? $tempo_custom : $tempo,
            'porcoes' => $porcoes_custom !== '' ? $porcoes_custom : $porcoes,
            'nivel' => $nivel_custom !== '' ? $nivel_custom : $nivel,
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array{kcal: string, carb: string, prot: string, fat: string, fiber: string}
     */
    private static function estimate_nutrition(array $recipe): array
    {
        $custom_nutrition = self::normalize_nutrition((array) ($recipe['nutrition'] ?? []));
        $ingredients_count = max(1, count((array) ($recipe['ingredients'] ?? [])));
        $steps_count = max(1, count((array) ($recipe['steps'] ?? [])));

        $kcal = 120 + ($ingredients_count * 20) + ($steps_count * 4);
        $carb = 8 + ($ingredients_count * 2);
        $prot = 6 + (int) round($ingredients_count * 1.3);
        $fat = 4 + (int) round($ingredients_count * 0.9);
        $fiber = 2 + (int) round($ingredients_count * 0.5);

        $estimated = [
            'kcal' => (string) max(120, min(420, $kcal)),
            'carb' => (string) max(8, min(55, $carb)) . 'g',
            'prot' => (string) max(6, min(30, $prot)) . 'g',
            'fat' => (string) max(4, min(24, $fat)) . 'g',
            'fiber' => (string) max(2, min(14, $fiber)) . 'g',
        ];

        if (! self::nutrition_has_values($custom_nutrition)) {
            return $estimated;
        }

        return [
            'kcal' => $custom_nutrition['kcal'] !== '' ? $custom_nutrition['kcal'] : $estimated['kcal'],
            'carb' => $custom_nutrition['carb'] !== '' ? $custom_nutrition['carb'] : $estimated['carb'],
            'prot' => $custom_nutrition['prot'] !== '' ? $custom_nutrition['prot'] : $estimated['prot'],
            'fat' => $custom_nutrition['fat'] !== '' ? $custom_nutrition['fat'] : $estimated['fat'],
            'fiber' => $custom_nutrition['fiber'] !== '' ? $custom_nutrition['fiber'] : $estimated['fiber'],
        ];
    }

    private static function recipe_description(string $title, string $custom_description = ''): string
    {
        $custom_description = trim($custom_description);
        if ($custom_description !== '') {
            return $custom_description;
        }

        $title_lc = mb_strtolower($title, 'UTF-8');
        if (strpos(self::normalize_for_match($title), 'biomassa') !== false) {
            return 'Receita com biomassa de banana verde, com foco em praticidade, saciedade e equilíbrio metabólico para o dia a dia.';
        }

        return 'Receita prática de ' . $title_lc . ', organizada para facilitar o preparo no dia a dia.';
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function recipe_core_score(array $recipe): int
    {
        return count((array) ($recipe['ingredients'] ?? [])) + count((array) ($recipe['steps'] ?? []));
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function recipe_metadata_score(array $recipe): int
    {
        $score = 0;
        foreach (['category', 'description', 'tempo', 'porcoes', 'dificuldade', 'image', 'tip'] as $field) {
            if (trim((string) ($recipe[$field] ?? '')) !== '') {
                $score += 1;
            }
        }

        $nutrition = self::normalize_nutrition((array) ($recipe['nutrition'] ?? []));
        foreach ($nutrition as $value) {
            if ($value !== '') {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function recipe_total_score(array $recipe): int
    {
        return (self::recipe_core_score($recipe) * 100) + self::recipe_metadata_score($recipe);
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     * @return array<string, mixed>
     */
    private static function merge_recipe_pair(array $primary, array $secondary): array
    {
        $merged = $primary;

        if (trim((string) ($merged['title'] ?? '')) === '' && trim((string) ($secondary['title'] ?? '')) !== '') {
            $merged['title'] = (string) $secondary['title'];
        }

        foreach (['category', 'description', 'tempo', 'porcoes', 'dificuldade', 'image', 'tip'] as $field) {
            $main_value = trim((string) ($merged[$field] ?? ''));
            $alt_value = trim((string) ($secondary[$field] ?? ''));
            if ($main_value === '' && $alt_value !== '') {
                $merged[$field] = $alt_value;
            }
        }

        $main_ingredients = array_values(array_filter(array_map('trim', (array) ($merged['ingredients'] ?? []))));
        $alt_ingredients = array_values(array_filter(array_map('trim', (array) ($secondary['ingredients'] ?? []))));
        if (! $main_ingredients && $alt_ingredients) {
            $merged['ingredients'] = $alt_ingredients;
        } else {
            $merged['ingredients'] = $main_ingredients;
        }

        $main_steps = array_values(array_filter(array_map('trim', (array) ($merged['steps'] ?? []))));
        $alt_steps = array_values(array_filter(array_map('trim', (array) ($secondary['steps'] ?? []))));
        if (! $main_steps && $alt_steps) {
            $merged['steps'] = $alt_steps;
        } else {
            $merged['steps'] = $main_steps;
        }

        $main_nutrition = self::normalize_nutrition((array) ($merged['nutrition'] ?? []));
        $alt_nutrition = self::normalize_nutrition((array) ($secondary['nutrition'] ?? []));
        $merged['nutrition'] = [
            'kcal' => $main_nutrition['kcal'] !== '' ? $main_nutrition['kcal'] : $alt_nutrition['kcal'],
            'carb' => $main_nutrition['carb'] !== '' ? $main_nutrition['carb'] : $alt_nutrition['carb'],
            'prot' => $main_nutrition['prot'] !== '' ? $main_nutrition['prot'] : $alt_nutrition['prot'],
            'fat' => $main_nutrition['fat'] !== '' ? $main_nutrition['fat'] : $alt_nutrition['fat'],
            'fiber' => $main_nutrition['fiber'] !== '' ? $main_nutrition['fiber'] : $alt_nutrition['fiber'],
        ];

        return $merged;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{kcal: string, carb: string, prot: string, fat: string, fiber: string}
     */
    private static function normalize_nutrition(array $input): array
    {
        return [
            'kcal' => trim((string) ($input['kcal'] ?? $input['calorias'] ?? '')),
            'carb' => trim((string) ($input['carb'] ?? $input['carboidratos'] ?? '')),
            'prot' => trim((string) ($input['prot'] ?? $input['proteinas'] ?? $input['proteínas'] ?? '')),
            'fat' => trim((string) ($input['fat'] ?? $input['gorduras'] ?? '')),
            'fiber' => trim((string) ($input['fiber'] ?? $input['fibras'] ?? '')),
        ];
    }

    /**
     * @param array{kcal: string, carb: string, prot: string, fat: string, fiber: string} $nutrition
     */
    private static function nutrition_has_values(array $nutrition): bool
    {
        foreach ($nutrition as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function recipe_media_html(array $recipe, array $theme, int $index, string $title): string
    {
        $image = self::normalize_image_src_for_output((string) ($recipe['image'] ?? ''));
        if ($image !== '') {
            return '<img src="' . self::h($image) . '" alt="' . self::h($title) . '">';
        }

        $style = self::recipe_cover_style($theme, $index);
        return '<div class="recipe-image-fallback" style="' . self::h($style) . '"><span>' . self::h($title) . '</span></div>';
    }

    private static function cover_media_html(string $image_src, string $title): string
    {
        $image_src = self::normalize_image_src_for_output($image_src);
        if ($image_src === '') {
            return '';
        }
        return '<div class="cover-media"><img src="' . self::h($image_src) . '" alt="' . self::h($title) . '"></div>';
    }

    private static function category_divider_media_html(string $image_src, string $title): string
    {
        $image_src = self::normalize_image_src_for_output($image_src);
        if ($image_src === '') {
            return '';
        }

        return '<div class="category-divider-media"><img src="' . self::h($image_src) . '" alt="' . self::h($title) . '"></div>';
    }

    private static function is_valid_image_src(string $src): bool
    {
        return self::normalize_image_src_for_output($src) !== '';
    }

    private static function normalize_image_src_for_output(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return '';
        }
        if ((bool) preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $src)) {
            return $src;
        }
        if (strpos($src, 'file://') === 0 || strpos($src, '/') === 0) {
            $local_path = self::resolve_local_image_path($src);
            if ($local_path !== '') {
                return self::path_to_file_uri($local_path);
            }
        }

        if (filter_var($src, FILTER_VALIDATE_URL)) {
            return $src;
        }

        return '';
    }

    private static function resolve_local_image_path(string $src): string
    {
        $path = trim($src);
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

        return $path;
    }

    private static function path_to_file_uri(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $trimmed = ltrim($normalized, '/');
        $segments = array_map('rawurldecode', explode('/', $trimmed));
        $encoded = array_map('rawurlencode', $segments);
        return 'file:///' . implode('/', $encoded);
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

    /**
     * Render educational body text with markdown-light support.
     * ## heading → <h3>, > quote → <blockquote>, blank lines → paragraph breaks.
     */
    private static function render_educational_body(string $text): string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return '';
        }

        $lines = explode("\n", $text);
        $html = '';
        $in_paragraph = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($in_paragraph) {
                    $html .= '</p>';
                    $in_paragraph = false;
                }
                continue;
            }

            // Subheading: ## Title
            if (preg_match('/^#{1,3}\s+(.+)$/', $trimmed, $m)) {
                if ($in_paragraph) {
                    $html .= '</p>';
                    $in_paragraph = false;
                }
                $html .= '<h3 class="edu-subheading">' . self::h(trim($m[1])) . '</h3>';
                continue;
            }

            // Blockquote: > text
            if (preg_match('/^>\s*(.+)$/', $trimmed, $m)) {
                if ($in_paragraph) {
                    $html .= '</p>';
                    $in_paragraph = false;
                }
                $html .= '<blockquote class="edu-quote">' . self::h(trim($m[1])) . '</blockquote>';
                continue;
            }

            // Regular text — accumulate into paragraphs
            if (! $in_paragraph) {
                $html .= '<p>' . self::h($trimmed);
                $in_paragraph = true;
            } else {
                $html .= ' ' . self::h($trimmed);
            }
        }

        if ($in_paragraph) {
            $html .= '</p>';
        }

        return $html;
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
