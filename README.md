# Plugin PDF WordPress

Plugin WordPress para geração de ebook em HTML/PDF com temas visuais, focado em fluxo simples no painel admin.

## Status

MVP funcional (v0.1.0):

- tela admin `PDF Ebook Studio`
- edição de título, autor, tema, receitas, dicas e seção sobre o autor
- exportação em `HTML`
- exportação em `PDF` com `dompdf` (opcional via Composer)

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

2. (Opcional) Habilitar exportação PDF:

```bash
cd plugin-pdf-wordpress
composer install --no-dev
```

3. Compacte a pasta e instale em `wp-admin > Plugins > Adicionar novo > Enviar plugin`.

## Uso

1. Abra `PDF Ebook Studio` no menu lateral do WordPress.
2. Preencha os campos.
3. Clique em `Gerar HTML` ou `Gerar PDF`.

## Observações

- Sem `dompdf`, o botão de PDF mostra aviso no painel.
- O MVP usa edição manual de receitas por texto com separador `---`.
- Próximas fases (parser DOCX/PDF, QA e automações) estão em `docs/ROADMAP.md`.
