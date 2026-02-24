# Plugin PDF WordPress

Plugin WordPress para geração de ebooks educacionais em HTML/PDF, com importação híbrida (upload + Google Drive), classificação inteligente de conteúdo e laboratório de transcrição integrado.

## Versão atual

`v0.5.0`

## Principais recursos

- tela admin `PDF Ebook Studio` no WordPress
- gestão de projetos e clientes (salvar, carregar, excluir)
- editor em seções: `Projetos`, `Capa/Tema`, `Importação`, `Categorias`, `Itens`, `Extras`, `Transcrição`, `Exportar`
- **conteúdo educacional como padrão**: campos de Duração, Nível, Conteúdo, Pontos-chave e Resumo
- classificação inteligente na importação (receitas somente com marcadores explícitos)
- detecção e correção automática de encoding UTF-8 (ISO-8859-1, Windows-1252)
- OCR integrado para PDFs baseados em imagem (Tesseract + poppler-utils)
- editor estruturado com sync bidirecional do campo bruto (`---`)
- drag-and-drop de itens e categorias
- categorias/módulos com `nome + subtítulo + imagem` para páginas divisórias
- importação por upload (`txt`, `md`, `html`, `docx`, `pdf`, `pptx`, `mp3`, `wav`, `m4a`, `ogg`, `mp4`, `mpeg`, `webm`, `mkv`)
- importação por pasta pública do Google Drive (com subpastas)
- auditoria de importação (resumo + status por arquivo)
- prévia dupla (`Prévia fiel (PDF)` e `Prévia rápida (HTML)`)
- geração final `HTML` e `PDF` (`dompdf`)

## Layout educacional do PDF

O ebook gerado inclui:

- **Capa** com imagem, título, subtítulo e autor
- **Sumário** automático com categorias/módulos
- **Apresentação** e **Destaques** (dicas de estudo)
- **Páginas divisórias** por módulo/categoria (full-page com imagem e numeral romano)
- **Páginas de conteúdo** com:
  - imagem hero opcional
  - barra de metadados (duração, nível)
  - descrição em destaque
  - corpo com suporte a subtítulos (`##`) e citações (`>`)
  - caixa de pontos-chave
  - card de resumo
  - nota do autor
- **Dicas finais** e **Sobre o Autor**
- 5 temas visuais: Clássico, Grafite Dourado, Azul Mineral, Terracota Moderna, Oliva & Areia

## Transcrição (Whisper)

- laboratório de transcrição no admin (`Laboratório de Transcrição`)
- saída em `TXT`, `SRT`, `VTT` e `Lipsync JSON`
- streaming de resposta `verbose_json` em arquivo temporário (proteção de memória)
- arquivos longos (> 15 min) são segmentados e processados em lote
- merge final com correção de offset temporal para `SRT/VTT/Lipsync`
- **retomada real após falha**:
  - estado salvo por `resume_token`
  - botão `Retomar da parte N` no painel
  - checkpoints por parte concluída
  - prévia agregada contínua durante o processamento

### Endpoint padrão

- API: `https://transcrever.rente.com.br/v1/audio/transcriptions`
- Landing: `https://transcrever.rente.com.br/`

## Requisitos

| Requisito | Obrigatório | Nota |
|-----------|------------|------|
| PHP >= 7.4 | Sim | Plugin bloqueia ativação em versão inferior |
| WordPress >= 5.9 | Sim | |
| `mbstring` | Recomendado | Warning no painel se ausente; fallback automático via `pdfw-compat.php` |
| `dompdf/dompdf ^2.0` | Sim (para PDF) | Via Composer ou bundle em `lib/dompdf/` |
| Python 3 | Recomendado | Extração de texto de PDFs (caminho oficial) |
| Tesseract OCR + poppler-utils | Opcional | OCR para PDFs baseados em imagem |
| FFmpeg | Opcional | Transcrição de áudio/vídeo no Laboratório |

## Estrutura

```text
plugin-pdf-wordpress/
  ├─ plugin-pdf-wordpress.php
  ├─ includes/
  │   ├─ pdfw-compat.php (wrappers mbstring safe)
  │   ├─ class-pdfw-plugin.php
  │   ├─ class-pdfw-admin-page.php
  │   ├─ class-pdfw-renderer.php
  │   ├─ class-pdfw-ingestor.php
  │   └─ class-pdfw-exporter.php
  ├─ templates/
  │   └─ admin-page.php
  ├─ assets/
  │   ├─ css/admin.css
  │   └─ js/admin.js
  ├─ lib/pypdf_vendor/
  │   └─ pdf_extract.py (extração de texto + OCR)
  └─ docs/
```

## Instalação

1. Clone:

```bash
git clone https://github.com/rentemkt/plugin-pdf-wordpress.git
```

2. Compacte a pasta e instale em:
`wp-admin > Plugins > Adicionar novo > Enviar plugin`.

### OCR (opcional)

Para importar PDFs baseados em imagem, o container WordPress precisa de:

```bash
apt-get install -y tesseract-ocr tesseract-ocr-por poppler-utils python3
```

Ou use a imagem Docker customizada `wordpress-ocr:latest` que já inclui essas dependências.

## Fluxo rápido de uso

1. Abra `PDF Ebook Studio` no WordPress.
2. Crie/selecione projeto.
3. Importe conteúdo (upload de PDFs ou pasta do Drive).
4. Organize categorias/módulos na aba `Categorias`.
5. Revise e edite itens na aba `Itens` (campos educacionais ou receita).
6. Gere `Prévia fiel (PDF)`.
7. Exporte `PDF` ou `HTML`.

## Continuidade para outros LLMs

Arquivos para handoff e continuidade:

- `docs/LLM-HANDOFF.md` (guia operacional + estado atual)
- `docs/llm-context.json` (resumo técnico estruturado)
- `docs/ROADMAP.md` (pendências e próximos passos)

## Observações

- sem `dompdf`, o botão de PDF exibe aviso no painel
- importação do Drive exige pasta pública
- varredura de subpastas do Drive: profundidade 4, até 80 arquivos
- receitas legado continuam funcionando (branch de renderização separado)
