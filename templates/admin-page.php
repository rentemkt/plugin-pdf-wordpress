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
            <span>Itens: <strong id="pdfw-sidebar-recipes-count">0</strong></span>
            <span id="pdfw-sidebar-dirty" class="is-clean">Salvo</span>
          </div>
        </div>
        <nav class="pdfw-sidebar-nav">
          <button type="button" class="pdfw-nav-item is-active" data-target-section="projetos">Projetos</button>
          <button type="button" class="pdfw-nav-item" data-target-section="capa">Capa e Tema</button>
          <button type="button" class="pdfw-nav-item" data-target-section="importacao">Importação</button>
          <button type="button" class="pdfw-nav-item" data-target-section="categorias">Categorias</button>
          <button type="button" class="pdfw-nav-item" data-target-section="itens">Itens</button>
          <button type="button" class="pdfw-nav-item" data-target-section="extras">Extras</button>
          <button type="button" class="pdfw-nav-item" data-target-section="transcricao">Laboratório de Transcrição</button>
          <button type="button" class="pdfw-nav-item" data-target-section="exportar">Exportar</button>
        </nav>
      </aside>

      <div class="pdfw-editor-area">
        <div class="pdfw-editor-panel" id="pdfw-editor-panel">
        <section class="pdfw-editor-section is-active" data-section-id="projetos">
          <section class="pdfw-card pdfw-projects-card">
            <h2>Projetos</h2>
            <div id="pdfw-projects-dashboard" class="pdfw-projects-grid">
              <div class="pdfw-projects-empty">Carregando projetos...</div>
            </div>
            <div class="pdfw-projects-row">
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
              <label>Imagem da capa</label>
              <div class="pdfw-img-upload" id="pdfw-cover-upload" style="cursor:pointer;">
                <?php $coverVal = (string) ($payload['cover_image'] ?? ''); ?>
                <?php if ($coverVal): ?>
                  <img src="<?php echo esc_attr($coverVal); ?>" alt="Capa">
                  <button type="button" class="pdfw-img-remove" id="pdfw-cover-remove">&times;</button>
                <?php else: ?>
                  <div class="pdfw-img-placeholder">Clique para enviar imagem de capa</div>
                <?php endif; ?>
              </div>
              <input type="file" id="pdfw-cover-input" accept="image/*" style="display:none">
              <input type="url" name="cover_image" value="<?php echo esc_attr($coverVal); ?>" placeholder="ou cole URL: https://.../capa.jpg" style="margin-top:6px">
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
                <input type="file" name="source_files[]" multiple accept=".txt,.md,.html,.htm,.docx,.pdf,.pptx,.mp3,.wav,.m4a,.ogg,.mp4,.mpeg,.webm,.mkv">
              </label>
              <p class="hint">Suporta: TXT, MD, HTML, DOCX, PPTX, PDF e mídia (MP3, WAV, M4A, OGG, MP4, MPEG, WEBM, MKV).</p>
              <label>Link da pasta pública do Google Drive
                <input type="url" name="drive_folder_url" value="<?php echo esc_attr((string) ($payload['drive_folder_url'] ?? '')); ?>" placeholder="https://drive.google.com/drive/folders/...">
              </label>
              <p class="hint">Importa subpastas automaticamente (até 4 níveis). Funcionalidade experimental — o Google pode alterar a estrutura a qualquer momento.</p>
              <label>Servidor Whisper
                <select id="pdfw-whisper-preset" style="margin-top:4px;">
                  <option value="online">Online (VPS)</option>
                  <option value="local">Local GPU (localhost:8765)</option>
                  <option value="custom">Personalizado</option>
                </select>
              </label>
              <div id="pdfw-whisper-custom-wrap" style="margin-top:6px;display:none;">
                <label>URL personalizada
                  <input type="url" id="pdfw-whisper-custom-url" value="<?php echo esc_attr((string) ($payload['whisper_url'] ?? PDFW_Ingestor::whisper_default_url())); ?>" placeholder="<?php echo esc_attr(PDFW_Ingestor::whisper_default_url()); ?>">
                </label>
              </div>
              <input type="hidden" id="pdfw-whisper-url-hidden" name="whisper_url" value="<?php echo esc_attr((string) ($payload['whisper_url'] ?? PDFW_Ingestor::whisper_default_url())); ?>">
              <p class="hint">Online: <code><?php echo esc_html(PDFW_Ingestor::whisper_default_url()); ?></code> &middot; Local GPU: <code>http://localhost:8765/v1/audio/transcriptions</code></p>
              <label>Modo de importação
                <select name="import_mode">
                  <option value="append" <?php selected((string) ($payload['import_mode'] ?? 'append'), 'append'); ?>>Somar com itens do editor</option>
                  <option value="replace" <?php selected((string) ($payload['import_mode'] ?? 'append'), 'replace'); ?>>Substituir pelos itens importados</option>
                </select>
              </label>
              <div class="pdfw-actions pdfw-import-actions">
                <button type="button" class="button button-primary" id="pdfw-import-content">Importar conteúdo</button>
              </div>
              <p class="hint" id="pdfw-import-status">Cole o link do Drive ou selecione arquivos e clique em Importar conteúdo.</p>
              <div id="pdfw-import-progress" class="pdfw-import-progress" hidden>
                <div class="pdfw-import-progress-track">
                  <div id="pdfw-import-progress-bar" class="pdfw-import-progress-bar"></div>
                </div>
                <div id="pdfw-import-progress-label" class="pdfw-import-progress-label">0%</div>
              </div>
            </section>
            <section class="pdfw-card">
              <h2>Formato esperado</h2>
              <p class="hint">
                Você pode importar conteúdo em dois formatos:<br>
                <strong>Educacional</strong>: <code>Título</code> + corpo, <code>Duração:</code>, <code>Nível:</code>, <code>Pontos-chave:</code>, <code>Resumo:</code>.<br>
                <strong>Receita (legado)</strong>: <code>Título</code>, <code>Ingredientes:</code>, <code>Modo de preparo:</code>, <code>Dica:</code>.<br>
                No modo bruto, separe os blocos com <code>---</code>.
              </p>
            </section>
          </div>
          <section class="pdfw-card pdfw-import-audit-card" id="pdfw-import-audit-card" hidden>
            <h2>Auditoria da importação</h2>
            <p class="hint" id="pdfw-import-audit-summary">Nenhuma importação executada.</p>
            <div id="pdfw-import-audit-table"></div>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="categorias">
          <section class="pdfw-card">
            <h2>Categorias / Módulos</h2>
            <p class="hint">Cada categoria gera uma página divisória no ebook. Defina subtítulo e imagem para personalizar.</p>
            <textarea name="categories_raw" rows="8" hidden><?php echo esc_textarea((string) ($payload['categories_raw'] ?? '')); ?></textarea>
            <div id="pdfw-category-manager"></div>
            <div class="pdfw-actions" style="margin-top:12px;">
              <button type="button" class="button button-secondary" id="pdfw-add-category">+ Nova Categoria</button>
            </div>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="itens">
          <section class="pdfw-card">
            <h2>Itens (editor estruturado)</h2>
            <p class="hint">Edite aulas e conteúdos por blocos para manter a diagramação consistente no PDF.</p>
            <div id="pdfw-recipe-builder"></div>
            <div class="pdfw-actions">
              <button type="button" class="button button-secondary" id="pdfw-add-recipe">Adicionar item</button>
              <button type="button" class="button" id="pdfw-apply-imported" style="display:none;">Aplicar itens importados da prévia</button>
              <button type="button" class="button" id="pdfw-load-sample">Restaurar exemplo</button>
            </div>
            <details class="pdfw-advanced-raw">
              <summary>Modo avançado (texto bruto)</summary>
              <p class="hint">Este campo é sincronizado automaticamente pelo editor estruturado. Separe blocos com <code>---</code>.</p>
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

        <section class="pdfw-editor-section" data-section-id="transcricao">
          <section class="pdfw-card pdfw-transcribe-lab">
            <div class="pdfw-transcribe-head">
              <div>
                <h2>Laboratório de Transcrição (Whisper AI)</h2>
                <p class="hint">Ferramenta avulsa para transformar áudio/vídeo em texto. O conteúdo gerado aqui não vai para o ebook automaticamente.</p>
              </div>
              <span class="pdfw-transcribe-pill">v0.4.1</span>
            </div>

            <div class="pdfw-transcribe-box">
              <label class="pdfw-file-drop" id="pdfw-transcribe-drop">
                <span class="pdfw-transcribe-icon dashicons dashicons-format-audio"></span>
                <span id="pdfw-transcribe-label">Clique ou arraste um arquivo aqui<br>(MP3, WAV, M4A, OGG, MP4, MPEG, WEBM, MKV)</span>
                <span class="pdfw-transcribe-subhint">Legendas e lipsync ficam disponíveis para download após o processamento.</span>
                <div class="pdfw-transcribe-format-chips">
                  <span>.mp3</span>
                  <span>.wav</span>
                  <span>.ogg</span>
                  <span>.m4a</span>
                  <span>.mp4</span>
                  <span>.webm</span>
                  <span>.mkv</span>
                </div>
                <input type="file" id="pdfw-transcribe-input" accept=".mp3,.wav,.m4a,.ogg,.mp4,.mpeg,.webm,.mkv" hidden>
              </label>

              <div id="pdfw-transcribe-progress" hidden>
                <div class="pdfw-spinner"></div> Processando áudio... isso pode demorar em arquivos grandes.
              </div>
            </div>

            <div class="pdfw-transcribe-result" id="pdfw-transcribe-result" hidden>
              <div class="pdfw-card-header-actions">
                <h3>Resultado da transcrição</h3>
                <div class="pdfw-transcribe-actions-inline">
                  <button type="button" class="button" id="pdfw-copy-transcription" disabled>Copiar texto</button>
                  <button type="button" class="button" id="pdfw-copy-transcription-all" disabled>Copiar output completo</button>
                  <button type="button" class="button button-secondary" id="pdfw-resume-transcription" disabled>Retomar da parte falha</button>
                </div>
              </div>
              <p class="hint" id="pdfw-transcribe-resume-hint" hidden></p>
              <div class="pdfw-transcribe-downloads">
                <button type="button" class="button button-secondary" id="pdfw-download-transcription-txt" disabled>Baixar TXT</button>
                <button type="button" class="button button-secondary" id="pdfw-download-transcription-srt" disabled>Baixar SRT</button>
                <button type="button" class="button button-secondary" id="pdfw-download-transcription-vtt" disabled>Baixar VTT</button>
                <button type="button" class="button button-secondary" id="pdfw-download-transcription-lipsync" disabled>Baixar Lipsync JSON</button>
              </div>
              <textarea id="pdfw-transcription-text" rows="15" class="pdfw-content-editor" readonly></textarea>
            </div>
          </section>
        </section>

        <section class="pdfw-editor-section" data-section-id="exportar">
          <section class="pdfw-card">
            <h2>Exportar</h2>
            <p class="hint">A prévia em tempo real aparece no painel ao lado. Use os botões abaixo para exportar o arquivo final.</p>
            <div class="pdfw-actions">
              <button type="button" class="button button-primary button-hero" id="pdfw-generate-preview-pdf">Baixar PDF</button>
              <button type="submit" class="button button-secondary button-hero" id="pdfw-generate-html" name="pdfw_output" value="html">Baixar HTML</button>
            </div>
            <p class="hint" id="pdfw-preview-status"></p>
            <pre id="pdfw-preview-log" class="pdfw-preview-log" hidden></pre>
          </section>
        </section>
        </div><!-- /.pdfw-editor-panel -->

        <div class="pdfw-preview-panel" id="pdfw-preview-panel">
          <div class="pdfw-preview-toolbar">
            <button type="button" class="button button-small" id="pdfw-zoom-out" title="Reduzir">&minus;</button>
            <span id="pdfw-zoom-label">70%</span>
            <button type="button" class="button button-small" id="pdfw-zoom-in" title="Ampliar">+</button>
            <button type="button" class="button button-small" id="pdfw-zoom-fit" title="Ajustar largura">&#x2922;</button>
            <button type="button" class="button button-small" id="pdfw-preview-refresh" title="Atualizar">&#x21bb;</button>
          </div>
          <div class="pdfw-preview-container">
            <iframe id="pdfw-preview-frame" title="Prévia do ebook" sandbox="allow-same-origin"></iframe>
          </div>
        </div><!-- /.pdfw-preview-panel -->

        <div class="pdfw-mobile-tabs" id="pdfw-mobile-tabs">
          <button type="button" class="pdfw-mobile-tab is-active" data-view="editor">Editor</button>
          <button type="button" class="pdfw-mobile-tab" data-view="preview">Preview</button>
        </div>
      </div>
    </div>
  </form>
  <div id="pdfw-toast-container" class="pdfw-toast-container" aria-live="polite" aria-atomic="true"></div>
</div>
