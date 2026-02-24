<?php if (! defined('ABSPATH')) {
    exit;
} ?>
<div class="wrap pdfw-wrap">
  <h1>PDF Ebook Studio</h1>
  <p class="description">Monte o conteúdo, escolha o tema e exporte em HTML ou PDF.</p>

  <?php if ($notice): ?>
    <div class="notice notice-warning"><p><?php echo wp_kses_post(nl2br(esc_html($notice))); ?></p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url($action_url); ?>" class="pdfw-form" enctype="multipart/form-data">
    <?php wp_nonce_field('pdfw_generate'); ?>
    <input type="hidden" name="action" value="pdfw_generate">

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
        <label>Tema
          <select name="theme">
            <?php foreach ($themes as $key => $label): ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($payload['theme'], $key); ?>>
                <?php echo esc_html($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </section>

      <section class="pdfw-card">
        <h2>Receitas</h2>
        <p class="hint">Separe receitas com <code>---</code>.</p>
        <textarea name="recipes_raw" rows="22"><?php echo esc_textarea($payload['recipes_raw']); ?></textarea>
      </section>
    </div>

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
      </section>
      <section class="pdfw-card">
        <h2>Como estruturar receita (manual)</h2>
        <p class="hint">
          Use o padrão:
          <code>Título</code>, <code>Ingredientes:</code>, <code>Modo de preparo:</code>, <code>Dica:</code>.<br>
          Separe receitas com <code>---</code>.
        </p>
      </section>
    </div>

    <div class="pdfw-grid">
      <section class="pdfw-card">
        <h2>Dicas finais</h2>
        <textarea name="tips" rows="7"><?php echo esc_textarea($payload['tips']); ?></textarea>
      </section>
      <section class="pdfw-card">
        <h2>Sobre o autor</h2>
        <textarea name="about" rows="7"><?php echo esc_textarea($payload['about']); ?></textarea>
      </section>
    </div>

    <div class="pdfw-actions">
      <button type="submit" class="button button-primary button-hero" name="pdfw_output" value="pdf">Gerar PDF</button>
      <button type="submit" class="button button-secondary button-hero" name="pdfw_output" value="html">Gerar HTML</button>
      <button type="button" class="button" id="pdfw-load-sample">Restaurar exemplo</button>
    </div>
  </form>
</div>
