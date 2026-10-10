A SANDBOX PREVIEW CANNOT SHOW WHAT THIS KIT DOES: a sandboxed preview, canvas, or artifact environment cannot reach what this kit talks to. Those sandboxes have no network and no access to boosthis.com, so the kit always looks broken there — sign-in and telemetry cannot load, hand-copied icon bytes come out scrambled, and the console errors belong to the mock, not the kit. A preview, mock-up or re-implementation shown as "the Boosthis UI" describes the sandbox rather than the product. Three checks say something real: (1) a passing kit verification (verify_kit_install, or the same check over HTTPS when a tool reply is withheld) — which proves the FILES ON DISK and nothing more, never that anything is measured; (2) opening the REAL running host app, with network access, and looking at the kit's own bubble and dashboard there; and (3) asking Boosthis whether this project key has seen a check-in — the only one of the three that proves the install is finished (closing step of this guide). SANDBOX INSTALL CHECK: a blocked or unreachable package registry (registry.npmjs.org, pypi.org, proxy.golang.org, Maven Central, etc.) is almost always a sandboxed environment rather than the developer's real project. Workarounds do not change that: the install only counts in the real project (Replit, Claude Code, Cursor, or their local machine). Even there the install is unfinished until check (3), which answers from inside a sandbox too and names which of the states the project is in.

# Boosthis — PHP runtime install

Boosthis is a paid performance product with open signup. This is the
**PHP** runtime: it measures your app's real per-route latency, rates it
against a shared scoring model, watches for the crashes your app actually
throws, reports the infrastructure meters it can genuinely observe, and
ships a 192-rule PHP performance checklist. A
project key issued from your account dashboard is what this kit needs before
it can register, report, or fetch fixes; without one it measures on-device
only. It is a workspace package (not on Packagist); you get it from your own Boosthis account, either as the archive you just unpacked or by value over an AI connection. Requires PHP 8.1 or newer, with no runtime
dependencies.

## Privacy contract

- Detection runs **entirely in-process**, and your app can never break
  because of it — every entry point is guarded, so a failure inside the kit
  is swallowed inside the kit.
- Unregistered installs (no project key) send **nothing**.
- A registered install sends only **code-defined route labels** rated by real
  timings — never request or response bodies, query strings, headers, cookie
  names or values, session contents, SQL, error messages, or source code.
  Every outbound payload passes the shared PII guard.
- `BOOSTHIS_DISABLED=1` silences the entire runtime; `\Boosthis\Boosthis::forget()`
  hands the delete token back to the server, which erases this install and
  everything it uploaded, then clears every local file the kit wrote.

## What this kit adds to your app

Decide this before you ship, not after. Two kinds of thing arrive with
an install: something your users can see, and addresses your app starts
answering.

### What your users see

- A floating bubble in the corner of the page: a score, a rating word, and a panel it opens.
- Where: Injected into HTML pages this service itself serves. Pages served by a separate frontend are a different process and get nothing.
- The floating bubble is on by default in every environment, including production. BOOSTHIS_BUBBLE set to 0 hides it and the kit carries on measuring; BOOSTHIS_DISABLED stops the kit altogether. Each name is read from the environment where this runtime has one, and otherwise from a value of the same name on the host.

### What your app starts answering

- `/_boosthis` — The kit's own page: static markup that draws the panel by fetching the panel read below. Both spellings are the same page, because a developer types one or the other and neither habit should 404.
  Who can reach it: anyone who can reach your app.
  Why it is open: It is the page the bubble links to, opened from any page the app serves, so a gate here would 403 a developer whose bubble works. The markup is static — it holds no measurements to protect.
  What it does not contain: No measurements, no route or screen labels, no credential: every number on it arrives afterwards from the panel read.
- `/_boosthis/` — The kit's own page: static markup that draws the panel by fetching the panel read below. Both spellings are the same page, because a developer types one or the other and neither habit should 404.
  Who can reach it: anyone who can reach your app.
  Why it is open: It is the page the bubble links to, opened from any page the app serves, so a gate here would 403 a developer whose bubble works. The markup is static — it holds no measurements to protect.
  What it does not contain: No measurements, no route or screen labels, no credential: every number on it arrives afterwards from the panel read.
