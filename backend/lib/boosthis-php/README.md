# Boosthis for PHP

Honest performance, crash and infrastructure meters for PHP applications —
readable by you, and by your AI assistant.

The kit measures the requests your app actually serves, notices the crashes it
actually throws, and reports what it can genuinely observe. When it cannot
measure something, it says so and shows nothing rather than inventing a number.

It also ships an offline rule book of 192 PHP-specific performance rules that
your AI assistant can read through the MCP server below.

- **Your visitors never wait for it.** Under PHP-FPM the kit hands the page back
  with `fastcgi_finish_request()` and only then talks to the network.
- **Your app can never break because of it.** Every entry point is wrapped; a
  failure inside the kit is swallowed inside the kit.
- **Your users' data never leaves.** No request bodies, no query strings, no
  cookie values, no error messages. Route labels are templated on the host
  before anything is sent, and every payload is checked against a privacy guard
  on the way out.

Requires PHP 8.1 or newer. No runtime dependencies.

Current runtime version: `1.0.70`.

## How this kit reaches you

**Boosthis kits are not published on Packagist, npm, or any other public package
registry. That is a decision, not an oversight.** A registry copy would be an
ungated copy of a kit that is meant to arrive against a project key. So there is
nothing of ours on Packagist to find: a Composer requirement resolved against it
finds nothing we published, and anything that does answer to that name is not
ours and should not be installed.

The kit arrives over your Boosthis connection instead. Your AI assistant asks
Boosthis for it with your project key, and Boosthis hands back every file — this
README included — to write into your project under `lib/boosthis-php`. If you
are reading this file inside your own repository, that step has already
happened.

Point Composer at the vendored copy with a **path repository** so the
`Boosthis\` namespace and the `boosthis-mcp` bin are on the autoloader. In your
project's `composer.json`:

```json
"repositories": [
  { "type": "path", "url": "lib/boosthis-php", "options": { "symlink": false } }
],
"require": {
  "boosthis/boosthis-php": "*"
}
```

Then:

```sh
composer update boosthis/boosthis-php
```

No download happens — it is a local path repo, and no registry is contacted.
(With no `composer.json` at all, add the path repo and run Composer, or register
the `src/` tree with your own PSR-4 autoloader under the `Boosthis\` prefix.)

To connect an assistant that has not done this yet, or to mint a project key,
see <https://www.boosthis.com/docs/connect-your-ai>.

Set your project key (from your Boosthis dashboard):

```bash
BOOSTHIS_PROJECT_KEY=your-project-key
```

## One codebase can hold more than one project

Give PHP its own key without changing or rebuilding the app:

```bash
BOOSTHIS_PROJECT_KEY_PHP=replace-with-php-project-key
```

The kit chooses, in order: `BOOSTHIS_PROJECT_KEY_PHP`, a `projectKey` passed to
`Boosthis::start()`, `BOOSTHIS_PROJECT_KEY`, then the legacy
`BOOSTHIS_INVITE_KEY`. Verify it in `Boosthis::connectionStatus()` or
`/_boosthis/status`; it shows the source and only the masked key tail.

## Wire it up

### Any PHP app (including WordPress)

One line, as early in the request as you can put it:

```php
\Boosthis\Boosthis::start();
```

This startup call is how the kit sees the request: it measures the request and
mounts the floating bubble. A separate status/settings page may be wired as an extra for APIs with
no HTML, but it does not replace the bubble wiring.

Outside a framework, the part Boosthis names is the request itself, taken from
the path it was served at — a job, or one step inside a page, is still yours to
measure with `trackPerf`.

In WordPress, a small must-use plugin at `wp-content/mu-plugins/boosthis.php` is
the right home for it.

### Laravel

Register the middleware first in the stack, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Boosthis\LaravelMiddleware::class);
})
```

It uses Laravel's route pattern (`GET orders/{order}`) as the label and does its
network work in `terminate()`, after the response has gone out.

### Slim, Mezzio, Laminas (PSR-15)

```php
\Boosthis\RouteInventory::registerApp($app); // lets snapshots include routes not visited yet
$app->add(new \Boosthis\Middleware());
```

Add it as the **outermost** middleware. Anything above it is work the kit cannot
see — and a rate limiter or load shedder mounted above it would make its own
rejections look like your app's responses.

### Symfony

Add `\Boosthis\Boosthis::start();` at the top of `public/index.php`, before the
kernel is booted.


## The status page (for an API with no web UI)

If this service serves no HTML, there is nowhere to inject the floating bubble —
so the kit can serve a standalone status page instead. It is a plain,
no-JavaScript screen that states whether the kit registered, whether anything is
being uploaded, when the last upload was accepted, and — when nothing is flowing
— why not. It never runs any JavaScript, makes no network calls, and never shows
your install token.

Open it at:

```
http://localhost:<your port>/_boosthis/status
```

It is **local-only**: the page is served only to requests from the machine
running the app (a direct loopback peer with no proxy/forwarding headers). A
remote request gets a `403` and learns nothing about the install; no option
opens it.

- **Laravel** serves it automatically once `LaravelMiddleware` is prepended.
- **PSR-15** serves it when you pass a response factory:
  `new \Boosthis\Middleware(['responseFactory' => $responseFactory])`.
- **Any plain PHP app** wires it with one line at the very top of the front
  controller, before the framework boots:

  ```php
  if (\Boosthis\StatusPage::serve()) { return; }
  ```

## What you get

| Surface | What it shows |
| --- | --- |
| The dashboard on boosthis.com | Every project, its speed, its crashes and its meters |
| The in-page bubble | The same picture in every environment, without leaving your app |
| Your AI assistant | The same picture again, through the MCP server below |

### Measuring one piece of work

