# Behavior specifications (Gherkin)

These `.feature` files specify **expected** Language Server behavior, focused on
cross-file resolution: navigation, hover, and inlay hints must resolve a symbol
through the warmed filesystem index — independent of which files happen to be
open in the editor.

Each scenario is arranged as **Given** / **When** / **Then**:

- **Given** — the workspace fixture files and their contents
  (`the file at "<path>" contains the following lines:`), plus the warmed FQN
  index. Stating contents rather than editor state keeps the arrange reusable
  across every scenario via `Background`.
- **When** — a single LSP request against a position in `Use.xphp`.
- **Then** — assert the response (target file + range, hover signature, or
  rendered inlay hint).

They are written against the LSP request/response contract so they can later be
driven by a headless client harness; there is no Behat wiring yet.

The fixtures mirror the sibling `xphp` package's
`test/fixture/compile/array_sugar/source/`:

- `Use.xphp` — uses `Collection<User>`, calls `->first()` / `->all()`.
- `Containers/Collection.xphp` — `class Collection<T>` with `first(): ?T`, `all(): T[]`.
- `Models/User.xphp` — `final class User`.

## Files

- `cross_file_definition.feature` — go-to-definition resolves across files.
- `cross_file_hover.feature` — hover resolves and substitutes generics across files.
- `inlay_hints.feature` — assignment inlay hints show substituted concrete types.

## Running

```sh
make test/behat            # sequential
make test/behat/parallel   # one process per feature file
```

The step definitions live in `test/Behat/FeatureContext.php` and drive the real
handlers against a fully **in-memory** workspace (each fixture is opened as a
`TextDocumentItem`; nothing is written to disk). Every scenario builds its own
workspace + handler stack, so the run is parallel-safe — sharding feature files
across processes produces identical, deterministic results.

Behat is installed in an isolated tooling dir (`tools/behat/`) rather than the
root `require-dev`, because Behat 3.x caps `symfony/console` at `^7` while the
project pins `^8` via `xphp-lang/xphp`. `make test/behat` bootstraps it on first
run (`composer install --working-dir=tools/behat`).

These specs run **strict**: scenarios are written to the desired behavior, so
the ones the server doesn't yet satisfy (FQN-vs-short-name inlay labels, the
`new Collection<User>()` instantiation hint, the substituted hover signatures,
the generic-method definition jump) fail by design. They are an executable
backlog, which is why Behat is **not** part of the `make test/unit` gate.
