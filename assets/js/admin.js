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
  const driveInput = form.querySelector('input[name="drive_folder_url"]');
  const importAuditCard = document.getElementById('pdfw-import-audit-card');
  const importAuditSummary = document.getElementById('pdfw-import-audit-summary');
  const importAuditTable = document.getElementById('pdfw-import-audit-table');

  const recipesRawInput = form.querySelector('textarea[name="recipes_raw"]');
  const categoriesRawInput = form.querySelector('textarea[name="categories_raw"]');
  const recipeBuilder = document.getElementById('pdfw-recipe-builder');
  const addRecipeButton = document.getElementById('pdfw-add-recipe');
  const applyImportedButton = document.getElementById('pdfw-apply-imported');
  const categoryManager = document.getElementById('pdfw-category-manager');
  const addCategoryButton = document.getElementById('pdfw-add-category');

  const projectSelect = document.getElementById('pdfw-project-select');
  const projectNameInput = document.getElementById('pdfw-project-name');
  const projectClientInput = document.getElementById('pdfw-project-client');
  const projectStatus = document.getElementById('pdfw-project-status');
  const projectNewButton = document.getElementById('pdfw-project-new');
  const projectSaveButton = document.getElementById('pdfw-project-save');
  const projectSaveAsButton = document.getElementById('pdfw-project-save-as');
  const projectDeleteButton = document.getElementById('pdfw-project-delete');
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

  const normalizeAuditItems = (rawItems) => {
    if (!Array.isArray(rawItems)) return [];
    return rawItems
      .map((item) => {
        if (!item || typeof item !== 'object') return null;
        const source = String(item.source || '') === 'drive' ? 'drive' : 'upload';
        const kindRaw = String(item.kind || '').toLowerCase();
        const kind = ['recipe', 'image', 'skip', 'error'].includes(kindRaw) ? kindRaw : 'skip';
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
      image: 0,
      skip: 0,
      error: 0,
      recipesDetected: 0,
    };
    items.forEach((item) => {
      if (item.kind === 'recipe') {
        totals.recipe += 1;
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

    importAuditSummary.textContent = `Arquivos analisados: ${items.length}. Receitas detectadas: ${recipesDetected}. Imagens: ${totals.image}. Ignorados: ${totals.skip}. Erros: ${totals.error}.`;

    const rowsHtml = items.map((item) => {
      const sourceLabel = item.source === 'drive' ? 'Google Drive' : 'Upload';
      let kindLabel = 'Ignorado';
      if (item.kind === 'recipe') {
        kindLabel = item.recipesCount > 1 ? `Receita (${item.recipesCount})` : 'Receita';
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
      next.push({ name: 'Receitas', subtitle: '', image: '' });
    }

    categoriesState = next;
    const firstCategoryName = categoriesState[0]?.name || 'Receitas';

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

      const title = lines.shift() || 'Receita sem título';
      let section = '';
      const ingredients = [];
      const steps = [];
      const tipLines = [];
      let category = '';
      let description = '';
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
          description = normalizeLine(descriptionMatch[1]);
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
        if (section === 'nutrition') {
          // Ignora linhas nutricionais não mapeadas.
        }
      });

      out.push({
        title: normalizeLine(title) || 'Receita sem título',
        category: normalizeCategoryName(category),
        description,
        tempo,
        porcoes,
        dificuldade,
        image,
        ingredients: ingredients.filter(Boolean),
        steps: steps.filter(Boolean),
        tip: tipLines.join(' ').trim(),
        nutrition,
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
        const title = normalizeLine(recipe?.title) || 'Receita sem título';
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

        const lines = [title];
        if (category) {
          lines.push(`Categoria: ${category}`);
        }
        if (description) {
          lines.push(`Descrição: ${description}`);
        }
        if (tempo) {
          lines.push(`Tempo: ${tempo}`);
        }
        if (porcoes) {
          lines.push(`Porções: ${porcoes}`);
        }
        if (dificuldade) {
          lines.push(`Dificuldade: ${dificuldade}`);
        }
        if (image) {
          lines.push(`Imagem: ${image}`);
        }
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
        if (nutritionKcal || nutritionCarb || nutritionProt || nutritionFat || nutritionFiber) {
          lines.push('Informação Nutricional:');
          if (nutritionKcal) lines.push(`Calorias: ${nutritionKcal}`);
          if (nutritionCarb) lines.push(`Carboidratos: ${nutritionCarb}`);
          if (nutritionProt) lines.push(`Proteínas: ${nutritionProt}`);
          if (nutritionFat) lines.push(`Gorduras: ${nutritionFat}`);
          if (nutritionFiber) lines.push(`Fibras: ${nutritionFiber}`);
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
            <span class="pdfw-category-count">${count} ${count === 1 ? 'receita' : 'receitas'}</span>
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
      recipeBuilder.innerHTML = '<div class="pdfw-recipe-empty">Nenhuma receita no editor. Clique em "Adicionar receita".</div>';
      updateSidebarMeta();
      return;
    }

    recipeBuilder.innerHTML = recipesState
      .map((recipe, index) => {
        const title = normalizeLine(recipe.title) || 'Receita sem título';
        const category = normalizeCategoryName(recipe.category) || categoriesState[0]?.name || 'Receitas';
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

        return `
          <article class="pdfw-recipe-card" data-index="${index}" draggable="true">
            <div class="pdfw-recipe-card-header">
              <div class="pdfw-recipe-card-title">
                <span class="pdfw-category-handle">☰</span>
                <span class="pdfw-recipe-index">${index + 1}</span>
                <span class="pdfw-recipe-name">${escapeHtml(title)}</span>
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
              <div class="pdfw-field pdfw-field--full">
                <label>Imagem da Receita (URL)</label>
                <input type="url" data-field="image" value="${escapeHtml(image)}" placeholder="https://.../receita.jpg">
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
  };

  const addRecipe = () => {
    const defaultCategory = categoriesState[0]?.name || 'Receitas';
    recipesState.push({
      title: 'Nova receita',
      category: defaultCategory,
      description: '',
      tempo: '',
      porcoes: '',
      dificuldade: '',
      image: '',
      ingredients: ['Ingrediente 1', 'Ingrediente 2'],
      steps: ['Passo 1', 'Passo 2'],
      tip: '',
      nutrition: { kcal: '', carb: '', prot: '', fat: '', fiber: '' },
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

    if (field === 'title') {
      recipesState[index].title = target.value;
    } else if (field === 'category') {
      const nextCategory = normalizeCategoryName(target.value);
      recipesState[index].category = nextCategory || (categoriesState[0]?.name || 'Receitas');
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

    rebuildCategoriesFromRecipes();
    syncRawFromRecipes();
    renderCategoryManager();
    renderRecipeBuilder();
    markDirty();
  };

  const handleRecipeBuilderClick = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const button = target.closest('button[data-action]');
    if (!button) return;

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
      window.alert('É necessário manter ao menos uma categoria.');
      return;
    }

    const removed = categoriesState[index];
    categoriesState.splice(index, 1);
    const fallback = categoriesState[0]?.name || 'Receitas';

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
      previewStatus.textContent = 'Alterações detectadas. Gere a prévia novamente.';
    }

    projectDirty = true;
    if (currentProjectId) {
      setProjectStatus('Alterações não salvas neste projeto.', 'warn');
    } else {
      setProjectStatus('Projeto não salvo.', 'warn');
    }
    updateSidebarMeta();
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
    } finally {
      previewBusy = false;
      setPreviewButtonsDisabled(false);
    }
  };

  const runImport = async () => {
    if (importBusy) return;

    const driveUrl = normalizeLine(driveInput?.value || '');
    const filesInput = form.querySelector('input[name="source_files[]"]');
    const filesCount = filesInput && filesInput.files ? filesInput.files.length : 0;

    if (!driveUrl && filesCount === 0) {
      activateSection('importacao');
      setImportStatus('Informe um link do Drive ou selecione arquivos para importar.', 'error');
      return;
    }

    syncRawFromRecipes();
    importBusy = true;
    if (importButton) importButton.disabled = true;
    setImportStatus('Importando conteúdo, aguarde...', 'busy');
    setLog('');

    try {
      const fd = new FormData(form);
      fd.set('action', 'pdfw_import');
      fd.set('nonce', importNonce);
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
        throw new Error('Resposta inválida ao importar conteúdo.');
      }

      if (!response.ok || !payload || payload.success !== true) {
        throw new Error(extractError(payload, 'Falha ao importar conteúdo.'));
      }

      const preparedRaw = String(payload?.data?.prepared_recipes_raw || '');
      const coverImage = String(payload?.data?.cover_image || '');
      const recipesCount = Number(payload?.data?.recipes_count || 0);
      const notice = String(payload?.data?.notice || '');
      const auditItems = Array.isArray(payload?.data?.audit_items) ? payload.data.audit_items : [];

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

      markDirty();

      let statusMessage = `Importação concluída: ${recipesCount} ${recipesCount === 1 ? 'receita' : 'receitas'} preparadas.`;
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

      setImportStatus(statusMessage, 'ok');
      renderImportAudit(auditItems, recipesCount, true);
      if (notice) {
        setLog(notice);
      }
      activateSection('receitas');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao importar conteúdo.';
      setImportStatus(message, 'error');
    } finally {
      importBusy = false;
      if (importButton) importButton.disabled = false;
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

  const renderProjectSelect = (projects) => {
    if (!projectSelect) return;

    const list = Array.isArray(projects) ? projects : [];
    projectsCache = list;

    const options = ['<option value="">Selecione um projeto...</option>'];
    list.forEach((project) => {
      const id = project?.id || '';
      const name = project?.name || 'Projeto sem nome';
      const client = project?.client ? ` (${project.client})` : '';
      options.push(`<option value="${escapeHtml(id)}">${escapeHtml(name + client)}</option>`);
    });
    projectSelect.innerHTML = options.join('');

    if (currentProjectId) {
      projectSelect.value = currentProjectId;
    }
  };

  const refreshProjects = async () => {
    const data = await projectAjax('list');
    renderProjectSelect(data.projects || []);
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
    updateSidebarMeta();
  };

  const saveProject = async (asNew) => {
    const name = (projectNameInput?.value || '').trim() || (form.querySelector('[name="title"]')?.value || '').trim() || 'Projeto sem nome';
    const client = (projectClientInput?.value || '').trim();

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

    renderProjectSelect(data.projects || projectsCache);
    if (projectSelect && currentProjectId) {
      projectSelect.value = currentProjectId;
    }

    projectDirty = false;
    setProjectStatus('Projeto salvo com sucesso.', 'ok');
    updateSidebarMeta();
  };

  const deleteProject = async () => {
    if (!currentProjectId) {
      throw new Error('Nenhum projeto selecionado para excluir.');
    }

    const ok = window.confirm('Excluir este projeto salvo? Essa ação não pode ser desfeita.');
    if (!ok) return;

    await projectAjax('delete', { project_id: currentProjectId });

    currentProjectId = '';
    if (projectSelect) projectSelect.value = '';
    if (projectNameInput) projectNameInput.value = '';
    if (projectClientInput) projectClientInput.value = '';

    await refreshProjects();
    setProjectStatus('Projeto excluído.', 'ok');
    updateSidebarMeta();
  };

  const createNewProject = () => {
    currentProjectId = '';
    if (projectSelect) projectSelect.value = '';
    if (projectNameInput) projectNameInput.value = '';
    if (projectClientInput) projectClientInput.value = '';

    if (initialPayload) {
      applyPayloadToForm(initialPayload, { silent: true });
    }

    projectDirty = false;
    setProjectStatus('Novo projeto iniciado (ainda não salvo).', 'warn');
    if (downloadPdfButton) {
      downloadPdfButton.style.display = 'none';
    }
    renderImportAudit([], null, false);
    updateSidebarMeta();
  };

  if (sampleButton) {
    sampleButton.addEventListener('click', () => {
      if (!recipesRawInput) return;
      const ok = window.confirm('Substituir receitas atuais pelo exemplo?');
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
      setProjectStatus('Receitas importadas aplicadas ao editor. Revise e salve.', 'warn');
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
      generatePreview('html');
    });
  }

  if (projectSelect) {
    projectSelect.addEventListener('change', async () => {
      const targetId = projectSelect.value;
      if (!targetId) {
        currentProjectId = '';
        setProjectStatus('Nenhum projeto selecionado.', 'warn');
        return;
      }

      if (projectDirty) {
        const proceed = window.confirm('Há alterações não salvas no formulário atual. Deseja carregar outro projeto mesmo assim?');
        if (!proceed) {
          projectSelect.value = currentProjectId;
          return;
        }
      }

      try {
        await loadProject(targetId);
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao carregar projeto.';
        setProjectStatus(message, 'error');
      }
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
    setImportStatus('Cole o link do Drive ou selecione arquivos e clique em Importar conteúdo.', 'idle');
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
    }

    updateSidebarMeta();
    activateSection(activeSection, false);
  };

  bootstrap();
  window.addEventListener('beforeunload', clearPreviewUrl);
})();