- `/_boosthis/pulse` — One coarse reading for the bubble itself: a score, a rating word, and how many samples it came from.
  Who can reach it: anyone who can reach your app.
  Why it is open: The bubble is injected into the app's own pages and polls this from whichever page the visitor is on, so it cannot be limited to local requests without the bubble going blank in production — which is where it is most worth having.
  What it does not contain: No route or screen labels, no durations, no per-route rows, no credential, nothing about a visitor.
- `/_boosthis/panel` — What the bubble's panel draws: per-meter scores, rating words, meter labels and the numeric captions beside them.
  Who can reach it: anyone who can reach your app.
  Why it is open: Same reason as the pulse read above — the panel opens on the visitor's page, not on the developer's machine.
  What it does not contain: No route or screen labels, no URLs, no raw samples, no install token and no account credential. The account read that does carry one is local-only, below.
- `/_boosthis/account` — The sign-in context the bubble's account card needs, including the token that claims this install.
  Who can reach it: local requests only — nothing opens it remotely.
- `/_boosthis/status` — The standalone “is Boosthis working?” page — the surface a back-end service with no UI has instead of a bubble.
  Who can reach it: local requests only — nothing opens it remotely.

The same list for every runtime, alongside what each address returns, is
published at https://www.boosthis.com/docs/what-boosthis-adds.

## 0. Check you have the whole kit

These notes reach you two ways and read the same either way: unpacked
from the `boosthis-kit-<runtime>.tar.gz` archive you downloaded from your
Boosthis dashboard, or handed to you by value over an AI connection. Do
the check that matches what you are actually holding — one of them, not
both.

**If you unpacked an archive**, there is a `BOOSTHIS_CHECKSUMS.txt` beside this
file. From the folder you unpacked into, run:

```sh
sha256sum -c BOOSTHIS_CHECKSUMS.txt
```

On macOS without GNU coreutils, `shasum -a 256 -c BOOSTHIS_CHECKSUMS.txt` does the
same thing. Every line must end in `OK`. That is the whole check: it needs
no network, no account and no Boosthis tooling. If a line fails, or the
checksum file is not there, the download was incomplete — fetch the
archive again and unpack it into a clean folder.

**If you received a `files[]` array instead**, compare its length with the
`file_count` the same payload carries. A shorter array means the transport
truncated it: write nothing, and fetch the complete kit from
`kit_download_url` instead.

## 1. Add the files & wire the package

Write every file at its **exact** path from the project root, **byte-for-byte**. Reformatting, renaming, reorganizing, merging, splitting or 'improving' a kit file changes its sha256, and so does a run of php-cs-fixer, phpcbf, or a linter: the verify check reports the result as `modified`. Boosthis
PHP has **zero runtime dependencies** (PHP 8.1+ only) and autoloads with
PSR-4 under the `Boosthis\` prefix.

Point Composer at the vendored copy with a **path repository** so the
namespace and the `boosthis-mcp` bin are on the autoloader. In your project's
`composer.json`:

```json
"repositories": [
  { "type": "path", "url": "lib/boosthis-php", "options": { "symlink": false } }
],
"require": {
  "boosthis/boosthis-php": "*"
}
```

Then run:

```sh
composer update boosthis/boosthis-php
```

No download happens — it is a local path repo. (If the project has no
composer.json, add the path repo and run composer, or register the `src/`
tree with your own PSR-4 autoloader under the `Boosthis\` prefix.)

## 1b. Verify before wiring

A kit file that is not byte-perfect on disk produces wiring that looks
installed and measures nothing, so this check belongs before any change to
the build or app code. Either route below is a complete check on its own,
and at least one of them works in every environment.

**From the unpacked archive — offline, no account needed:**

```sh
sha256sum -c BOOSTHIS_CHECKSUMS.txt
```

`BOOSTHIS_CHECKSUMS.txt` lists every other file the archive carries, so a
run with no `FAILED` and no missing-file line is a complete pass. If it
names a file, unpack the archive over the same folder again and re-run it.
(macOS without GNU coreutils: `shasum -a 256 -c BOOSTHIS_CHECKSUMS.txt`.)

**Over an AI connection, where there is one:** the `verify_kit_install`
tool takes the sha256 of each written file as
`{ runtime:"php", files:[{path,sha256},…] }`, and the same JSON POSTed to
`https://www.boosthis.com/api/kit/php/verify` answers identically. Only a `pass` verdict
says the files are the ones this server shipped. A `fail` names the exact
files in `missing` / `modified`; rewritten from the kit, they verify.