`trackPerf` returns a function. Call it when the work is finished:

```php
$done = \Boosthis\Boosthis::trackPerf('checkout:tax');
// ... the work ...
$done();
```

### Outbound calls

PHP gives a library no safe way to intercept every HTTP client, so — like the Go
and Java kits — the kit is told about outbound calls explicitly. This is the only
way the network and retry-storm meters learn anything:

```php
$startedAt = microtime(true);
$response = $client->get($url);
\Boosthis\Boosthis::recordOutbound('api.stripe.com', (microtime(true) - $startedAt) * 1000);
```

Nothing about the URL, the status or the payload is recorded — only the host, the
duration, and whether it stalled.

When using native cURL, hand the completed handle to the kit instead. cURL
exposes whether a connection was newly opened and its separate TCP/TLS setup
phases, so this also supplies the Data Distance reading:

```php
$ch = curl_init($url);
curl_exec($ch);
\Boosthis\Boosthis::recordCurl($ch);
```

### Background jobs and queue workers

Start the kit during worker bootstrap even when the process has no HTTP port,
then wrap a job run:

```php
\Boosthis\Boosthis::startWorker();
$done = \Boosthis\Boosthis::beginJob('sendDigest', [
    'queuedAtMs' => $queuedAtMs, // optional; omit when the queue cannot say
    'attempt' => 2,              // optional, 1-based
]);
try {
    run_the_job();
    $done(false);
} catch (\Throwable $error) {
    $done(true);
    throw $error;
}
```

For synchronous callbacks, `Boosthis::trackJob(string $name, callable $job,
array $options = [])` is the shorter equivalent. Laravel queue workers are
attached automatically when `Boosthis::start()` can see Laravel's event
dispatcher; unusual bootstraps can call
`Boosthis::attachLaravelQueue(object $events)` explicitly. Job arguments,
payloads, queue names and job names are never uploaded—only bounded
cardinalities, counts and durations.

### Held-open connections

PHP-FPM does not expose its web-server-owned socket, so this reading stays
absent there. Application servers that own WebSockets or event streams can mark
their real lifecycle explicitly:

```php
$connection = \Boosthis\Boosthis::openConnection('/events', [
    'flowMeasurable' => true, // false/default when only transport bytes are known
]);
\Boosthis\Boosthis::connectionActivity($connection, messages: 1, backlog: 0);
\Boosthis\Boosthis::closeConnection($connection); // application clean close
// Or: \Boosthis\Boosthis::dropConnection($connection);
```

The endpoint is folded in memory and never uploaded. Do not pass payloads,
headers, addresses, or visitor identifiers; the API accepts none of them.

### Joining a request to the service behind it

```php
$headers = \Boosthis\Boosthis::traceHeaders();
```

Attach those to an outbound call and the dashboard can show one trace across
both sides.

## The 192-rule PHP checklist

The rule book lives entirely offline in this package (`src/Checklist.php`) —
192 rules across three categories. Browse the full title, when-to-apply text and
evidence for any rule with the MCP tools below (`boosthis.list_rules` /
`boosthis.get_rule`).

**Case study (28)** — patterns distilled from real PHP production incidents:

`php-registration-retry-no-cooldown` ·
`php-registration-idempotent-single-winner` · `php-install-id-reuse` ·
`php-session-cookie-payment-redirect` ·
`php-background-work-needs-scheduler` ·
`php-session-lock-blocks-concurrency` · `php-n-plus-one-eloquent-doctrine` ·
`php-missing-eager-loading` · `php-unbounded-result-set` ·
`php-missing-index-slow-query` · `php-blocking-curl-no-timeout` ·
`php-no-http-connection-reuse` · `php-cache-stampede` ·
`php-serialized-cache-payload-bloat` · `php-sync-mail-http-in-request` ·
`php-output-buffering-misuse` · `php-glob-scandir-hot-path` ·
`php-pdo-emulated-prepares` · `php-unbuffered-mysqli-query` ·
`php-regex-catastrophic-backtracking` · `php-array-in-loop-quadratic` ·
`php-memory-limit-proximity` · `php-client-timeout-not-remote-cancel` ·
`php-self-blocking-bootstrap-flag` · `php-vendored-copy-drifts-from-source` ·
`php-restart-old-process-holds-port` · `php-observer-never-attached` ·
`php-fixed-ms-timing-assert`

**Industry (111)** — well-documented PHP / framework engineering patterns:

