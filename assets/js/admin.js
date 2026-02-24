(() => {
  const form = document.querySelector('.pdfw-form');
  if (!form) return;

  const sampleButton = document.getElementById('pdfw-load-sample');
  const previewButton = document.getElementById('pdfw-generate-preview');
  const downloadPdfButton = document.getElementById('pdfw-download-pdf');
  const previewFrame = document.getElementById('pdfw-preview-frame');
  const previewStatus = document.getElementById('pdfw-preview-status');
  const previewLog = document.getElementById('pdfw-preview-log');
  const previewNonce = document.getElementById('pdfw-preview-nonce')?.value || '';
  let previewObjectUrl = '';

  const sampleRecipes = `Panqueca de Banana
Ingredientes:
- 1 banana madura
- 1 ovo
- Canela a gosto
Modo de preparo:
1. Amasse a banana e misture com o ovo.
2. Leve à frigideira antiaderente em fogo baixo.
3. Doure dos dois lados e finalize com canela.
Dica:
Sirva com iogurte natural para aumentar proteínas.

---

Omelete com Legumes
Ingredientes:
- 2 ovos
- 1/2 tomate picado
- 2 colheres de espinafre picado
- Sal e pimenta a gosto
Modo de preparo:
1. Bata os ovos e adicione os legumes.
2. Tempere e cozinhe em frigideira antiaderente.
3. Dobre a omelete quando estiver firme.
Dica:
Finalize com azeite extravirgem após o preparo.`;

  if (sampleButton) {
    sampleButton.addEventListener('click', () => {
      const recipes = document.querySelector('textarea[name="recipes_raw"]');
      if (!recipes) return;
      const ok = window.confirm('Substituir o conteúdo atual pelo exemplo?');
      if (!ok) return;
      recipes.value = sampleRecipes;
      recipes.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  let previewBusy = false;

  const markDirty = () => {
    if (downloadPdfButton) {
      downloadPdfButton.style.display = 'none';
    }
    if (previewStatus) {
      previewStatus.textContent = 'Alterações detectadas. Gere a prévia novamente.';
    }
  };

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);

  const getAjaxUrl = () => {
    if (typeof window.ajaxurl === 'string' && window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  };

  const setStatus = (text) => {
    if (previewStatus) previewStatus.textContent = text;
  };

  const setLog = (text) => {
    if (!previewLog) return;
    const clean = String(text || '').trim();
    if (clean === '') {
      previewLog.hidden = true;
      previewLog.textContent = '';
      return;
    }
    previewLog.hidden = false;
    previewLog.textContent = clean;
  };

  const clearPreviewUrl = () => {
    if (!previewObjectUrl) return;
    URL.revokeObjectURL(previewObjectUrl);
    previewObjectUrl = '';
  };

  const base64ToBlob = (base64, contentType) => {
    const binary = atob(base64);
    const len = binary.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i += 1) {
      bytes[i] = binary.charCodeAt(i);
    }
    return new Blob([bytes], { type: contentType });
  };

  const extractError = (payload, fallback) => {
    if (payload && payload.data && typeof payload.data.message === 'string') {
      return payload.data.message;
    }
    return fallback;
  };

  const generatePreview = async () => {
    if (previewBusy) return;

    previewBusy = true;
    if (previewButton) previewButton.disabled = true;
    setStatus('Gerando pré-visualização...');
    setLog('');

    try {
      const fd = new FormData(form);
      fd.set('action', 'pdfw_preview');
      fd.set('nonce', previewNonce);
      fd.delete('pdfw_output');

      const response = await fetch(getAjaxUrl(), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        throw new Error('Resposta inválida ao gerar prévia.');
      }

      if (!response.ok || !payload || payload.success !== true) {
        throw new Error(extractError(payload, 'Falha ao gerar pré-visualização.'));
      }

      const pdfBase64 = payload?.data?.pdf_base64 || '';
      if (!pdfBase64) {
        throw new Error('Pré-visualização sem PDF retornado pelo servidor.');
      }

      const pdfBlob = base64ToBlob(pdfBase64, 'application/pdf');
      clearPreviewUrl();
      previewObjectUrl = URL.createObjectURL(pdfBlob);

      if (previewFrame) {
        previewFrame.removeAttribute('srcdoc');
        previewFrame.src = previewObjectUrl;
      }

      setLog(payload?.data?.notice || '');
      if (downloadPdfButton) {
        downloadPdfButton.style.display = 'inline-flex';
      }
      setStatus('Pré-visualização paginada atualizada. Se estiver tudo certo, clique em Baixar PDF.');
    } catch (err) {
      setStatus('Erro na pré-visualização. Ajuste os dados e tente novamente.');
      const message = err instanceof Error ? err.message : 'Erro inesperado';
      setLog(message);
    } finally {
      previewBusy = false;
      if (previewButton) previewButton.disabled = false;
    }
  };

  if (previewButton) {
    previewButton.addEventListener('click', (event) => {
      event.preventDefault();
      generatePreview();
    });
  }

  window.addEventListener('beforeunload', clearPreviewUrl);
})();
