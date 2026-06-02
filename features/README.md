# Behavior specifications (Gherkin)

Executable acceptance specs for the xphp language server, organized by theme.
Each scenario drives the real LSP handlers against a fully **in-memory**
workspace (every fixture is opened as a `TextDocumentItem`; nothing is written
to disk), so the suite is isolated and parallel-safe.

```
features/
├── navigate/   definition, type-definition, references, implementation,
│               document & workspace symbols, document highlight,
│               call hierarchy, type hierarchy
├── edit/       rename, code actions, code lens, willRenameFiles
├── understand/ hover, signature help, inlay hints, folding ranges, semantic tokens
├── validate/   diagnostics (parse, undefined-name, bound, ctor-arg, duplicate)
└── find/       completion, completion-item resolve
```

Each scenario is arranged **Given** (fixture file contents + warmed FQN index) /
**When** (one LSP request) / **Then** (assert the response). Fixtures use
leading-slash URIs (`/Foo.xphp`) for handlers that go through worse-reflection.

## Step definitions

The step definitions live in `test/Behat/`, split to mirror the themes:

- `WorldTrait` — the shared world: the workspace, the full handler stack
  (mirrors `LspDispatcherFactory` with an empty rootPath), the fixture Givens,
  and the position/assertion helpers.
- `NavigateSteps`, `EditSteps`, `UnderstandSteps`, `ValidateSteps`, `FindSteps`
  — the When/Then steps for each theme.
- `FeatureContext` — a thin aggregator that composes the traits.

## Running

```sh
make test/behat            # sequential
make test/behat/parallel   # one process per feature file
```

Behat is installed in an isolated tooling dir (`tools/behat/`) because Behat 3.x
caps `symfony/console` at `^7` while the project pins `^8` via `xphp-lang/xphp`.
`make test/behat` bootstraps it on first run.

## @todo scenarios

Deferred behavior is written as `@todo` scenarios that document the desired
outcome but are skipped (via the gherkin tag filter in `behat.dist.yml`), so the
suite stays green on what's expected to work. Current `@todo`s:

- go-to-definition through a generic **method** call (navigate/definition)
- **duplicate-template** diagnostic on the edited file — the per-file pull
  provider canonicalizes the edited file, so it surfaces on the other file;
  needs the roadmap's cross-file diagnostic broadcast (validate/diagnostics)
