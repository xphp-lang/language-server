# xphp Language Server

Language Server Protocol \[partial\] implementation that powers editor
intelligence for `xphp` files across any LSP-capable editor:

- diagnostics
- navigation
- refactoring
- completion
- code lenses

The server can be shipped as a self-contained PHAR that can be used by other
tools, like IDE plugins.

The server reuses the parent `xphp` package's AST, generic-instantiation
`Registry`, and `TypeHierarchy` directly -- no second parser, no duplicated
language semantics.

Targets **xphp 0.2.x**, including the turbofish call-site syntax
(`new Box::<T>()`, `Foo::method::<T>(...)`), variance markers, default type
arguments, and composite (intersection / union) bounds.

For the public-facing feature inventory plus what's planned next, see
[roadmap](docs/roadmap.md).

---

## Install

```bash
composer require xphp-lang/language-server
```

---

## Build

```bash
make build/phar # → var/xphp-lsp.phar
```

The PHAR is the distribution format for editor integrations bundle --
zero-config install for editors that can't reasonably depend on a
Composer-managed working tree.

---

## Overview

```mermaid
---
config:
  layout: tidy-tree
---
mindmap
  root((LSP))
    Navigate
      definition
      typeDefinition
      references
      implementation
      callHierarchy
      typeHierarchy
      documentSymbol
      workspaceSymbol
      documentHighlight
    Edit
      rename
      willRenameFiles
      codeAction
      bound-error fix-it
      codeLens
    Understand
      hover
      signatureHelp
      inlayHint
      foldingRange
      semanticTokens
    Validate
      parse
      bound
      duplicate-template
      undefined-bareword
      constructor-arg-mismatch
      argument-mismatch
      cross-file broadcast
    Find
      completion
      completionItem/resolve
    Performance
      AST cache
      stub cache
      tolerant-parse
      UTF-16 columns
      proximity FQN resolution
      lint mode
```

## See also

- [detailed list of features](docs/features/index.md)
- [roadmap](./docs/roadmap.md)