`php-laravel-config-not-cached` · `php-laravel-debug-mode-on` ·
`php-laravel-telescope-in-prod` · `php-laravel-queue-worker-memory` ·
`php-laravel-chunk-large-jobs` · `php-laravel-collection-over-query` ·
`php-laravel-cache-config-array-driver` ·
`php-laravel-model-events-in-loop` · `php-symfony-dev-container-in-prod` ·
`php-symfony-no-apcu-metadata-cache` · `php-symfony-missing-http-cache` ·
`php-symfony-serializer-per-request` · `php-wp-autoloaded-options-bloat` ·
`php-wp-uncached-remote-request` · `php-wp-admin-ajax-hot-path` ·
`php-wp-missing-object-cache` · `php-wp-query-in-loop` ·
`php-outbound-retry-storm` · `php-missing-cache-headers` ·
`php-response-compression-missing` · `php-sync-kdf-on-request` ·
`php-logging-eager-string-build` · `php-persistent-connection-misuse` ·
`php-include-path-scanning` · `php-fanout-overload` ·
`php-per-row-write-in-loop` · `php-redis-no-pipelining` ·
`php-hydrate-tracked-models-on-read` · `php-json-decode-unchecked` ·
`php-unguarded-decoded-payload-deref` ·
`php-http-wrapper-swallows-transport-failure` ·
`php-buffer-huge-json-response` · `php-decode-assoc-array-hot-path` ·
`php-datetimezone-rebuilt-per-request` · `php-object-as-array-key` ·
`php-middleware-order-wrong` · `php-cookie-payload-bloat` ·
`php-per-route-timing-not-app-wide` ·
`php-front-controller-one-label-for-every-url` · `php-redirect-loop` ·
`php-server-rendered-markup-hydration-mismatch` ·
`php-sprintf-in-hot-loop` · `php-array-function-pipeline-per-row` ·
`php-lazy-loading-not-disabled` · `php-always-eager-relations` ·
`php-result-sets-and-handles-held-too-long` ·
`php-descriptor-leak-in-long-running-worker` ·
`php-render-blocking-scripts-in-head` ·
`php-third-party-tags-block-first-paint` ·
`php-render-blocking-stylesheets` ·
`php-credential-change-callback-one-shot-flag` ·
`php-error-signatures-not-grouped` · `php-template-row-fields-unguarded` ·
`php-summary-totals-on-nullable-data` ·
`php-iterating-a-value-that-is-not-a-list` · `php-cut-vertex-middleware` ·
`php-partial-failure-takes-whole-page` · `php-huge-server-rendered-dom` ·
`php-typeahead-query-unbounded` · `php-per-phase-log-lines` ·
`php-first-or-create-race` · `php-deep-clone-on-request-path` ·
`php-image-processing-in-request` · `php-shell-out-on-request-path` ·
`php-password-hash-cost-on-request` ·
`php-db-error-text-leaked-to-response` ·
`php-singleton-captures-request-state` ·
`php-doctrine-hydrate-then-filter` · `php-handler-swallows-into-200` ·
`php-download-read-into-memory` · `php-retry-without-backoff-jitter` ·
`php-string-concat-in-loop` · `php-webfont-blocking-render` ·
`php-no-next-navigation-hints` · `php-render-full-table-on-first-view` ·
`php-images-emitted-at-full-size` · `php-upload-read-into-memory` ·
`php-image-decode-memory-bomb` · `php-no-dimensions-on-emitted-media` ·
`php-http-client-not-centrally-configured` ·
`php-pii-not-scrubbed-before-export` ·
`php-pii-in-trace-and-metric-labels` · `php-secrets-in-logs` ·
`php-stream-clients-have-no-connect-timeout` ·
`php-lookup-table-not-built-for-joins` · `php-mass-assignment-wide-open` ·
`php-eval-or-generated-include-at-runtime` ·
`php-no-fragment-cache-for-repeated-partials` ·
`php-queue-job-not-idempotent` · `php-broadcast-inside-write-path` ·
`php-migrations-from-a-request` · `php-count-per-row` ·
`php-cached-objects-break-across-deploys` ·
`php-global-middleware-stack-bloat` ·
`php-static-state-leaks-across-requests` · `php-fatal-error-unreported`

**Operational (53)** — production hygiene and runtime configuration:

`php-fpm-pool-sizing` · `php-fpm-pm-mode-mismatch` · `php-fpm-slowlog-off` ·
`php-opcache-disabled-or-misconfigured` ·
`php-opcache-validate-timestamps-prod` · `php-realpath-cache-too-small` ·
`php-composer-autoloader-not-optimized` · `php-xdebug-loaded-in-prod` ·
`php-file-cache-session-on-network-storage` ·
`php-max-execution-time-overrun` · `php-error-display-on-in-prod` ·
`php-no-page-cache` · `php-static-assets-served-by-php` ·
`php-fpm-metrics-not-exported` · `php-boosthis-not-mounted-in-mcp` ·
`php-unbounded-request-body-size` · `php-db-statement-no-timeout` ·
`php-jit-and-modern-runtime` · `php-fpm-worker-memory-not-monitored` ·
`php-heavy-health-check` · `php-drop-correlation-headers-at-proxy` ·
`php-post-max-size-truncation-invisible` ·
`php-no-per-release-perf-comparison` · `php-db-transaction-left-open` ·
`php-recompress-static-assets-per-request` ·
`php-shared-install-id-baked-into-config` ·
`php-max-children-raised-to-hide-blocking` ·
`php-worker-cold-start-budget` · `php-endpoint-latency-budget` ·
`php-unreachable-registered-routes` · `php-funnel-routes-not-prioritised` ·
`php-highest-leverage-page-first` · `php-composer-dependency-bloat` ·
`php-inline-gzip-of-dynamic-payloads` ·
`php-persistent-connection-not-capped` ·
`php-debug-packages-shipped-to-prod` · `php-edge-limits-unset` ·
`php-no-asset-cache-headers` · `php-optimise-by-total-time-not-worst-case` ·
`php-one-latency-budget-for-every-endpoint` ·
`php-single-process-batch-work` · `php-framework-auto-reload-in-prod` ·
`php-queue-wait-time-not-watched` · `php-log-level-debug-in-prod` ·
`php-composer-audit-not-in-ci` · `php-secrets-committed-in-config`

The prescriptive fix text for every rule is server-only and is served one
rule at a time — the kit ships detection metadata only.

## The MCP server

Your AI assistant can read this project's rules and its live measurements:

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

## Configuration

Without the project key and the request/bubble wiring above, the kit measures
nothing. The remaining configuration switches are optional.

The floating bubble is on by default in every environment, including
production. `BOOSTHIS_BUBBLE` set to 0 hides it and the kit carries on
measuring; `BOOSTHIS_DISABLED` stops the kit altogether. Each name is read
from the environment where this runtime has one, and otherwise from a value
of the same name on the host.

