# Plugin PDF WordPress

Plugin WordPress para geração de ebook em HTML/PDF com temas visuais, focado em fluxo simples no painel admin.

## Status

Versão atual (v0.3.9):

- tela admin `PDF Ebook Studio`
- gestão de projetos e clientes no próprio WordPress (salvar, carregar, excluir)
- editor em seções (Projetos, Capa/Tema, Importação, Receitas, Extras, Exportar/Prévia)
- editor estruturado de receitas com metadados completos (categoria, descrição, tempo, porções, dificuldade, imagem, dica e nutricional) com sincronização do texto bruto
- drag-and-drop de receitas e categorias no editor
- categorias pré-cadastradas com `nome + subtítulo + imagem` para subcapas
- contador de receitas por categoria (incluindo categorias vazias)
- suporte a `Categoria:` no conteúdo bruto para preservar agrupamento manual no PDF
- botão `Importar conteúdo` (Drive/upload) para popular o editor sem depender de prévia
- importação pode atualizar automaticamente o projeto salvo no banco
- auditoria visual da importação (resumo + status por arquivo processado)
- parser heurístico para receitas em texto corrido (sem cabeçalhos)
- fallback de leitura de PDF com extrator Python embarcado (`lib/pypdf_vendor`)
- prévia dupla: `Prévia fiel (PDF)` e `Prévia rápida (HTML)`
- reaproveitamento de cache entre prévia e geração final (evita reprocessar quando nada mudou)
- botão para aplicar no editor as receitas consolidadas vindas da prévia/importação
- importação automática por upload (`txt`, `md`, `html`, `docx`, `pdf`)
- importação automática por link público de pasta Google Drive (com subpastas)
- exportação em `HTML`
- exportação em `PDF` com `dompdf` embutido no plugin

## Estrutura

```text
plugin-pdf-wordpress/
  ├─ plugin-pdf-wordpress.php
  ├─ includes/
  ├─ templates/
  ├─ assets/
  └─ docs/
```

## Instalação local

1. Clone o repositório:

```bash
git clone https://github.com/rentemkt/plugin-pdf-wordpress.git
```

2. Compacte a pasta e instale em `wp-admin > Plugins > Adicionar novo > Enviar plugin`.

## Uso

1. Abra `PDF Ebook Studio` no menu lateral do WordPress.
2. Defina `Nome do projeto` e opcionalmente `Cliente`.
3. Preencha/importe o conteúdo e use `Salvar projeto`.
4. Gere `Prévia fiel (PDF)` para validar paginação.
5. Se necessário, clique em `Aplicar receitas importadas da prévia`, ajuste no editor e salve.
6. Clique em `Baixar PDF` ou `Gerar HTML`.

## Observações

- Sem `dompdf`, o botão de PDF mostra aviso no painel.
- O editor estruturado de receitas sincroniza automaticamente com o campo bruto (`---`).
- Importação do Google Drive requer pasta pública (link compartilhável).
- A varredura de subpastas do Drive é limitada (profundidade 4 e até 80 arquivos).
- Próximas fases (parser DOCX/PDF, QA e automações) estão em `docs/ROADMAP.md`.

## Referência de UX

- Cópia de referência do editor legado (somente leitura): `reference/ebook-pdf/`.
- Origem da cópia: `/home/iheri/ebook/pdf` (não alterado).
