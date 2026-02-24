<?php if (! defined('ABSPATH')) {
    exit;
} ?>
<div class="wrap pdfw-wrap">
  <h1>PDF Ebook Studio</h1>
  <p class="description">Editor com projetos, clientes, prévia fiel paginada e geração final em HTML/PDF.</p>

  <?php if ($notice): ?>
    <div class="notice notice-warning"><p><?php echo wp_kses_post(nl2br(esc_html($notice))); ?></p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url($action_url); ?>" class="pdfw-form" enctype="multipart/form-data">
    <?php wp_nonce_field('pdfw_generate'); ?>
    <input type="hidden" name="action" value="pdfw_generate">
    <input type="hidden" id="pdfw-preview-nonce" value="<?php echo esc_attr($preview_nonce); ?>">
    <input type="hidden" id="pdfw-projects-nonce" value="<?php echo esc_attr($projects_nonce); ?>">
    <input type="hidden" id="pdfw-import-nonce" value="<?php echo esc_attr($import_nonce); ?>">
    <input type="hidden" id="pdfw-preview-cache-key" name="preview_cache_key" value="">

    <div class="pdfw-studio-layout">
      <aside class="pdfw-sidebar" aria-label="Navegação do editor">
        <div class="pdfw-sidebar-brand">Ebook Studio</div>
        <div class="pdfw-sidebar-meta">
          <div class="pdfw-sidebar-project" id="pdfw-sidebar-project-name"><?php echo esc_html($payload['title']); ?></div>
          <div class="pdfw-sidebar-submeta">
            <span>Receitas: <strong id="pdfw-sidebar-recipes-count">0</strong></span>
            <span id="pdfw-sidebar-dirty" class="is-clean">Salvo</span>
          </div>
        </div>
        <nav class="pdfw-sidebar-nav">
          <button type="button" class="pdfw-nav-item is-active" data-target-section="projetos">Projetos</button>
          <button type="button" class="pdfw-nav-item" data-target-section="capa">Capa e Tema</button>
          <button type="button" class="pdfw-nav-item" data-target-section="importacao">Importação</button>
          <button type="button" class="pdfw-nav-item" data-target-section="receitas">Receitas</button>
          <button type="button" class="pdfw-nav-item" data-target-section="extras">Extras</button>
          <button type="button" class="pdfw-nav-item" data-target-section="exportar">Exportar e Prévia</button>
        </nav>
      </aside>

      <div class="pdfw-editor-area">
        <section class="pdfw-editor-section is-active" data-section-id="projetos">
          <section class="pdfw-card pdfw-projects-card">
            <h2>Projetos</h2>
            <div class="pdfw-projects-row">
              <label class="pdfw-project-field">Projeto salvo
                <select id="pdfw-project-select">
                  <option value="">Carregando projetos...</option>
                </select>
              </label>
              <label class="pdfw-project-field">Nome do projeto
                <input type="text" id="pdfw-project-name" placeholder="Ex.: Ebook 4 - versão final">
              </label>
              <label class="pdfw-project-field">Cliente
                <input type="text" id="pdfw-project-client" placeholder="Ex.: Webescola">
              </label>
            </div>
            <div class="pdfw-actions">
              <button type="button" class="button button-secondary" id="pdfw-project-new">Novo projeto</button>
              <button type="button" class="button button-primary" id="pdfw-project-save">Salvar projeto</button>
              <button type="button" class="button" id="pdfw-project-save-as">Salvar como novo</button>
              <button type="button" class="button" id="pdfw-project-delete">Excluir projeto</button>
            </div>
            <p class="hint" id="pdfw-project-status">Projeto não salvo.</p>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="capa">
          <div class="pdfw-grid">
            <section class="pdfw-card">
              <h2>Dados do ebook</h2>
              <label>Título
                <input type="text" name="title" value="<?php echo esc_attr($payload['title']); ?>" required>
              </label>
              <label>Subtítulo
                <input type="text" name="subtitle" value="<?php echo esc_attr($payload['subtitle']); ?>">
              </label>
              <label>Autor
                <input type="text" name="author" value="<?php echo esc_attr($payload['author']); ?>">
              </label>
              <label>Selo de autoria
                <input type="text" name="seal" value="<?php echo esc_attr($payload['seal']); ?>">
              </label>
              <label>Imagem da capa (URL opcional)
                <input type="url" name="cover_image" value="<?php echo esc_attr((string) ($payload['cover_image'] ?? '')); ?>" placeholder="https://.../capa.jpg">
              </label>
            </section>

            <section class="pdfw-card">
              <h2>Tema visual</h2>
              <label>Tema
                <select name="theme">
                  <?php foreach ($themes as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($payload['theme'], $key); ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="hint">A paleta e os blocos visuais são aplicados automaticamente no HTML e no PDF.</p>
            </section>
          </div>
        </section>

        <section class="pdfw-editor-section" data-section-id="importacao">
          <div class="pdfw-grid">
            <section class="pdfw-card">
              <h2>Importação automática</h2>
              <label>Upload de arquivos
                <input type="file" name="source_files[]" multiple accept=".txt,.md,.html,.htm,.docx,.pdf">
              </label>
              <p class="hint">Suporta: TXT, MD, HTML, DOCX e PDF (PDF depende de parser instalado).</p>
              <label>Link da pasta pública do Google Drive
                <input type="url" name="drive_folder_url" value="<?php echo esc_attr((string) ($payload['drive_folder_url'] ?? '')); ?>" placeholder="https://drive.google.com/drive/folders/...">
              </label>
              <p class="hint">Importa também subpastas automaticamente (até 4 níveis).</p>
              <label>Modo de importação
                <select name="import_mode">
                  <option value="append" <?php selected((string) ($payload['import_mode'] ?? 'append'), 'append'); ?>>Somar com receitas do campo manual</option>
                  <option value="replace" <?php selected((string) ($payload['import_mode'] ?? 'append'), 'replace'); ?>>Substituir por receitas importadas</option>
                </select>
              </label>
              <div class="pdfw-actions pdfw-import-actions">
                <button type="button" class="button button-primary" id="pdfw-import-content">Importar conteúdo</button>
              </div>
              <p class="hint" id="pdfw-import-status">Cole o link do Drive ou selecione arquivos e clique em Importar conteúdo.</p>
            </section>
            <section class="pdfw-card">
              <h2>Formato esperado</h2>
              <p class="hint">
                Estrutura recomendada:
                <code>Título</code>, <code>Ingredientes:</code>, <code>Modo de preparo:</code>, <code>Dica:</code>.<br>
                Se usar modo texto bruto, separe receitas com <code>---</code>.
              </p>
            </section>
          </div>
          <section class="pdfw-card pdfw-import-audit-card" id="pdfw-import-audit-card" hidden>
            <h2>Auditoria da importação</h2>
            <p class="hint" id="pdfw-import-audit-summary">Nenhuma importação executada.</p>
            <div id="pdfw-import-audit-table"></div>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="receitas">
          <section class="pdfw-card">
            <h2>Receitas (editor estruturado)</h2>
            <p class="hint">Edite por blocos para manter a diagramação consistente no PDF.</p>
            <div class="pdfw-category-panel">
              <div class="pdfw-category-header">
                <strong>Categorias</strong>
                <button type="button" class="button button-small" id="pdfw-add-category">Adicionar categoria</button>
              </div>
              <p class="hint">Cada categoria gera uma página divisória. Defina subtítulo e imagem da subcapa para personalizar.</p>
              <textarea name="categories_raw" rows="8" hidden><?php echo esc_textarea((string) ($payload['categories_raw'] ?? '')); ?></textarea>
              <div id="pdfw-category-manager"></div>
            </div>
            <div id="pdfw-recipe-builder"></div>
            <div class="pdfw-actions">
              <button type="button" class="button button-secondary" id="pdfw-add-recipe">Adicionar receita</button>
              <button type="button" class="button" id="pdfw-apply-imported" style="display:none;">Aplicar receitas importadas da prévia</button>
              <button type="button" class="button" id="pdfw-load-sample">Restaurar exemplo</button>
            </div>
            <details class="pdfw-advanced-raw">
              <summary>Modo avançado (texto bruto)</summary>
              <p class="hint">Este campo é sincronizado automaticamente pelo editor estruturado.</p>
              <textarea name="recipes_raw" rows="18"><?php echo esc_textarea($payload['recipes_raw']); ?></textarea>
            </details>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="extras">
          <div class="pdfw-grid">
            <section class="pdfw-card">
              <h2>Dicas finais</h2>
              <textarea name="tips" rows="10"><?php echo esc_textarea($payload['tips']); ?></textarea>
            </section>
            <section class="pdfw-card">
              <h2>Sobre o autor</h2>
              <textarea name="about" rows="10"><?php echo esc_textarea($payload['about']); ?></textarea>
            </section>
          </div>
        </section>

        <section class="pdfw-editor-section" data-section-id="exportar">
          <div class="pdfw-actions">
            <button type="button" class="button button-primary button-hero" id="pdfw-generate-preview-pdf">Prévia fiel (PDF)</button>
            <button type="button" class="button button-secondary button-hero" id="pdfw-generate-preview-html">Prévia rápida (HTML)</button>
            <button type="submit" class="button button-primary button-hero" id="pdfw-download-pdf" name="pdfw_output" value="pdf" style="display:none;">Baixar PDF</button>
            <button type="submit" class="button button-secondary button-hero" id="pdfw-generate-html" name="pdfw_output" value="html">Gerar HTML</button>
          </div>

          <section class="pdfw-card pdfw-preview-card">
            <h2>Pré-visualização</h2>
            <p class="hint">Use <strong>Prévia fiel (PDF)</strong> para paginação real e <strong>Prévia rápida (HTML)</strong> para checagem de conteúdo.</p>
            <p class="hint" id="pdfw-preview-status">Nenhuma prévia gerada ainda.</p>
            <pre id="pdfw-preview-log" class="pdfw-preview-log" hidden></pre>
            <iframe id="pdfw-preview-frame" title="Prévia do ebook"></iframe>
          </section>
        </section>
      </div>
    </div>
  </form>
</div>