| Variable | What it does |
| --- | --- |
| `BOOSTHIS_PROJECT_KEY` | Your project key. Without it the kit stays silent. |
| `BOOSTHIS_APP_NAME` | Overrides the detected application name. |
| `BOOSTHIS_INSTALL_ID` | Pins the install id — useful across a fleet of identical containers. |
| `BOOSTHIS_DISABLED=1` | Turns the whole kit off, permanently and immediately. |
| `BOOSTHIS_BUBBLE` | Primary bubble directive: `1`/`true`/`yes`/`on` shows it; `0`/`false`/`no`/`off` hides it (trimmed, case-insensitive). |
| `BOOSTHIS_NO_BUBBLE=1` | Legacy hide alias. Loses to `BOOSTHIS_BUBBLE`. |
| `BOOSTHIS_FORCE_BUBBLE=1` | Legacy show alias. Loses to `BOOSTHIS_BUBBLE` and `BOOSTHIS_NO_BUBBLE`. |
| `BOOSTHIS_STATE_DIR` | Where cross-request state is kept. Defaults to a per-project directory under the system temp dir. |
| `BOOSTHIS_TURBO=0` | Skips the fast first-run upload ramp. |
| `BOOSTHIS_DEP_INVENTORY=1` | Opt in to sending a count and names of your Composer dependencies, so patch-lag can be measured. |
| `BOOSTHIS_API_BASE` | Points the kit at a different Boosthis endpoint. |

### State between requests

PHP forgets everything at the end of a request, so the kit keeps its counters in
APCu when it is available and in a per-project directory otherwise. If neither is
writable, the kit still measures each request — it just cannot build the meters
that need a window of history. `\Boosthis\Boosthis::connectionStatus()` tells you
which backing store you got.

## Turning it off, and removing your data

```php
\Boosthis\Boosthis::forget();
```

That hands the install's delete token back to the server, which erases the
install and everything it uploaded, then clears every local file the kit wrote.

## What is measured, and what is never sent

Sent: route **labels** with ids collapsed (`GET /orders/:id`), durations, counts,
error class names, a hashed crash signature, the top stack frame with paths cut
back to file names, and the meter values below.

Never sent: request or response bodies, query strings, headers, cookie names or
values, session contents, SQL, error messages, stack frame arguments, source
code, IP addresses, or anything typed by one of your users.

Meters the PHP kit reports when it can measure them honestly: request confidence,
budget, baseline drift, network, idle, latency floor, load deflection, dev
posture, cookie exposure, access pressure, swallowed errors, leak watch, process
CPU, CPU entitlement and throttling, context switching, worker restarts and
imbalance, system load, resident growth, container pressure, OPcache health, memory
headroom, execution headroom, GC cycles, realpath cache saturation, PHP-FPM
worker saturation, JIT buffer pressure, include churn, interned strings, boot
cost, queue time, preload coverage, crash-free rate, reliability, file-descriptor
saturation and heap fragmentation. A meter that has not seen enough traffic yet
shows as warming up rather than as a score.

## Version

`1.0.70` — A band with reversed, equal or non-finite good and poor constants
now raises a named error instead of producing a score. Memory and GC readings
also carry a rating, with a score when graded.

`1.0.69` — This README no longer tells you to fetch the package from Packagist.
Boosthis kits are not published on Packagist, npm or any other public package
registry, and the README now says so where you meet the install step: a
registry copy would be an ungated copy of a kit that is meant to arrive
against a project key. Nothing of ours holds the name `boosthis/boosthis-php`
on Packagist, so the old bare Composer requirement pointed at whatever a
stranger might publish there. In its place is how the kit actually arrives —
over your Boosthis connection, written into your project, then wired in with
the Composer path repository the setup guide has always used, which downloads
nothing.

`1.0.67` — The README now states, in the kit's own words, what Boosthis names as a part
when your app runs outside a supported framework: the request itself, taken
from the path it was served at. A job, or one step inside a page, is still
yours to measure with trackPerf. Nothing about the kit's behaviour changed —
this kit has always measured the request it is in — but the condition was only
written down where we could read it, so a conditional yes read as an
unconditional one.

`1.0.66` — One rule now decides what a part of the app is called — a route here, a
screen on the device kits — and every Boosthis kit takes it from the same
source instead of carrying its own copy of the idea. The limit is 100
characters, the same bound the server enforces, so this kit never sends a
name the wire would quietly reshape. A longer name is REFUSED rather than
shortened: two routes whose names differ only past that point used to be
stored as one row with a pooled timing, under a name neither developer
wrote. A name the rule refuses is counted, and where you wrote it by hand
the kit says so once through the warning channel it already uses, with the
reason and an acceptable form — never echoing the rejected text back. The
inventory's 120-character rule, a 100-character truncation at upload and a
second privacy screen run over the already-truncated text are all retired
for the one rule — a name carrying a value past the bound could pass a
screen that never saw it.

`1.0.65` — Adds the request-path, error and timer readings PHP can honestly
take at its request boundary. Rates use the completed request's own window;
timer growth, response backpressure, exception churn and process uptime are
explicitly marked unavailable rather than guessed across PHP's torn-down
request world.

`1.0.64` — Adds the processor and PHP-FPM worker readings: cgroup CPU
entitlement and current-window throttling, process context switching, worker
restarts and traffic imbalance. Cumulative kernel counters are anchored in the
existing cross-request store, reset safely, and withheld until their required
windows have filled.

`1.0.63` — The in-app page can now tell a meter that never started from one
that is still warming up. A meter whose collector never ran used to render in
the same grey 'pending' style as one that is a minute from a reading, so a
developer was told to wait for a number that was never coming; the page now has
its own 'never started' style for that state, and the 'not registered' notice
says how many meters never started instead of claiming the kit is measuring
locally.

