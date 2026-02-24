(() => {
  const sampleButton = document.getElementById("pdfw-load-sample");
  if (!sampleButton) return;

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

  sampleButton.addEventListener("click", () => {
    const recipes = document.querySelector('textarea[name="recipes_raw"]');
    if (!recipes) return;
    const ok = window.confirm("Substituir o conteúdo atual pelo exemplo?");
    if (!ok) return;
    recipes.value = sampleRecipes;
  });
})();