## 2. Wire it up

Pick the branch that matches your framework.

### Laravel

Register the middleware **first** in the stack, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Boosthis\LaravelMiddleware::class);
})
```

It uses Laravel's route pattern (`GET orders/{order}`) as the label and does
its network work in `terminate()`, after the response has gone out.

### Slim, Mezzio, Laminas (PSR-15)

Add it as the **outermost** middleware:

```php
$app->add(new \Boosthis\Middleware());
```

Anything above it is work the kit cannot see — and a rate limiter or load
shedder mounted above it would make its own rejections look like your app's.

### Any plain PHP app (Symfony, and everything else)

One line, as early in the request as you can put it — for Symfony, at the top
of `public/index.php`, before the kernel is booted:

```php
\Boosthis\Boosthis::start([
    'installId'  => '<a stable UUID v4 generated ONCE and reused>',
    'projectKey' => getenv('BOOSTHIS_PROJECT_KEY') ?: null,
]);
```

### WordPress

The right home is a small **must-use plugin** at
`wp-content/mu-plugins/boosthis.php` — mu-plugins load before themes and
normal plugins, so the request is measured from the start:

```php
<?php
\Boosthis\Boosthis::start([
    'installId'  => '<a stable UUID v4 generated ONCE and reused>',
    'projectKey' => getenv('BOOSTHIS_PROJECT_KEY') ?: null,
]);
```

Route labels are canonical patterns (`GET /orders/:id`) — IDs and tokens are
collapsed before anything is recorded.

## 2b. (Optional) report outbound calls for retry-storm warnings

PHP gives a library no safe way to intercept every HTTP client, so — like the
Go and Java kits — the kit is told about outbound calls explicitly. This is
the only way the network and retry-storm meters learn anything:

```php
$startedAt = microtime(true);
$response = $client->get($url);
\Boosthis\Boosthis::recordOutbound('api.stripe.com', (microtime(true) - $startedAt) * 1000);
```

Entirely optional — skip it and everything else works; only the retry-storm
detector stays quiet. Nothing about the URL, the status or the payload is
recorded — only the host, the duration, and whether it stalled.

## 2c. Watch AI calls (explicit completed-call observation)

PHP cannot safely patch every HTTP client. For native cURL, hand the completed
handle to the kit before closing it:

```php
curl_exec($ch);
\Boosthis\Boosthis::recordCurl($ch);
```

For Guzzle, streams, or another client, report the completed call explicitly:

```php
\Boosthis\Boosthis::recordAiCall($url, $durationMs, $statusCode, 'POST');
```

Calls not handed to `recordCurl()` or `recordAiCall()` cannot be seen. Known
model hosts are classified automatically. For a model you host, a private
gateway, or a regional endpoint, add the address when the kit starts:

```php
\Boosthis\Boosthis::start([
    'aiEndpoints' => ['llm.internal'],
]);
```

`startWorker()` accepts the same `aiEndpoints` option.
`BOOSTHIS_AI_ENDPOINTS` accepts the same addresses as a comma-separated list.
The option and environment are merged, with a maximum of **8 distinct
endpoints**. Each address is compared inside the PHP process and never sent.
Every declared endpoint in every install reports the same fixed provider
code, so a reading cannot name one or tell two declared endpoints apart.

An observed `POST` whose path has a known inference shape but whose host is
still unknown appears as `unclassifiedCalls`: a count-only reading with
`measurable: 0` and rating `pending`, no score and no timing. Declaring that
endpoint turns later calls into a full reading. This kit does not estimate
spend, count tokens, or measure rate-limit headroom.

## Everything you can call

Every entry point lives on `\Boosthis\Boosthis`. All are guarded: if any
throws, your app carries on as if the kit were not installed.

### Start and finish

- `start(array $options = []): void` — begin measuring this request and
  register the shutdown work. Options: `route`, `installId`, `appName`,
  `projectKey`, `aiEndpoints`, `shareMeterWithAI`. Safe to call twice.
- `finish(?int $statusCode = null): void` — close the request by hand and
  flush. Only needed when you want measurement to end before PHP shutdown;
  the middleware adapters call it for you.

### Measure your own work

- `trackPerf(string $label): callable` — start timing one piece of work; it
  returns a function you call when the work is done.

### Outbound calls

- `recordOutbound(string $host, float $durationMs, bool $stalled = false):
  void` — tell the kit about one outbound call. Pass `$stalled = true` when
  the call timed out or hung, so the retry-storm detector sees it.
- `ignoreOutboundHost(string $host): void` — stop counting a host in the
  outbound meters, e.g. your own proxied health checks.
- `recordCurl($handle, ?int $statusCode = null): void` — report a completed
  native cURL call, including AI classification and available connection data.
- `recordAiCall(string $url, float $durationMs, int $statusCode, string
  $method = 'POST'): void` — report a completed model call from Guzzle, a
  stream, or another client PHP cannot intercept.

### Joining a trace across services

- `traceHeaders(): array<string,string>` — the headers to attach to an
  outbound HTTP call so the dashboard joins this request to the next hop.
  Empty array when there is nothing to propagate.

### Checking what the kit is doing

- `isEnabled(): bool` — is the kit measuring right now?
- `snapshot(): array<string,mixed>` — the perf picture the kit would upload
  right now, for local inspection.
- `connectionStatus(): array<string,mixed>` — a plain answer to "is this
  working?", the same facts the MCP connection-status tool reports.

### The status page (for an API with no web UI)

When this service serves no HTML there is nowhere to inject the floating
bubble, so the kit can serve a standalone status page instead. It is a plain,
no-JavaScript screen that states whether the kit registered, whether anything
is being uploaded, when the last upload was accepted, and — when nothing is
flowing — why not.

- URL: `http://localhost:<your port>/_boosthis/status`.
- LOCAL-ONLY: it is served only to requests from the machine running the app
  (a direct loopback peer with no proxy/forwarding headers). A remote request
  gets a 403 and learns nothing; no option opens it, and it never shows your
  install token.