`1.0.62` — The in-app panel now warms the new memory and collection rows PHP
can actually fill, matching the web dashboard while readings arrive. Peak
memory and resident-memory growth remain present-only because their Linux
process sources may not exist on another host; the panel does not promise rows
that could warm forever.

`1.0.61` — Memory and garbage-collector readings now share the agreed names,
units and bands: peak and resident-memory trend, memory per request, heap
headroom and fragmentation, collection tax and pause tail, generation balance
and code-cache pressure. Trends now carry the standard error used to judge
them and abstain inside it. When PHP cannot take one of these readings, the kit
declares the reason rather than showing a zero or leaving it blank.

`1.0.60` — The in-app panel now warms the Disk Pressure tile, so it shows the
same reading the dashboard promises for a PHP project while the first snapshot
is being taken. The kit now reports disk pressure, storage speed and storage
failures for its state-directory volume at this container or whole-machine
scope. It reports I/O pressure, page faults, file-descriptor mix and
descriptor saturation for this process, and system load for the whole machine.
If every storage probe is blocked, the dashboard shows that reason for storage
speed rather than a zero.

`1.0.59` — Your router is now asked for its whole route list when a report is
taken, so the app map can show routes traffic has never reached — marked “not
seen”, never “dead” or “unused”. Slim and Laravel answer. A router this kit
cannot read reports that it could not be read, rather than an empty list —
which would say this app has no routes at all. Route templates only, no source
file is ever read, and every label is redacted by the same rules a request path
is. Off wherever `BOOSTHIS_ROUTE_LIST=0`.

`1.0.58` — The README that ships beside this kit now states the version you
are actually running, and lists every release in one place. The version line
had been left at 1.0.54, and the releases were written down in two lists kept
in step by hand — a short one near the top that stopped at 1.0.54 and a longer
one at the end that stopped at 1.0.52 — so three releases were described
nowhere in the file shipped with the code. There is now a single list, at the
end, and it is complete. Nothing this kit measures, sends or shows has changed
— the code is identical to 1.0.57. It is a release rather than a quiet
correction because a corrected file only reaches an installed kit when the
version moves; at an unchanged version every consumer is told it is already
current, and the fix never arrives.

`1.0.57` — The kit's own pages now say where your scheduled jobs live. Until
now the in-app page at /_boosthis and the local status page at
/_boosthis/status listed meters and said nothing about jobs at all, which
reads — to a developer and to an AI reading the page — as a report that this
app has none. Both pages now carry a Scheduled jobs line that is always shown,
and for this kit it states the real limit: this kit reports no named job at
all, so background work reaches Boosthis only as the combined background-work
meter and your dashboard's scheduled-jobs list stays empty however many jobs
this app runs. That is this kit, not your app — and the line says so rather
than pointing you at a call this kit does not export.

`1.0.56` — New: database readings. Hand your PDO connection to
Boosthis::watchPdo once, at start-up, and three readings appear. Database Work
shows how much of a request was spent waiting on the database and which single
statement was issued most often inside one request — the repeated query that
is usually a loop nobody meant to write. Database Sequencing shows queries
issued one after another that could have run at the same time. Database Rows
shows how much came back. All three are banked before your response completes,
because PHP has no phase afterwards in which to finish the accounting. The kit
also counts database libraries it cannot watch — mysqli and pgsql used
directly, rather than through PDO — and names PDO as what it does watch, so an
empty reading tells you which it is. There is no pool reading: under php-fpm
and mod_php a request holds its own connection for its own lifetime, so there
is no pool to occupy.

`1.0.55` — The page and the floating panel this kit serves inside your app now
fit a phone. The page's two tables keep their full width inside their own
scrolling box — with a line underneath that says so when there is more to the
right — instead of being squeezed to one word a line or pushed past an edge
the page could not scroll back. The panel is capped against the screen width,
and shades its top and bottom edge when there is more to scroll. Nothing
measured changed: no reading was added, removed or reworded.

`1.0.54` — The package metadata now agrees with the runtime and hosted
manifest version, so Composer installs and hosted MCP updates cannot disagree.

`1.0.53` — The page this kit serves inside your app now says where to get the
credentials that read this install's measurements from outside it — the
“Connect AI” card on this project's row in your Boosthis dashboard — and why
the page itself shows the install id and never a token.

`1.0.52` — This kit no longer reads the install identity it saved earlier
before checking the one you pin, in code or in the environment. The identity it
picks is unchanged — a pinned id already won — but the order the code reads in
now matches the order it promises, so saved state cannot come to outrank what
you pinned. The answer it sends about whether an identity came back from disk
is now decided at the same rung, where the store is genuinely consulted.
Nothing measured changed.

`1.0.51` — The kit now keeps its generated install identity across restarts
when configured, home or service-scoped scratch state is available, and reports
the storage rung honestly when consent is registered.

`1.0.50` — — Your project's promises now reach the panel this kit serves inside
your app. Each one shows the wording you wrote and where it stands with
Boosthis, in the same two words the dashboard uses. The section stays hidden
when the project has none and when they could not be read, so the page shows
nothing rather than an error. Nothing measured changed.

`1.0.48` — An app that calls a model this kit does not recognise no longer
looks like an app with no AI in it. Hand completed cURL calls to
`recordCurl()`, or Guzzle and other client calls to `recordAiCall()`: an
inference-shaped POST to an unknown host appears as a count-only unclassified
reading, and naming up to eight endpoints with the `aiEndpoints` start option
or `BOOSTHIS_AI_ENDPOINTS` turns later calls into a full reading. Each address
is compared in-process and never sent.

