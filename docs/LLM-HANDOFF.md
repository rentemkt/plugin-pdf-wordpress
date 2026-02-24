# LLM Handoff (Plugin PDF WordPress)

Este arquivo é o ponto de continuidade para qualquer LLM que assumir o projeto.

## Escopo atual

- Repositório: `plugin-pdf-wordpress`
- Branch padrão: `main`
- **Fora do escopo neste repo:** alterar infraestrutura/servidor de transcrição (`transcrever.rente.com.br`).

## Estado técnico consolidado

### Admin/UI

- Tela principal: `PDF Ebook Studio`
- Seções: projetos, capa/tema, importação, itens, extras, transcrição, exportar.
- Arquivo principal de UI: `assets/js/admin.js`
- Template admin: `templates/admin-page.php`

### Transcrição

- Endpoint padrão configurável: `https://transcrever.rente.com.br/v1/audio/transcriptions`
- Fluxo de transcrição AJAX:
  - ação: `pdfw_standalone_transcribe`
  - polling: `pdfw_transcribe_progress`
- Suporte de mídia: `mp3`, `wav`, `m4a`, `ogg`, `mp4`, `mpeg`, `webm`, `mkv`
- Saídas: `text`, `srt`, `vtt`, `lipsync_json`

### Mídia longa e retomada

- Trigger de segmentação: mídia com duração > 900s
- Segmentação + merge com correção de offset temporal (SRT/VTT/Lipsync)
- Estado de retomada persistido por `resume_token` no WP transient
- Checkpoint salvo por parte concluída (`chunk_done`)
- UI com botão: `Retomar da parte N`
- Prévia de texto agregada incremental no painel

## Arquivos críticos

- `includes/class-pdfw-ingestor.php`
  - chunking, merge, callbacks de progresso e `resume_state`
- `includes/class-pdfw-admin-page.php`
  - handlers AJAX, transients de progresso e retomada
- `assets/js/admin.js`
  - polling, toasts, downloads, resume button, preview incremental
- `templates/admin-page.php`
  - estrutura visual da aba de transcrição

## Contratos importantes (não quebrar)

- Ações AJAX já usadas no frontend:
  - `pdfw_standalone_transcribe`
  - `pdfw_transcribe_progress`
  - `pdfw_import`
  - `pdfw_preview`
  - `pdfw_projects`
- Resposta de transcrição deve manter:
  - `text`, `srt`, `vtt`, `lipsync_json`
  - `partial`, `failed_part`, `processed_parts`, `total_parts`
  - `resume_token`, `resume_next_part`

## Fluxo de teste manual mínimo

1. Abrir a aba `Laboratório de Transcrição`.
2. Enviar arquivo longo (> 15 min).
3. Validar progresso por partes no painel.
4. Simular falha (ou interromper) e confirmar botão `Retomar da parte N`.
5. Retomar e validar continuidade da saída agregada.
6. Baixar `TXT/SRT/VTT/Lipsync` e validar conteúdo.

## Pendências recomendadas

- Adicionar testes automatizados (ao menos smoke de endpoints AJAX)
- Melhorar feedback de erro de rede no polling
- Adicionar inspeção de consistência de legenda após merge (QA técnico)

## Convenções de continuidade

- Faça commits pequenos e focados.
- Não incluir `output/`, `reference/` e arquivos locais de auditoria não rastreados sem solicitação explícita.
- Antes de mexer no fluxo de transcrição, preserve os campos de resposta usados pelo frontend.