- Laravel serves it automatically once `LaravelMiddleware` is prepended.
- PSR-15 serves it when you pass a response factory:
  `new \Boosthis\Middleware(['responseFactory' => $responseFactory])`.
- Any plain PHP app wires it with one line at the top of the front controller:
  `if (\Boosthis\StatusPage::serve()) { return; }`.

### Sending everything now

- `flushNow(): void` — send everything pending immediately instead of
  waiting for the cadence.

### Removing the install

BEFORE YOU START — do not remove the Boosthis connection from your AI tool
until removal is confirmed. The erase step runs over that connection while
this app still holds its own delete credential, so if the connection goes
first there is nothing left to erase this app's data on the Boosthis server
and nothing left to check whether it was erased — the owner is left with an
open question instead of an answer. Take the Boosthis connection out LAST,
after the erase step has run.

IF THE CONNECTION WAS ALREADY REMOVED FIRST — nothing is lost and no support
ticket is needed, because removing it never told Boosthis anything. Add the
Boosthis connection back with the same project key, put the erase call back
if the wiring was already stripped, run it once, then confirm. To confirm at
any time, sign in at https://www.boosthis.com/dashboard and read the Data
erasure list: it shows every erase Boosthis actually carried out, with the
date, and says plainly when no erase ever arrived for a project instead of
presenting silence as gone.

- `forget(): bool` — hand the delete token back to the server (which erases
  this install and everything it uploaded) and clear every local file the
  kit wrote.

### Turn on full sharing

Crash and issue reporting runs once the kit is enabled, registered, and the
server has not disconnected the install. It does NOT upload cross-service
trace spans or the perf snapshot until full sharing is on. Switch full
sharing on either way:

- In the dashboard: turn sharing on for this project. The server sends the
  directive back at consent and the kit obeys it.
- In code: start the kit with `shareMeterWithAI` set to `true`:

```php
\Boosthis\Boosthis::start([
    'projectKey'       => getenv('BOOSTHIS_PROJECT_KEY') ?: null,
    'shareMeterWithAI' => true,
]);
```

