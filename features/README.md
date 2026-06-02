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
