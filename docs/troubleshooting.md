# Troubleshooting

## `MissingCredentialsException` at boot

> `No Google credentials configured. Set at least one of google_sheets.auth.api_key, google_sheets.auth.client_id/client_secret, or google_sheets.auth.auth_config.`

The bundle is wired but no credential made it through. Common causes:

- The env var is empty in this environment. Run `bin/console debug:dotenv` to confirm. If you switched between dev/prod recently, the missing var is usually in `.env.local` (dev) or wherever your prod env exports come from.
- `auth_config` points at a path that doesn't exist. Check `bin/console debug:config google_sheets` and verify the resolved path exists and is readable by the PHP process.
- The env value resolves to `''` (empty string) — the bundle treats empty strings as "not set." Use `null` or omit the key instead.

## `Google_Service_Exception: 403 The caller does not have permission`

The credentials worked, but Google denied the request.

- **Service account**: the sheet wasn't shared with the SA's email. Open the spreadsheet → Share → add `<client_email>` from the JSON file as Editor (or Viewer for read-only).
- **API key**: API keys cannot read private sheets — only public ones. Switch to OAuth or a service account.
- **OAuth**: the user's token doesn't include the scope you're using. Re-prompt them with `prompt('consent')` and the broader scope.

## `Google_Service_Exception: 404 Requested entity was not found`

Spreadsheet ID is wrong, the sheet has been deleted, or the sheet is in a different Drive (e.g. shared drive vs. personal) that the credential can't see. Double-check by opening the URL `https://docs.google.com/spreadsheets/d/<your-id>/edit` while logged in as the identity the credential represents.

## `DuplicateHeaderException` from `readAssoc`

The first row contains two cells with the same value. Either rename one of them in the sheet, or fall back to `readRaw()` and de-duplicate yourself:

```php
$rows = $this->sheets->readRaw($id, $tab);
$header = array_shift($rows);
// disambiguate as you see fit, e.g. suffix with index
```

## `InvalidHeaderException` from `readAssoc`

A header cell is an array — usually because the API returned a structured value (`#REF!`, `#NAME?`, `#ERROR!`) instead of a plain string. Fix the source sheet, or use `readRaw()` and stringify yourself.

## `MixedRowShapeException` from `append`

The rows you passed to `append` mix associative (string-keyed) and positional (int-keyed) shapes. The underlying client decides based on the first row and silently drops cells in the rest. Normalise first:

```php
$rows = array_map(static fn (array $r): array => array_values($r), $rows); // all positional
// OR
$rows = array_map(static fn (array $r): array => array_combine($header, $r), $rows); // all associative
```

## `Cannot generate lazy ghost: class "X" is final` (only relevant if you wrap entities)

Not a bundle bug, but if you see it in a project using this bundle: it means Doctrine's lazy-ghost generator tried to subclass a `final` entity. Drop the `final` modifier from the offending entity. This is unrelated to the bundle but bites teams who copy the bundle's `final` convention into their entity layer.

## Tests fail with `Class … is declared "final" and cannot be doubled`

PHPUnit can't mock final classes. The bundle's `SheetsClientFactory` is deliberately non-final so tests can mock it; the rest of the public API uses interfaces or stable concrete types. If you're extending the bundle and need to mock a final class, switch to a stub double of an interface instead.

## "Symfony recipes are disabled: symfony/flex not found in the root composer.json" in CI

Harmless. The CI workflow installs Flex globally to apply `extra.symfony.require` pinning, but doesn't add it as a `require-dev` of the bundle. The message means recipes won't auto-apply (we don't need them — there's no recipe to apply for a bundle).

## Tests marked `Risky` because of "exception handlers" / "error handlers"

Booting a Symfony kernel registers global error/exception handlers that PHPUnit's strict mode flags. They don't affect test results, and the bundle accepts them by setting `failOnRisky="false"` while keeping `failOnWarning="true"`. The exit code is still 0 when only risky markers are present.

## Hitting the Sheets API rate limit

`Google_Service_Exception: 429` on big batches. The Sheets API throttles per-minute. Pragmatic fixes:

- Batch updates with `update()` (one request per range) instead of one `append()` per row.
- Add an exponential backoff at the call site. The Google client supports retries via `setConfig('retry', [...])`.
- For huge exports, prefer overwriting a range with `update` over many incremental `append` calls.

## Need more detail

Set the Google client to verbose:

```php
$client = $this->sheets->client()->getService()->getClient();
$client->setLogger($logger); // any PSR-3 logger
```

The Google client logs every HTTP request to the configured logger — enable that temporarily when an integration is going sideways.
