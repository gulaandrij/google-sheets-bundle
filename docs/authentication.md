# Authentication

This bundle wraps `Google\Client`, which supports several authentication methods. Pick the one that matches your use case:

| Method                       | Token type   | What it can read     | What it can write    | When to use                                          |
|------------------------------|--------------|----------------------|----------------------|------------------------------------------------------|
| API key                      | Developer key | Public spreadsheets  | Nothing              | Fast prototypes, public data sources                 |
| OAuth client + user consent  | User token    | Sheets the user owns | Sheets the user owns | User-facing apps where each user logs in             |
| Service account              | Server key   | Sheets shared with the SA | Sheets shared with the SA | Cron jobs, backends, server-to-server               |

Configure exactly one (or more) of these under `google_sheets.auth` in `config/packages/google_sheets.yaml`. The bundle throws `MissingCredentialsException` at runtime if none are present.

## 1. API key (read-only, public sheets)

For sheets that are world-readable — e.g. a published export of company data — an API key is enough.

```yaml
# config/packages/google_sheets.yaml
google_sheets:
    auth:
        api_key: '%env(GOOGLE_API_KEY)%'
    scopes:
        - 'https://www.googleapis.com/auth/spreadsheets.readonly'
```

```dotenv
# .env.local
GOOGLE_API_KEY=AIzaSy...your-key...
```

How to generate one: Google Cloud Console → APIs & Services → Credentials → Create credentials → API key. Then under **API restrictions** restrict it to **Google Sheets API** and **Google Drive API** so the same key can't accidentally be used elsewhere.

## 2. Service account (recommended for backends)

A service account is a non-human identity that authenticates with a private key. Best fit for scheduled jobs, message handlers, and admin actions because nothing in the loop requires a user to be present.

### Setup steps

1. Google Cloud Console → IAM & Admin → Service Accounts → Create.
2. Skip role assignment (no Cloud IAM role needed for Sheets API).
3. On the new service account → Keys → Add key → JSON. A file downloads.
4. In each spreadsheet you want to access, click **Share** and add the service account's email (the file's `client_email` field, looks like `name@project-id.iam.gserviceaccount.com`) with **Editor** (or **Viewer** for read-only).

### Wiring it up

Two options — pick whichever fits your secrets pipeline.

**A. Path to the JSON file** (good for local dev where the file is on disk):

```yaml
google_sheets:
    auth:
        auth_config: '%env(resolve:GOOGLE_AUTH_CONFIG)%'
    scopes:
        - 'https://www.googleapis.com/auth/spreadsheets'
        - 'https://www.googleapis.com/auth/drive.readonly'
```

```dotenv
GOOGLE_AUTH_CONFIG=%kernel.project_dir%/config/secrets/google-service-account.json
```

**B. Decoded array** (good for ECS/Lambda where the JSON arrives as an env var):

```yaml
google_sheets:
    auth:
        auth_config: '%env(json:GOOGLE_AUTH_JSON)%'
```

```dotenv
GOOGLE_AUTH_JSON='{"type":"service_account","project_id":"…","private_key":"-----BEGIN PRIVATE KEY-----\n…"}'
```

`auth_config` accepts both shapes — the bundle's config tree validates and forwards either to `Google\Client::setAuthConfig()`.

### Domain-wide delegation

If you need the service account to act on behalf of users in a Workspace domain, configure delegation in the Google Workspace Admin console, then in your application code reach for the underlying client:

```php
$google = $this->sheets->client()->getService()->getClient();
$google->setSubject('user@your-domain.com');
```

Each `$this->sheets->client()` call returns a fresh client, so per-request subject impersonation is safe.

## 3. OAuth 2.0 (user-consent flows)

For apps where each user authenticates with their own Google account, configure the OAuth client ID and secret and drive the consent flow yourself — the bundle does not include a controller for the consent dance.

```yaml
google_sheets:
    auth:
        client_id: '%env(GOOGLE_CLIENT_ID)%'
        client_secret: '%env(GOOGLE_CLIENT_SECRET)%'
    scopes:
        - 'https://www.googleapis.com/auth/spreadsheets'
```

Then in your controller:

```php
public function startOAuth(SheetsService $sheets): RedirectResponse
{
    $client = $sheets->client()->getService()->getClient();
    $client->setRedirectUri($this->generateUrl('google_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL));
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    return new RedirectResponse($client->createAuthUrl());
}

public function oauthCallback(Request $request, SheetsService $sheets): Response
{
    $code = (string) $request->query->get('code');
    $client = $sheets->client()->getService()->getClient();
    $client->setRedirectUri(...);
    $token = $client->fetchAccessTokenWithAuthCode($code);
    // Persist $token (access + refresh) on your User entity for later use.
}
```

Persist the resulting token on your user record. To use it later, set it on a per-request basis via `$sheets->client()->setAccessToken($token)` — again, fresh client per call means no leak across users.

## Choosing scopes

The defaults are read-only:

```yaml
google_sheets:
    scopes:
        - https://www.googleapis.com/auth/spreadsheets.readonly
        - https://www.googleapis.com/auth/drive.readonly
```

Read-only scopes are appropriate for `readRaw` / `readAssoc` / `listSheets`. To `append`, `update`, or `clear`, replace them with the broader scopes:

```yaml
google_sheets:
    scopes:
        - https://www.googleapis.com/auth/spreadsheets
        - https://www.googleapis.com/auth/drive.readonly
```

Granting write scope on Drive is rarely needed; the bundle uses Drive only to look up spreadsheet metadata. If you do need to create files (`spreadsheetByTitle` discovery, etc.), upgrade to `drive.file` or `drive`.

## Multiple credentials in one app

The bundle registers a single set of credentials. If you need two (e.g. one service account for reads, another OAuth identity for writes), wire your own `GoogleClientFactory` and `SheetsClientFactory` services pointing at separate `Google\Client` instances. See [architecture.md](architecture.md) for the service graph.
