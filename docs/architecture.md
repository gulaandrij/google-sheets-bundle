# Architecture

This bundle exists because `revolution/laravel-google-sheets` v7 made one specific design choice that breaks cleanly outside Laravel: the convenient `Revolution\Google\Sheets\Sheets` class became a Laravel facade, and the concrete client is now `SheetsClient`. This bundle wires that concrete class into Symfony's container with a higher-level service on top — and adds the guard rails it's missing for cross-call safety.

## Service graph

```
config/packages/google_sheets.yaml
            │
            ▼
GoogleSheetsBundle::loadExtension(...)
            │
            ├──▶ google_sheets.client_factory       (GoogleClientFactory)
            │        │
            │        └─factory─▶ google_sheets.google_client       (Google\Client, shared)
            │                              │
            │                              └─arg─▶ google_sheets.google_service  (Google\Service\Sheets, shared)
            │                                                   │
            │                                                   └─arg─▶ google_sheets.sheets_client_factory  (SheetsClientFactory, shared)
            │                                                                                       │
            │                                                                                       ├─factory─▶ google_sheets.sheets_client  (SheetsClient, NON-SHARED)
            │                                                                                       │
            │                                                                                       └─arg─▶ google_sheets.sheets_service.<name>  (SheetsService, one per configured spreadsheet, public)
```

Per-spreadsheet binding (Flysystem-bundle pattern):

- For every entry under `google_sheets.spreadsheets`, the bundle creates `google_sheets.sheets_service.<name>` with the spreadsheet ID baked into the constructor.
- An autowire-by-name alias `SheetsService $<camelCaseName>` resolves to that concrete service.
- The `default_spreadsheet` entry additionally backs the bare `SheetsService` alias and `google_sheets.sheets_service`.

Class aliases registered for autowiring:

| FQCN                                                                  | Targets                                                                                          |
|-----------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsService`                 | `google_sheets.sheets_service.<default_spreadsheet>` (public, only registered when spreadsheets are configured) |
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsService $<varName>`      | `google_sheets.sheets_service.<spreadsheet>` (public, one alias per declared spreadsheet)        |
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory`           | `google_sheets.sheets_client_factory`                                                            |
| `Revolution\Google\Sheets\SheetsClient`                               | `google_sheets.sheets_client` (non-shared)                                                       |
| `Google\Service\Sheets`                                               | `google_sheets.google_service`                                                                   |
| `Google\Client`                                                       | `google_sheets.google_client`                                                                    |

Inject whichever level fits:

- `SheetsService $<name>` — everyday application code (the recommended pattern).
- `SheetsClientFactory` — dynamic spreadsheet IDs not known at boot time.
- `SheetsClient` (autowired or `factory->create()`) — advanced operations not covered by `SheetsService`.
- `Google\Client` — reuse the same authenticated transport for other Google APIs in the project.

## Why a fresh `SheetsClient` per call?

`Revolution\Google\Sheets\SheetsClient` carries four pieces of selector state as instance fields:

- `$range` (set by `range(string)`)
- `$majorDimension` (set by `majorDimension(string)`)
- `$valueRenderOption` (set by `valueRenderOption(string)`)
- `$dateTimeRenderOption` (set by `dateTimeRenderOption(string)`)

**None of them are reset by `spreadsheet()` or `sheet()`.** In a Laravel project the facade resolves a fresh instance per controller boot, so the bug is largely hidden. In a Symfony project, the default DI service is shared (singleton) — and that's catastrophic for a stateful client:

```php
// Request 1
$service->readRaw('id1', 'tab1', 'A1:B10');
// → SheetsClient::$range is now 'A1:B10'

// Request 2 (same worker process)
$service->readRaw('id2', 'tab2');
// → SheetsClient::ranges() returns 'tab2!A1:B10' (sticky $range)
// → reads the wrong slice — silently
```

Worse, `clear()` and `update()` would wipe / overwrite a range the caller didn't intend.

### The fix

`SheetsClientFactory` builds a brand-new `SheetsClient` on every `create()` call. `SheetsService` calls `$this->factory->create()` at the top of every public method, so the state cannot survive across calls. The `google_sheets.sheets_client` DI service is registered with `->share(false)`, so any code that autowires `SheetsClient` (or fetches it from the container) also gets a fresh instance. The `SheetsService::client()` escape hatch likewise returns a fresh client each call.

What the bundle does **not** prevent:

- Mutating a `SheetsClient` you obtained from `client()` and then handing it to two consumers in the same request. You own that variable.
- Hot-cache in the Google\Client itself (auth tokens, HTTP keep-alive). That's intentional — re-authenticating per call would be slow.

## Why the high-level service?

The `SheetsService` API is intentionally narrow — six verbs (`readRaw`, `readAssoc`, `listSheets`, `listSheetsWithIds`, `append`, `update`, `clear`) — because those cover ~95% of integration code. They normalise three sharp edges in the underlying client:

1. **Header validation in `readAssoc`** — duplicate header values throw `DuplicateHeaderException` instead of silently collapsing columns (`array_combine` keeps the last value). Non-scalar header cells (the Sheets API returns arrays for `#REF!` and similar errors) throw `InvalidHeaderException` instead of triggering `Array to string conversion` warnings.
2. **Uniform row shape in `append`** — mixing positional and associative rows throws `MixedRowShapeException`. The underlying client inspects only the first row to decide whether to reorder by header, so non-conforming rows would be silently turned into empty values.
3. **Fresh state per call** — described above.

For anything outside that 95%, `client()` hands back a fresh underlying `SheetsClient` and you fall back to its fluent API.

## Why the config tree validates at the bundle layer?

`Google\Client` accepts every credential as a string, including JSON service-account documents. The bundle's config tree allows `auth_config` to be either a `string` (path or inline JSON) or an `array` (decoded JSON) — so users can configure either of the natural Symfony env patterns:

```yaml
auth_config: '%env(resolve:GOOGLE_AUTH_CONFIG)%'      # path
auth_config: '%env(json:GOOGLE_AUTH_JSON)%'           # array
```

The factory accepts both and forwards verbatim to `Google\Client::setAuthConfig()`, which natively handles both shapes.

## Why `loadExtension` does runtime validation instead of trusting the docblock?

PHPStan level max enforces strict LSP — narrowing the `array $config` parameter in `loadExtension()` to a typed array shape violates contravariance against the parent's bare `array`. To keep `loadExtension` signature-compatible with `AbstractBundle::loadExtension` AND still hand precisely-typed values to `GoogleClientFactory`, the bundle does the type assertions in a private `extractFactoryArgs()` helper. The helper's `@return` docblock gives PHPStan the shape it needs; the runtime checks make the call safe even if a future Symfony version loosens the config tree.

## Testing isolation

Tests boot a real Symfony kernel via `tests/Fixtures/TestKernel.php`. A compiler pass on the kernel exposes every `google_sheets.*` service publicly so the test suite can introspect the wiring without reflection gymnastics, while production builds keep them private. Each test gets a unique cache directory so parallel runs (and the kernel's own compile cache) don't collide.