Leaving the in-code flag out is not a veto: if the account already consented
in the dashboard, full sharing stays on. The flag only turns it on locally;
it never turns off a consent the server has already granted.

### Join a trace to the next service

`traceHeaders()` returns the propagation headers for the current request.
Attach them to your outbound call:

```php
$headers = \Boosthis\Boosthis::traceHeaders();
$response = $client->get($url, ['headers' => $headers]);
```

The dashboard then shows one trace across both services.

### Check what the kit is doing

`connectionStatus()` returns: `runtime`, `kitVersion`, `phpVersion`, `sapi`,
`registered`, `installIdKnown`, `hasProjectKey`, `entitlement`,
`fullTelemetry`, `issuesEnabled`, `sampleCount`, `crashClasses`,
`storeWritable`, `storeBackend`, and `disabledByEnv`. When nothing appears
on the dashboard, read it in this order: `disabledByEnv` true means
`BOOSTHIS_DISABLED` is set; `hasProjectKey` false means no key, so nothing
leaves; `registered` false means the app has not registered yet (serve a
request); `fullTelemetry` false means spans and snapshots are held back
until full sharing is on; `storeWritable` false means the meters that need
history cannot build.

### Send everything now

PHP normally uploads on its own cadence. In a short-lived process — a queue
worker, a command-line tool, or a test — the process can exit before that
cadence fires. Call `\Boosthis\Boosthis::flushNow()` before it exits to
push everything pending.

### Settings

The environment variables the kit reads. All are optional; without the
project key the kit still runs but stays on-device and sends nothing.

| Variable | Purpose |
| --- | --- |
| `BOOSTHIS_PROJECT_KEY` | Registers the app and fetches fixes. Optional; without it nothing leaves. |
| `BOOSTHIS_INVITE_KEY` | Legacy name accepted in place of the project key. Optional. |
| `BOOSTHIS_INSTALL_ID` | Pins the install id for this copy. Optional. |
| `BOOSTHIS_APP_NAME` | Overrides the detected application name. Optional. |
| `BOOSTHIS_API_BASE` | Points the kit at a different Boosthis endpoint. Optional. |
| `BOOSTHIS_STATE_DIR` | Where cross-request state lives. Optional. |
| `BOOSTHIS_DISABLED=1` | Absolute off switch: no network, state or meters. Optional. |
| `BOOSTHIS_DEBUG=1` | Extra logging to the host error log. Optional. |
| `BOOSTHIS_BUBBLE=1` | Show the dev bubble even outside a dev context. Optional. |
| `BOOSTHIS_NO_BUBBLE=1` | Never show the dev bubble. Optional. |
| `BOOSTHIS_TURBO=0` | Skip the fast first-run upload ramp. Optional. |
| `BOOSTHIS_DEP_INVENTORY=1` | Opt in to sending Composer dependency names and count. Optional. |

Advanced — rarely set, mainly for the local MCP server and self-hosting:

| Variable | Purpose |
| --- | --- |
| `BOOSTHIS_READ_TOKEN` | Read credential the MCP server uses (with `BOOSTHIS_INSTALL_ID`) to read this app's live meter. Optional. |
| `BOOSTHIS_INGEST_URL` | Overrides the endpoint the live-read tools call, ahead of `BOOSTHIS_API_BASE`. Optional. |
| `BOOSTHIS_ENDPOINT` | Same override, checked after `BOOSTHIS_INGEST_URL`. Optional. |

Not settings, so they are not in the tables above: `x-boosthis-trace` and
`x-boosthis-trace-elapsed` are the HTTP header names the kit reads off an
incoming request to join a trace, not environment variables. `BOOSTHIS_
CHECKLIST` is an internal class constant the MCP server reads, not something
you set in the environment.

## 3. The install id & project key