`1.0.47` — Adds Outside Services and Data Distance from explicit
`recordOutbound()` / `recordCurl()` observations, including corrected closed
service-kind codes; adds Background work from `beginJob()` / `trackJob()` or
Laravel queue events; and adds Live Connections for application-owned realtime
transports when the developer calls `openConnection()`,
`connectionActivity()`, and `closeConnection()` / `dropConnection()`.

`1.0.46` — The panel inside your app now uses the same words for a scoreless
row as your dashboard does. A row that is deliberately never graded reads “no
score by design”, a reading this host cannot take reads “not available here”,
and only a row that really is still gathering reads “pending”. Nothing
measured changed.

`1.0.45` — Three different silences used to share one word. A rating of
“pending” promises a score is coming, and refusal honesty was saying it too —
an axis that reports its counts and deliberately never grades them, because a
refusal can be right or wrong and header presence cannot tell which. It now
reads “no score by design”, and a reading this host cannot take at all reads
“not available here”. Nothing measured changed.

`1.0.44` — A fast route can no longer be reported as steady. Requests are
timed in whole milliseconds, so a handler that finishes in under half a
millisecond records as 0ms, and a route whose earlier window is all zeros
leaves the baseline axis nothing to divide by. Those routes were counted as
examined and then dropped in silence, so an app whose sub-millisecond route
suddenly took 100ms still scored 100 and read “steady”. A route that cannot be
divided by is now reported as too fast to compare, the steady count names only
the routes actually compared, and an app where every route was too fast says
it cannot judge rather than scoring full marks. Routes slow enough to compare
are judged exactly as before.

`1.0.43` — Held-open streaming and upgraded connections remain in flight but
no longer distort request-duration axes, and exclusions stay visible. Realpath
cache saturation now preserves precision and states its bytes; execution
headroom states its limit and worst time so overruns above 100% are bounded;
process CPU states its core ceiling. Unreadable figures are omitted rather
than guessed.

`1.0.42` — The latency floor is no longer judged against a bar built for web pages.
That axis takes the worst ten-second window's p95 request latency, so one bad
window is never averaged away — but it was then scored on the shared
page-interaction band, which calls 500ms good. A server whose worst window ran
at a p95 of 401ms therefore scored full marks, and four tenths of a second at
the 95th percentile is not a smooth server. The axis now carries its own
server-request band — good at 200ms, poor at 1000ms — declared beside the
axis with the reason written next to it rather than borrowed from a different
kind of measurement. What the meter measures is unchanged; this changes only
what the score says about it.

`1.0.41` — The kit no longer writes PHP warnings into your application's
error log. Two places that read a recorded request — the per-route series
and the latency-floor windows — assumed every sample carried a route label
and a timestamp. A sample that carried neither made PHP emit an "Undefined
array key" warning for each one, so a busy project could find its own log
filled with notices from us; on a host that turns warnings into exceptions
that is far worse than a missing measurement. Both places now skip a sample
they cannot place instead of reading it as route `""` or as the epoch, which
also stops such a sample being counted into a window it does not belong to.
Scores and axes are unchanged for any sample that was already complete. This
release also corrects the heap-fragmentation meter, which scored
allocator-held-but-unused memory as a fault — a script holding a megabyte
rated worse than one at its memory_limit. It now measures the empty part of
what the allocator holds against memory_limit, and says it cannot rate the
reading when the limit is unlimited.

`1.0.40` — An install id you set in the environment can no longer be undone
by a saved rotation. PHP already read BOOSTHIS_INSTALL_ID first, which is
why it kept its identity across restarts — but a rotation recorded against
an older id was applied whichever id had just been chosen, so a pinned
service could still be filed under a different install. A rotation is now
resumed only when it belongs to the id actually in force, and an id that is
not a UUID is refused by name rather than used. When the kit cannot keep its
identity on disk at all, it now says so in one line at startup, naming the
reason, so the condition is visible before duplicate installs appear rather
than inferred from them afterwards.

`1.0.39` — A request that ends before the usual upload window now sends
what it measured. PHP has no phase after the response, so the kit flushes
from a shutdown function instead — which is the real end of the request —
rather than holding a reading for a next request that may reach a different
process entirely. A CLI script or a short-lived worker that registered and
reported nothing now lands its measurements.

`1.0.38` — A trace that crosses into this runtime now nests the whole way
through. Each measured leg of an action records which leg called it, and
passes its own identity on to the next service it calls, so a full-stack
action draws as a waterfall instead of going flat at this hop. The identifiers
are random, carry no request data, and nothing new is uploaded.

`1.0.37` — One new rule and nothing else: a page whose query count rises
with the size of the tenant. A lazy relation touched inside a Blade loop,
or a whole-history count in the header, costs nothing against seed data.
The rule says to count statements with `DB::listen` at two tenant sizes,
eager-load with `with()` and `withCount()`, switch on
`preventLazyLoading` in development, and assert the count in a feature
test. Rule book only: no new meter, no new upload.

`1.0.36` — Requests this kit already timed now reach your project page. They
upload in small batches (100 at most) under the same sharing permission,
privacy guard and credential as the snapshot it already sent, and are dropped
rather than queued for ever when the server cannot be reached. Nothing new is
measured — a project that could only ever say "registered, no measurements
yet" now fills in its own timings.

`1.0.35` — Six new AI-provider rules flag missing Guzzle/cURL connection and
total timeouts, retries that ignore `Retry-After`, serial calls instead of
Guzzle async promises or `curl_multi`, duplicate prompts without a
request-scoped digest, unstable JSON prefixes that defeat prompt caching, and
streams missing `stream_options.include_usage` or their terminal usage chunk.
Rule book only: nothing new is measured and nothing new is sent.

