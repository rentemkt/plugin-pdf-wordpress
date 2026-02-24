<?php if (! defined('ABSPATH')) {
    exit;
} ?>
<div class="wrap pdfw-wrap">
  <h1>PDF Ebook Studio</h1>
  <p class="description">Monte o conteúdo, escolha o tema e exporte em HTML ou PDF.</p>

  <?php if ($notice): ?>
    <div class="notice notice-warning"><p><?php echo esc_html($notice); ?></p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url($action_url); ?>" class="pdfw-form">
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
