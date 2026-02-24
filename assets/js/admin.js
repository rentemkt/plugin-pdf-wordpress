(() => {
  const form = document.querySelector('.pdfw-form');
  if (!form) return;

  const sampleButton = document.getElementById('pdfw-load-sample');
  const previewPdfButton = document.getElementById('pdfw-generate-preview-pdf');
  const previewHtmlButton = document.getElementById('pdfw-generate-preview-html');
  const downloadPdfButton = document.getElementById('pdfw-download-pdf');
  const previewFrame = document.getElementById('pdfw-preview-frame');
  const previewStatus = document.getElementById('pdfw-preview-status');
  const previewLog = document.getElementById('pdfw-preview-log');
  const previewNonce = document.getElementById('pdfw-preview-nonce')?.value || '';
  const importNonce = document.getElementById('pdfw-import-nonce')?.value || '';
  const previewCacheInput = document.getElementById('pdfw-preview-cache-key');
  const projectsNonce = document.getElementById('pdfw-projects-nonce')?.value || '';
  const importButton = document.getElementById('pdfw-import-content');
  const importStatus = document.getElementById('pdfw-import-status');
  const importProgress = document.getElementById('pdfw-import-progress');
  const importProgressBar = document.getElementById('pdfw-import-progress-bar');
  const importProgressLabel = document.getElementById('pdfw-import-progress-label');
  const driveInput = form.querySelector('input[name="drive_folder_url"]');
  const whisperUrlInput = form.querySelector('input[name="whisper_url"]');
  const importAuditCard = document.getElementById('pdfw-import-audit-card');
  const importAuditSummary = document.getElementById('pdfw-import-audit-summary');
  const importAuditTable = document.getElementById('pdfw-import-audit-table');
  const transcribeInput = document.getElementById('pdfw-transcribe-input');
  const transcribeDrop = document.getElementById('pdfw-transcribe-drop');
  const transcribeProgress = document.getElementById('pdfw-transcribe-progress');
  const transcribeResult = document.getElementById('pdfw-transcribe-result');
  const transcriptionText = document.getElementById('pdfw-transcription-text');
  const copyTranscribeBtn = document.getElementById('pdfw-copy-transcription');
  const copyTranscribeAllBtn = document.getElementById('pdfw-copy-transcription-all');
  const resumeTranscribeBtn = document.getElementById('pdfw-resume-transcription');
  const downloadTranscribeTxtBtn = document.getElementById('pdfw-download-transcription-txt');
  const downloadTranscribeSrtBtn = document.getElementById('pdfw-download-transcription-srt');
  const downloadTranscribeVttBtn = document.getElementById('pdfw-download-transcription-vtt');
  const downloadTranscribeLipsyncBtn = document.getElementById('pdfw-download-transcription-lipsync');
  const transcribeLabel = document.getElementById('pdfw-transcribe-label');
  const transcribeResumeHint = document.getElementById('pdfw-transcribe-resume-hint');

  const recipesRawInput = form.querySelector('textarea[name="recipes_raw"]');
  const categoriesRawInput = form.querySelector('textarea[name="categories_raw"]');
  const recipeBuilder = document.getElementById('pdfw-recipe-builder');
  const addRecipeButton = document.getElementById('pdfw-add-recipe');
  const applyImportedButton = document.getElementById('pdfw-apply-imported');
  const categoryManager = document.getElementById('pdfw-category-manager');
  const addCategoryButton = document.getElementById('pdfw-add-category');

  const projectDashboard = document.getElementById('pdfw-projects-dashboard');
  const projectNameInput = document.getElementById('pdfw-project-name');
  const projectClientInput = document.getElementById('pdfw-project-client');
  const projectStatus = document.getElementById('pdfw-project-status');
  const projectNewButton = document.getElementById('pdfw-project-new');
  const projectSaveButton = document.getElementById('pdfw-project-save');
  const projectSaveAsButton = document.getElementById('pdfw-project-save-as');
  const projectDeleteButton = document.getElementById('pdfw-project-delete');
  const toastContainer = document.getElementById('pdfw-toast-container');
  const navButtons = Array.from(document.querySelectorAll('.pdfw-nav-item[data-target-section]'));
  const editorSections = Array.from(document.querySelectorAll('.pdfw-editor-section[data-section-id]'));
  const sidebarProjectName = document.getElementById('pdfw-sidebar-project-name');
  const sidebarRecipesCount = document.getElementById('pdfw-sidebar-recipes-count');
  const sidebarDirty = document.getElementById('pdfw-sidebar-dirty');
  const SECTION_STORAGE_KEY = 'pdfw_active_section';

  let previewObjectUrl = '';
  let previewBusy = false;
  let importBusy = false;
  let suppressDirty = false;

  let recipesState = [];
  let categoriesState = [];
  let importedRecipesRaw = '';
  let initialPayload = null;
  let draggedRecipeIndex = -1;
  let draggedCategoryIndex = -1;

  let projectsCache = [];
  let currentProjectId = '';
  let projectDirty = false;
  let activeSection = 'projetos';
  let transcribeOutputs = {
    txt: '',
    srt: '',
    vtt: '',
    lipsync: '',
  };
  const importButtonDefaultHtml = importButton ? importButton.innerHTML : 'Importar conteúdo';
  const transcribeDefaultLabelHtml = transcribeLabel ? transcribeLabel.innerHTML : '';
  const transcribeProgressDefaultHtml = transcribeProgress
    ? transcribeProgress.innerHTML
    : '<div class="pdfw-spinner"></div> Processando áudio...';
  let transcribeProgressTimer = 0;
  let activeTranscribeJobId = '';
  let transcribeSeenChunkIndex = 0;
  let transcribeResumeToken = '';
  let transcribeResumeNextPart = 0;
  let transcribeResumeProcessedParts = 0;
  let transcribeResumeTotalParts = 0;
  let transcribeResumeFileName = '';
  let updateLivePreview = () => {};

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
    if (!clean) {
      previewLog.hidden = true;
      previewLog.textContent = '';
      return;
    }
    previewLog.hidden = false;
    previewLog.textContent = clean;
  };

  const setProjectStatus = (text, level = 'warn') => {
    if (!projectStatus) return;
    projectStatus.textContent = text;
    if (level === 'ok') {
      projectStatus.style.color = '#0f5132';
      return;
    }
    if (level === 'error') {
      projectStatus.style.color = '#842029';
      return;
    }
    projectStatus.style.color = '#646970';
  };

  const setImportStatus = (text, level = 'idle') => {
    if (!importStatus) return;
    importStatus.textContent = text;
    importStatus.classList.remove('is-ok', 'is-error', 'is-busy');
    if (level === 'ok') importStatus.classList.add('is-ok');
    if (level === 'error') importStatus.classList.add('is-error');
    if (level === 'busy') importStatus.classList.add('is-busy');
  };

  const setImportProgress = (percent, label = '') => {
    if (!importProgress || !importProgressBar || !importProgressLabel) return;
    const pctRaw = Number(percent);
    const pct = Number.isFinite(pctRaw) ? Math.max(0, Math.min(100, Math.round(pctRaw))) : 0;
    importProgress.hidden = false;
    importProgressBar.style.width = `${pct}%`;
    importProgressLabel.textContent = label ? `${label} (${pct}%)` : `${pct}%`;
  };

  const hideImportProgress = () => {
    if (!importProgress || !importProgressBar || !importProgressLabel) return;
    importProgress.hidden = true;
    importProgressBar.style.width = '0%';
    importProgressLabel.textContent = '0%';
  };

  const normalizeAuditItems = (rawItems) => {
    if (!Array.isArray(rawItems)) return [];
    return rawItems
      .map((item) => {
        if (!item || typeof item !== 'object') return null;
        const source = String(item.source || '') === 'drive' ? 'drive' : 'upload';
        const kindRaw = String(item.kind || '').toLowerCase();
        const kind = ['recipe', 'generic', 'image', 'skip', 'error'].includes(kindRaw) ? kindRaw : 'skip';
        const name = normalizeLine(item.name || 'arquivo');
        const note = normalizeLine(item.note || '');
        const recipesCountRaw = Number(item.recipes_count);
        const recipesCount = Number.isFinite(recipesCountRaw) ? Math.max(0, Math.trunc(recipesCountRaw)) : 0;
        return {
          source,
          kind,
          name: name || 'arquivo',
          note,
          recipesCount,
        };
      })
      .filter((item) => item !== null);
  };

  const renderImportAudit = (rawItems, recipesCount = null, showWhenEmpty = false) => {
    if (!importAuditCard || !importAuditSummary || !importAuditTable) return;

    const items = normalizeAuditItems(rawItems);
    if (!items.length) {
      importAuditCard.hidden = !showWhenEmpty;
      importAuditSummary.textContent = showWhenEmpty
        ? 'Nenhum arquivo elegível foi processado nesta importação.'
        : 'Nenhuma importação executada.';
      importAuditTable.innerHTML = '';
      return;
    }

    const totals = {
      recipe: 0,
      generic: 0,
      image: 0,
      skip: 0,
      error: 0,
      recipesDetected: 0,
    };
    items.forEach((item) => {
      if (item.kind === 'recipe') {
        totals.recipe += 1;
        totals.recipesDetected += item.recipesCount;
      } else if (item.kind === 'generic') {
        totals.generic += 1;
        totals.recipesDetected += item.recipesCount;
      } else if (item.kind === 'image') {
        totals.image += 1;
      } else if (item.kind === 'error') {
        totals.error += 1;
      } else {
        totals.skip += 1;
      }
    });

    const recipesDetectedFromResponse = Number(recipesCount);
    const recipesDetected = Number.isFinite(recipesDetectedFromResponse)
      ? Math.max(0, Math.trunc(recipesDetectedFromResponse))
      : totals.recipesDetected;

    importAuditSummary.textContent = `Arquivos analisados: ${items.length}. Itens detectados: ${recipesDetected}. Receitas: ${totals.recipe}. Textos: ${totals.generic}. Imagens: ${totals.image}. Ignorados: ${totals.skip}. Erros: ${totals.error}.`;

    const rowsHtml = items.map((item) => {
      const sourceLabel = item.source === 'drive' ? 'Google Drive' : 'Upload';
      let kindLabel = 'Ignorado';
      if (item.kind === 'recipe') {
        kindLabel = item.recipesCount > 1 ? `Receita (${item.recipesCount})` : 'Receita';
      } else if (item.kind === 'generic') {
        kindLabel = item.recipesCount > 1 ? `Texto (${item.recipesCount})` : 'Texto';
      } else if (item.kind === 'image') {
        kindLabel = 'Imagem';
      } else if (item.kind === 'error') {
        kindLabel = 'Erro';
      }

      return `
        <tr>
          <td><span class="pdfw-audit-pill pdfw-audit-source">${escapeHtml(sourceLabel)}</span></td>
          <td class="pdfw-audit-name">${escapeHtml(item.name)}</td>
          <td><span class="pdfw-audit-pill pdfw-audit-kind is-${escapeHtml(item.kind)}">${escapeHtml(kindLabel)}</span></td>
          <td>${escapeHtml(item.note || 'Sem detalhe adicional')}</td>
        </tr>
      `;
    }).join('');

    importAuditTable.innerHTML = `
      <div class="pdfw-import-audit-table-wrap">
        <table class="pdfw-import-audit-table">
          <thead>
            <tr>
              <th>Origem</th>
              <th>Arquivo</th>
              <th>Status</th>
              <th>Detalhe</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    `;
    importAuditCard.hidden = false;
  };

  const setStoredSection = (sectionId) => {
    try {
      window.localStorage.setItem(SECTION_STORAGE_KEY, sectionId);
    } catch {
      // ignore storage failures
    }
  };

  const getStoredSection = () => {
    try {
      return window.localStorage.getItem(SECTION_STORAGE_KEY) || '';
    } catch {
      return '';
    }
  };

  const updateSidebarMeta = () => {
    const projectName = (projectNameInput?.value || '').trim();
    const title = (form.querySelector('[name="title"]')?.value || '').trim();
    const fallbackName = projectName || title || 'Projeto sem nome';
    if (sidebarProjectName) {
      sidebarProjectName.textContent = fallbackName;
    }
    if (sidebarRecipesCount) {
      sidebarRecipesCount.textContent = String(recipesState.length);
    }
    if (sidebarDirty) {
      if (projectDirty) {
        sidebarDirty.textContent = 'Alterado';
        sidebarDirty.classList.add('is-dirty');
        sidebarDirty.classList.remove('is-clean');
      } else {
        sidebarDirty.textContent = 'Salvo';
        sidebarDirty.classList.add('is-clean');
        sidebarDirty.classList.remove('is-dirty');
      }
    }
  };

  const activateSection = (sectionId, persist = true) => {
    if (!sectionId) return;

    let found = false;
    editorSections.forEach((section) => {
      const id = section.getAttribute('data-section-id');
      const isActive = id === sectionId;
      section.classList.toggle('is-active', isActive);
      if (isActive) found = true;
    });

    navButtons.forEach((button) => {
      const target = button.getAttribute('data-target-section');
      button.classList.toggle('is-active', target === sectionId);
    });

    if (!found) return;
    activeSection = sectionId;
    if (persist) {
      setStoredSection(sectionId);
    }
  };

  const setPreviewButtonsDisabled = (disabled) => {
    if (previewPdfButton) previewPdfButton.disabled = disabled;
    if (previewHtmlButton) previewHtmlButton.disabled = disabled;
  };

  const clearPreviewUrl = () => {
    if (!previewObjectUrl) return;
    URL.revokeObjectURL(previewObjectUrl);
    previewObjectUrl = '';
  };

  const resetPreviewPanel = () => {
    clearPreviewUrl();
    if (previewFrame) {
      previewFrame.removeAttribute('srcdoc');
      previewFrame.src = 'about:blank';
    }
    if (previewCacheInput) {
      previewCacheInput.value = '';
    }
    setStatus('Nenhuma prévia gerada ainda.');
    setLog('');
    if (downloadPdfButton) {
      downloadPdfButton.style.display = 'none';
    }
  };

  const clearProjectCardSelection = () => {
    if (!projectDashboard) return;
    projectDashboard.querySelectorAll('.pdfw-project-card.is-active').forEach((card) => {
      card.classList.remove('is-active');
    });
  };

  const base64ToBlob = (base64, contentType) => {
    const binary = atob(base64);
    const len = binary.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i += 1) bytes[i] = binary.charCodeAt(i);
    return new Blob([bytes], { type: contentType });
  };

  const extractError = (payload, fallback) => {
    if (payload && payload.data && typeof payload.data.message === 'string') {
      return payload.data.message;
    }
    return fallback;
  };

  const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const normalizeLine = (value) => String(value || '').trim();

  const PDFW_PREVIEW_THEMES = {
    'ebook2-classic': {
      pageBg: '#f7f3ec',
      heading: '#1a1a2e',
      accent: '#c27a5a',
      text: '#2a2a2a',
      coverBg: '#1a1a2e',
      coverText: '#ffffff',
      fontDisplay: "'Merriweather', serif",
      fontBody: "'Open Sans', sans-serif",
    },
    'grafite-dourado': {
      pageBg: '#f8f6f2',
      heading: '#24252a',
      accent: '#c4a95d',
      text: '#2b2b2b',
      coverBg: '#24252a',
      coverText: '#efe3be',
      fontDisplay: "'Merriweather', serif",
      fontBody: "'Open Sans', sans-serif",
    },
    'azul-mineral': {
      pageBg: '#f3f7fb',
      heading: '#204565',
      accent: '#3b8dbd',
      text: '#233342',
      coverBg: '#204565',
      coverText: '#dbe9f4',
      fontDisplay: "'Merriweather', serif",
      fontBody: "'Open Sans', sans-serif",
    },
    'terracota-moderna': {
      pageBg: '#fbf4ef',
      heading: '#7f3f2f',
      accent: '#d47652',
      text: '#3a2c28',
      coverBg: '#7f3f2f',
      coverText: '#ffe9df',
      fontDisplay: "'Merriweather', serif",
      fontBody: "'Open Sans', sans-serif",
    },
    'oliva-areia': {
      pageBg: '#f6f3e8',
      heading: '#4d5a3b',
      accent: '#9b7b4a',
      text: '#2f3426',
      coverBg: '#4d5a3b',
      coverText: '#e6ead7',
      fontDisplay: "'Merriweather', serif",
      fontBody: "'Open Sans', sans-serif",
    },
  };

  const resolvePreviewTheme = (themeKey) => {
    const key = normalizeLine(themeKey);
    if (key && PDFW_PREVIEW_THEMES[key]) {
      return PDFW_PREVIEW_THEMES[key];
    }
    return PDFW_PREVIEW_THEMES['ebook2-classic'];
  };

  const debounce = (func, wait) => {
    let timeout = 0;
    return (...args) => {
      window.clearTimeout(timeout);
      timeout = window.setTimeout(() => {
        func(...args);
      }, wait);
    };
  };

  const collectPayloadForLivePreview = () => {
    const read = (name) => {
      const field = form.querySelector(`[name="${name}"]`);
      return normalizeLine(field?.value || '');
    };
    return {
      title: read('title') || 'Ebook',
      subtitle: read('subtitle'),
      author: read('author') || 'Autor',
      theme: read('theme') || 'ebook2-classic',
      about: read('about'),
      tips: read('tips'),
      cover_image: read('cover_image'),
    };
  };

  const buildPreviewHtml = (payload, recipes) => {
    const theme = resolvePreviewTheme(payload?.theme);
    const coverImage = normalizeLine(payload?.cover_image || '');
    const list = Array.isArray(recipes) ? recipes : [];
    const safeTitle = escapeHtml(payload?.title || 'Ebook');
    const safeSubtitle = escapeHtml(payload?.subtitle || '');
    const safeAuthor = escapeHtml(payload?.author || 'Autor');

    const css = `
      @import url('https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@400;600;700&display=swap');
      :root {
        --page-bg: ${theme.pageBg};
        --heading: ${theme.heading};
        --accent: ${theme.accent};
        --text: ${theme.text};
        --cover-bg: ${theme.coverBg};
        --cover-text: ${theme.coverText};
        --font-display: ${theme.fontDisplay};
        --font-body: ${theme.fontBody};
      }
      * { box-sizing: border-box; }
      body { margin: 0; padding: 20px; background: #525659; font-family: var(--font-body); color: var(--text); }
      .page {
        width: 148mm; min-height: 210mm; margin: 0 auto 20px;
        background: var(--page-bg); padding: 15mm 12mm; position: relative;
        box-shadow: 0 4px 12px rgba(0,0,0,0.28); overflow: hidden;
      }
      .cover { background: var(--cover-bg); color: var(--cover-text); display: flex; flex-direction: column; justify-content: center; text-align: center; }
      .cover h1 { margin: 0 0 8px; font-family: var(--font-display); font-size: 26pt; line-height: 1.15; text-transform: uppercase; letter-spacing: 1.3px; }
      .cover p { margin: 0; font-size: 13pt; opacity: .9; }
      .cover .author { margin-top: auto; font-size: 10pt; letter-spacing: .7px; text-transform: uppercase; }
      .cover-img { width: 100%; height: 250px; object-fit: cover; margin: 18px 0; border-radius: 4px; opacity: .9; }
      h2, h3 { font-family: var(--font-display); color: var(--heading); }
      h2 { margin: 0 0 10px; border-bottom: 2px solid var(--accent); padding-bottom: 5px; font-size: 18pt; }
      .toc-item { display: flex; justify-content: space-between; border-bottom: 1px dotted #c7c7c7; margin-bottom: 7px; padding-bottom: 2px; font-size: 10pt; }
      .toc-page { color: var(--accent); font-weight: 700; }
      .meta { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
      .tag { display: inline-block; background: rgba(0,0,0,.05); border-radius: 4px; padding: 2px 8px; font-size: .8em; color: #555; }
      .desc { margin: 0 0 14px; color: #555; font-style: italic; }
      .cols { display: flex; gap: 18px; }
      .col { flex: 1; min-width: 0; }
      .col h3 { margin: 0 0 7px; font-size: 11pt; text-transform: uppercase; letter-spacing: .4px; color: var(--accent); }
      ul, ol { margin: 0; padding-left: 18px; font-size: 10pt; line-height: 1.4; }
      li { margin-bottom: 4px; }
      .content-body { white-space: pre-wrap; line-height: 1.6; font-size: 10.5pt; text-align: justify; }
      .tip { margin-top: 14px; border-left: 4px solid var(--accent); background: rgba(0,0,0,.03); padding: 10px 12px; border-radius: 6px; font-size: 9.6pt; }
      .hero-media { height: 132px; margin: -15mm -12mm 14px; overflow: hidden; background: #ebebeb; }
      .hero-media img { width: 100%; height: 100%; object-fit: cover; }
    `;

    let html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><style>${css}</style></head><body>`;
    html += `<div class="page cover" id="preview-cover">${coverImage ? `<img class="cover-img" src="${escapeHtml(coverImage)}" alt="">` : ''}<h1>${safeTitle}</h1><p>${safeSubtitle}</p><div class="author">${safeAuthor}</div></div>`;

    if (list.length) {
      html += '<div class="page"><h2 style="text-align:center">Sumário</h2>';
      list.forEach((recipe, idx) => {
        const title = escapeHtml(normalizeLine(recipe?.title || `Item ${idx + 1}`));
        html += `<div class="toc-item"><span>${idx + 1}. ${title}</span><span class="toc-page">Pg ${idx + 3}</span></div>`;
      });
      html += '</div>';
    }

    list.forEach((recipe, idx) => {
      const title = escapeHtml(normalizeLine(recipe?.title || `Item ${idx + 1}`));
      const category = normalizeLine(recipe?.category || '');
      const tempo = normalizeLine(recipe?.tempo || '');
      const porcoes = normalizeLine(recipe?.porcoes || '');
      const dificuldade = normalizeLine(recipe?.dificuldade || '');
      const image = normalizeLine(recipe?.image || '');
      const description = normalizeLine(recipe?.description || '');
      const tip = normalizeLine(recipe?.tip || '');
      const ingredients = Array.isArray(recipe?.ingredients) ? recipe.ingredients.filter((i) => normalizeLine(i) !== '') : [];
      const steps = Array.isArray(recipe?.steps) ? recipe.steps.filter((s) => normalizeLine(s) !== '') : [];
      const isRecipe = ingredients.length > 0 || steps.length > 0;

      html += `<div class="page" id="preview-item-${idx}">`;
      if (image) {
        html += `<div class="hero-media"><img src="${escapeHtml(image)}" alt=""></div>`;
      }
      html += `<h2>${title}</h2>`;
      const metas = [];
      if (category) metas.push(category);
      if (tempo) metas.push(`⏱ ${tempo}`);
      if (porcoes) metas.push(`🍽 ${porcoes}`);
      if (dificuldade) metas.push(`📊 ${dificuldade}`);
      if (metas.length) {
        html += `<div class="meta">${metas.map((meta) => `<span class="tag">${escapeHtml(meta)}</span>`).join('')}</div>`;
      }
      if (description) {
        html += `<p class="${isRecipe ? 'desc' : 'content-body'}">${escapeHtml(description).replace(/\n/g, '<br>')}</p>`;
      }

      if (isRecipe) {
        html += '<div class="cols"><div class="col"><h3>Ingredientes</h3><ul>';
        ingredients.forEach((item) => {
          html += `<li>${escapeHtml(item)}</li>`;
        });
        html += '</ul></div><div class="col"><h3>Modo de Preparo</h3><ol>';
        steps.forEach((step) => {
          html += `<li>${escapeHtml(step)}</li>`;
        });
        html += '</ol></div></div>';
        if (tip) {
          html += `<div class="tip"><strong>Dica:</strong> ${escapeHtml(tip)}</div>`;
        }
      }

      html += '</div>';
    });

    const about = normalizeLine(payload?.about || '');
    const tips = normalizeLine(payload?.tips || '');
    if (tips || about) {
      html += '<div class="page">';
      if (tips) {
        html += `<h2>Dicas Finais</h2><div class="content-body">${escapeHtml(tips).replace(/\n/g, '<br>')}</div>`;
      }
      if (about) {
        html += `<h2 style="margin-top:20px;">Sobre o Autor</h2><div class="content-body">${escapeHtml(about).replace(/\n/g, '<br>')}</div>`;
      }
      html += '</div>';
    }

    html += '</body></html>';
    return html;
  };

  const scrollToPreviewItem = (index) => {
    if (!previewFrame || !previewFrame.contentWindow) return;
    const doc = previewFrame.contentWindow.document;
    if (!doc) return;
    const target = doc.getElementById(`preview-item-${index}`);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  updateLivePreview = debounce(() => {
    if (!previewFrame) return;
    const payload = collectPayloadForLivePreview();
    const html = buildPreviewHtml(payload, recipesState);
    showHtmlPreview(html);
    setStatus('Prévia em tempo real atualizada.');
  }, 220);

  const showToast = (message, type = 'info', ttlMs = 4200) => {
    if (!toastContainer) return;
    const text = normalizeLine(message);
    if (!text) return;

    const toast = document.createElement('div');
    toast.className = `pdfw-toast is-${type}`;
    toast.innerHTML = `<span>${escapeHtml(text)}</span>`;

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pdfw-toast-close';
    closeBtn.setAttribute('aria-label', 'Fechar notificação');
    closeBtn.innerHTML = '&times;';
    closeBtn.addEventListener('click', () => {
      toast.classList.add('is-leaving');
      window.setTimeout(() => toast.remove(), 220);
    });

    toast.appendChild(closeBtn);
    toastContainer.appendChild(toast);

    window.setTimeout(() => {
      toast.classList.add('is-leaving');
      window.setTimeout(() => toast.remove(), 220);
    }, ttlMs);
  };

  const setTranscribeBusy = (busy, label = '') => {
    if (transcribeProgress) {
      transcribeProgress.hidden = !busy;
    }
    if (transcribeDrop) {
      transcribeDrop.classList.toggle('is-busy', busy);
    }
    if (transcribeLabel && label) {
      transcribeLabel.innerHTML = escapeHtml(label).replace(/\n/g, '<br>');
    }
    if (transcribeInput) {
      transcribeInput.disabled = busy;
    }
    if (resumeTranscribeBtn) {
      resumeTranscribeBtn.disabled = busy || !transcribeResumeToken;
    }
  };

  const setTranscribeResumeState = (state = null) => {
    const token = normalizeLine(state?.token || '');
    const nextPartRaw = Number(state?.nextPart || 0);
    const processedRaw = Number(state?.processedParts || 0);
    const totalRaw = Number(state?.totalParts || 0);
    const fileName = normalizeLine(state?.fileName || '');

    transcribeResumeToken = token;
    transcribeResumeNextPart = Number.isFinite(nextPartRaw) ? Math.max(0, Math.trunc(nextPartRaw)) : 0;
    transcribeResumeProcessedParts = Number.isFinite(processedRaw) ? Math.max(0, Math.trunc(processedRaw)) : 0;
    transcribeResumeTotalParts = Number.isFinite(totalRaw) ? Math.max(0, Math.trunc(totalRaw)) : 0;
    transcribeResumeFileName = fileName;

    if (resumeTranscribeBtn) {
      if (token) {
        const part = transcribeResumeNextPart > 0 ? transcribeResumeNextPart : (transcribeResumeProcessedParts + 1);
        resumeTranscribeBtn.textContent = `Retomar da parte ${part}`;
      } else {
        resumeTranscribeBtn.textContent = 'Retomar da parte falha';
      }
      resumeTranscribeBtn.disabled = !token;
    }

    if (transcribeResumeHint) {
      if (!token) {
        transcribeResumeHint.hidden = true;
        transcribeResumeHint.textContent = '';
      } else {
        const part = transcribeResumeNextPart > 0 ? transcribeResumeNextPart : (transcribeResumeProcessedParts + 1);
        const total = transcribeResumeTotalParts > 0 ? ` de ${transcribeResumeTotalParts}` : '';
        const fromFile = transcribeResumeFileName ? ` Arquivo: ${transcribeResumeFileName}.` : '';
        transcribeResumeHint.hidden = false;
        transcribeResumeHint.textContent = `Retomada disponível na parte ${part}${total}.${fromFile}`;
      }
    }
  };

  const setTranscribeProgressStatus = (message, percent = null, current = 0, total = 0) => {
    if (!transcribeProgress) return;
    const msg = normalizeLine(message || 'Processando...');
    let suffix = '';
    const pctRaw = Number(percent);
    if (Number.isFinite(pctRaw)) {
      suffix += ` (${Math.max(0, Math.min(100, Math.round(pctRaw)))}%)`;
    }
    const currentNum = Number(current);
    const totalNum = Number(total);
    if (Number.isFinite(currentNum) && Number.isFinite(totalNum) && totalNum > 0) {
      suffix += ` • ${Math.max(0, Math.trunc(currentNum))}/${Math.max(0, Math.trunc(totalNum))}`;
    }
    transcribeProgress.innerHTML = `<div class="pdfw-spinner"></div>${escapeHtml(msg + suffix)}`;
  };

  const appendChunkPreviewText = (chunkIndex, chunkText) => {
    const idx = Number(chunkIndex);
    const text = normalizeLine(chunkText || '');
    if (!Number.isFinite(idx) || idx <= 0 || idx <= transcribeSeenChunkIndex || !text) {
      return;
    }

    transcribeSeenChunkIndex = idx;
    const current = normalizeLine(transcribeOutputs.txt || '');
    const next = current ? `${current}\n\n${text}` : text;
    transcribeOutputs.txt = next;
    if (transcriptionText) {
      transcriptionText.value = next;
      transcriptionText.scrollTop = transcriptionText.scrollHeight;
    }
    if (transcribeResult) {
      transcribeResult.hidden = false;
    }
    updateTranscribeActionState();
  };

  const stopTranscribeProgressPolling = () => {
    if (transcribeProgressTimer) {
      window.clearInterval(transcribeProgressTimer);
      transcribeProgressTimer = 0;
    }
    activeTranscribeJobId = '';
  };

  const pollTranscribeProgress = async () => {
    if (!activeTranscribeJobId) return;

    const fd = new FormData();
    fd.set('action', 'pdfw_transcribe_progress');
    fd.set('nonce', importNonce);
    fd.set('job_id', activeTranscribeJobId);

    try {
      const response = await fetch(getAjaxUrl(), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload || payload.success !== true) return;

      const stage = normalizeLine(payload?.data?.stage || '');
      const percent = Number(payload?.data?.percent);
      const message = normalizeLine(payload?.data?.message || 'Processando...');
      const current = Number(payload?.data?.current || 0);
      const total = Number(payload?.data?.total || 0);
      setTranscribeProgressStatus(message, percent, current, total);

      const lastChunkIndex = Number(payload?.data?.last_chunk_index || 0);
      const lastChunkText = normalizeLine(payload?.data?.last_chunk_text || '');
      if (Number.isFinite(lastChunkIndex) && lastChunkIndex > 0 && lastChunkText) {
        appendChunkPreviewText(lastChunkIndex, lastChunkText);
      } else {
        const chunkIndex = Number(payload?.data?.chunk_index || 0);
        const chunkText = normalizeLine(payload?.data?.chunk_text || '');
        if (Number.isFinite(chunkIndex) && chunkIndex > 0 && chunkText) {
          appendChunkPreviewText(chunkIndex, chunkText);
        }
      }

      if (['done', 'partial', 'error'].includes(stage)) {
        stopTranscribeProgressPolling();
      }
    } catch {
      // ignora falha de polling para não interromper a transcrição em andamento
    }
  };

  const startTranscribeProgressPolling = (jobId, initialSeenChunkIndex = 0) => {
    stopTranscribeProgressPolling();
    activeTranscribeJobId = normalizeLine(jobId);
    if (!activeTranscribeJobId) return;
    const seed = Number(initialSeenChunkIndex);
    transcribeSeenChunkIndex = Number.isFinite(seed) ? Math.max(0, Math.trunc(seed)) : 0;
    setTranscribeProgressStatus('Preparando transcrição...', 2, 0, 0);
    transcribeProgressTimer = window.setInterval(() => {
      void pollTranscribeProgress();
    }, 1500);
    void pollTranscribeProgress();
  };

  const isStandaloneTranscribeFileAllowed = (fileName) => {
    const name = String(fileName || '').toLowerCase();
    return ['.mp3', '.wav', '.m4a', '.ogg', '.mp4', '.mpeg', '.webm', '.mkv'].some((ext) => name.endsWith(ext));
  };

  const isStandaloneTranscribeVideoFile = (fileName) => {
    const name = String(fileName || '').toLowerCase();
    return ['.mp4', '.webm', '.mkv', '.mpeg'].some((ext) => name.endsWith(ext));
  };

  const copyTextToClipboard = async (text, successMessage) => {
    const value = normalizeLine(text);
    if (!value) return false;

    try {
      let copied = false;
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(value);
        copied = true;
      } else if (transcriptionText) {
        transcriptionText.value = value;
        transcriptionText.select();
        document.execCommand('copy');
        copied = true;
      }

      if (!copied) {
        return false;
      }
      if (successMessage) {
        showToast(successMessage, 'success');
      }
      return true;
    } catch {
      return false;
    }
  };

  const normalizeTranscribeOutputText = (value, format = 'txt') => {
    const raw = normalizeLine(value || '');
    if (!raw) return '';

    if (format === 'vtt') {
      const hasHeader = raw.slice(0, 6).toUpperCase() === 'WEBVTT';
      return hasHeader ? raw : `WEBVTT\n\n${raw}`;
    }

    return raw;
  };

  const buildTranscriptionBundle = () => {
    const parts = [];
    if (transcribeOutputs.txt) {
      parts.push(['TXT', transcribeOutputs.txt].join('\n'));
    }
    if (transcribeOutputs.srt) {
      parts.push(['SRT', transcribeOutputs.srt].join('\n'));
    }
    if (transcribeOutputs.vtt) {
      parts.push(['VTT', transcribeOutputs.vtt].join('\n'));
    }
    if (transcribeOutputs.lipsync) {
      parts.push(['LIPSYNC_JSON', transcribeOutputs.lipsync].join('\n'));
    }
    if (!parts.length) return '';
    return parts
      .map((block) => {
        const lines = block.split('\n');
        const label = lines.shift() || 'OUTPUT';
        const body = lines.join('\n');
        return `===== ${label} =====\n${body}`;
      })
      .join('\n\n');
  };

  const updateTranscribeActionState = () => {
    const hasTxt = normalizeLine(transcribeOutputs.txt) !== '';
    const hasSrt = normalizeLine(transcribeOutputs.srt) !== '';
    const hasVtt = normalizeLine(transcribeOutputs.vtt) !== '';
    const hasLipsync = normalizeLine(transcribeOutputs.lipsync) !== '';
    const hasAny = hasTxt || hasSrt || hasVtt || hasLipsync;

    if (copyTranscribeBtn) copyTranscribeBtn.disabled = !hasTxt;
    if (copyTranscribeAllBtn) copyTranscribeAllBtn.disabled = !hasAny;
    if (downloadTranscribeTxtBtn) downloadTranscribeTxtBtn.disabled = !hasTxt;
    if (downloadTranscribeSrtBtn) downloadTranscribeSrtBtn.disabled = !hasSrt;
    if (downloadTranscribeVttBtn) downloadTranscribeVttBtn.disabled = !hasVtt;
    if (downloadTranscribeLipsyncBtn) downloadTranscribeLipsyncBtn.disabled = !hasLipsync;
  };

  const resetTranscribeOutputs = ({ clearResume = false } = {}) => {
    transcribeOutputs = {
      txt: '',
      srt: '',
      vtt: '',
      lipsync: '',
    };
    transcribeSeenChunkIndex = 0;

    if (transcriptionText) {
      transcriptionText.value = '';
    }
    if (clearResume) {
      setTranscribeResumeState(null);
    }
    updateTranscribeActionState();
  };

  const setTranscribeOutputs = (payloadData) => {
    const txt = normalizeTranscribeOutputText(payloadData?.text || '', 'txt');
    const srt = normalizeTranscribeOutputText(payloadData?.srt || '', 'srt');
    const vtt = normalizeTranscribeOutputText(payloadData?.vtt || '', 'vtt');
    const lipsync = normalizeLine(payloadData?.lipsync_json || '');

    transcribeOutputs = {
      txt,
      srt,
      vtt,
      lipsync,
    };

    if (transcriptionText) {
      transcriptionText.value = txt;
    }
    updateTranscribeActionState();
  };

  const copyTranscriptionToClipboard = async () => {
    if (normalizeLine(transcribeOutputs.txt) === '') {
      showToast('Não há texto para copiar ainda.', 'warn');
      return;
    }

    const copied = await copyTextToClipboard(transcribeOutputs.txt, 'Texto copiado para a área de transferência.');
    if (!copied) {
      showToast('Não foi possível copiar o texto automaticamente.', 'error');
    }
  };

  const copyAllTranscriptionToClipboard = async () => {
    const bundle = buildTranscriptionBundle();
    if (!bundle) {
      showToast('Não há output para copiar ainda.', 'warn');
      return;
    }

    const copied = await copyTextToClipboard(bundle, 'Output completo copiado para a área de transferência.');
    if (!copied) {
      showToast('Não foi possível copiar o output completo.', 'error');
    }
  };

  const downloadTranscribeOutput = (text, filename, mimeType) => {
    const value = normalizeLine(text);
    if (!value) {
      showToast('Esse formato ainda não está disponível para download.', 'warn');
      return;
    }

    const blob = new Blob([value], { type: mimeType || 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  };

  const handleStandaloneTranscription = async (file, options = {}) => {
    const resumeMode = Boolean(options && options.resume);
    if (!resumeMode && !file) return;
    if (resumeMode && !transcribeResumeToken) {
      showToast('Nenhuma transcrição pendente para retomar.', 'warn');
      return;
    }
    if (!resumeMode && file && !isStandaloneTranscribeFileAllowed(file.name)) {
      showToast('Formato não suportado. Use MP3, WAV, M4A, OGG, MP4, MPEG, WEBM ou MKV.', 'error');
      return;
    }

    const sourceName = resumeMode
      ? (transcribeResumeFileName || 'arquivo anterior')
      : normalizeLine(file?.name || 'arquivo');

    if (!resumeMode) {
      if (transcribeResult) {
        transcribeResult.hidden = true;
      }
      resetTranscribeOutputs({ clearResume: true });
    }

    const requestResumeToken = resumeMode
      ? transcribeResumeToken
      : `rsm_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;

    if (!resumeMode) {
      setTranscribeResumeState({
        token: requestResumeToken,
        nextPart: 1,
        processedParts: 0,
        totalParts: 0,
        fileName: sourceName,
      });
    }

    if (!resumeMode && file && isStandaloneTranscribeVideoFile(file.name)) {
      showToast('Vídeo longo detectado. O processamento pode levar alguns minutos dependendo do tamanho. Por favor, aguarde.', 'info', 7000);
    }

    const jobId = `job_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
    const initialSeenChunk = resumeMode ? Math.max(0, transcribeResumeProcessedParts) : 0;
    setTranscribeBusy(
      true,
      resumeMode ? `Retomando transcrição: ${sourceName}` : `Processando: ${sourceName}`,
    );
    startTranscribeProgressPolling(jobId, initialSeenChunk);

    try {
      const fd = new FormData();
      fd.set('action', 'pdfw_standalone_transcribe');
      fd.set('nonce', importNonce);
      fd.set('job_id', jobId);
      fd.set('resume_token', requestResumeToken);
      if (!resumeMode && file) {
        fd.set('audio_file', file);
      }
      if (whisperUrlInput && normalizeLine(whisperUrlInput.value || '')) {
        fd.set('whisper_url', whisperUrlInput.value);
      }

      const response = await fetch(getAjaxUrl(), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        throw new Error('Resposta inválida da API de transcrição.');
      }

      if (!response.ok || !payload || payload.success !== true) {
        const errorResumeToken = normalizeLine(payload?.data?.resume_token || '');
        const errorProcessed = Number(payload?.data?.processed_parts || transcribeResumeProcessedParts || 0);
        const errorTotal = Number(payload?.data?.total_parts || transcribeResumeTotalParts || 0);
        const errorFailedPart = Number(payload?.data?.resume_next_part || payload?.data?.failed_part || 0);
        if (errorResumeToken) {
          setTranscribeResumeState({
            token: errorResumeToken,
            nextPart: errorFailedPart > 0 ? errorFailedPart : (errorProcessed + 1),
            processedParts: errorProcessed,
            totalParts: errorTotal,
            fileName: sourceName,
          });
          if (transcribeResult) {
            transcribeResult.hidden = false;
          }
        }
        throw new Error(extractError(payload, 'Falha na transcrição.'));
      }

      const text = normalizeLine(payload?.data?.text || '');
      const partial = Boolean(payload?.data?.partial);
      if (!text && !partial) {
        throw new Error('A API respondeu sem texto transcrito.');
      }

      setTranscribeOutputs(payload?.data || {});
      if (transcribeResult) {
        transcribeResult.hidden = false;
      }

      const processedParts = Number(payload?.data?.processed_parts || 0);
      const totalParts = Number(payload?.data?.total_parts || 0);
      const failedPart = Number(payload?.data?.failed_part || 0);
      const resumeToken = normalizeLine(payload?.data?.resume_token || '');
      const resumeNextPartRaw = Number(payload?.data?.resume_next_part || 0);
      const resumeNextPart = Number.isFinite(resumeNextPartRaw) && resumeNextPartRaw > 0
        ? Math.trunc(resumeNextPartRaw)
        : (failedPart > 0 ? failedPart : (processedParts + 1));

      if (partial) {
        setTranscribeResumeState({
          token: resumeToken || transcribeResumeToken,
          nextPart: resumeNextPart,
          processedParts,
          totalParts,
          fileName: sourceName,
        });
        showToast(
          `Transcrição parcial agregada (${processedParts}/${totalParts}). Clique em "Retomar da parte ${resumeNextPart}" para continuar.`,
          'warn',
          9000,
        );
      } else {
        setTranscribeResumeState(null);
        showToast('Transcrição concluída com sucesso.', 'success');
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao transcrever arquivo.';
      showToast(message, 'error');
    } finally {
      stopTranscribeProgressPolling();
      setTranscribeBusy(false, 'Clique ou arraste um arquivo aqui\n(MP3, WAV, M4A, OGG, MP4, MPEG, WEBM, MKV)');
      if (transcribeProgress) {
        transcribeProgress.innerHTML = transcribeProgressDefaultHtml;
      }
      if (transcribeLabel && transcribeDefaultLabelHtml) {
        transcribeLabel.innerHTML = transcribeDefaultLabelHtml;
      }
      if (transcribeInput) {
        transcribeInput.value = '';
      }
    }
  };

  const normalizeMediaKey = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  const mediaKeyTokens = (value) => normalizeMediaKey(value).split('-').filter(Boolean);

  const applyImageEntriesToItems = (items, imageEntries) => {
    const list = Array.isArray(items) ? items.map((item) => ({ ...item })) : [];
    const available = (Array.isArray(imageEntries) ? imageEntries : [])
      .map((entry) => ({
        src: normalizeLine(entry?.src || ''),
        key: normalizeMediaKey(entry?.key || entry?.base || entry?.name || ''),
      }))
      .filter((entry) => entry.src !== '');

    if (!list.length || !available.length) return list;

    list.forEach((item) => {
      if (!item || typeof item !== 'object') return;
      if (normalizeLine(item.image || '') !== '') return;

      const title = normalizeLine(item.title || '');
      const titleKey = normalizeMediaKey(title);
      if (!titleKey) return;

      const titleTokens = mediaKeyTokens(titleKey);
      let bestIndex = -1;
      let bestScore = 0;

      available.forEach((entry, index) => {
        const key = entry.key;
        if (!key) return;

        let score = 0;
        if (key === titleKey) {
          score = 100;
        } else if (key.includes(titleKey) || titleKey.includes(key)) {
          score = 80;
        } else {
          const overlap = titleTokens.filter((token) => mediaKeyTokens(key).includes(token));
          score = overlap.length * 10;
        }

        if (score > bestScore) {
          bestScore = score;
          bestIndex = index;
        }
      });

      if (bestIndex >= 0 && bestScore >= 20) {
        item.image = available[bestIndex].src;
        available.splice(bestIndex, 1);
      }
    });

    return list;
  };

  const toList = (text, kind) => {
    return String(text || '')
      .replace(/\r\n/g, '\n')
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line !== '')
      .map((line) => {
        if (kind === 'ingredients') return line.replace(/^[-*]\s*/, '').trim();
        if (kind === 'steps') return line.replace(/^\d+[\).:-]?\s*/, '').trim();
        return line.trim();
      })
      .filter((line) => line !== '');
  };

  const normalizeCategoryName = (value) => {
    const clean = String(value || '').replace(/\s+/g, ' ').trim();
    return clean;
  };

  const categoryKey = (value) => {
    if (value && typeof value === 'object') {
      return normalizeCategoryName(value.name).toLowerCase();
    }
    return normalizeCategoryName(value).toLowerCase();
  };

  const makeCategoryState = (value, defaults = {}) => {
    const name = normalizeCategoryName(value?.name ?? value ?? defaults.name ?? '');
    if (!name) return null;
    return {
      name,
      subtitle: normalizeLine(value?.subtitle ?? defaults.subtitle ?? ''),
      image: normalizeLine(value?.image ?? defaults.image ?? ''),
    };
  };

  const uniqueCategoryName = (candidate, ignoreIndex = -1) => {
    const base = normalizeCategoryName(candidate) || 'Categoria';
    let next = base;
    let suffix = 2;
    while (categoriesState.some((category, index) => index !== ignoreIndex && categoryKey(category) === categoryKey(next))) {
      next = `${base} ${suffix}`;
      suffix += 1;
    }
    return next;
  };

  const parseCategoriesRaw = (raw) => {
    const input = String(raw || '').trim();
    if (!input) return [];

    if (input.startsWith('[')) {
      try {
        const parsed = JSON.parse(input);
        if (!Array.isArray(parsed)) return [];
        const seen = new Set();
        const out = [];
        parsed.forEach((item) => {
          if (!item || typeof item !== 'object') return;
          const category = makeCategoryState({
            name: item.name || item.title || '',
            subtitle: item.subtitle || '',
            image: item.image || '',
          });
          if (!category) return;
          const key = categoryKey(category);
          if (seen.has(key)) return;
          seen.add(key);
          out.push(category);
        });
        return out;
      } catch {
        // fallback para parser por linhas
      }
    }

    const blocks = input.split(/^\s*---+\s*$/m).map((block) => block.trim()).filter(Boolean);
    const out = [];
    const seen = new Set();
    blocks.forEach((block) => {
      const lines = block.split('\n').map((line) => line.trim()).filter(Boolean);
      if (!lines.length) return;
      let name = normalizeCategoryName(lines[0].replace(/^categoria\s*:?\s*/i, ''));
      let subtitle = '';
      let image = '';

      lines.slice(1).forEach((line) => {
        const subtitleMatch = line.match(/^(subt[ií]tulo|descri(?:c|ç)(?:a|ã)o)\s*:?\s*(.+)$/i);
        if (subtitleMatch && subtitleMatch[2]) {
          subtitle = normalizeLine(subtitleMatch[2]);
          return;
        }
        const imageMatch = line.match(/^imagem\s*:?\s*(.+)$/i);
        if (imageMatch && imageMatch[1]) {
          image = normalizeLine(imageMatch[1]);
        }
      });

      if (!name) return;
      const category = makeCategoryState({ name, subtitle, image });
      if (!category) return;
      const key = categoryKey(category);
      if (seen.has(key)) return;
      seen.add(key);
      out.push(category);
    });

    return out;
  };

  const categoriesToRaw = () => {
    const payload = categoriesState.map((category) => ({
      name: normalizeCategoryName(category?.name),
      subtitle: normalizeLine(category?.subtitle),
      image: normalizeLine(category?.image),
    })).filter((category) => category.name);
    return JSON.stringify(payload, null, 2);
  };

  const syncCategoriesRawFromState = () => {
    if (!categoriesRawInput) return;
    categoriesRawInput.value = categoriesToRaw();
  };

  const rebuildCategoriesFromRecipes = () => {
    const seen = new Set();
    const next = [];

    categoriesState.forEach((category) => {
      const clean = makeCategoryState(category);
      if (!clean) return;
      const key = categoryKey(clean);
      if (!key || seen.has(key)) return;
      seen.add(key);
      next.push(clean);
    });

    recipesState.forEach((recipe) => {
      const categoryName = normalizeCategoryName(recipe?.category);
      if (!categoryName) return;
      const key = categoryKey(categoryName);
      if (seen.has(key)) return;
      seen.add(key);
      next.push({
        name: categoryName,
        subtitle: '',
        image: '',
      });
    });

    if (!next.length) {
      next.push({ name: 'Itens', subtitle: '', image: '' });
    }

    categoriesState = next;
    const firstCategoryName = categoriesState[0]?.name || 'Itens';

    recipesState = recipesState.map((recipe) => {
      const current = normalizeCategoryName(recipe?.category);
      if (current) {
        const matched = categoriesState.find((category) => categoryKey(category) === categoryKey(current));
        if (matched) {
          return { ...recipe, category: matched.name };
        }
      }
      return { ...recipe, category: firstCategoryName };
    });

    sortRecipesByCategoryOrder();
    syncCategoriesRawFromState();
  };

  const sortRecipesByCategoryOrder = () => {
    const order = new Map();
    categoriesState.forEach((category, idx) => {
      order.set(categoryKey(category), idx);
    });

    recipesState = recipesState
      .map((recipe, idx) => ({ recipe, idx }))
      .sort((a, b) => {
        const aKey = categoryKey(a.recipe?.category);
        const bKey = categoryKey(b.recipe?.category);
        const aOrder = order.has(aKey) ? order.get(aKey) : Number.MAX_SAFE_INTEGER;
        const bOrder = order.has(bKey) ? order.get(bKey) : Number.MAX_SAFE_INTEGER;
        if (aOrder !== bOrder) return aOrder - bOrder;
        return a.idx - b.idx;
      })
      .map((entry) => entry.recipe);
  };

  const categoryOptionsHtml = (selectedCategory) => {
    const selected = normalizeCategoryName(selectedCategory);
    return categoriesState
      .map((category) => {
        const name = normalizeCategoryName(category?.name);
        const isSelected = categoryKey(name) === categoryKey(selected);
        return `<option value="${escapeHtml(name)}" ${isSelected ? 'selected' : ''}>${escapeHtml(name)}</option>`;
      })
      .join('');
  };

  const parseRecipesRaw = (raw) => {
    const input = String(raw || '').replace(/\r\n/g, '\n').trim();
    if (!input) return [];

    const blocks = input.split(/^\s*---+\s*$/m);
    const out = [];

    blocks.forEach((block) => {
      const lines = block
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
      if (!lines.length) return;

      const title = lines.shift() || 'Item sem título';
      const blockLower = block.toLowerCase();
      const looksLikeRecipe = blockLower.includes('ingredientes:')
        || blockLower.includes('modo de preparo')
        || blockLower.includes('preparo:');

      let section = looksLikeRecipe ? '' : 'description';
      const ingredients = [];
      const steps = [];
      const tipLines = [];
      const descriptionLines = [];
      let category = '';
      let tempo = '';
      let porcoes = '';
      let dificuldade = '';
      let image = '';
      const nutrition = { kcal: '', carb: '', prot: '', fat: '', fiber: '' };

      lines.forEach((line) => {
        const low = line.toLowerCase();
        const categoryMatch = line.match(/^categoria\s*:?\s*(.+)$/i);
        if (categoryMatch && categoryMatch[1]) {
          const parsedCategory = normalizeCategoryName(categoryMatch[1]);
          if (parsedCategory) {
            category = parsedCategory;
          }
          return;
        }
        const descriptionMatch = line.match(/^descri(?:c|ç)(?:a|ã)o\s*:?\s*(.+)$/i);
        if (descriptionMatch && descriptionMatch[1]) {
          descriptionLines.push(normalizeLine(descriptionMatch[1]));
          section = 'description';
          return;
        }
        const contentMatch = line.match(/^conte(?:u|ú)do\s*:?\s*(.+)$/i);
        if (contentMatch && contentMatch[1]) {
          descriptionLines.push(normalizeLine(contentMatch[1]));
          section = 'description';
          return;
        }
        const tempoMatch = line.match(/^tempo\s*:?\s*(.+)$/i);
        if (tempoMatch && tempoMatch[1]) {
          tempo = normalizeLine(tempoMatch[1]);
          return;
        }
        const porcoesMatch = line.match(/^por(?:c|ç)(?:o|õ)es?\s*:?\s*(.+)$/i);
        if (porcoesMatch && porcoesMatch[1]) {
          porcoes = normalizeLine(porcoesMatch[1]);
          return;
        }
        const dificuldadeMatch = line.match(/^dificuldade\s*:?\s*(.+)$/i);
        if (dificuldadeMatch && dificuldadeMatch[1]) {
          dificuldade = normalizeLine(dificuldadeMatch[1]);
          return;
        }
        const imageMatch = line.match(/^imagem(?:\s+da\s+receita)?\s*:?\s*(.+)$/i);
        if (imageMatch && imageMatch[1]) {
          image = normalizeLine(imageMatch[1]);
          return;
        }
        if (low.includes('ingredientes')) {
          section = 'ingredients';
          return;
        }
        if (low.includes('modo de preparo') || low === 'preparo:' || low === 'preparo') {
          section = 'steps';
          return;
        }
        if (low.startsWith('descrição:') || low.startsWith('descricao:') || low.startsWith('conteúdo:') || low.startsWith('conteudo:')) {
          section = 'description';
          return;
        }
        if (low.includes('informação nutricional') || low.includes('informacao nutricional')) {
          section = 'nutrition';
          return;
        }
        const kcalMatch = line.match(/^calorias?\s*:?\s*(.+)$/i);
        if (kcalMatch && kcalMatch[1]) {
          nutrition.kcal = normalizeLine(kcalMatch[1]);
          section = 'nutrition';
          return;
        }
        const carbMatch = line.match(/^carboidratos?\s*:?\s*(.+)$/i);
        if (carbMatch && carbMatch[1]) {
          nutrition.carb = normalizeLine(carbMatch[1]);
          section = 'nutrition';
          return;
        }
        const protMatch = line.match(/^prote(?:i|í)nas?\s*:?\s*(.+)$/i);
        if (protMatch && protMatch[1]) {
          nutrition.prot = normalizeLine(protMatch[1]);
          section = 'nutrition';
          return;
        }
        const fatMatch = line.match(/^gorduras?\s*:?\s*(.+)$/i);
        if (fatMatch && fatMatch[1]) {
          nutrition.fat = normalizeLine(fatMatch[1]);
          section = 'nutrition';
          return;
        }
        const fiberMatch = line.match(/^fibras?\s*:?\s*(.+)$/i);
        if (fiberMatch && fiberMatch[1]) {
          nutrition.fiber = normalizeLine(fiberMatch[1]);
          section = 'nutrition';
          return;
        }
        if (low.startsWith('dica')) {
          section = 'tip';
          const rest = line.replace(/^dica\s*:?\s*/i, '').trim();
          if (rest) tipLines.push(rest);
          return;
        }

        if (section === 'ingredients') {
          ingredients.push(line.replace(/^[-*]\s*/, '').trim());
          return;
        }
        if (section === 'steps') {
          steps.push(line.replace(/^\d+[\).:-]?\s*/, '').trim());
          return;
        }
        if (section === 'tip') {
          tipLines.push(line);
          return;
        }
        if (section === 'description' || section === '') {
          descriptionLines.push(line);
          return;
        }
        if (section === 'nutrition') {
          // Ignora linhas nutricionais não mapeadas.
        }
      });

      const hasNutrition = Object.values(nutrition).some((value) => normalizeLine(value) !== '');
      const hasRecipeData = ingredients.length > 0
        || steps.length > 0
        || normalizeLine(tempo) !== ''
        || normalizeLine(porcoes) !== ''
        || normalizeLine(dificuldade) !== ''
        || hasNutrition;
      const isGeneric = !hasRecipeData;

      out.push({
        title: normalizeLine(title) || 'Item sem título',
        category: normalizeCategoryName(category),
        description: descriptionLines.join('\n').trim(),
        tempo,
        porcoes,
        dificuldade,
        image,
        ingredients: ingredients.filter(Boolean),
        steps: steps.filter(Boolean),
        tip: tipLines.join(' ').trim(),
        nutrition,
        isGeneric,
      });
    });

    return out;
  };

  const recipesToRaw = (recipes) => {
    if (!Array.isArray(recipes)) return '';

    const orderedRecipes = recipes
      .map((recipe, index) => ({ recipe, index }))
      .sort((left, right) => {
        const aOrder = categoriesState.findIndex((cat) => categoryKey(cat) === categoryKey(left.recipe?.category));
        const bOrder = categoriesState.findIndex((cat) => categoryKey(cat) === categoryKey(right.recipe?.category));
        const aNorm = aOrder < 0 ? Number.MAX_SAFE_INTEGER : aOrder;
        const bNorm = bOrder < 0 ? Number.MAX_SAFE_INTEGER : bOrder;
        if (aNorm !== bNorm) return aNorm - bNorm;
        return left.index - right.index;
      })
      .map((entry) => entry.recipe);

    const blocks = orderedRecipes
      .map((recipe) => {
        const title = normalizeLine(recipe?.title) || 'Item sem título';
        const category = normalizeCategoryName(recipe?.category);
        const description = normalizeLine(recipe?.description);
        const tempo = normalizeLine(recipe?.tempo);
        const porcoes = normalizeLine(recipe?.porcoes);
        const dificuldade = normalizeLine(recipe?.dificuldade);
        const image = normalizeLine(recipe?.image);
        const ingredients = Array.isArray(recipe?.ingredients) ? recipe.ingredients : [];
        const steps = Array.isArray(recipe?.steps) ? recipe.steps : [];
        const tip = normalizeLine(recipe?.tip);
        const nutrition = recipe?.nutrition && typeof recipe.nutrition === 'object'
          ? recipe.nutrition
          : {};
        const nutritionKcal = normalizeLine(nutrition?.kcal);
        const nutritionCarb = normalizeLine(nutrition?.carb);
        const nutritionProt = normalizeLine(nutrition?.prot);
        const nutritionFat = normalizeLine(nutrition?.fat);
        const nutritionFiber = normalizeLine(nutrition?.fiber);
        const hasNutrition = [nutritionKcal, nutritionCarb, nutritionProt, nutritionFat, nutritionFiber].some((value) => value !== '');
        const isRecipeMode = !recipe?.isGeneric
          && (
            ingredients.length > 0
            || steps.length > 0
            || tempo !== ''
            || porcoes !== ''
            || dificuldade !== ''
            || hasNutrition
          );

        const lines = [title];
        if (category) {
          lines.push(`Categoria: ${category}`);
        }
        if (description) {
          if (isRecipeMode) {
            lines.push(`Descrição: ${description}`);
          } else {
            description.split('\n').forEach((line) => {
              const clean = normalizeLine(line);
              if (clean) lines.push(clean);
            });
          }
        }
        if (isRecipeMode && tempo) {
          lines.push(`Tempo: ${tempo}`);
        }
        if (isRecipeMode && porcoes) {
          lines.push(`Porções: ${porcoes}`);
        }
        if (isRecipeMode && dificuldade) {
          lines.push(`Dificuldade: ${dificuldade}`);
        }
        if (image) {
          lines.push(`Imagem: ${image}`);
        }

        if (isRecipeMode) {
          lines.push('Ingredientes:');
          if (ingredients.length) {
            ingredients.forEach((item) => {
              const clean = normalizeLine(item);
              if (clean) lines.push(`- ${clean}`);
            });
          } else {
            lines.push('- Ingredientes conforme orientação nutricional.');
          }

          lines.push('Modo de preparo:');
          if (steps.length) {
            let idx = 1;
            steps.forEach((step) => {
              const clean = normalizeLine(step);
              if (clean) {
                lines.push(`${idx}. ${clean}`);
                idx += 1;
              }
            });
          } else {
            lines.push('1. Organize os ingredientes.');
            lines.push('2. Faça o preparo conforme orientação.');
          }

          if (tip) {
            lines.push('Dica:');
            lines.push(tip);
          }
          if (hasNutrition) {
            lines.push('Informação Nutricional:');
            if (nutritionKcal) lines.push(`Calorias: ${nutritionKcal}`);
            if (nutritionCarb) lines.push(`Carboidratos: ${nutritionCarb}`);
            if (nutritionProt) lines.push(`Proteínas: ${nutritionProt}`);
            if (nutritionFat) lines.push(`Gorduras: ${nutritionFat}`);
            if (nutritionFiber) lines.push(`Fibras: ${nutritionFiber}`);
          }
        }

        return lines.join('\n');
      })
      .filter(Boolean);

    return blocks.join('\n\n---\n\n');
  };

  const syncRawFromRecipes = () => {
    if (recipesRawInput) {
      recipesRawInput.value = recipesToRaw(recipesState);
    }
    syncCategoriesRawFromState();
  };

  const renderCategoryManager = () => {
    if (!categoryManager) return;

    if (!categoriesState.length) {
      categoryManager.innerHTML = '<div class="pdfw-category-empty">Nenhuma categoria criada.</div>';
      return;
    }

    const html = categoriesState
      .map((category, index) => {
        const categoryName = normalizeCategoryName(category?.name);
        const subtitle = normalizeLine(category?.subtitle);
        const image = normalizeLine(category?.image);
        const count = recipesState.filter((recipe) => categoryKey(recipe?.category) === categoryKey(categoryName)).length;
        return `
          <div class="pdfw-category-item" data-category-index="${index}" draggable="true">
            <span class="pdfw-category-handle">☰</span>
            <div class="pdfw-category-fields">
              <input type="text" class="pdfw-category-name" data-category-field="name" value="${escapeHtml(categoryName)}" placeholder="Nome da categoria">
              <input type="text" class="pdfw-category-subtitle" data-category-field="subtitle" value="${escapeHtml(subtitle)}" placeholder="Subtítulo da subcapa (opcional)">
              <input type="url" class="pdfw-category-image" data-category-field="image" value="${escapeHtml(image)}" placeholder="Imagem da subcapa (URL opcional)">
            </div>
            <span class="pdfw-category-count">${count} ${count === 1 ? 'item' : 'itens'}</span>
            <button type="button" class="button button-small" data-category-action="remove">Excluir</button>
          </div>
        `;
      })
      .join('');

    categoryManager.innerHTML = `<div class="pdfw-category-list">${html}</div>`;
  };

  const renderRecipeBuilder = () => {
    if (!recipeBuilder) return;

    if (!recipesState.length) {
      recipeBuilder.innerHTML = '<div class="pdfw-recipe-empty">Nenhum item no editor. Clique em "Adicionar item".</div>';
      updateSidebarMeta();
      return;
    }

    recipeBuilder.innerHTML = recipesState
      .map((recipe, index) => {
        const title = normalizeLine(recipe.title) || 'Item sem título';
        const category = normalizeCategoryName(recipe.category) || categoriesState[0]?.name || 'Itens';
        const description = normalizeLine(recipe.description);
        const tempo = normalizeLine(recipe.tempo);
        const porcoes = normalizeLine(recipe.porcoes);
        const dificuldade = normalizeLine(recipe.dificuldade);
        const image = normalizeLine(recipe.image);
        const ingredientsText = (recipe.ingredients || []).join('\n');
        const stepsText = (recipe.steps || []).join('\n');
        const tip = recipe.tip || '';
        const nutrition = recipe.nutrition && typeof recipe.nutrition === 'object'
          ? recipe.nutrition
          : { kcal: '', carb: '', prot: '', fat: '', fiber: '' };
        const kcal = normalizeLine(nutrition.kcal);
        const carb = normalizeLine(nutrition.carb);
        const prot = normalizeLine(nutrition.prot);
        const fat = normalizeLine(nutrition.fat);
        const fiber = normalizeLine(nutrition.fiber);
        const hasNutrition = [kcal, carb, prot, fat, fiber].some((value) => value !== '');
        const isRecipeMode = !recipe?.isGeneric
          && (
            (recipe.ingredients || []).length > 0
            || (recipe.steps || []).length > 0
            || tempo !== ''
            || porcoes !== ''
            || dificuldade !== ''
            || hasNutrition
          );

        let contentHtml = '';
        if (isRecipeMode) {
          contentHtml = `
              <div class="pdfw-field pdfw-field--full">
                <label>Descrição</label>
                <textarea rows="3" data-field="description">${escapeHtml(description)}</textarea>
              </div>
              <div class="pdfw-field">
                <label>Tempo</label>
                <input type="text" data-field="tempo" value="${escapeHtml(tempo)}" placeholder="Ex.: 45 min">
              </div>
              <div class="pdfw-field">
                <label>Porções</label>
                <input type="text" data-field="porcoes" value="${escapeHtml(porcoes)}" placeholder="Ex.: 4 porções">
              </div>
              <div class="pdfw-field">
                <label>Dificuldade</label>
                <input type="text" data-field="dificuldade" value="${escapeHtml(dificuldade)}" placeholder="Ex.: Fácil">
              </div>
              <div class="pdfw-field">
                <label>Ingredientes (1 por linha)</label>
                <textarea rows="7" data-field="ingredients">${escapeHtml(ingredientsText)}</textarea>
              </div>
              <div class="pdfw-field">
                <label>Modo de preparo (1 passo por linha)</label>
                <textarea rows="7" data-field="steps">${escapeHtml(stepsText)}</textarea>
              </div>
              <div class="pdfw-field pdfw-field--full">
                <label>Dica do Chef</label>
                <textarea rows="3" data-field="tip">${escapeHtml(tip)}</textarea>
              </div>
              <div class="pdfw-field pdfw-field--full">
                <label>Informação Nutricional</label>
                <div class="pdfw-nutrition-grid">
                  <input type="text" data-field="nutrition_kcal" value="${escapeHtml(kcal)}" placeholder="Calorias">
                  <input type="text" data-field="nutrition_carb" value="${escapeHtml(carb)}" placeholder="Carboidratos">
                  <input type="text" data-field="nutrition_prot" value="${escapeHtml(prot)}" placeholder="Proteínas">
                  <input type="text" data-field="nutrition_fat" value="${escapeHtml(fat)}" placeholder="Gorduras">
                  <input type="text" data-field="nutrition_fiber" value="${escapeHtml(fiber)}" placeholder="Fibras">
                </div>
              </div>
          `;
        } else {
          contentHtml = `
              <div class="pdfw-field pdfw-field--full">
                <label>Conteúdo (capítulo/aula)</label>
                <textarea rows="14" data-field="description" placeholder="Cole ou edite o texto completo aqui...">${escapeHtml(description)}</textarea>
              </div>
          `;
        }

        return `
          <article class="pdfw-recipe-card ${isRecipeMode ? 'is-recipe' : 'is-generic'}" data-index="${index}" draggable="true">
            <div class="pdfw-recipe-card-header">
              <div class="pdfw-recipe-card-title">
                <span class="pdfw-category-handle">☰</span>
                <span class="pdfw-recipe-index">${index + 1}</span>
                <span class="pdfw-recipe-name">${escapeHtml(title)} <small>(${isRecipeMode ? 'Receita' : 'Texto'})</small></span>
              </div>
              <div class="pdfw-recipe-actions">
                <button type="button" class="button button-small" data-action="up" ${index === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="button button-small" data-action="down" ${index === recipesState.length - 1 ? 'disabled' : ''}>↓</button>
                <button type="button" class="button button-small" data-action="remove">Excluir</button>
              </div>
            </div>
            <div class="pdfw-recipe-grid">
              <div class="pdfw-field pdfw-field--full">
                <label>Título</label>
                <input type="text" data-field="title" value="${escapeHtml(title)}">
              </div>
              <div class="pdfw-field pdfw-field--full">
                <label>Categoria</label>
                <select data-field="category">${categoryOptionsHtml(category)}</select>
              </div>
              <div class="pdfw-field pdfw-field--full">
                <label>Imagem de destaque (URL)</label>
                <input type="url" data-field="image" value="${escapeHtml(image)}" placeholder="https://.../receita.jpg">
              </div>
              ${contentHtml}
            </div>
          </article>
        `;
      })
      .join('');
    updateSidebarMeta();
  };

  const syncRecipesFromRaw = () => {
    recipesState = parseRecipesRaw(recipesRawInput?.value || '');
    categoriesState = parseCategoriesRaw(categoriesRawInput?.value || '');
    rebuildCategoriesFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    updateLivePreview();
  };

  const addRecipe = () => {
    const defaultCategory = categoriesState[0]?.name || 'Itens';
    recipesState.push({
      title: 'Novo item',
      category: defaultCategory,
      description: '',
      tempo: '',
      porcoes: '',
      dificuldade: '',
      image: '',
      ingredients: [],
      steps: [],
      tip: '',
      nutrition: { kcal: '', carb: '', prot: '', fat: '', fiber: '' },
      isGeneric: true,
    });
    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const addCategory = () => {
    const nextIndex = categoriesState.length + 1;
    const base = `Categoria ${nextIndex}`;
    const candidate = uniqueCategoryName(base);

    categoriesState.push({
      name: candidate,
      subtitle: '',
      image: '',
    });

    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const handleRecipeBuilderInput = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const card = target.closest('.pdfw-recipe-card');
    if (!card) return;

    const index = Number(card.getAttribute('data-index'));
    if (Number.isNaN(index) || !recipesState[index]) return;

    const field = target.getAttribute('data-field');
    if (!field) return;
    const wasGeneric = Boolean(recipesState[index]?.isGeneric);
    let requiresFullRender = false;
    let requiresCategoryRefresh = false;

    if (field === 'title') {
      recipesState[index].title = target.value;
    } else if (field === 'category') {
      const nextCategory = normalizeCategoryName(target.value);
      recipesState[index].category = nextCategory || (categoriesState[0]?.name || 'Itens');
      requiresFullRender = true;
      requiresCategoryRefresh = true;
    } else if (field === 'description') {
      recipesState[index].description = target.value;
    } else if (field === 'tempo') {
      recipesState[index].tempo = target.value;
    } else if (field === 'porcoes') {
      recipesState[index].porcoes = target.value;
    } else if (field === 'dificuldade') {
      recipesState[index].dificuldade = target.value;
    } else if (field === 'image') {
      recipesState[index].image = target.value;
    } else if (field === 'ingredients') {
      recipesState[index].ingredients = toList(target.value, 'ingredients');
    } else if (field === 'steps') {
      recipesState[index].steps = toList(target.value, 'steps');
    } else if (field === 'tip') {
      recipesState[index].tip = target.value;
    } else if (field.startsWith('nutrition_')) {
      if (!recipesState[index].nutrition || typeof recipesState[index].nutrition !== 'object') {
        recipesState[index].nutrition = { kcal: '', carb: '', prot: '', fat: '', fiber: '' };
      }
      const key = field.replace('nutrition_', '');
      if (['kcal', 'carb', 'prot', 'fat', 'fiber'].includes(key)) {
        recipesState[index].nutrition[key] = target.value;
      }
    }

    const current = recipesState[index] || {};
    const hasNutrition = current?.nutrition && typeof current.nutrition === 'object'
      ? Object.values(current.nutrition).some((value) => normalizeLine(value) !== '')
      : false;
    const hasRecipeData = (Array.isArray(current.ingredients) && current.ingredients.length > 0)
      || (Array.isArray(current.steps) && current.steps.length > 0)
      || normalizeLine(current.tempo) !== ''
      || normalizeLine(current.porcoes) !== ''
      || normalizeLine(current.dificuldade) !== ''
      || hasNutrition;
    recipesState[index].isGeneric = !hasRecipeData;
    if (wasGeneric !== recipesState[index].isGeneric) {
      requiresFullRender = true;
      requiresCategoryRefresh = true;
    }

    if (requiresCategoryRefresh) {
      rebuildCategoriesFromRecipes();
    }
    syncRawFromRecipes();

    if (requiresFullRender) {
      renderCategoryManager();
      renderRecipeBuilder();
    } else {
      if (field === 'title') {
        const nameEl = card.querySelector('.pdfw-recipe-name');
        if (nameEl) {
          const label = normalizeLine(recipesState[index].title || '') || 'Item sem título';
          const mode = recipesState[index].isGeneric ? 'Texto' : 'Receita';
          nameEl.innerHTML = `${escapeHtml(label)} <small>(${escapeHtml(mode)})</small>`;
        }
      }
      updateSidebarMeta();
    }

    markDirty();
    scrollToPreviewItem(index);
  };

  const handleRecipeBuilderClick = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const button = target.closest('button[data-action]');
    if (!button) {
      const focusCard = target.closest('.pdfw-recipe-card');
      if (!focusCard) return;
      const focusIndex = Number(focusCard.getAttribute('data-index'));
      if (Number.isFinite(focusIndex)) {
        scrollToPreviewItem(focusIndex);
      }
      return;
    }

    const card = button.closest('.pdfw-recipe-card');
    if (!card) return;

    const index = Number(card.getAttribute('data-index'));
    if (Number.isNaN(index) || !recipesState[index]) return;

    const action = button.getAttribute('data-action');
    if (action === 'remove') {
      recipesState.splice(index, 1);
    } else if (action === 'up' && index > 0) {
      const current = recipesState[index];
      recipesState[index] = recipesState[index - 1];
      recipesState[index - 1] = current;
    } else if (action === 'down' && index < recipesState.length - 1) {
      const current = recipesState[index];
      recipesState[index] = recipesState[index + 1];
      recipesState[index + 1] = current;
    }

    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const handleCategoryInput = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const input = target.closest('input[data-category-field]');
    if (!input) return;

    const item = input.closest('.pdfw-category-item');
    if (!item) return;

    const index = Number(item.getAttribute('data-category-index'));
    if (Number.isNaN(index) || !categoriesState[index]) return;

    const field = input.getAttribute('data-category-field');
    if (!field) return;

    const current = categoriesState[index];
    const previousName = normalizeCategoryName(current?.name);

    if (field === 'name') {
      const fallback = previousName || `Categoria ${index + 1}`;
      const candidate = normalizeCategoryName(input.value) || fallback;
      const nextName = uniqueCategoryName(candidate, index);

      categoriesState[index] = {
        ...current,
        name: nextName,
      };

      if (nextName !== input.value) {
        input.value = nextName;
      }

      recipesState = recipesState.map((recipe) => {
        if (categoryKey(recipe?.category) !== categoryKey(previousName)) return recipe;
        return { ...recipe, category: nextName };
      });
    } else if (field === 'subtitle') {
      categoriesState[index] = {
        ...current,
        subtitle: normalizeLine(input.value),
      };
    } else if (field === 'image') {
      categoriesState[index] = {
        ...current,
        image: normalizeLine(input.value),
      };
    }

    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const handleCategoryClick = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const button = target.closest('button[data-category-action]');
    if (!button) return;

    const item = button.closest('.pdfw-category-item');
    if (!item) return;

    const index = Number(item.getAttribute('data-category-index'));
    if (Number.isNaN(index) || !categoriesState[index]) return;

    const action = button.getAttribute('data-category-action');
    if (action !== 'remove') return;

    if (categoriesState.length <= 1) {
      showToast('É necessário manter ao menos uma categoria.', 'error');
      return;
    }

    const removed = categoriesState[index];
    categoriesState.splice(index, 1);
    const fallback = categoriesState[0]?.name || 'Itens';

    recipesState = recipesState.map((recipe) => {
      if (categoryKey(recipe?.category) !== categoryKey(removed?.name)) return recipe;
      return { ...recipe, category: fallback };
    });

    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const handleCategoryDragStart = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const item = target.closest('.pdfw-category-item');
    if (!item) return;
    draggedCategoryIndex = Number(item.getAttribute('data-category-index'));
    if (Number.isNaN(draggedCategoryIndex)) {
      draggedCategoryIndex = -1;
      return;
    }
    item.classList.add('is-dragging');
  };

  const handleCategoryDragOver = (event) => {
    event.preventDefault();
  };

  const handleCategoryDrop = (event) => {
    event.preventDefault();
    const target = event.target;
    if (!(target instanceof Element)) return;
    const item = target.closest('.pdfw-category-item');
    if (!item) return;
    const targetIndex = Number(item.getAttribute('data-category-index'));
    if (Number.isNaN(targetIndex) || draggedCategoryIndex < 0 || draggedCategoryIndex === targetIndex) return;

    const moved = categoriesState[draggedCategoryIndex];
    categoriesState.splice(draggedCategoryIndex, 1);
    categoriesState.splice(targetIndex, 0, moved);

    sortRecipesByCategoryOrder();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const clearCategoryDragging = () => {
    draggedCategoryIndex = -1;
    if (!categoryManager) return;
    categoryManager.querySelectorAll('.pdfw-category-item.is-dragging').forEach((element) => {
      element.classList.remove('is-dragging');
    });
  };

  const handleRecipeDragStart = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const card = target.closest('.pdfw-recipe-card');
    if (!card) return;
    draggedRecipeIndex = Number(card.getAttribute('data-index'));
    if (Number.isNaN(draggedRecipeIndex)) {
      draggedRecipeIndex = -1;
      return;
    }
    card.classList.add('is-dragging');
  };

  const handleRecipeDragOver = (event) => {
    event.preventDefault();
  };

  const handleRecipeDrop = (event) => {
    event.preventDefault();
    const target = event.target;
    if (!(target instanceof Element)) return;
    const card = target.closest('.pdfw-recipe-card');
    if (!card) return;

    const targetIndex = Number(card.getAttribute('data-index'));
    if (Number.isNaN(targetIndex) || draggedRecipeIndex < 0 || draggedRecipeIndex === targetIndex) return;

    const moved = recipesState[draggedRecipeIndex];
    recipesState.splice(draggedRecipeIndex, 1);
    recipesState.splice(targetIndex, 0, moved);

    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const clearRecipeDragging = () => {
    draggedRecipeIndex = -1;
    if (!recipeBuilder) return;
    recipeBuilder.querySelectorAll('.pdfw-recipe-card.is-dragging').forEach((element) => {
      element.classList.remove('is-dragging');
    });
  };

  const markDirty = () => {
    if (suppressDirty) return;

    if (downloadPdfButton) {
      downloadPdfButton.style.display = 'none';
    }
    if (previewCacheInput) {
      previewCacheInput.value = '';
    }
    if (previewStatus) {
      previewStatus.textContent = 'Alterações detectadas. Atualizando prévia em tempo real...';
    }

    projectDirty = true;
    if (currentProjectId) {
      setProjectStatus('Alterações não salvas neste projeto.', 'warn');
    } else {
      setProjectStatus('Projeto não salvo.', 'warn');
    }
    updateSidebarMeta();
    updateLivePreview();
  };

  const showPdfPreview = (pdfBase64) => {
    const pdfBlob = base64ToBlob(pdfBase64, 'application/pdf');
    clearPreviewUrl();
    previewObjectUrl = URL.createObjectURL(pdfBlob);

    if (previewFrame) {
      previewFrame.removeAttribute('srcdoc');
      previewFrame.src = previewObjectUrl;
    }
  };

  const showHtmlPreview = (html) => {
    clearPreviewUrl();
    if (previewFrame) {
      previewFrame.removeAttribute('src');
      previewFrame.srcdoc = html;
    }
  };

  const generatePreview = async (mode) => {
    if (previewBusy) return;

    activateSection('exportar');
    syncRawFromRecipes();
    previewBusy = true;
    setPreviewButtonsDisabled(true);
    setStatus(mode === 'html' ? 'Gerando pré-visualização HTML...' : 'Gerando pré-visualização paginada...');
    setLog('');

    try {
      const fd = new FormData(form);
      fd.set('action', 'pdfw_preview');
      fd.set('nonce', previewNonce);
      fd.set('preview_mode', mode);
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

      const cacheKey = payload?.data?.cache_key || '';
      if (previewCacheInput) {
        previewCacheInput.value = typeof cacheKey === 'string' ? cacheKey : '';
      }

      importedRecipesRaw = payload?.data?.prepared_recipes_raw || '';
      if (applyImportedButton) {
        applyImportedButton.style.display = importedRecipesRaw ? 'inline-flex' : 'none';
      }

      if (mode === 'html') {
        const html = payload?.data?.html || '';
        if (!html) {
          throw new Error('Pré-visualização HTML vazia retornada pelo servidor.');
        }
        showHtmlPreview(html);
        setStatus('Pré-visualização HTML atualizada. Para validar paginação real, use a prévia PDF.');
      } else {
        const pdfBase64 = payload?.data?.pdf_base64 || '';
        if (!pdfBase64) {
          throw new Error('Pré-visualização sem PDF retornado pelo servidor.');
        }
        showPdfPreview(pdfBase64);
        setStatus('Pré-visualização paginada atualizada. Se estiver tudo certo, clique em Baixar PDF.');
      }

      setLog(payload?.data?.notice || '');
      if (downloadPdfButton) {
        downloadPdfButton.style.display = 'inline-flex';
      }
    } catch (err) {
      setStatus('Erro na pré-visualização. Ajuste os dados e tente novamente.');
      const message = err instanceof Error ? err.message : 'Erro inesperado';
      setLog(message);
      showToast(message, 'error');
    } finally {
      previewBusy = false;
      setPreviewButtonsDisabled(false);
    }
  };

  const processImportResult = (data) => {
    const preparedRaw = String(data?.prepared_recipes_raw || '');
    const coverImage = String(data?.cover_image || '');
    const recipesCountPayload = Number(data?.recipes_count || 0);
    const notice = String(data?.notice || '');
    const auditItems = Array.isArray(data?.audit_items) ? data.audit_items : [];
    const importedByAudit = normalizeAuditItems(auditItems)
      .reduce((sum, item) => {
        if (item.kind === 'recipe' || item.kind === 'generic') {
          return sum + item.recipesCount;
        }
        return sum;
      }, 0);
    const recipesCount = importedByAudit > 0
      ? importedByAudit
      : (Number.isFinite(recipesCountPayload) ? Math.max(0, Math.trunc(recipesCountPayload)) : 0);

    if (preparedRaw && recipesRawInput) {
      recipesRawInput.value = preparedRaw;
      syncRecipesFromRaw();
    }

    if (coverImage) {
      const coverField = form.querySelector('[name="cover_image"]');
      if (coverField && !normalizeLine(coverField.value || '')) {
        coverField.value = coverImage;
      }
    }

    importedRecipesRaw = preparedRaw;
    if (applyImportedButton) {
      applyImportedButton.style.display = preparedRaw ? 'inline-flex' : 'none';
    }

    renderImportAudit(auditItems, recipesCount, true);
    if (notice) {
      setLog(notice);
    }

    return {
      recipesCount,
      auditItems,
      notice,
    };
  };

  const runImport = async () => {
    if (importBusy) return;

    const driveUrl = normalizeLine(driveInput?.value || '');
    const filesInput = form.querySelector('input[name="source_files[]"]');
    const hasUpload = Boolean(filesInput && filesInput.files && filesInput.files.length > 0);

    if (!driveUrl && !hasUpload) {
      activateSection('importacao');
      setImportStatus('Informe um link do Drive ou selecione arquivos para importar.', 'error');
      hideImportProgress();
      showToast('Informe um link do Drive ou selecione arquivos para importar.', 'error');
      return;
    }

    syncRawFromRecipes();
    importBusy = true;
    if (importButton) importButton.disabled = true;
    if (importButton) importButton.innerHTML = '<span class="pdfw-spinner"></span>Importando...';
    setLog('');
    setImportStatus('Importação iniciada...', 'busy');
    setImportProgress(2, 'Iniciando');

    let importedCount = 0;
    const allAuditItems = [];
    const noticeLines = [];
    const driveImageEntries = [];

    try {
      if (hasUpload) {
        setImportStatus('Processando upload local...', 'busy');
        setImportProgress(12, 'Processando upload');

        const fd = new FormData(form);
        fd.set('action', 'pdfw_import');
        fd.set('nonce', importNonce);
        fd.delete('pdfw_output');
        fd.delete('drive_folder_url');

        const response = await fetch(getAjaxUrl(), {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });

        let payload = null;
        try {
          payload = await response.json();
        } catch {
          throw new Error('Resposta inválida ao importar upload local.');
        }

        if (!response.ok || !payload || payload.success !== true) {
          throw new Error(extractError(payload, 'Falha ao importar upload local.'));
        }

        const uploadResult = processImportResult(payload.data || {});
        importedCount += uploadResult.recipesCount;
        allAuditItems.push(...uploadResult.auditItems);
        if (uploadResult.notice) {
          noticeLines.push(uploadResult.notice);
        }
        setImportProgress(driveUrl ? 28 : 55, 'Upload concluído');
      }

      if (driveUrl) {
        setImportStatus('Escaneando pasta do Drive...', 'busy');
        setImportProgress(hasUpload ? 32 : 10, 'Escaneando Drive');

        const fdScan = new FormData();
        fdScan.set('action', 'pdfw_drive_scan');
        fdScan.set('nonce', importNonce);
        fdScan.set('url', driveUrl);

        const scanResponse = await fetch(getAjaxUrl(), {
          method: 'POST',
          body: fdScan,
          credentials: 'same-origin',
        });

        let scanPayload = null;
        try {
          scanPayload = await scanResponse.json();
        } catch {
          throw new Error('Resposta inválida ao escanear Drive.');
        }

        if (!scanResponse.ok || !scanPayload || scanPayload.success !== true) {
          throw new Error(extractError(scanPayload, 'Falha ao escanear pasta do Drive.'));
        }

        const items = Array.isArray(scanPayload?.data?.items) ? scanPayload.data.items : [];
        if (!items.length) {
          setImportStatus('Nenhum arquivo elegível encontrado na pasta do Drive.', 'error');
          setImportProgress(100, 'Nenhum item elegível');
        } else {
          const total = items.length;
          const driveRecipes = [];
          const driveAudit = [];
          let processed = 0;

          renderImportAudit([], 0, true);

          for (const item of items) {
            const pct = Math.round((processed / total) * 100);
            const itemName = normalizeLine(item?.name || 'arquivo');
            setImportStatus(`Processando Drive: ${processed + 1}/${total} (${pct}%) - ${itemName}`, 'busy');
            if (importButton) {
              importButton.innerHTML = `<span class="pdfw-spinner"></span>Processando ${processed + 1}/${total}...`;
            }
            const progressBase = hasUpload ? 32 : 10;
            const progressPct = progressBase + Math.round(((processed + 1) / total) * (100 - progressBase));
            setImportProgress(progressPct, `Processando ${processed + 1}/${total}`);

            try {
              const fdItem = new FormData();
              fdItem.set('action', 'pdfw_drive_process');
              fdItem.set('nonce', importNonce);
              fdItem.set('item', JSON.stringify(item || {}));

              const itemResponse = await fetch(getAjaxUrl(), {
                method: 'POST',
                body: fdItem,
                credentials: 'same-origin',
              });

              let itemPayload = null;
              try {
                itemPayload = await itemResponse.json();
              } catch {
                throw new Error('Resposta inválida ao processar item do Drive.');
              }

              if (!itemResponse.ok || !itemPayload || itemPayload.success !== true) {
                throw new Error(extractError(itemPayload, 'Falha ao processar item do Drive.'));
              }

              const result = itemPayload.data || {};
              if (result.audit && typeof result.audit === 'object') {
                driveAudit.push(result.audit);
              }

              const recipes = Array.isArray(result.recipes) ? result.recipes : [];
              if (recipes.length) {
                driveRecipes.push(...recipes);
              }

              const images = Array.isArray(result.images) ? result.images : [];
              if (images.length) {
                images.forEach((entry) => {
                  if (entry && typeof entry === 'object') {
                    driveImageEntries.push(entry);
                  }
                });
                const firstImage = images.find((entry) => normalizeLine(entry?.src || ''));
                if (firstImage) {
                  const coverField = form.querySelector('[name="cover_image"]');
                  if (coverField && !normalizeLine(coverField.value || '') && normalizeLine(firstImage.src || '')) {
                    coverField.value = normalizeLine(firstImage.src || '');
                  }
                }
              }
            } catch (itemError) {
              const message = itemError instanceof Error ? itemError.message : 'Erro ao processar item do Drive.';
              driveAudit.push({
                source: 'drive',
                name: itemName,
                kind: 'error',
                recipes_count: 0,
                note: message,
              });
            }

            processed += 1;
            if (processed % 5 === 0 || processed === total) {
              renderImportAudit(driveAudit, driveRecipes.length, true);
            }
          }

          const coverField = form.querySelector('[name="cover_image"]');
          if (coverField && !normalizeLine(coverField.value || '') && driveImageEntries.length > 0) {
            const hinted = driveImageEntries.find((entry) => Boolean(entry?.is_cover_hint) && normalizeLine(entry?.src || '') !== '');
            const first = hinted || driveImageEntries.find((entry) => normalizeLine(entry?.src || '') !== '');
            if (first && normalizeLine(first.src || '') !== '') {
              coverField.value = normalizeLine(first.src || '');
            }
          }

          const driveRecipesWithImages = applyImageEntriesToItems(driveRecipes, driveImageEntries);
          const mode = form.querySelector('select[name="import_mode"]')?.value || 'append';
          const driveRaw = recipesToRaw(driveRecipesWithImages);
          if (driveRaw && recipesRawInput) {
            if (mode === 'replace') {
              recipesRawInput.value = driveRaw;
            } else {
              const currentRaw = recipesRawInput.value.trim();
              recipesRawInput.value = currentRaw ? `${currentRaw}\n\n---\n\n${driveRaw}` : driveRaw;
            }
            syncRecipesFromRaw();
          }

          importedCount += driveRecipesWithImages.length;
          allAuditItems.push(...driveAudit);
          renderImportAudit(allAuditItems, importedCount, true);
        }
      }

      importedRecipesRaw = recipesRawInput ? recipesRawInput.value : importedRecipesRaw;
      if (applyImportedButton) {
        applyImportedButton.style.display = importedRecipesRaw ? 'inline-flex' : 'none';
      }

      markDirty();

      let statusMessage = `Importação concluída: ${importedCount} ${importedCount === 1 ? 'item' : 'itens'} detectados.`;
      const shouldAutoSave = Boolean(currentProjectId || normalizeLine(projectNameInput?.value || ''));

      if (shouldAutoSave) {
        try {
          await saveProject(false);
          statusMessage += ' Projeto atualizado no banco.';
          setProjectStatus('Importação concluída e projeto salvo.', 'ok');
        } catch (saveError) {
          const saveMessage = saveError instanceof Error ? saveError.message : 'Erro ao salvar projeto após importação.';
          statusMessage += ` ${saveMessage}`;
          setProjectStatus(saveMessage, 'error');
        }
      } else {
        setProjectStatus('Importação concluída. Salve o projeto para persistir no banco.', 'warn');
      }

      setImportStatus(statusMessage, importedCount > 0 ? 'ok' : 'error');
      setImportProgress(100, importedCount > 0 ? 'Concluído' : 'Concluído sem itens');
      renderImportAudit(allAuditItems, importedCount, true);
      showToast(statusMessage, importedCount > 0 ? 'success' : 'warn');
      if (noticeLines.length) {
        setLog(noticeLines.join('\n\n'));
      }
      activateSection('receitas');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao importar conteúdo.';
      setImportStatus(message, 'error');
      setImportProgress(100, 'Falha na importação');
      showToast(message, 'error');
    } finally {
      importBusy = false;
      if (importButton) {
        importButton.disabled = false;
        importButton.innerHTML = importButtonDefaultHtml;
      }
      window.setTimeout(hideImportProgress, 1400);
    }
  };

  const collectFormPayload = () => {
    syncRawFromRecipes();
    const data = {};
    const fields = [
      'title',
      'subtitle',
      'author',
      'seal',
      'theme',
      'recipes_raw',
      'categories_raw',
      'about',
      'tips',
      'drive_folder_url',
      'cover_image',
      'whisper_url',
      'import_mode',
    ];

    fields.forEach((name) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (!field) return;
      data[name] = field.value;
    });

    return data;
  };

  const applyPayloadToForm = (payload, options = {}) => {
    const { silent = false } = options;
    suppressDirty = true;

    Object.entries(payload || {}).forEach(([name, value]) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (!field) return;
      field.value = String(value ?? '');
    });

    syncRecipesFromRaw();

    suppressDirty = false;
    if (!silent) {
      markDirty();
    }
  };

  const projectAjax = async (op, extra = {}) => {
    const fd = new FormData();
    fd.set('action', 'pdfw_projects');
    fd.set('nonce', projectsNonce);
    fd.set('project_op', op);
    Object.entries(extra).forEach(([key, value]) => {
      fd.set(key, String(value ?? ''));
    });

    const response = await fetch(getAjaxUrl(), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    });

    let json = null;
    try {
      json = await response.json();
    } catch {
      throw new Error('Resposta inválida ao consultar projetos.');
    }

    if (!response.ok || !json || json.success !== true) {
      throw new Error(extractError(json, 'Falha ao processar projetos.'));
    }

    return json.data || {};
  };

  const formatProjectDate = (value) => {
    const date = new Date(value || '');
    if (Number.isNaN(date.getTime())) return 'Sem data';
    return date.toLocaleDateString('pt-BR');
  };

  const renderProjectDashboard = (projects) => {
    if (!projectDashboard) return;

    const list = Array.isArray(projects) ? projects : [];
    projectsCache = list;

    if (!list.length) {
      projectDashboard.innerHTML = '<div class="pdfw-projects-empty">Nenhum projeto salvo ainda.</div>';
      return;
    }

    const cardsHtml = list.map((project) => {
      const projectId = normalizeLine(project?.id || '');
      if (!projectId) return '';
      const isActive = projectId === currentProjectId;
      const title = normalizeLine(project?.name || 'Projeto sem nome');
      const client = normalizeLine(project?.client || 'Sem cliente');
      const updatedAt = formatProjectDate(project?.updated_at);
      return `
        <article class="pdfw-project-card ${isActive ? 'is-active' : ''}" data-project-id="${escapeHtml(projectId)}">
          <button type="button" class="pdfw-project-delete" data-project-action="delete" title="Excluir projeto" aria-label="Excluir projeto">&times;</button>
          <div class="pdfw-project-title">${escapeHtml(title)}</div>
          <div class="pdfw-project-client">${escapeHtml(client)}</div>
          <div class="pdfw-project-date">Atualizado em ${escapeHtml(updatedAt)}</div>
        </article>
      `;
    }).filter(Boolean).join('');

    projectDashboard.innerHTML = cardsHtml || '<div class="pdfw-projects-empty">Nenhum projeto válido encontrado.</div>';
  };

  const refreshProjects = async () => {
    const data = await projectAjax('list');
    renderProjectDashboard(data.projects || []);
  };

  const requestProjectLoad = async (projectId) => {
    const targetId = normalizeLine(projectId);
    if (!targetId) return;

    if (projectDirty && targetId !== currentProjectId) {
      const proceed = window.confirm('Há alterações não salvas no formulário atual. Deseja carregar outro projeto mesmo assim?');
      if (!proceed) return;
    }

    try {
      await loadProject(targetId);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao carregar projeto.';
      setProjectStatus(message, 'error');
      showToast(message, 'error');
    }
  };

  const loadProject = async (projectId) => {
    if (!projectId) return;

    const data = await projectAjax('get', { project_id: projectId });
    const project = data.project || null;
    if (!project || !project.payload) {
      throw new Error('Projeto sem payload válido.');
    }

    currentProjectId = project.id || projectId;
    if (projectNameInput) projectNameInput.value = project.name || '';
    if (projectClientInput) projectClientInput.value = project.client || '';

    applyPayloadToForm(project.payload, { silent: true });
    renderImportAudit([], null, false);
    projectDirty = false;
    setProjectStatus('Projeto carregado.', 'ok');
    showToast(`Projeto "${project.name || 'sem nome'}" carregado.`, 'success');
    renderProjectDashboard(projectsCache);
    updateSidebarMeta();
  };

  const saveProject = async (asNew) => {
    const name = (projectNameInput?.value || '').trim() || (form.querySelector('[name="title"]')?.value || '').trim() || 'Projeto sem nome';
    const client = (projectClientInput?.value || '').trim();
    setProjectStatus('Salvando projeto...', 'warn');
    showToast('Salvando projeto...', 'info', 1500);

    const payload = collectFormPayload();
    const projectIdToSend = asNew ? 'new' : (currentProjectId || 'new');

    const data = await projectAjax('save', {
      project_id: projectIdToSend,
      project_name: name,
      project_client: client,
      project_payload: JSON.stringify(payload),
    });

    currentProjectId = data.project_id || currentProjectId;
    if (projectNameInput && data.project?.name) projectNameInput.value = data.project.name;
    if (projectClientInput && typeof data.project?.client === 'string') projectClientInput.value = data.project.client;

    renderProjectDashboard(data.projects || projectsCache);

    projectDirty = false;
    setProjectStatus('Projeto salvo com sucesso.', 'ok');
    showToast('Projeto salvo com sucesso.', 'success');
    updateSidebarMeta();
  };

  const deleteProjectById = async (projectId) => {
    const targetId = normalizeLine(projectId);
    if (!targetId) {
      throw new Error('Nenhum projeto selecionado para excluir.');
    }

    const ok = window.confirm('Excluir este projeto salvo? Essa ação não pode ser desfeita.');
    if (!ok) return;

    const wasActive = targetId === currentProjectId;
    await projectAjax('delete', { project_id: targetId });
    await refreshProjects();
    if (wasActive) {
      createNewProject({ silentToast: true, silentStatus: true });
    }

    setProjectStatus(wasActive ? 'Projeto excluído e editor limpo para novo projeto.' : 'Projeto excluído.', 'ok');
    showToast('Projeto excluído.', 'success');
    updateSidebarMeta();
  };

  const deleteProject = async () => {
    await deleteProjectById(currentProjectId);
  };

  const createNewProject = (options = {}) => {
    const { silentToast = false, silentStatus = false } = options;
    currentProjectId = '';
    if (projectNameInput) projectNameInput.value = '';
    if (projectClientInput) projectClientInput.value = '';
    if (driveInput) driveInput.value = '';

    const filesInput = form.querySelector('input[name="source_files[]"]');
    if (filesInput) {
      try {
        filesInput.value = '';
      } catch {
        // browsers can block non-empty assignment; empty reset is safe best-effort
      }
    }

    if (initialPayload) {
      applyPayloadToForm(initialPayload, { silent: true });
    }

    projectDirty = false;
    if (!silentStatus) {
      setProjectStatus('Novo projeto iniciado (ainda não salvo).', 'warn');
    }
    setImportStatus('Cole o link do Drive ou selecione arquivos e clique em Importar conteúdo.', 'idle');
    hideImportProgress();
    importedRecipesRaw = '';
    if (applyImportedButton) {
      applyImportedButton.style.display = 'none';
    }
    renderImportAudit([], null, false);
    resetPreviewPanel();
    updateLivePreview();
    renderProjectDashboard(projectsCache);
    clearProjectCardSelection();
    if (!silentToast) {
      showToast('Novo projeto iniciado.', 'info');
    }
    updateSidebarMeta();
  };

  if (sampleButton) {
    sampleButton.addEventListener('click', () => {
      if (!recipesRawInput) return;
      const ok = window.confirm('Substituir itens atuais pelo exemplo?');
      if (!ok) return;
      recipesRawInput.value = sampleRecipes;
      syncRecipesFromRaw();
      markDirty();
    });
  }

  if (addRecipeButton) {
    addRecipeButton.addEventListener('click', (event) => {
      event.preventDefault();
      addRecipe();
    });
  }

  if (applyImportedButton) {
    applyImportedButton.addEventListener('click', (event) => {
      event.preventDefault();
      if (!importedRecipesRaw || !recipesRawInput) return;
      recipesRawInput.value = importedRecipesRaw;
      syncRecipesFromRaw();
      markDirty();
      setProjectStatus('Itens importados aplicados ao editor. Revise e salve.', 'warn');
      showToast('Itens importados aplicados ao editor.', 'info');
    });
  }

  if (recipeBuilder) {
    recipeBuilder.addEventListener('input', handleRecipeBuilderInput);
    recipeBuilder.addEventListener('click', handleRecipeBuilderClick);
    recipeBuilder.addEventListener('dragstart', handleRecipeDragStart);
    recipeBuilder.addEventListener('dragover', handleRecipeDragOver);
    recipeBuilder.addEventListener('drop', handleRecipeDrop);
    recipeBuilder.addEventListener('dragend', clearRecipeDragging);
  }

  if (categoryManager) {
    categoryManager.addEventListener('input', handleCategoryInput);
    categoryManager.addEventListener('click', handleCategoryClick);
    categoryManager.addEventListener('dragstart', handleCategoryDragStart);
    categoryManager.addEventListener('dragover', handleCategoryDragOver);
    categoryManager.addEventListener('drop', handleCategoryDrop);
    categoryManager.addEventListener('dragend', clearCategoryDragging);
  }

  if (addCategoryButton) {
    addCategoryButton.addEventListener('click', (event) => {
      event.preventDefault();
      addCategory();
    });
  }

  if (importButton) {
    importButton.addEventListener('click', async (event) => {
      event.preventDefault();
      await runImport();
    });
  }

  if (driveInput) {
    driveInput.addEventListener('keydown', async (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      await runImport();
    });
  }

  if (transcribeInput) {
    transcribeInput.addEventListener('change', async (event) => {
      const input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.files || !input.files[0]) {
        return;
      }
      await handleStandaloneTranscription(input.files[0]);
    });
  }

  if (transcribeDrop) {
    transcribeDrop.addEventListener('dragover', (event) => {
      event.preventDefault();
      transcribeDrop.classList.add('is-dragover');
    });
    transcribeDrop.addEventListener('dragleave', () => {
      transcribeDrop.classList.remove('is-dragover');
    });
    transcribeDrop.addEventListener('drop', async (event) => {
      event.preventDefault();
      transcribeDrop.classList.remove('is-dragover');
      const files = event.dataTransfer?.files;
      if (!files || !files.length) return;
      await handleStandaloneTranscription(files[0]);
    });
  }

  if (copyTranscribeBtn) {
    copyTranscribeBtn.addEventListener('click', async (event) => {
      event.preventDefault();
      await copyTranscriptionToClipboard();
    });
  }

  if (copyTranscribeAllBtn) {
    copyTranscribeAllBtn.addEventListener('click', async (event) => {
      event.preventDefault();
      await copyAllTranscriptionToClipboard();
    });
  }

  if (resumeTranscribeBtn) {
    resumeTranscribeBtn.addEventListener('click', async (event) => {
      event.preventDefault();
      await handleStandaloneTranscription(null, { resume: true });
    });
  }

  if (downloadTranscribeTxtBtn) {
    downloadTranscribeTxtBtn.addEventListener('click', (event) => {
      event.preventDefault();
      downloadTranscribeOutput(transcribeOutputs.txt, 'transcricao.txt', 'text/plain;charset=utf-8');
    });
  }

  if (downloadTranscribeSrtBtn) {
    downloadTranscribeSrtBtn.addEventListener('click', (event) => {
      event.preventDefault();
      downloadTranscribeOutput(transcribeOutputs.srt, 'transcricao.srt', 'text/plain;charset=utf-8');
    });
  }

  if (downloadTranscribeVttBtn) {
    downloadTranscribeVttBtn.addEventListener('click', (event) => {
      event.preventDefault();
      downloadTranscribeOutput(transcribeOutputs.vtt, 'transcricao.vtt', 'text/vtt;charset=utf-8');
    });
  }

  if (downloadTranscribeLipsyncBtn) {
    downloadTranscribeLipsyncBtn.addEventListener('click', (event) => {
      event.preventDefault();
      downloadTranscribeOutput(transcribeOutputs.lipsync, 'transcricao-lipsync.json', 'application/json;charset=utf-8');
    });
  }

  if (recipesRawInput) {
    recipesRawInput.addEventListener('input', () => {
      syncRecipesFromRaw();
      markDirty();
    });
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);

  navButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const sectionId = button.getAttribute('data-target-section') || '';
      activateSection(sectionId);
    });
  });

  if (previewPdfButton) {
    previewPdfButton.addEventListener('click', (event) => {
      event.preventDefault();
      generatePreview('pdf');
    });
  }

  if (previewHtmlButton) {
    previewHtmlButton.addEventListener('click', (event) => {
      event.preventDefault();
      activateSection('exportar');
      updateLivePreview();
      setLog('');
      showToast('Prévia HTML local atualizada.', 'info', 1800);
    });
  }

  if (projectDashboard) {
    projectDashboard.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      const deleteButton = target.closest('button[data-project-action="delete"]');
      if (deleteButton) {
        const card = deleteButton.closest('.pdfw-project-card');
        const projectId = card?.getAttribute('data-project-id') || '';
        try {
          await deleteProjectById(projectId);
        } catch (error) {
          const message = error instanceof Error ? error.message : 'Erro ao excluir projeto.';
          setProjectStatus(message, 'error');
          showToast(message, 'error');
        }
        return;
      }

      const card = target.closest('.pdfw-project-card');
      if (!card) return;
      const projectId = card.getAttribute('data-project-id') || '';
      await requestProjectLoad(projectId);
    });
  }

  if (projectSaveButton) {
    projectSaveButton.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        await saveProject(false);
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao salvar projeto.';
        setProjectStatus(message, 'error');
        showToast(message, 'error');
      }
    });
  }

  if (projectSaveAsButton) {
    projectSaveAsButton.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        await saveProject(true);
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao salvar projeto como novo.';
        setProjectStatus(message, 'error');
        showToast(message, 'error');
      }
    });
  }

  if (projectDeleteButton) {
    projectDeleteButton.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        await deleteProject();
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao excluir projeto.';
        setProjectStatus(message, 'error');
        showToast(message, 'error');
      }
    });
  }

  if (projectNewButton) {
    projectNewButton.addEventListener('click', (event) => {
      event.preventDefault();
      if (projectDirty) {
        const ok = window.confirm('Há alterações não salvas. Deseja iniciar um novo projeto mesmo assim?');
        if (!ok) return;
      }
      createNewProject();
    });
  }

  const bootstrap = async () => {
    syncRecipesFromRaw();
    initialPayload = collectFormPayload();
    resetTranscribeOutputs({ clearResume: true });
    setImportStatus('Cole o link do Drive ou selecione arquivos e clique em Importar conteúdo.', 'idle');
    hideImportProgress();
    renderImportAudit([], null, false);
    const storedSection = getStoredSection();
    if (storedSection) {
      activeSection = storedSection;
    }

    try {
      await refreshProjects();
      setProjectStatus('Pronto para editar. Salve para criar um projeto.', 'warn');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao carregar projetos.';
      setProjectStatus(message, 'error');
      showToast(message, 'error');
    }

    updateSidebarMeta();
    activateSection(activeSection, false);
  };

  bootstrap();
  window.addEventListener('beforeunload', clearPreviewUrl);
})();
