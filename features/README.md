# Behavior specifications (Gherkin)

Executable acceptance specs for the xphp language server, organized by theme.
Each scenario drives the **real language server end-to-end** — phpactor's
`LanguageServerTester` builds the production `LspDispatcherFactory`, runs the
initialize/ServerCapabilities handshake, and routes real JSON-RPC requests
through the full middleware stack to the handlers. Everything is **in-memory**
(fixtures are opened via `textDocument/didOpen`; the transmitter is an array
buffer — no stdio, sockets, or files), so the suite is isolated and
parallel-safe.

```
features/
├── navigate/   definition, type-definition, references, implementation,
│               document & workspace symbols, document highlight,
│               call hierarchy, type hierarchy
├── edit/       rename, code actions (import, optimize, bound fix-its),
│               code lens, willRenameFiles
├── understand/ hover, signature help, inlay hints, folding ranges, semantic tokens
├── validate/   diagnostics (parse, undefined-name, bound, duplicate,
│               argument-type mismatch: ctor / method / static / function),
│               cross-file broadcast (push-mode re-publish of dependents)
└── find/       completion, completion-item resolve
```

Each scenario is arranged **Given** (fixture file contents + warmed FQN index) /
**When** (one LSP request) / **Then** (assert the response). Fixtures use
leading-slash URIs (`/Foo.xphp`) for handlers that go through worse-reflection.

## Step definitions

The step definitions live in `test/Behat/` as plain context classes:

- `World` — the shared per-scenario state + helpers: the `LanguageServerTester`
  (real dispatcher), the request dispatch, and the fixture/position/assertion
  helpers. It is **constructor-injected** into every context and a fresh one is
  used per scenario/example.
- `WorldArgumentResolver` + `WorldExtension` — the Behat extension that performs
  that injection (tagged `context.argument_resolver`) and resets the `World`
  before each scenario/example (tagged `event_dispatcher.subscriber`).
- `ServerContext` — the cross-theme fixture Givens and the generic request
  dispatchers.
- `NavigateContext`, `EditContext`, `UnderstandContext`, `ValidateContext`,
  `FindContext` — the When/Then steps for each theme, delegating to the injected
  `World`.

## Running

```sh
make test/behat            # sequential
make test/behat/parallel   # one process per feature file
```

Behat is installed in an isolated tooling dir (`tools/behat/`) because Behat 3.x
caps `symfony/console` at `^7` while the project pins `^8` via `xphp-lang/xphp`.
`make test/behat` bootstraps it on first run.

## Coverage boundary — what is *not* covered here, and why

The suite is **100% in-memory**: `World` builds the server with
`new InitializeParams(new ClientCapabilities())` — **no `rootUri`/`rootPath`** —
so the filesystem walk is empty and every scenario is open-document-only (files
arrive via `textDocument/didOpen`, never from disk). That guarantee is what
keeps the suite isolated and parallel-safe, but it puts whole categories of
behavior structurally out of reach. Those are covered by **PHPUnit unit tests**
instead (named below), not Behat — driving them here would require writing real
files to a real `rootUri`, which would break the in-memory / no-disk /
parallel-safe invariant.

**Filesystem layer (unit-tested, never Behat):**
- The FQN **filesystem index** + **proximity-aware resolution** and its
  per-request origin anchor (`OriginTrackingMiddleware`) — duplicate FQNs across
  on-disk files resolved by nearness to the requesting document.
  → `test/Reflection/FqnIndexTest.php`, `test/Dispatcher/OriginTrackingMiddlewareTest.php`
- Cross-file go-to-definition / hover into **closed** (on-disk, not open) files.
- The warmers (`FqnIndexWarmer`, `ParsedDocumentCacheWarmer`).
- Bound-check hierarchy single-sourcing across duplicate-FQN packages.
  → `test/Diagnostics/XphpDiagnosticsProviderTest.php`
- `workspace/didChangeWatchedFiles` (file-watcher index invalidation).

**Other unit-only behaviors:**
- Non-ASCII semantic-token **length** (UTF-16 code units): the Behat token
  decoder is byte-based, so the length is pinned in
  `test/Handler/SemanticTokens/AstVisitorTest.php` instead.

**In-memory-drivable but currently not scripted (low value):**
- Document-lifecycle notifications `textDocument/didClose` / `didSave` /
  `willSave` / `willSaveWaitUntil` (Behat only drives `didOpen` / `didChange`).
- The `codeAction/resolve` round-trip (providers attach edits eagerly, so the
  resolve step is a no-op in practice).

Everything else — all of navigate / edit / understand / validate / find — is
exercised end-to-end through the real dispatcher here.

## @todo scenarios

Deferred behavior can be written as `@todo` scenarios that document the desired
outcome but are skipped (via the gherkin tag filter in `behat.dist.yml`), so the
suite stays green on what's expected to work. There are currently none — every
scenario runs.