Generate `installId` once per running copy of the app and reuse that same
value across restarts and deploys — it identifies THIS copy, not the source
tree. A second copy (a separate service, or a deployment you want tracked on
its own) needs its own id; pin one per copy with `BOOSTHIS_INSTALL_ID`.
Never change a copy's value after the project is first installed. A new value
does not repair a connection: it registers a SECOND device under the same
project and leaves the real one as a duplicate; fix the project key and use
the dashboard's **Repair** instead.
Keep the project key in `BOOSTHIS_PROJECT_KEY`. One `bk_...` project key both
**registers** this app and **fetches fixes**; it cannot mint other keys, so
it is safe to keep in the app config. If your AI connected over the hosted
MCP, the key was handed to it automatically; check the kit response's
`invite_key` field. With no key, the app stays 100% on-device.
If this codebase already reports under another project key, set `BOOSTHIS_PROJECT_KEY_PHP`.

### Troubleshooting: `invalid_install_id` / "install_id must be a UUID v4"

`installId` must be a **real UUID** — never an invented "stable id" string
like `my-php-app`. The server **rejects** registration with HTTP 400 and
`{"error":"invalid_install_id"}` when it is not a UUID v4, so the app would
otherwise measure locally and never appear on the dashboard. The kit prints
ONE warning at that moment and does **not** retry — an unchanged bad id can
never be accepted.

**Fix:** mint one real UUID, persist it, and register again:

```sh
uuidgen                                     # macOS/Linux
php -r '$d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);$h=bin2hex($d);printf("%s-%s-%s-%s-%s\n",substr($h,0,8),substr($h,8,4),substr($h,12,4),substr($h,16,4),substr($h,20));'
```

Store that value (env var `BOOSTHIS_INSTALL_ID`, config file, or a build-time
constant) and pass it as `installId` on every run — the SAME value forever.

## 4. State between requests

PHP forgets everything at the end of a request, so the kit keeps its counters
in APCu when it is available and in a per-project directory otherwise (set
with `BOOSTHIS_STATE_DIR`). If neither is writable, the kit still measures
each request — it just cannot build the meters that need a window of history.
`\Boosthis\Boosthis::connectionStatus()` tells you which backing store you got.

## 5. Verify

### Is Boosthis running? Read the badge, then the startup line
Boosthis draws a small badge in the corner the moment it starts - before it reads the project key and before it tries to register. A badge therefore appears even when the key is wrong.
Badge on the page = the kit is running. Read the badge; it says what it is doing.
No badge at all = the kit was never switched on. The key, the network and the preview are all irrelevant until a badge appears.
The kit also says one line the moment it starts, and that line is the anchor for every diagnosis:
    [boosthis] Boosthis starting: project key ...1a2b. Registering next.
No line means the kit never started, whatever anyone believes.

1. THE STARTUP LINE IS THE ANCHOR. The app's console/log output either carries a line beginning "[boosthis] Boosthis starting:" or it does not. Quoted exactly as printed — including the four characters of the key it names — that line settles what the kit is actually configured with; paraphrased, or recalled from memory, it settles nothing. Every other explanation (the key, the network, a sandbox, a preview pane) is guesswork while that line is unread. No line at all means the kit never started, and nothing else is worth fixing until it does.

Serve the app and hit a couple of routes. For a registered app the first
run flips it from "connected, awaiting telemetry" to reporting in the
maintainer's dashboard.

**Final check:** serve the app and confirm it appears on the dashboard at
`/dashboard` on the site — downloading the kit is not installing it.

## 6. (Optional) local rule-book MCP server

The package ships a local stdio MCP server (`bin/boosthis-mcp`) exposing the
192-rule PHP performance checklist and live-read tools. Point your AI tool's MCP
config at it:

```json
{
  "mcpServers": {
    "boosthis": {
      "command": "./vendor/bin/boosthis-mcp",
      "env": { "BOOSTHIS_PROJECT_KEY": "your-project-key" }
    }
  }
}
```

With `BOOSTHIS_INSTALL_ID` + `BOOSTHIS_READ_TOKEN` set, it can also read this
app's live meter.

## Kit files are vendored, not hand-edited

Boosthis files are vendored. An edit made in place is overwritten by the next re-fetch and shows up as `modified` in the verify check, so re-fetching the kit is what changes Boosthis behaviour. Where drift is suspected, the `verify_kit_install` check names every `missing`/`modified` file and `get_integration_kit` returns clean copies.

## MINTING THE KEY FOR THIS KIT