`1.0.34` — A deliberately cut project credential now makes the installed kit
stop and report the server's non-active status immediately. An ordinary
unauthorized response or an unreachable server still preserves the cached
entitlement so recoverable installs are not stranded.

`1.0.33` — Six new rules about calling an outside service: a call with no
deadline, retries with no cap, independent calls made one after another
instead of together, a vendor call left on the visitor's own path, and the two
background-job versions of the same mistake — a job with no time limit, and
one that retries forever with nowhere to fail to. Each names the fix, and is
written against PHP's own mechanisms — cURL and Guzzle timeouts, a capped
retry, `curl_multi` and a failed-jobs table. Rule book only: nothing new is
measured and nothing new is sent.

`1.0.32` — Full-stack traces are now drawn where the work really happened.
Every span this kit recorded claimed to start at the very beginning of the
action, so each one was drawn at the left edge of the waterfall — and a long
one could out-rank the layer the action really began on, making the trace name
the wrong layer as the start and report that layer's time as the end-to-end
time. A span now carries the offset the measured work really began at.

`1.0.31` — Your install's access tokens are no longer left where other
accounts on the same machine can read them. This kit keeps the tokens the
server issues in a state file so a restart does not orphan the install, and
that file used to be written with whatever permissions the machine's default
allowed — on a shared server, a CI runner or a mounted container volume, that
let any other local account read them and then send or erase this project's
measurements. The state folder is now created private to your account, one an
older kit left open is tightened along with the files already inside it, and
if it cannot be made private the kit keeps its state in memory for that run
rather than write tokens somewhere others can read. Nothing about what is
measured or sent has changed.

`1.0.30` — Your project now tells Boosthis about the problems it keeps hitting. Until
now this kit found them, showed you what it found and then forgot: nothing
recurring ever left the app, so a crash was the only thing Boosthis could
learn from. A problem seen more than once is now reported, and once a rule
has been served for it, whether the measurement actually got better
afterwards. What travels is a one-way signature and nothing else: the kind
of problem from a fixed published list, plus how bad it is and how often it
came back, both as coarse bands. No route name, no value, no code, no
message, nothing free-form. It rides the consent and registration you
already gave — an install that is not registered, or that has not
consented, sends nothing at all — and it can never slow or crash your app.
Under PHP-FPM and LiteSpeed the report leaves after the page has, as every
other outbound call does; on a setup where PHP cannot hand the page back
early, the report is written without waiting for a reply, so a slow Boosthis
can never be added to somebody's page load. The status page is unchanged.

`1.0.29` — Corrects the published breakdown of the offline PHP rule book. The
179-rule total in the heading was right, but the sentence beneath it said 170
rules, and the per-category headings said 96 industry and 46 operational —
together describing a smaller book than the one that ships. The real split is 28
case-study, 98 industry and 53 operational. Nothing the kit measures or checks
has changed; the total and every category heading are now derived from
`src/Checklist.php` by a release check. (`1.0.28` is deliberately skipped: a
build reporting that version is already in the field, and reusing the number
would put two different payloads behind one version.)

`1.0.27` — Your project now says WHERE IT RUNS. Alongside the language and kit
version an install already reported, it now names the hosting platform it is
running on, that platform's region where the platform declares one, and whether
this is production, a preview, or a development run. Previews are marked
wherever the project's numbers appear, so a throwaway preview's slow figures can
never be mistaken for the real app's. The kit's own status page shows the same
thing, including when it cannot reach Boosthis. It is recognition, never
guesswork: only what the platform publishes about itself is read, only short
words from fixed lists are sent (no addresses, no configuration, nothing
free-form), a platform we do not know is shown as unrecognised rather than
guessed from a similar one, and a platform that declares nothing says so instead
of leaving a blank. It refreshes when a project moves.

`1.0.26` — At startup, PHP now says one line when OPcache, PHP-FPM status, or
Linux `/proc/self` cannot be read. The line names the affected meters and makes
clear that every other Boosthis reading continues normally.

`1.0.25` — a refused upload batch is now recorded and shown. Non-2xx replies
and requests that never answer are classified (credentials rejected, batch
rejected, Boosthis failed to store it, no answer), the status page headline
says uploads are not getting through, and the page and panel show the last
failure and how many uploads have been lost.

`1.0.24` — The Boosthis button now appears before the first successful
check-in. Until now it stayed hidden until Boosthis had confirmed the install,
so an app that could not reach Boosthis at all — a preview with no outbound
network, a rejected or rotated project key, a reinstall — showed nothing and
looked broken. The button now draws straight away and opens on the kit's
existing “Not registered yet” notice, with the reason when one is known.
Everything that hid the button before still hides it: the kill switch, the
bubble-off directive, the in-code option set false, a tampered install and a
lapsed offline grace window. Nothing is measured or uploaded before the
install is confirmed — that is unchanged.

`1.0.23` — Adds four checklist rules: connect to the address you actually
validated; do not take over the host's logging or exception hooks; do not
re-enter a non-reentrant lock; and cancel an abandoned attempt before retrying.
This is a rule-book update only; no meter, score, or wire data changes.

`1.0.22` — Adds the repeatedWork meter: it notices when your app makes the SAME
call more than once inside a single request — the n+1 query loop and the
identical downstream GET the rule book already warns about. It reports the worst
number of identical calls in one request, how many watched requests contained a
repeat, the watched-request total, and the share of request time those repeats
accounted for. Identity is worked out inside this kit by hashing the call target
and its arguments: the query text, the URL and the argument values never leave
your app — only counts and durations are uploaded. It watches
Boosthis::trackCall calls and outbound HTTP; plain Boosthis::trackPerf timing
blocks are deliberately NOT counted, because two blocks with the same label may
be doing different work. An app that identifies no calls reports “cannot tell”
rather than a misleading zero, and the meter is additive — it never moves your
Speed score.

