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
├── edit/       rename, code actions, code lens, willRenameFiles
├── understand/ hover, signature help, inlay hints, folding ranges, semantic tokens
├── validate/   diagnostics (parse, undefined-name, bound, ctor-arg, duplicate)
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

## @todo scenarios

Deferred behavior can be written as `@todo` scenarios that document the desired
outcome but are skipped (via the gherkin tag filter in `behat.dist.yml`), so the
suite stays green on what's expected to work. There are currently none — every
scenario runs.