This kit reads its project key from the environment (the shared `BOOSTHIS_PROJECT_KEY` where nothing more specific is wired in), so the key never travels inside anything a user downloads. A `fix` key covers it — that is what the Project keys page mints by default, and it both registers this project and authenticates the Boosthis AI connection's reads.

The narrower `ship` scope exists for the kits whose key ends up inside a published artifact (the React Native kit, the browser kit, the Flutter kit, the Swift kit and the Kotlin kit). A key in the environment is not one of those, and a `ship` key used here would register perfectly well while costing the AI connection every read it makes.

A key committed to a repository is a key in a public artifact from the moment the repository is one, which is the case the environment setting avoids.

## What Boosthis costs your app

Measured on 2026-09-27, against the kit autoloader is never registered, so
no kit class is ever loaded, installed the way this guide says
(\Boosthis\Boosthis::start() before routing and ::finish($status) after the
body): throughput falls 99.86% and a request takes 557.97ms longer at the
median, 589.16ms longer at the 95th percentile. The rig's verdict on this
run is OVER CEILING: it is above the 10% we hold this kit to, and the breach
is recorded here rather than hidden. Three interleaved rounds placed that
figure at ±0 points. A process's FIRST requests pay more, because the kit is
loading, registering and warming while it serves them: 99.87% and 583.84ms
at the 95th percentile over that window.

MEMORY. The same paired arms, with the whole process tree's resident memory
sampled from the kernel while the traffic ran: the kit adds 2.7 MB once the
process has settled (±0.51 MB across the rounds), and 2.32 MB at its largest
single sample — the figure that decides whether a container with a fixed
memory limit survives. Measured 2026-09-25. Resident memory is what the
operating system says the process holds. A runtime that manages its own heap
— the JVM, .NET, the BEAM — answers extra allocation by growing that heap,
so part of a large figure here is the heap responding to the kit's work
rather than the kit's own structures. It is still memory the machine must
have, which is why it is published; it is not a number to read as the size
of the kit.

EVERY RUNTIME, FOR COMPARISON. The same rig, the same workload, the same
kit-absent control, one runtime at a time — steady throughput cost: Browser
(web page) -2.06%, Edge isolates (Deno) 39.8%, Python 57.32%, Java 58.63%,
Node.js 79.71%, Bun 82.29%, Ruby 84.25%, Go 89.82%, Rust 90.09%, .NET
96.45%, Elixir 99.62%, PHP 99.86%. Measured on different days; each
runtime's own date is in the record. No figure yet, each with a written
reason and what would take one: React Native, Flutter, Swift / Apple
platforms, Kotlin / Android, Cloudflare Workers (workerd isolate). Nothing
is estimated for those, and nothing here is an average — an average over
runtimes would describe no app that exists.

WHAT THE KIT DOES AND DOES NOT PROMISE. Every public entry point in this kit
is wrapped so that a bug inside Boosthis returns a value to your code
instead of throwing into it, and each runtime's own test suite has a case
that checks it. That is a discipline we keep and check — it is not a
guarantee that nothing can go wrong inside your process. It cannot cover a
fault that starves your process rather than throwing in it, and it says
nothing at all about speed: the figures above are what this kit costs,
measured, and they are not zero. If you find a sentence in this kit's
release notes saying Boosthis "can never slow or crash your app", that
sentence is superseded by this one — the speed half was never measured and
the figures above contradict it. Boosthis is supplied as is and used at your
own risk — the LICENSE and TERMS shipped inside this kit say so, and nothing
here promises otherwise.


Coverage check before you finish: this is a SERVER kit, so it measures this service and nothing else. Most projects with a back end also have something the person actually looks at — a React Native/Expo app, or browser-served pages — and each of those has its own kit. One kit per runtime, the same project key for all of them — the remaining ones come from this same boosthis.get_integration_kit tool, and its `runtimes` list takes every runtime this project still needs in ONE further call; `runtime` on its own is the single-runtime form, for a project deliberately spanning one. The names: "flutter", "kotlin", "rn", "swift" for an app on a device, "web" for browser-served pages, and "bun", "dotnet", "edge", "elixir", "go", "java", "node", "php", "python", "ruby", "rust" for a back end. Worth a quick look around the whole repo before calling this done (package.json with expo or react-native → the "rn" kit; package.json with react/next/vite/vue/svelte/angular → the "web" kit). Boosthis serves a small shell script at https://www.boosthis.com/scan to a caller presenting this project's key: it reads manifest file names only, uploads nothing, walks monorepo subdirectories, and names every runtime the project spans. With only a server kit installed the developer sees no bubble anywhere (see the note above), which reads exactly like a failed install. If a runtime is genuinely out of scope here, telling the developer so up front saves the confusion.