`1.0.21` — inactive installs now distinguish missing, deliberately omitted,
revoked, billing-paused, unknown, and otherwise refused project keys.

`1.0.20` — refusal honesty measures presence-only credential/challenge outcomes.

`1.0.19` — the in-app status page now asks Boosthis whether the install is
registered, clearly separates all three answers, always shows the install id,
and only says nothing is being sent when Boosthis confirms that.

`1.0.18` — startup always names the project-key tail and badge visibility before
registration; malformed, quoted, truncated, or Bearer-prefixed keys are refused.

`1.0.17` — the floating bubble now follows one environment-independent
visibility order: absolute stops, tri-state directive, legacy aliases, code
option, then visible by default; failed stop reads hide it and failed ordinary
reads leave it visible.

`1.0.16` — the kit's own panel, in-app page and status page now name which
Boosthis project this app reports to. Boosthis sends the project name and its
short code when the app registers; the kit keeps them beside its other stored
details, so the project is still named after a restart, and says plainly when
Boosthis has not sent a name yet. The "not registered" / "nothing is being
uploaded" notice now appears ABOVE the score instead of below it, and the score
carries a line saying it is measured on this device and is not proof that
anything reached Boosthis.

`1.0.15` — a codebase can hold more than one Boosthis project. This kit now
reads its own project key from `BOOSTHIS_PROJECT_KEY_PHP` before the key passed
in code, so a second app sharing your codebase stops reporting into your first
app's project. Wherever the kit reports its status, it now names where the key
came from and the last four characters of the key. It also says so once at
startup when a per-language key replaces a different one.

`1.0.14` — a failure inside the kit can no longer surface as your app's own
error page. The two addresses the kit answers (`/_boosthis` and
`/_boosthis/panel`) build their response by asking your project's autoloader for
a PSR-7 factory; if that lookup threw, the exception reached your framework's
error handling and your visitor saw your error page. Both are now wrapped, on
PSR-15 and Laravel alike, so the worst case is that the page is not served and
the host keeps the request. The Laravel terminable hook is wrapped too. No
meter, score, or wire change.

`1.0.13` — your app now tells you when the server threw away some of what the
kit sent. An accepted upload used to count as an unqualified success, so rows
the server refused — a route name the privacy guard would not accept, spans
past the 20-span limit, or a snapshot entry the guard refused — vanished with
nothing to show for them. The kit now reads the counts the reply carries,
writes one line to standard error per run for each cause naming the cause and
the fix, and shows a running "Measurements dropped" figure on the kit's own
pages (`/_boosthis` and `/_boosthis/status`) and in the bubble's panel. A
healthy app shows nothing at all, and a server that does not report these
counts behaves exactly as before. Nothing extra is uploaded and nothing is
retried.

`1.0.12` — corrected the rule-count figures quoted in the README and MCP guide
text so they match the checklist source (which is authoritative), and adds one
new case-study check: a test, gate or health probe that hinges on a fixed number
of milliseconds (a `usleep`/`sleep` then assert, or an `hrtime`/`microtime` delta
asserted under N ms) measures the machine, not the code — poll for the condition
to a deadline and assert the operation's own reported duration instead. No meter,
score, or wire change.

`1.0.11` — three new checks in the PHP checklist: a generated or vendored copy
that has drifted from the source it was produced from, a restart that left the
previous process still holding the listen port, and an observer that is
implemented but never attached to anything. Checklist-only — no meter, score or
wire change.

`1.0.10` — adds a standalone status page at `/_boosthis/status` for APIs with no
web UI. It reports whether the kit registered, whether anything is uploading,
and the last accepted upload — local-only, no JavaScript, never shows your
install token. Laravel serves it automatically; PSR-15 serves it when given a
response factory; a plain PHP app wires it with `\Boosthis\StatusPage::serve()`.

`1.0.5` — a copy that already holds credentials now re-asks the server at most
once an hour what the dashboard's full-telemetry switch says, so a change made in
the dashboard reaches an app installed earlier.

`1.0.4` — install-guide accuracy pass, no code change. The guide now documents
every method you can call (finish, trackPerf, traceHeaders, flushNow,
ignoreOutboundHost, isEnabled, snapshot, connectionStatus), every option on
start, the stalled flag on recordOutbound, how to turn full sharing on, and
every environment variable the kit reads. No meter, score or wire change.

`1.0.3` — install-guide fix. The fallback command for minting an install id
printed 32 bare hex characters rather than a UUID, and the server rejects that,
so an install that followed it never appeared on the dashboard. The command now
prints a real UUID v4. No code, meter or wire change.

`1.0.2` — three fixes, all about an install that looks fine and is not. An
install id already held by another copy of the app is now recognised from the
server's already-registered marker and rotated once, instead of filing a
registration that can never upload. A registration that does not go through now
writes exactly one line to standard error naming the cause and the fix, instead
of running silently forever. And the bubble's panel now says when an app is
registered but sharing is switched off. No meter, score or wire change.

`1.0.1` — corrects two warming-up captions. Heap fragmentation and boot cost
need ten sampled requests before they can report (`MIN_FRAG_SAMPLES` /
`MIN_BOOT_SAMPLES`), and the bubble and dashboard now say so instead of
promising a reading after one request. No meter, score or wire change.

`1.0.0` — first stable release. Adds eight meters (internedStrings, bootCost,
queueTime, preloadCoverage, crashFree, reliability, fdSaturation,
heapFragmentation — all additive and display-only) on top of `1.0.0a3`:
per-route timing, crash reporting, the runtime meters above, the in-page bubble,
the 175-rule offline checklist and the MCP server.

## Licence

Apache-2.0.