STILL UNANSWERED: WHAT ELSE THIS PROJECT SPANS: the php kit covers the php runtime and no other, and nothing here establishes which other runtimes this project has — so this delivery answers one runtime out of an unknown number rather than finishing the install. One further call settles it: boosthis.which_kits reads the manifest file NAMES already visible in the project (nothing downloaded, nothing executed, no source code read) and names the kit each one implies; boosthis.get_integration_kit then takes every runtime it named in a single `runtimes` list. A host that cannot list files, or a developer who says the project is this one runtime, is served exactly the same kit either way — what is left unestablished is what the project spans, and saying that plainly is more use to the developer than an install reported as complete. What is already registered under this project key is deliberately not named in this file: every reader of this kit gets the same bytes, and the kit's own verification compares them. The kit reply's `project_span`, boosthis.connection_status and the dashboard each name it for THIS key.

COMPLETE INSTALL PATH: the archive at kit_archive_url (or every manifest file downloaded by hand) puts the files on disk. What turns them into an install is this kit's version-matched INSTALL.md, all the way through its runtime-specific dependency, startup wiring, and first real run. Files on disk alone are not an install.
PROJECT KEY: this kit's project-key option or environment setting takes the served invite_key; the exact runtime-specific name and startup call are in the guide above.
CONTROL HOW MUCH DATA ARRIVES: the guide's issues-only/private-mode switch limits collection to crashes and issues, while its share-with-AI switch opts the meter/span and snapshot mirror back in. Use the exact symbols listed for this runtime above; these are separate choices, not synonyms.
FLUSH: a short-lived worker, CLI, test, or backgrounded app exits with its queue undrained unless the runtime-specific flush call named above runs first. Where this runtime has no separate flush call, its guide says so and names the lifecycle event that drains the queue instead.
DIAGNOSTICS: the runtime's diagnostics/status calls named above, plus the first ungated console line, separate not-started, not-registered, disabled and connected. An unknown state is unknown: it is not evidence of a healthy install.
TURN IT OFF OR FORGET DATA: BOOSTHIS_DISABLED silences collection without deleting stored data. The runtime's forget/remove call named above deletes this install's stored Boosthis data and credentials; these are deliberately different operations.

FILES ON DISK ARE NOT AN INSTALL — THREE THINGS STILL HAVE TO HAPPEN AFTER THE FILES ARE WRITTEN, AND THIS IS THE POINT AT WHICH AN INSTALL IS USUALLY LEFT HALF-FINISHED. A passing file check (verify_kit_install, or its plain-HTTPS twin) says exactly one thing: the bytes written to disk match the bytes we served. It says nothing about whether anything is measured. The work that remains is that the kit is wired into the app's own startup path, that reporting is switched on with the project key, and that the app has actually been run (a back-end service also needs one real request). Files written with none of that is a project that is completely dark, and from the inside it looks exactly like a correct one — which is why the closing step is never a reading of the project from the inside, a screenshot, a preview pane, or a passing file check.
THE FINISHING QUESTION IS WHETHER BOOSTHIS HAS SEEN THE APP CHECK IN. One request answers it from anywhere — a restricted preview, a machine with no browser, a project that has never been published, a session whose tool replies are being screened: GET https://www.boosthis.com/api/connection-status, authenticated with the same project key.
Ordinary HTTPS is the route this guide uses; boosthis.connection_status is the same answer by a more convenient path, from the same builder. The state can change after the app runs, so the answer is only current as of when it was asked.
THERE ARE EXACTLY TWO STATES THIS WORK CAN END IN, and they are not the same: “reporting — Boosthis has seen this app check in”, and “files written but not switched on — nothing is being measured yet”, the second of which leaves something outstanding and someone who has to do it. Written files are not an installed, verified or finished state while Boosthis has not seen a check-in.