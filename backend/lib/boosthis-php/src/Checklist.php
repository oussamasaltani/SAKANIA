<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The Boosthis checklist — PHP edition.
 *
 * A port of Java's Checklist.java (itself parallel to
 * lib/boosthis-py/boosthis/checklist.py and lib/boosthis-go/checklist.go). This
 * pack carries 192 PHP-specific rules covering the pitfalls that actually bite
 * real PHP projects: PHP-FPM pool sizing, OPcache and realpath-cache
 * misconfiguration, Composer autoloading, session locks, N+1 and unbounded
 * queries in Eloquent / Doctrine, blocking I/O in the request path, and the
 * Laravel / Symfony / WordPress ecosystem quirks that dominate production
 * incidents. Each entry mirrors the shape of every other runtime checklist so
 * cross-runtime tooling can treat any rule uniformly:
 *
 *   { id, title, whenToApply, evidence, category, languages }
 *
 * DETECTION METADATA ONLY. This pack ships the stable id, one-line title, the
 * "whenToApply" trigger shape, and plain-text evidence references so matching
 * works fully offline and no user code ever leaves the host. The prescriptive
 * "what to change" guidance (fixTemplate) is the crown-jewel IP and lives
 * SERVER-ONLY in lib/boosthis-fixes, served one rule at a time. There are
 * deliberately NO fixTemplate fields and NO URLs anywhere in this file —
 * evidence entries are human-readable doc references only.
 *
 * PHP DIFFERENCE: PHP has no enum-of-constants + one-immutable-class idiom as
 * ergonomic as Java's, so Category is a native PHP 8.1 backed enum and Entry is
 * a final class with readonly promoted properties. The public surface (the
 * Category cases, the Entry fields, the private rule() helper, and a static
 * all() accessor) matches the Java sibling one-for-one.
 */
/**
 * Rule grouping, parallel to every sibling runtime's Category. A native PHP 8.1
 * backed enum stands in for Java's nested `enum Category`; the string backing
 * values match the case labels used by the Python and Go packs
 * ("case-study" / "industry" / "operational") so cross-runtime tooling reads
 * them uniformly.
 *
 * PHP DIFFERENCE: kept in this file rather than its own src/Category.php because
 * the whole checklist ships as one delegated unit and the enum has no use apart
 * from Entry; the "one class per file" convention is relaxed only for these two
 * checklist-local types, exactly as Java nests them inside Checklist.
 */
enum Category: string
{
    /** Patterns distilled from real PHP prod incidents. */
    case CASE_STUDY = 'case-study';
    /** Well-documented PHP / framework engineering patterns. */
    case INDUSTRY = 'industry';
    /** Production hygiene and deployment/runtime configuration. */
    case OPERATIONAL = 'operational';
}

/**
 * One checklist rule. Detection metadata only — never a fixTemplate.
 *
 * PHP DIFFERENCE: Java's nested `final class Entry` with final fields becomes a
 * final class with readonly promoted properties; the field names (id, title,
 * whenToApply, evidence, category, languages) match the Java sibling one-for-one.
 */
final class Entry
{
    /** Always ["php"] for this pack. Present for cross-runtime symmetry. */
    public readonly array $languages;

    /**
     * @param list<string> $evidence Docs / references. No URLs.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        /** When this rule applies — the code shape or setting that triggers a check. */
        public readonly string $whenToApply,
        public readonly array $evidence,
        public readonly Category $category,
    ) {
        $this->languages = ['php'];
    }
}

final class Checklist
{
    private function __construct()
    {
    }

    /**
     * The 192 PHP rules, built once and cached. Kept as a method (not a class
     * constant) because Entry instances cannot be constant expressions.
     *
     * @var list<Entry>|null
     */
    private static ?array $cache = null;

    /**
     * All PHP checklist rules. Detection metadata only — never a fixTemplate.
     *
     * @return list<Entry>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            // ───────── Case Study (24) — patterns from real PHP prod incidents ─────────
            self::rule(
                'php-registration-retry-no-cooldown',
                'Cool down failed registration/handshake retries — never re-attempt on every flush',
                "A one-time 'first contact' call (install/device registration, consent, enrollment, license activation, first token mint) is lazily retried from a hot path — every upload flush, queue-worker tick, cron pass, or request — until it succeeds, with no cool-down and no special handling for HTTP 429. Symptom: a client that cannot finish registering (offline at setup, pending invite, busy server) silently re-attempts dozens of times an hour; behind a shared proxy or NAT egress the retries drain the server's per-source registration rate bucket and starve OTHER clients' genuine first registrations, so the failure surfaces on a different machine than the bug.",
                Category::CASE_STUDY,
                'Boosthis production incident (Jul 2026) — per-flush consent retries starved a shared per-source registration rate bucket and blocked new installs',
                'Google SRE Book — Handling Overload (retry amplification)'
            ),
            self::rule(
                'php-registration-idempotent-single-winner',
                'First-contact/registration endpoints must be idempotent single-winner',
                "A 'first contact' endpoint — registration, enrollment, activation, or credential mint — persists its result with an upsert (Eloquent updateOrCreate, Doctrine merge, INSERT ... ON DUPLICATE KEY UPDATE, or a read-then-write) so a concurrent double-fire or client retry lets a SECOND request overwrite the credential the FIRST request already issued. Symptom: two registrations race (double-tap, retry after a timeout, at-least-once queue redelivery) and the second silently rotates the credential the client is already holding, leaving a client that registered fine suddenly unauthenticated. Fix shape: single-winner insert (INSERT ... ON CONFLICT DO NOTHING with RETURNING, or insert-and-catch the unique violation) so exactly one caller mints the credential; the loser gets a credential-less success and the client must KEEP its existing credentials on such a response.",
                Category::CASE_STUDY,
                'Boosthis production incident (Jul 2026) — a consent-registration upsert clobbered the already-issued credential when two retried registrations raced; switched to insert-on-conflict-do-nothing single-winner',
                'PostgreSQL docs — INSERT ... ON CONFLICT DO NOTHING / RETURNING'
            ),
            self::rule(
                'php-install-id-reuse',
                'Never reuse a registration/install ID across installs — mint a fresh UUID each time',
                'Host code hard-codes an install/instance identifier, copies one from docs or another app, or persists a single ID (in .env, a config file, or a seeded row) and re-presents it across reinstalls, so the SAME identity is claimed by more than one logical install. Symptom: once an original install is orphaned (consented but its credential was lost), every LATER install presenting that same ID keeps getting a credential-less "you already registered" success forever — a deadlock no server-side lever can break. Rule: mint a FRESH unique identifier per logical install at first run, persist it only for that install, and never seed it from a literal, a config value, another app, or a previous install.',
                Category::CASE_STUDY,
                'Boosthis production incident (Jul 2026) — a persisted-and-reused install ID left every later install stuck on a credential-less success after the first install was orphaned; fix mints a fresh UUID per install',
                'RFC 4122 — UUID uniqueness guarantees'
            ),
            self::rule(
                'php-session-cookie-payment-redirect',
                'Never rely on session cookies surviving a payment/3DS redirect',
                'A payment confirm / return / callback route requires the logged-in session (reads the user via the auth guard or a PHPSESSID-bound session) to finish an order. Symptom: the 3DS / bank / gateway hop is a CROSS-SITE redirect back to your return URL, so the browser drops the SameSite=Lax/Strict session cookie and a customer who really PAID lands on a login page with no order recorded. Rule: payment return/confirm legs must be session-FREE by design — re-verify the payment server-to-server with the provider using the payment/order IDs carried in the return URL or webhook params, give that leg its own rate-limit bucket, and run a background sweep that reconciles paid-but-stranded checkouts.',
                Category::CASE_STUDY,
                'Boosthis production incident (Jul 2026) — a payment confirm leg required the login session; the 3DS cross-site redirect dropped the cookie and stranded a paid customer at the login page; fix verifies server-to-server by ID',
                'MDN — SameSite cookie attribute and cross-site redirect behaviour'
            ),
            self::rule(
                'php-background-work-needs-scheduler',
                'Scheduled/background work must never depend on page visits',
                "Work that MUST happen on a schedule — subscription renewals, data-retention purges, reconciliation sweeps, digest sends, token rotation — is triggered from inside a request handler (an admin dashboard controller, a health-check route, a middleware that 'catches up' overdue jobs) instead of from a real timer. Symptom: as long as someone opens the page the work runs, but the moment nobody visits, renewals silently stop billing and legally-required purges silently never happen, with no error because nothing ran. Rule: anything time-driven needs a genuine scheduler owned by an always-on process — a system cron invoking the framework scheduler, a supervised worker, or a platform scheduled job — never piggybacked on an OPTIONAL page's request handler.",
                Category::CASE_STUDY,
                'Boosthis production incident (Jul 2026) — billing renewals and retention purges ran only inside an admin-page handler, so they silently never fired when nobody opened the page; moved onto an always-on scheduler',
                'PHP manual: cron and long-running processes'
            ),
            self::rule(
                'php-session-lock-blocks-concurrency',
                'Release the session write lock early — a held session file serializes a user\'s concurrent requests',
                'A handler calls session_start() and keeps the default file session open for the whole request while the browser fires several requests at once (parallel XHR, prefetch, a slow long-poll alongside normal navigation). The default files handler holds an exclusive lock on the session file until the script ends or session_write_close() is called, so every OTHER in-flight request for the same user blocks waiting for that lock. Symptom: a single slow endpoint appears to freeze the whole app for that one logged-in user while unrelated users are fine.',
                Category::CASE_STUDY,
                'PHP manual: session locking and session_write_close()',
                'PHP manual: session.save_handler behaviour'
            ),
            self::rule(
                'php-n-plus-one-eloquent-doctrine',
                'Eager-load associations to batch N+1 lazy loads in Eloquent / Doctrine list routes',
                'A list endpoint loads parent models then touches a related association inside the loop (foreach ($orders as $o) { echo $o->customer->name; }) so Eloquent or Doctrine fires one extra SELECT per row. Symptom: the route is O(1) queries on dev seed data but O(N) in production, and its p99 scales linearly with result count. Distinct from the unbounded-result-set rule — that is rows per query, this is query COUNT.',
                Category::CASE_STUDY,
                'Laravel docs — eager loading (with) to avoid N+1',
                'Doctrine ORM docs — fetch strategies and N+1 problem'
            ),
            self::rule(
                'php-missing-eager-loading',
                'Declare which relations a route reads up front instead of lazy-loading them ad hoc',
                'A controller returns models to a view or serializer that touch relations the query never eager-loaded, so the relations resolve one lazy query at a time as the template or transformer walks them. Symptom: the query log shows a burst of tiny related-row SELECTs fired during rendering rather than data access, and enabling strict lazy-loading prevention in dev immediately throws on the offending relation.',
                Category::CASE_STUDY,
                'Laravel docs — preventing lazy loading in development',
                'Doctrine ORM docs — LAZY vs EAGER association mapping'
            ),
            self::rule(
                'php-unbounded-result-set',
                'Cap read queries with LIMIT + pagination — never fetch a whole table',
                'A read query selects rows with no LIMIT or pagination — a raw SELECT *, an Eloquent Model::all() / ->get() over a growing table, or a Doctrine findAll(). Symptom: fine on seed data, but as the table grows the query, hydration, serialization, and JSON payload all scale linearly until one request pulls hundreds of MB into memory and trips memory_limit or max_execution_time.',
                Category::CASE_STUDY,
                'Laravel docs — paginate() / cursor() / chunk()',
                'Doctrine ORM docs — Paginator and setMaxResults'
            ),
            self::rule(
                'php-missing-index-slow-query',
                'Add the index a hot WHERE / ORDER BY / JOIN needs — a full scan grows with the table',
                'A frequently-hit query filters, sorts, or joins on a column with no supporting index (a status flag, a foreign key, a created_at range, a lookup on a slug). Symptom: the query is instant on seed data but its cost climbs linearly as rows accumulate; EXPLAIN shows a full table scan or filesort, and the slow-query log fills with the same statement under load.',
                Category::CASE_STUDY,
                'MySQL docs — how indexes are used / EXPLAIN output',
                'PostgreSQL docs — using EXPLAIN to find sequential scans'
            ),
            self::rule(
                'php-blocking-curl-no-timeout',
                'Set a connect AND request timeout on every outbound cURL / HTTP call',
                'An outbound call uses cURL, Guzzle, file_get_contents on a remote URL, or a stream context without both a connect timeout and a total/read timeout. Symptom: when the upstream hangs the PHP-FPM worker waits — up to default_socket_timeout or forever — and under load every worker in the pool ends up parked on a dead dependency, turning a slow upstream into a full outage with no error to alert on.',
                Category::CASE_STUDY,
                'PHP manual: CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT',
                'PHP manual: default_socket_timeout for stream wrappers'
            ),
            self::rule(
                'php-no-http-connection-reuse',
                'Reuse one HTTP client / cURL handle across calls — don\'t pay a fresh handshake each time',
                'Code creates a new cURL handle (or a fresh Guzzle client) for every outbound call, or opens a new DB link per query, so each request pays DNS + TCP + TLS setup even to the same host. Symptom: an integration that should take tens of milliseconds takes several times longer and the latency comes from connection setup, not the remote service; a curl_multi or a reused persistent handle removes it.',
                Category::CASE_STUDY,
                'PHP manual: cURL handle reuse and curl_multi',
                'Guzzle docs — reusing a client and connection pooling'
            ),
            self::rule(
                'php-cache-stampede',
                'Guard expensive cache rebuilds against a stampede when a hot key expires',
                'An expensive value is stored in the cache and, when the key expires, EVERY concurrent request misses at once and all of them recompute it (the SQL aggregate, the rendered fragment, the remote fetch) simultaneously. Symptom: a periodic latency and load spike that lines up exactly with the cache TTL boundary as dozens of workers rebuild the same value together — a self-inflicted thundering herd on the database or upstream. A lock / lease around the rebuild or early recomputation removes it.',
                Category::CASE_STUDY,
                'Laravel docs — Cache::lock() atomic locks',
                'PHP manual: APCu / opcache concurrency and cache warming'
            ),
            self::rule(
                'php-serialized-cache-payload-bloat',
                'Keep cached objects small — serializing a fat graph makes every cache hit expensive',
                'Code caches whole models or object graphs with PHP serialize() (or stores rendered HTML blobs) where a few scalar fields would do, so each cache read pays to unserialize a large structure and each write re-serializes it. Symptom: a "cache hit" is measurably slower than expected, the cache store fills faster than the working set warrants, and CPU on the read path is dominated by unserialize rather than the work the cache was meant to avoid.',
                Category::CASE_STUDY,
                'PHP manual: serialize / unserialize cost',
                'Laravel docs — caching primitive values vs whole models'
            ),
            self::rule(
                'php-sync-mail-http-in-request',
                'Move mail sends and outbound HTTP off the request path onto a queue',
                'A request handler sends an email, posts to a webhook, calls a third-party API, or generates a PDF inline before returning the response, so the user waits on work the response does not need. Symptom: a route\'s latency tracks a remote SMTP or API round-trip; when that dependency slows, checkout / signup / contact routes slow with it, and a dependency outage turns into user-visible request failures. Dispatch the work to a queued job and return immediately.',
                Category::CASE_STUDY,
                'Laravel docs — queued jobs and queued mail (ShouldQueue)',
                'Symfony Messenger docs — async message handling'
            ),
            self::rule(
                'php-output-buffering-misuse',
                'Don\'t buffer a large response in memory — stream it instead',
                'A handler builds a large export (CSV, report, file download) by concatenating it into a string or an ob_start() buffer before echoing it, holding the whole payload in memory at once. Symptom: a download that grows with the data set pushes the worker toward memory_limit and delays the first byte until the entire body is assembled; streaming the response row by row (a streamed response or flushed chunks) removes both problems.',
                Category::CASE_STUDY,
                'PHP manual: output buffering (ob_start / flush)',
                'Laravel docs — streamed responses / streamed downloads'
            ),
            self::rule(
                'php-glob-scandir-hot-path',
                'Keep glob() / scandir() / directory walks off the request hot path',
                'A request handler scans a directory on every call — glob() over an uploads folder, scandir() to find the newest file, is_file()/file_exists() probing a list of candidate paths, or a recursive directory iterator to build a menu. Symptom: the route\'s latency tracks the directory\'s size and the filesystem\'s stat() cost rather than the work it returns, and it degrades badly on network-mounted or large directories. Precompute or cache the listing instead of walking the filesystem per request.',
                Category::CASE_STUDY,
                'PHP manual: filesystem stat and directory functions',
                'PHP manual: clearstatcache and stat overhead'
            ),
            self::rule(
                'php-pdo-emulated-prepares',
                'Turn off PDO emulated prepares when you rely on real server-side prepares',
                'A PDO connection is left on the default ATTR_EMULATE_PREPARES = true, so the driver interpolates parameters client-side and re-parses the statement string on each execution. Symptom: prepared statements you assumed were parsed once are re-planned every call, integer/boolean bind types silently become strings, and query plans differ from the mysql client; setting emulated prepares off (with the right error mode) gives true server-side prepares.',
                Category::CASE_STUDY,
                'PHP manual: PDO::ATTR_EMULATE_PREPARES',
                'PHP manual: PDO prepared statements and parameter binding'
            ),
            self::rule(
                'php-unbuffered-mysqli-query',
                'Choose buffered vs unbuffered mysqli/PDO queries deliberately — don\'t block the connection by accident',
                'A large result is fetched with an unbuffered query while other work runs, or a huge result is fetched buffered and pulled entirely into memory. With mysqli MYSQLI_USE_RESULT (or PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false) no further query can run on that connection until the whole set is consumed; with the buffered default a giant result is copied into PHP memory at once. Symptom: either "commands out of sync" errors and a stalled connection, or a memory_limit spike on a big SELECT. Pick the mode to match the result size.',
                Category::CASE_STUDY,
                'PHP manual: mysqli buffered vs unbuffered result sets',
                'PHP manual: PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'
            ),
            self::rule(
                'php-regex-catastrophic-backtracking',
                'Block catastrophic backtracking (ReDoS) in preg_* on user-controlled input',
                'A handler validates a query param, header, or form/JSON field with a PCRE pattern containing nested quantifiers ((a+)+, (.*)*) or overlapping alternation. PCRE is a backtracking engine. Symptom: a crafted ~30-character input pegs a PHP-FPM worker for seconds (or trips pcre.backtrack_limit and silently returns false, which the code may misread as "no match"); a handful of such requests exhaust the pool and stall the service.',
                Category::CASE_STUDY,
                'OWASP — Regular expression Denial of Service (ReDoS)',
                'PHP manual: PCRE limits (pcre.backtrack_limit / pcre.recursion_limit)'
            ),
            self::rule(
                'php-array-in-loop-quadratic',
                'Don\'t rebuild an array or search a list inside a loop — that turns O(n) into O(n squared)',
                'A loop appends with array_merge($acc, [$row]) each iteration (copying the whole accumulator every pass), or repeatedly calls in_array() / array_search() against a growing list to test membership. Symptom: an export or import job that should be linear takes minutes on large inputs and the profile is dominated by array copying or linear scans; appending with $acc[] and using an isset() lookup on a keyed map removes it.',
                Category::CASE_STUDY,
                'PHP manual: array copy-on-write and array_merge cost',
                'PHP manual: array key lookup (isset) vs in_array complexity'
            ),
            self::rule(
                'php-memory-limit-proximity',
                'Watch routes that run close to memory_limit — the next byte is a fatal error, not a slow response',
                'A route routinely loads a large data set into memory at once — a full-table hydrate, an in-memory image manipulation, a big spreadsheet parse, or an unbounded array build — and runs near the configured memory_limit. Symptom: the route works until data grows slightly, then dies with "Allowed memory size exhausted" — a fatal 500 with a half-written response, not a graceful slowdown — and the failure is intermittent because it depends on input size. Stream or chunk the data so peak memory stays bounded.',
                Category::CASE_STUDY,
                'PHP manual: memory_limit and memory_get_peak_usage',
                'PHP manual: generators for constant-memory iteration'
            ),
            self::rule(
                'php-client-timeout-not-remote-cancel',
                'A Guzzle/cURL timeout stops YOUR wait, not the remote write — retries of mutations must be idempotent',
                "A handler fires an outbound mutation (POST/PUT/DELETE to a payment gateway, a partner API, an internal write service) with Guzzle, cURL, or a stream context, hits the configured timeout or the caller aborts, and immediately retries treating the timeout as 'it didn't happen'. Symptom: a timeout only tears down YOUR socket — the upstream request often keeps running to completion, so a blind retry double-charges, double-ships, or double-books, and the duplicate surfaces days later in reconciliation rather than at the call site. Rule/Fix shape: send an idempotency key (or dedupe on a natural unique key) so a retried mutation is a no-op server-side; only retry writes that are provably idempotent, and reconcile timeouts by READING the resource's real state before re-issuing.",
                Category::CASE_STUDY,
                'Stripe docs — Idempotent requests (idempotency keys for safe retries)',
                'AWS Builders\' Library — Timeouts, retries and backoff with jitter'
            ),
            self::rule(
                'php-self-blocking-bootstrap-flag',
                'Never gate first-time registration/activation on a flag only that same request can set',
                "First-contact bootstrap (device registration, license activation, first token mint, schema/seed self-install) is guarded by a config value, cache entry, or session flag that is ONLY written once the bootstrap has succeeded — so the guard is meant to say 'already done' but on a fresh process it reads empty and the code must still attempt the work. Symptom: under PHP-FPM/mod_php every request starts with a cold process and no in-memory state, so a bootstrap that short-circuits on an unset-but-expected flag (or waits for another request to have set it) never runs at all, and the feature is silently dead on every node until someone notices. Rule/Fix shape: bootstrap must ATTEMPT the one-time work and make it idempotent (INSERT ... ON CONFLICT DO NOTHING, a persisted marker in shared storage, an advisory lock), never assume a peer request already populated an in-process or per-request flag.",
                Category::CASE_STUDY,
                'PHP manual: PHP-FPM and the shared-nothing request lifecycle',
                'Google SRE Book — avoiding self-inflicted bootstrap deadlocks (circular dependencies)'
            ),

            // ───────── Industry (96) — framework/ecosystem-specific patterns ─────────
            // Laravel
            self::rule(
                'php-laravel-config-not-cached',
                'Cache config, routes, and views in production — don\'t re-parse them per request',
                'A Laravel app runs in production without config:cache, route:cache, event:cache, and view:cache, so every request re-reads and merges all config files, recompiles the route table, and recompiles Blade templates on first hit. Symptom: baseline per-request overhead that has nothing to do with the route\'s own work, and cold latency after every deploy; the cache artifacts collapse that boot cost to a single compiled file.',
                Category::INDUSTRY,
                'Laravel docs — optimization: config, route, and view caching',
                'Laravel docs — deployment optimization (artisan optimize)'
            ),
            self::rule(
                'php-laravel-debug-mode-on',
                'Turn APP_DEBUG off in production — debug mode leaks internals and slows errors',
                'A production deployment runs with APP_DEBUG=true (or APP_ENV left at local). Symptom: every exception renders a full stack trace with environment variables and query bindings to the client — a serious information leak — and the verbose error pipeline is far heavier than a plain 500; the app should run with debug off and errors logged, not displayed.',
                Category::INDUSTRY,
                'Laravel docs — configuration: the debug option',
                'OWASP — improper error handling and information exposure'
            ),
            self::rule(
                'php-laravel-telescope-in-prod',
                'Don\'t run Telescope (or Debugbar) enabled in production',
                'Laravel Telescope or the Debugbar package is installed and enabled in production, recording every request, query, job, and cache operation into storage on the hot path. Symptom: each request pays extra writes and memory to capture debug data nobody reads in prod, storage grows unbounded, and internal details are exposed through the Telescope UI; these tools belong in local/staging only, gated behind environment or an auth gate.',
                Category::INDUSTRY,
                'Laravel Telescope docs — restricting access / production use',
                'Laravel Debugbar docs — disabling in production'
            ),
            self::rule(
                'php-laravel-queue-worker-memory',
                'Give queue workers a memory ceiling and restart them — long-lived PHP leaks',
                'A Laravel queue worker runs without --max-jobs / --max-time / --memory bounds and without queue:restart on deploy, so a single php artisan queue:work process handles thousands of jobs across a growing resident set. Symptom: worker RSS climbs steadily (the framework and jobs hold references across the loop) until the OOM killer or the platform terminates it mid-job; bounding jobs/time/memory and cycling workers keeps memory flat.',
                Category::INDUSTRY,
                'Laravel docs — queue worker options (--max-time, --memory)',
                'Laravel docs — queue:restart after deploy'
            ),
            self::rule(
                'php-laravel-chunk-large-jobs',
                'Iterate large result sets with chunk / lazy / cursor, not a single hydrate',
                'A command or job processes a large table by loading it all at once (Model::all() then a foreach) instead of chunkById(), lazy(), or cursor(). Symptom: the job hydrates every row into memory before doing any work, peaks near memory_limit, and fails once the table outgrows the box — even though the work itself is streamable one batch at a time.',
                Category::INDUSTRY,
                'Laravel docs — chunking results (chunkById, lazy, cursor)',
                'PHP manual: generators and constant-memory iteration'
            ),
            self::rule(
                'php-laravel-collection-over-query',
                'Filter and aggregate in the database, not with Collection methods after ->get()',
                'Code pulls a broad query with ->get() and then filters, sorts, or aggregates the result with Collection methods (->where(), ->filter(), ->sum(), ->sortBy()) in PHP. Symptom: the database returns far more rows than the request needs and PHP does work the query engine could do with an index; pushing the where/order/aggregate into the query builder cuts both the transfer and the CPU.',
                Category::INDUSTRY,
                'Laravel docs — query builder where / aggregates vs collections',
                'Laravel docs — database queries return only needed rows'
            ),
            self::rule(
                'php-laravel-cache-config-array-driver',
                'Use a real cache/session/queue driver in production — not array or sync',
                'A production Laravel app is left on CACHE_DRIVER=array (per-request, discarded), SESSION_DRIVER=file on shared storage, or QUEUE_CONNECTION=sync (jobs run inline in the request). Symptom: the "cache" never persists across requests so every request recomputes, sessions contend on file locks, and "queued" work actually blocks the response; a shared store (Redis/Memcached) and a real queue backend are required in prod.',
                Category::INDUSTRY,
                'Laravel docs — cache, session, and queue drivers',
                'Laravel docs — production configuration'
            ),
            self::rule(
                'php-laravel-model-events-in-loop',
                'Bypass model events / observers when mass-inserting in a loop',
                'A bulk import saves rows one at a time in a loop with Model::create() / ->save(), firing every model event, observer, and cast per row and issuing one INSERT each. Symptom: an import that could be a single batched insert instead runs N round-trips with N event dispatches; using insert() (or disabling events for the batch) turns it into one statement when the per-row hooks are not needed.',
                Category::INDUSTRY,
                'Laravel docs — mass inserts vs Eloquent create',
                'Laravel docs — model events and observers'
            ),
            // Symfony
            self::rule(
                'php-symfony-dev-container-in-prod',
                'Compile and warm the Symfony container for prod — never serve with the dev container or debug on',
                'A Symfony app is served with APP_ENV=dev / APP_DEBUG=1, so the container is rebuilt when files change, the profiler collects data on every request, and the dev error pages expose internals. Symptom: heavy per-request overhead from the profiler and container freshness checks, plus information exposure; production must run APP_ENV=prod with the compiled, warmed container and cache cleared/warmed at deploy.',
                Category::INDUSTRY,
                'Symfony docs — configuring for the prod environment',
                'Symfony docs — deploying and cache warmup'
            ),
            self::rule(
                'php-symfony-no-apcu-metadata-cache',
                'Cache Doctrine metadata and the container in APCu/OPcache — don\'t parse mappings each request',
                'A Symfony/Doctrine app in production has no APCu (or PHP-file) cache configured for Doctrine ORM metadata and query/result caches, so entity mappings and DQL parsing are recomputed on every request. Symptom: a fixed per-request tax reflecting annotation/attribute parsing and DQL compilation rather than the query itself; wiring the metadata and query caches to a persistent pool removes it.',
                Category::INDUSTRY,
                'Doctrine ORM docs — metadata, query, and result caches',
                'Symfony docs — cache pools (APCu / system cache)'
            ),
            self::rule(
                'php-symfony-missing-http-cache',
                'Send HTTP cache headers / use the Symfony HTTP cache for cacheable responses',
                'A Symfony controller returns public, non-personalized content (a catalog page, reference data, a rendered fragment) with no Cache-Control, ETag, or the reverse-proxy/ESI HTTP cache in front. Symptom: identical responses are recomputed for every client and CDN because nothing is marked cacheable; setting response cache headers (or fragment/ESI caching) offloads the repeat work. Do NOT apply to per-user or rapidly-changing responses.',
                Category::INDUSTRY,
                'Symfony docs — HTTP cache and Response cache headers',
                'MDN — HTTP caching (Cache-Control, ETag)'
            ),
            self::rule(
                'php-symfony-serializer-per-request',
                'Reuse the configured Serializer / normalizers — don\'t rebuild them per request',
                'Code constructs a new Symfony Serializer (or a fresh set of normalizers/encoders) on each request instead of pulling the shared service from the container. Symptom: needless setup and lost per-type normalizer caching on every serialization route; injecting the container-managed serializer service reuses the warmed instance.',
                Category::INDUSTRY,
                'Symfony docs — the Serializer as a shared service',
                'Symfony docs — dependency injection and service reuse'
            ),
            // WordPress
            self::rule(
                'php-wp-autoloaded-options-bloat',
                'Keep autoloaded options small — a bloated wp_options autoload runs on every page',
                'Plugins or theme code store large or numerous values in wp_options with autoload set to yes, so WordPress loads the entire autoload set into memory on EVERY request via the alloptions cache. Symptom: an oversized alloptions blob (transients that should not autoload, serialized settings, leftover data from removed plugins) inflates baseline memory and slows the initial query on every page; auditing autoloaded option size and turning off autoload for large values removes it.',
                Category::INDUSTRY,
                'WordPress developer docs — autoloaded options and alloptions',
                'WordPress developer docs — the Options API'
            ),
            self::rule(
                'php-wp-uncached-remote-request',
                'Cache wp_remote_get results in a transient — don\'t call an external API on every page load',
                'Theme or plugin code calls wp_remote_get / wp_remote_post (a feed, a weather widget, a license check, an analytics beacon) on a front-end template or an init hook with no transient caching and no timeout tuning. Symptom: every page view blocks on a remote round-trip, so a slow or down third party makes the whole site slow or unreachable; caching the result in a transient with a sane TTL and a short timeout removes the per-view dependency.',
                Category::INDUSTRY,
                'WordPress developer docs — HTTP API (wp_remote_get)',
                'WordPress developer docs — Transients API'
            ),
            self::rule(
                'php-wp-admin-ajax-hot-path',
                'Keep admin-ajax.php / REST handlers lean — they run the full WordPress bootstrap',
                'Frequent front-end polling or autosave hits admin-ajax.php (or a REST route) whose handler runs heavy queries, loads all plugins, and bootstraps WordPress on each call. Symptom: a heartbeat or live-search feature quietly drives a steady stream of full-bootstrap requests, each doing real query work, so idle pages generate constant database load; trimming the handler, caching its result, and slowing the poll cadence removes it.',
                Category::INDUSTRY,
                'WordPress developer docs — AJAX in plugins (admin-ajax)',
                'WordPress developer docs — Heartbeat API cadence'
            ),
            self::rule(
                'php-wp-missing-object-cache',
                'Install a persistent object cache — without one WordPress re-queries every request',
                'A WordPress site with meaningful traffic runs without a persistent object cache backend (Redis or Memcached drop-in), so the object cache is per-request only and every request re-runs the same option, meta, and term queries. Symptom: repeated identical queries across requests that a persistent cache would serve from memory, keeping database load high even for cache-friendly read pages.',
                Category::INDUSTRY,
                'WordPress developer docs — object cache and persistent backends',
                'WordPress developer docs — caching layers'
            ),
            self::rule(
                'php-wp-query-in-loop',
                'Don\'t run WP_Query / get_post_meta per item inside a loop',
                'Template or plugin code runs a fresh WP_Query, get_posts(), or an uncached get_post_meta() inside a loop over posts or terms, firing one query set per iteration. Symptom: a listing page\'s query count scales with the number of items rendered rather than staying flat; batching the lookups (a single meta query, update_meta_cache / _prime_post_caches, or one query with the needed joins) collapses it.',
                Category::INDUSTRY,
                'WordPress developer docs — WP_Query and query efficiency',
                'WordPress developer docs — priming meta caches'
            ),
            // Cross-ecosystem industry patterns
            self::rule(
                'php-outbound-retry-storm',
                'Cap outbound retries with exponential backoff + jitter, not a tight retry loop',
                'An outbound call (cURL, Guzzle, a DB or SDK client) is retried on failure in a while/for loop or a recursive catch with no backoff, no jitter, and no max-attempts ceiling. Symptom: the moment an upstream slows or returns 5xx, every worker hammers it as fast as it can — a self-inflicted thundering herd that turns a brief blip into a sustained outage; Boosthis flags a burst of repeated same-target outbound calls clustered in time.',
                Category::INDUSTRY,
                'AWS Architecture Blog — exponential backoff and jitter',
                'Google SRE Book — handling overload (retry amplification)'
            ),
            self::rule(
                'php-missing-cache-headers',
                'Send Cache-Control / ETag on cacheable GET responses',
                'A GET route returning identical, non-personalized data for many clients (public config, catalog, rarely-changing reference data) sends no Cache-Control, ETag, or Last-Modified, so every client and CDN re-fetches the full body each time. Symptom: avoidable origin load and time-to-first-byte for data that could be served from cache. Do NOT apply to per-user or rapidly-changing responses — those should stay no-store.',
                Category::INDUSTRY,
                'MDN — HTTP caching (Cache-Control, ETag)',
                'PHP manual: header() and conditional GET (If-None-Match)'
            ),
            self::rule(
                'php-response-compression-missing',
                'Enable gzip/brotli for large JSON / HTML responses',
                'The server returns JSON or HTML larger than ~1KB with no compression — no zlib.output_compression, no gzip/brotli at the web server, and no compressed variant. Symptom: time-to-first-byte is fine but client time-to-interactive is bloated several-fold because compressible payloads ship raw over the wire, wasting bandwidth and slowing every client.',
                Category::INDUSTRY,
                'PHP manual: zlib.output_compression',
                'MDN — HTTP compression'
            ),
            self::rule(
                'php-sync-kdf-on-request',
                'Bound password-hashing cost — a high work factor burns a whole worker per login',
                'A login / signup / password-change route runs password_hash / password_verify (bcrypt, argon2) with a high cost or memory factor directly on the request. Symptom: each authentication spends tens to hundreds of milliseconds of CPU on an FPM worker; a burst of logins saturates the pool and stalls unrelated routes. Tune the cost to the box\'s target latency and cap concurrency on auth routes.',
                Category::INDUSTRY,
                'PHP manual: password_hash and cost / memory parameters',
                'OWASP — password storage cheat sheet'
            ),
            self::rule(
                'php-logging-eager-string-build',
                'Guard costly log arguments — don\'t build a message that a disabled level discards',
                'A hot path logs with eager string building (logger->debug("row " . expensiveToString($row))), so the message and its arguments are assembled even when the debug level is disabled. Symptom: production CPU shows real cycles spent formatting log lines that are never written; passing context lazily (PSR-3 placeholders with a context array, or an isDebug guard around costly arguments) removes it.',
                Category::INDUSTRY,
                'PSR-3 — Logger interface (context placeholders)',
                'PHP manual: logging levels and lazy context'
            ),
            self::rule(
                'php-persistent-connection-misuse',
                'Use persistent DB connections deliberately — they multiply across FPM workers and hold state',
                'Code opens the database with PDO::ATTR_PERSISTENT (or mysqli p: prefix) without accounting for the fact that each PHP-FPM worker keeps its own persistent link, and left-over session state (temp tables, locks, unbuffered results, SET session vars) can leak into the next request that reuses the link. Symptom: total DB connections equal workers-times-pools and can exceed the server\'s max_connections, and occasional "stale connection state" bugs appear on reused links; size the pool to the worker count and reset per-request state.',
                Category::INDUSTRY,
                'PHP manual: PDO::ATTR_PERSISTENT and persistent connections',
                'MySQL docs — max_connections and connection accounting'
            ),
            self::rule(
                'php-include-path-scanning',
                'Rely on the Composer classmap — don\'t make the autoloader stat the include_path per class',
                'The app leans on __autoload-style include_path scanning, a long include_path, or PSR-0/PSR-4 lookups that fall back to filesystem probing for each class, so loading a class stats several candidate directories. Symptom: request startup does a burst of failed file_exists / stat calls per class as the autoloader searches directories; an optimized classmap turns each class into a single direct include.',
                Category::INDUSTRY,
                'PHP manual: include_path and file stat cost',
                'Composer docs — autoloader optimization (classmap)'
            ),
            self::rule(
                'php-fanout-overload',
                'Bound per-request fan-out — one request driving N concurrent downstream calls is over-current',
                'A single incoming request fans out to an anomalously high number of downstream calls: one HTTP call per collection item, an aggregator route that calls every internal service on each hit, or a per-item lookup multiplied across a burst. Symptom: downstream services see your traffic multiplied N-fold, the outbound connection budget and the DB pool exhaust, and p99 tracks the slowest of the N calls; one hot route can effectively DoS your own internal APIs. Distinct from the ORM N+1 rule (queries inside a loop) — this is service-to-service fan-out at the request layer.',
                Category::INDUSTRY,
                'Google SRE Book — Handling Overload, fan-out and load amplification',
                'PHP manual: curl_multi for bounded concurrent requests'
            ),
            self::rule(
                'php-per-row-write-in-loop',
                'Batch inserts/updates — a per-row write inside a loop pays a round-trip per row',
                "A loop issues one INSERT/UPDATE per iteration — Eloquent ->save() or ::create() per model, a Doctrine persist()+flush() inside the loop, or a bare PDO ->execute() per row — so an N-row import or backfill costs N network round-trips and N transaction commits. Symptom: an import/backfill that should be seconds runs for minutes, the DB shows a flood of tiny identical statements, and latency scales linearly with row count while CPU sits idle waiting on the wire. Rule/Fix shape: collect rows and do one bulk write — a single multi-row INSERT (upsert/insertOrIgnore), a chunked mass-insert, or wrap the batch in one transaction with a prepared statement executed per row so only the round-trips collapse; flush Doctrine once per chunk, not per entity.",
                Category::INDUSTRY,
                'PHP manual: PDO prepared statements and transactions',
                'Doctrine ORM docs — batch processing and bulk inserts'
            ),
            self::rule(
                'php-redis-no-pipelining',
                'Pipeline related Redis commands — command-by-command calls pay one RTT each',
                "Code issues a sequence of dependent-but-independent Redis commands one at a time through phpredis or Predis (a GET/SET per cache key in a loop, N HGETs to assemble a view, per-item SADD), so each command blocks on its own request/response round-trip. Symptom: a page that touches Redis dozens of times spends most of its cache time in network latency, not Redis CPU, and the slowness scales with key count and RTT to the cache node rather than data size. Rule/Fix shape: batch with pipelining (->pipeline() / ->multi(Redis::PIPELINE)) or multi-key primitives (MGET/MSET/HMGET) so many commands ride one round-trip; keep pipelines bounded so a single pipeline can't monopolise the connection.",
                Category::INDUSTRY,
                'Redis docs — Using pipelining to speed up Redis queries',
                'phpredis README — pipeline() and multi() modes'
            ),
            self::rule(
                'php-hydrate-tracked-models-on-read',
                'On read-only paths, skip full ORM hydration — fetch scalars/DTOs, not change-tracked entities',
                "A read-only endpoint (a list, a report, a JSON API index) pulls full Eloquent models or managed Doctrine entities purely to read a few fields, so the ORM hydrates every column, registers each object with the unit of work / identity map, and holds it for dirty-checking that never fires. Symptom: a list route's memory and CPU climb with row count far beyond the data actually rendered, GC and hydration dominate the profile, and large pages flirt with memory_limit even though nothing is written. Rule/Fix shape: on read paths select only needed columns and return arrays/scalars (Query Builder ->get() of specific columns, Eloquent ->toBase(), Doctrine getArrayResult() / scalar hydration or a DTO projection via NEW) so no tracked object graph is built.",
                Category::INDUSTRY,
                'Doctrine ORM docs — hydration modes (array/scalar) and read-only queries',
                'Laravel docs — Query Builder vs Eloquent for read-only selects'
            ),
            self::rule(
                'php-json-decode-unchecked',
                'Check json_decode failure — a null return on invalid JSON is not an empty object',
                "Code calls json_decode() on an HTTP response body, a queue message, or a request payload and immediately dereferences the result without checking for failure. json_decode() returns null both for the literal JSON `null` AND for a parse error, and it does NOT inspect the HTTP status or Content-Type — so an upstream 500 HTML error page, a truncated body, or a rate-limit notice decodes to null and the code treats it as 'no data'. Symptom: a downstream hiccup surfaces as a confusing 'Attempt to read property on null' deep in business logic, or as silently-empty results, far from the real cause (the failed call). Rule/Fix shape: decode with JSON_THROW_ON_ERROR (or check json_last_error()), verify the HTTP status and Content-Type BEFORE decoding, and validate the shape/keys before use.",
                Category::INDUSTRY,
                'PHP manual: json_decode, json_last_error and JSON_THROW_ON_ERROR',
                'PHP manual: json_validate() (PHP 8.3) for cheap pre-validation'
            ),
            self::rule(
                'php-unguarded-decoded-payload-deref',
                'Guard decoded/remote arrays before nested access — a missing key or wrong type is a fatal, not a default',
                "A handler or Blade/Twig view reaches into a decoded JSON payload, a request array, a cache entry, or an ORM result with nested access (\$data['user']['email'], \$item->price, foreach over a field assumed to be a list) without proving the shape first. Symptom: whenever the remote/decoded value is null, a scalar where an array was expected, or simply missing a key, PHP 8 throws (undefined array key warning escalating to a TypeError, 'foreach() argument must be of type array', or 'Attempt to read property on null') and turns a soft data gap into a 500. Rule/Fix shape: validate before dereference — isset()/array_key_exists(), null checks, is_array()/is_iterable() before foreach/count(), nullsafe (?->) and null-coalescing defaults — and reject or default malformed payloads at the boundary rather than mid-render.",
                Category::INDUSTRY,
                'PHP manual: undefined array key / property access errors in PHP 8',
                'PHP RFC — nullsafe operator (?->) and null coalescing for safe access'
            ),
            self::rule(
                'php-http-wrapper-swallows-transport-failure',
                'A retrying HTTP wrapper must THROW on transport failure — never return a fake Response or null',
                "A shared HTTP-client wrapper around cURL, Guzzle, or Symfony HttpClient catches connect/timeout/TLS/DNS exceptions and, instead of failing, returns a synthetic empty Response, a null, or a hard-coded default so callers 'don't have to handle errors'. Symptom: a transport failure (upstream down, TLS handshake failure, DNS blip) is indistinguishable from a real empty result, so retry/circuit-breaker logic never fires, callers cache or persist the fake empty body, and the outage manifests as silent data loss instead of a retryable error. Rule/Fix shape: let transport failures propagate as exceptions/rejections (Guzzle throws RequestException/ConnectException; Symfony surfaces TransportExceptionInterface on the first content access) so retry, backoff, and alerting can act; reserve fallbacks for explicitly-classified, safe cases only.",
                Category::INDUSTRY,
                'Guzzle docs — exceptions (ConnectException, RequestException) and http_errors',
                'Symfony HttpClient docs — TransportExceptionInterface on transport errors'
            ),
            self::rule(
                'php-buffer-huge-json-response',
                'Stream large responses — json_encode() on a huge array materialises the whole payload before the first byte',
                "An export/report/large-list endpoint builds the entire result in memory (an all-rows ->get(), a giant array) and json_encode()s or echoes it in one shot, so peak memory holds the full source data AND the full encoded string at once before anything is flushed. Symptom: the endpoint's memory scales with result size, brushes or exceeds memory_limit as data grows (a fatal mid-response, not a slow one), and time-to-first-byte is the whole build time because nothing streams. Rule/Fix shape: stream with a Symfony/Laravel StreamedResponse (or ob-free chunked echo) driven by a generator or a DB cursor (Eloquent ->cursor()/lazy(), unbuffered PDO), encoding and flushing row-by-row (NDJSON or a manually-assembled JSON array) so peak memory stays bounded and bytes start flowing immediately.",
                Category::INDUSTRY,
                'Symfony docs — StreamedResponse for large/streamed output',
                'PHP manual: generators and unbuffered queries for constant-memory iteration'
            ),
            self::rule(
                'php-decode-assoc-array-hot-path',
                'On hot paths, decode JSON into typed objects/DTOs — associative arrays cost a hash entry and zval per field',
                "A high-traffic handler decodes every request/response with json_decode(\$s, true) into deeply-nested associative arrays and then indexes into them by string key throughout the request. Each decoded field is a separate hashtable bucket and zval, and every ['key'] access is a hash lookup, so a hot path repeats thousands of allocations and string-keyed lookups per request. Symptom: the profile of a busy endpoint is dominated by json_decode and array access rather than real work, and per-request memory churn drives GC and OPcache/JIT pressure. Rule/Fix shape: on the hottest paths decode into typed objects/DTOs (json_decode without the assoc flag, or map into readonly value objects) so field access is a property read on a fixed shape, and decode ONCE per payload rather than re-decoding the same body.",
                Category::INDUSTRY,
                'PHP internals: zval and hashtable cost of associative arrays',
                'PHP manual: json_decode object vs associative-array output'
            ),
            self::rule(
                'php-datetimezone-rebuilt-per-request',
                'Build DateTimeZone/formatter objects once — reconstructing them per request re-parses tz data on the hot path',
                "Hot code constructs `new DateTimeZone('...')`, an IntlDateFormatter, or a NumberFormatter (or repeatedly calls date_default_timezone_set) inside a per-request or per-row path, so the timezone database and ICU locale data are parsed and loaded again for every request or every loop iteration. Symptom: a formatting- or timezone-heavy endpoint spends measurable time in tz/ICU initialisation that does no useful work, and it scales with request rate and row count rather than data. Rule/Fix shape: build the zone/formatter/pattern ONCE (a static/singleton, a container binding, or app-level tz config) and reuse the immutable object across requests and iterations, exactly as you would compile a regex or a prepared statement once rather than per call.",
                Category::INDUSTRY,
                'PHP manual: DateTimeZone and the bundled timezone database',
                'PHP manual: IntlDateFormatter / ICU data loading cost'
            ),
            self::rule(
                'php-object-as-array-key',
                'Use only stable scalar keys for arrays — object identity needs SplObjectStorage/WeakMap, not array keys',
                "Code tries to key a plain PHP array by an object, a float, or an unstable composite (implicitly casting the object to string, or json_encode()ing a struct as the key), expecting value-equality de-duplication or a per-object map. PHP array keys can only be int or string, so an object key is a TypeError and casting collapses distinct objects or loses precision; two 'equal' objects with different identity silently overwrite or fail to match. Symptom: a de-dupe or grouping keyed by object/float either throws, silently merges entries that should be distinct, or leaks memory because entries are never matched to be removed. Rule/Fix shape: for object-keyed maps use SplObjectStorage or WeakMap (identity-based, and WeakMap avoids leaking by not pinning the object alive); for value-equality grouping derive a stable canonical string/int key explicitly.",
                Category::INDUSTRY,
                'PHP manual: array key types (int/string only) and casting rules',
                'PHP manual: SplObjectStorage and WeakMap for object-keyed maps'
            ),
            self::rule(
                'php-middleware-order-wrong',
                'Order the middleware pipeline correctly — trusted-proxy, session, CSRF, auth and throttle are order-sensitive',
                "The framework middleware stack (Laravel's global/group middleware, Symfony's kernel event/firewall order) is assembled in an order that violates dependencies: throttling or auth runs before TrustProxies so it keys on the load-balancer IP not the client; CSRF or auth runs before the session is started; CORS or exception handling sits too deep to cover early-terminating middleware. Symptom: rate limits bucket all traffic under one proxy IP (or leak the real IP), 'random' 419/CSRF-token-mismatch or logged-out errors on valid requests, and CORS/error responses that are missing their headers because an outer layer short-circuited first. Rule/Fix shape: order the pipeline by dependency — trusted proxies first, then sessions, then CSRF/auth, then bindings, then throttling and CORS, with exception handling outermost so it wraps everything — and treat middleware order as part of the contract, not incidental.",
                Category::INDUSTRY,
                'Laravel docs — middleware and global middleware priority ($middlewarePriority)',
                'Symfony docs — kernel event/listener priorities and firewall ordering'
            ),
            self::rule(
                'php-cookie-payload-bloat',
                'Keep cookies small — a bloated session/flags cookie is re-sent on every request and asset',
                "The app stores real data in cookies — a cookie session driver holding a serialized blob, feature flags, cached user attributes, or a fat JWT — so a multi-kilobyte Cookie header rides on EVERY request the browser makes to the origin, including each static asset and XHR. Symptom: upstream request sizes balloon, some requests intermittently fail once the header crosses the web server's header-size limit, and the cookie-session encrypt/decrypt cost is paid per request; the slowdown is uniform across the site and invisible in DB/APM timings. Rule/Fix shape: keep only a small opaque session ID in the cookie and store session data server-side (Redis/DB session driver), move large state out of cookies, and cap cookie size; reserve cookies for identifiers, not payloads.",
                Category::INDUSTRY,
                'MDN — HTTP cookies and per-request Cookie header overhead',
                'Laravel docs — session drivers (cookie vs database/redis) and payload size'
            ),
            self::rule(
                'php-per-route-timing-not-app-wide',
                'Time each matched route by its pattern — one app-wide FPM average hides the slow nested endpoint',
                'Performance is judged by a single number: an nginx access-log average, an FPM request_slowlog threshold, or a global before/after middleware timer that stamps every request with the same label. Every controller action, admin export, dashboard partial, webhook receiver and REST endpoint is folded into one bucket, so a fast home page and a 4-second orders export average out to something that looks acceptable. Symptom: the app-wide mean and even the p95 look fine, but individual users report specific pages hanging, and when the pool saturates you cannot tell which route is parking the workers because the timing data has no per-endpoint breakdown. This is common when timing lives in a shared base controller, a Laravel global middleware, or a Symfony kernel event that runs for everything but records nothing route-specific. The fix is to key the timing on the matched route pattern, not the app as a whole.',
                Category::INDUSTRY,
                'Laravel docs: named routes and the router',
                'Symfony docs: routing and request attributes'
            ),
            self::rule(
                'php-front-controller-one-label-for-every-url',
                'Derive the perf label after dispatch — a front-controller app labels every URL as one route otherwise',
                'The app runs through a single front controller (public/index.php in Laravel or Symfony, index.php in WordPress) and the performance label is set during bootstrap — in the same index.php, in a service provider boot, or in a middleware that runs before the router. Because every URL enters through that one file, the label is identical for the whole site, so the tracker reports one route no matter what page was served. Symptom: the per-endpoint breakdown shows a single line (index.php or the app name) carrying all traffic, and you cannot separate the checkout page from the sitemap. A related failure is when someone tries to fix this by labelling with the raw request URI, which then produces a distinct label for every id and query string and blows up cardinality. The correct label is derived only after the router has matched a route.',
                Category::INDUSTRY,
                'Symfony docs: the HttpKernel and request lifecycle',
                'Laravel docs: request lifecycle and the router'
            ),
            self::rule(
                'php-redirect-loop',
                'Break redirect loops in auth, locale and https rules — a missing trusted-proxy sees the wrong scheme',
                'A controller, middleware or web-server rule issues a redirect whose target immediately redirects again. Common shapes: auth middleware sends a guest to a login route that is itself behind the auth guard; trailing-slash or locale normalisation fights the router so each hop rewrites the URL back; or an application-level http-to-https redirect runs behind a TLS-terminating proxy or load balancer where trusted-proxy configuration is missing, so the app only ever sees http on the forwarded request and redirects to https forever. Symptom: the browser shows ERR_TOO_MANY_REDIRECTS, or every page silently costs two or three extra full round trips before it renders, inflating time-to-first-byte. In logs you see the same path returning 301 or 302 in a tight chain. The direction of the fix is to make the redirect target a fixed point and to teach the app the real client scheme.',
                Category::INDUSTRY,
                'MDN: HTTP redirections and status codes',
                'Symfony docs: trusting proxies and X-Forwarded headers'
            ),
            self::rule(
                'php-server-rendered-markup-hydration-mismatch',
                'Keep server-rendered markup deterministic — non-reproducible HTML forces a full client re-render',
                'PHP emits HTML that a JavaScript layer then adopts on the client: Inertia server-side rendering, Livewire morphing a component, or a Blade or Twig partial feeding a React or Vue island. The server markup is built from inputs the client cannot reproduce — the current time printed in a partial, a random token or CSRF value embedded in the tree, a different locale, timezone or currency between the server render and the client render, or a Livewire list rendered without a stable wire:key. Symptom: the browser console shows a hydration warning and the client throws away the server HTML to re-render the whole subtree, causing a visible flash and wasted CPU; with Livewire the DOM diff morphs the wrong element after an update, moving or duplicating rows. The fix is to make the server output a deterministic function of shared, stable inputs.',
                Category::INDUSTRY,
                'Laravel docs: Livewire wire:key and DOM morphing',
                'web.dev: rendering on the web and hydration'
            ),
            self::rule(
                'php-sprintf-in-hot-loop',
                'Skip sprintf for trivial key and string building in tight loops — the format string is parsed every call',
                'sprintf, vsprintf, number_format or str_pad is called once per row inside a loop that runs thousands of times per request — building a cache key, an array index, a label, or a small piece of output. Each call re-parses the format string and dispatches through the formatting machinery even when the task is a plain concatenation or a cast. Symptom: a profiler run on a list or export route shows the formatting function near the top of the flame graph, above the database query, and the route is CPU-bound on string assembly rather than on real work. This is easy to miss because any single call is cheap; it only matters at loop scale. The fix is to reserve sprintf for genuine formatting and use concatenation or casts for the trivial cases.',
                Category::INDUSTRY,
                'PHP manual: sprintf and format string parsing',
                'PHP manual: string operators and type casting'
            ),
            self::rule(
                'php-array-function-pipeline-per-row',
                'Fold a chained array_map/filter/reduce pipeline into one loop — each stage allocates a whole array',
                'A hot path chains array_map, array_filter and array_reduce (or a long Laravel Collection chain with map, filter, pluck, reduce) over a large array. Each stage builds a complete intermediate array and pays a callback dispatch per element, so a three-stage pipeline over N rows allocates roughly three N-sized arrays and makes 3N callback calls before producing the result. Symptom: memory_get_peak_usage on the route spikes to several times the size of the source data, the route is slow, and there is no database query to blame — the profiler points at array functions and closure calls, not SQL. This surfaces on export builders, report aggregations and API transformers that reshape a big result set. The fix is to collapse the pipeline into a single pass or stream it lazily.',
                Category::INDUSTRY,
                'PHP manual: array_map, array_filter and array_reduce',
                'Laravel docs: LazyCollection and lazy evaluation'
            ),
            self::rule(
                'php-lazy-loading-not-disabled',
                'Fail loudly on unexpected lazy loads in dev — silent lazy loading only shows the N+1 in production',
                'The ORM is left with lazy loading switched on and no strict guard, so a relation accessed without being eager-loaded quietly fires an extra query instead of failing. Laravel offers Model::preventLazyLoading and Doctrine can be configured to reject lazy proxy initialisation on a request path, but neither is enabled in development or staging. Symptom: the query count looks fine on seed data but balloons in production, and the extra queries fire from inside the Blade template, the API resource, or the serializer — places nobody profiles — so the N+1 is invisible until production database load or slow-query logs surface it. The angle here is not the eager-load itself but the missing guardrail that would have made the omission fail during development. The fix is to turn strict lazy-loading detection on outside production.',
                Category::INDUSTRY,
                'Laravel docs: preventLazyLoading and strict mode',
                'Doctrine ORM docs: lazy loading and proxy objects'
            ),
            self::rule(
                'php-always-eager-relations',
                'Do not mark relations always-eager — a global $with or fetch=EAGER pays the graph on every read',
                'A relation is declared to load eagerly for every read of the model rather than per query: Eloquent\'s protected $with array on the model, or a Doctrine association mapped with fetch=EAGER. Because this applies to EVERY query that touches the entity, even a count, an existence check, a single-column lookup or a lightweight dropdown query drags the whole related graph along, often nested several levels deep. Symptom: simple queries that should be one cheap statement issue extra joins or IN queries and hydrate large object graphs nobody in that path uses, inflating memory and query time across the whole app rather than on one route. This is the opposite mistake to a missing eager load: over-fetching by default. The fix is to make eager loading a per-query decision.',
                Category::INDUSTRY,
                'Laravel docs: default eager loading with the $with property',
                'Doctrine ORM docs: association fetch modes (LAZY and EAGER)'
            ),
            self::rule(
                'php-result-sets-and-handles-held-too-long',
                'Free result sets and handles as you finish them — end-of-request cleanup is wrong inside long-running work',
                'Result sets, prepared statements, file handles and cURL handles are opened and left for PHP\'s end-of-request shutdown to reclaim. That is harmless in a short web request, but wrong inside a loop, a batch pass, a scheduled command or a queue worker, where the process runs for a long time and the handles pile up until the whole job ends. Symptom: a long-running artisan command or queue worker climbs steadily in memory and open file or connection count, eventually hitting memory_limit, the open-files ulimit, or the database max_connections, even though each iteration only needs one handle at a time. Under a persistent runtime such as Octane the leak spans requests too. The fix is to close each resource the moment the code is done with it rather than relying on request shutdown.',
                Category::INDUSTRY,
                'PHP manual: fclose, curl_close and resource lifetime',
                'PHP manual: PDOStatement closeCursor and mysqli_free_result'
            ),
            self::rule(
                'php-descriptor-leak-in-long-running-worker',
                'Close streams, cURL handles and sockets inside long-running workers — a leak becomes too-many-open-files',
                'A long-lived process — a Laravel queue:work worker, a Symfony Messenger consumer, an Octane or RoadRunner worker, or a plain CLI daemon looping over jobs — opens resources with fopen(), fsockopen(), stream_socket_client(), curl_init(), opendir(), or a fresh PDO connection per iteration and never closes them. Under PHP-FPM a leaked handle is released when the request ends, so this hides in development, but a persistent worker never ends, so every unclosed handle accumulates for the lifetime of the process. Missing fclose(), curl_close(), closedir(), or a statement left referenced so the cursor stays open are the usual shapes; a resource opened inside a try with no finally leaks on the exception path. Symptom: the worker runs cleanly for minutes or hours then throws failed to open stream: Too many open files or a cURL could not resolve host error once the process crosses its ulimit -n descriptor ceiling, and the failure lands on whichever unlucky job was next rather than the job that leaked. lsof against the worker PID shows the descriptor count climbing steadily and never falling.',
                Category::INDUSTRY,
                'PHP manual: fopen, fclose and stream resources',
                'PHP manual: curl_close and cURL handle lifecycle'
            ),
            self::rule(
                'php-render-blocking-scripts-in-head',
                'Emit noncritical script tags with defer or async — a plain script in the head stops the parser before first paint',
                'Server templates render script src tags in the head with no defer or async attribute, so the browser parser halts, fetches and executes each script before it paints anything. The shapes: a raw script tag hand-written in a Blade or Twig layout head, a WordPress wp_enqueue_script call registered for the header with no defer strategy (in_footer left false and no strategy argument), or a bundler helper output placed in the head as a classic script rather than a module. Because the markup that decides what blocks is generated by PHP, this is a server-template concern even though the cost lands in the browser. Symptom: Lighthouse or web.dev flags render-blocking resources, first contentful paint is delayed by the sum of the head scripts\' download and execute time, and the page shows blank while scripts nobody needs for the first screen are fetched; view-source shows the offending tags sitting above the visible content.',
                Category::INDUSTRY,
                'web.dev: eliminate render-blocking resources',
                'WordPress developer handbook: wp_enqueue_script defer strategy'
            ),
            self::rule(
                'php-third-party-tags-block-first-paint',
                'Load analytics, ads and chat tags async and after paint — never synchronously in the shared header template',
                'Third-party marketing tags — an analytics snippet, a tag manager, an ad network loader, a live-chat or heatmap widget — are pasted straight into the shared header partial of a Laravel, Symfony or WordPress theme as synchronous script tags, so first paint waits on a host the team does not control. The shape is a vendor snippet copied verbatim into the head of the master layout, often several of them, some using document.write, so the page blocks on DNS, connection and download to an external domain before it renders. This is distinct from your own render-blocking scripts: the risk here is that an unrelated third party\'s latency or outage stalls YOUR first paint. Symptom: time-to-first-paint tracks the slowest third-party host, a vendor incident makes the whole site feel slow or hang, and Lighthouse attributes blocking time to domains that are not yours; the network waterfall shows the render start line sitting after the third-party requests complete.',
                Category::INDUSTRY,
                'web.dev: efficiently load third-party JavaScript',
                'MDN: async and defer script loading'
            ),
            self::rule(
                'php-render-blocking-stylesheets',
                'Inline the critical CSS and load the rest without blocking — every stylesheet link in the head blocks first paint',
                'The page head links a full stylesheet (or several) with link rel stylesheet, and every such link is render-blocking by default. Common shapes generated by PHP templates: a theme or layout that enqueues an entire CSS framework for every page, a wp_enqueue_style call pulling a large bundle, and CSS at-import chains where one imported file imports another so downloads serialise one after the next instead of running in parallel. The browser will not paint until every blocking stylesheet in the head has loaded and been parsed. Symptom: first contentful paint is delayed by the largest CSS download in the head, Lighthouse flags render-blocking CSS and unused CSS rules, and the effect is worse on slow connections where the serialised at-import chain adds a full round-trip per hop; the network waterfall shows the render start line waiting on the last stylesheet.',
                Category::INDUSTRY,
                'web.dev: defer non-critical CSS and inline critical CSS',
                'MDN: render-blocking CSS and at-import performance'
            ),
            self::rule(
                'php-credential-change-callback-one-shot-flag',
                'Fire the credential-changed callback on every real value change — never gate it behind a one-shot static flag',
                'An SDK or library that notifies the host on a credential change — an onTokenIssued, onCredentialChanged or similar hook — is gated behind a one-shot guard so only the FIRST change is ever delivered. The shape: a static bool, a class property set once, or an isset check that suppresses the callback after the initial invocation, wrapping the notify call in an if that can only be true once. Under PHP-FPM this often hides because a static resets when the request ends, so within a single request only the first change fires and subsequent rotations in the same request are silently dropped; under a persistent worker (Octane, RoadRunner or a queue consumer) the same static lives for the whole process, so after the very first change the host is NEVER told again for the life of the worker. Symptom: the host keeps using a stale token after a refresh, a rotated credential is not propagated, and the failure depends on runtime — intermittent under FPM, permanent under a persistent worker — which is exactly the confusing shape this rule catches.',
                Category::INDUSTRY,
                'PHP manual: static variables and their lifetime under FPM',
                'Laravel Octane docs: state that persists between requests'
            ),
            self::rule(
                'php-error-signatures-not-grouped',
                'Group failures by a normalised exception signature — report the first occurrence with a count',
                'Errors are logged and counted one at a time with no grouping, so a single root cause fans out into thousands of separate records. The shape: a Monolog or framework logger writing each exception as its own line with variable data (an id, a timestamp, a request path) embedded in the message, no shared correlation id threaded through controllers and queue jobs, and no normalisation before counting. When one downstream dependency fails, it surfaces as distinct 500s across many controllers plus failures in Laravel or Symfony queue jobs and outbound calls, each logged separately. Symptom: an alerting or log tool shows a flood of unique-looking errors during a single incident, on-call cannot tell one outage from many because the count is inflated and the messages differ only in variable fields, and the first failing request that would point at the cause is buried; the direction of the fix is to normalise the signature and aggregate.',
                Category::INDUSTRY,
                'Monolog docs: processors and adding context to records',
                'OWASP: logging cheat sheet (structured logging)'
            ),
            self::rule(
                'php-template-row-fields-unguarded',
                'Guard per-row template fields before you read them — a null relation throws mid-render, not before',
                'A Blade or Twig loop over a list reads a nullable relation, an optional key from a decoded API payload, or a translation row that may not exist, and dereferences it without a guard: item.customer.name inside a foreach, arr[key] with no isset, or item.profile.avatar where profile is null. In Blade this is a raw {{ $item->customer->name }} or {{ $row[\'label\'] }}; in Twig a {{ item.customer.name }} with no default filter. The value comes back null on some rows because the relation was never eager-loaded, the join found no match, or the upstream JSON omitted the field. Symptom: the page starts streaming and renders the header and the first few rows, then throws an Attempt to read property on null or Undefined array key partway down, so the visitor sees a half-painted page or a raw 500 rather than a clean empty state. Direction of the fix is a per-row guard plus a sensible fallback so one bad row degrades to a placeholder instead of killing the whole response.',
                Category::INDUSTRY,
                'Laravel docs: Blade optional chaining and the optional helper',
                'Twig docs: the default filter and null-safe access'
            ),
            self::rule(
                'php-summary-totals-on-nullable-data',
                'Guard summaries over nullable data — a null sum, a divide-by-zero average, or null into number_format',
                'A view or controller computes a summary over data that can be null or empty without a guard: summing a numeric column that contains nulls, computing an average by dividing a total by a count that can be zero, or passing a possibly-null value straight into number_format(), round(), or a currency helper. Typical shapes are $orders->sum(\'total\') where total is nullable, $total / $orders->count() with no zero check, number_format($row[\'amount\']) where amount may be missing from a decoded payload, or a Blade line like {{ number_format($stats->revenue) }} where revenue is null on a fresh account. Symptom: on empty or partially-populated data the page shows a DivisionByZeroError, or on PHP 8.1 and later a deprecation notice Passing null to parameter 1 of number_format that becomes a TypeError on PHP 9, so a report that works on seeded data breaks for a brand-new tenant with no rows yet. Direction of the fix is a guard and a sensible default before the arithmetic and before any formatting helper.',
                Category::INDUSTRY,
                'PHP manual: number_format and null argument deprecation in PHP 8.1',
                'PHP manual: DivisionByZeroError and the intdiv behaviour'
            ),
            self::rule(
                'php-iterating-a-value-that-is-not-a-list',
                'Prove a value is iterable before foreach or count — a scalar or null is a fatal, not an empty list',
                'Code runs foreach, count(), array indexing, or Collection methods on a value whose type was never checked: the result of json_decode() on a payload that may be null or a scalar, a helper that returns false on failure, or a config value that a client can set to a string. Typical shapes are foreach (json_decode($body, true) as $item), count($decoded[\'rows\']) where rows may be absent, or collect($payload)->map(...) on data that arrived as null. Symptom: on modern PHP, count() on a non-countable throws TypeError count(): Argument must be of type Countable|array, and foreach over a non-iterable throws or warns and the request dies with a 500 rather than rendering an empty list; on the false-on-failure helpers the loop silently iterates nothing or fatals. Direction of the fix is an explicit type guard that normalises the value to an array or Collection before any list operation.',
                Category::INDUSTRY,
                'PHP manual: count and the Countable requirement',
                'PHP manual: is_array, is_iterable and json_decode return values'
            ),
            self::rule(
                'php-cut-vertex-middleware',
                'Find the cut-vertex step every request passes through — a slow global middleware stalls the whole app',
                'One shared step sits on the path of nearly every request: a global middleware registered in the kernel, an auth or entitlement check that runs before every controller, a shared internal API call made on each hit, or a single Redis or database instance every route touches. The shape to look for is middleware in Laravel\'s global $middleware array or Symfony\'s kernel that does real work per request — a remote permissions lookup, an uncached feature-flag fetch, a session or token verification that hits the network. Because it is common to all routes, it is both a latency multiplier and a single point of failure: when it is slow, everything is slow, and when it is down, everything is down. Symptom: p95 rises across unrelated routes at the same time with no single feature to blame, and FPM workers pile up waiting on the same downstream because every in-flight request is parked in the same shared step. Direction of the fix is to make the shared step cheap, cached, and fail-fast, and to remove it from routes that do not need it.',
                Category::INDUSTRY,
                'Laravel docs: global versus route middleware and the kernel',
                'Google SRE Book: single points of failure and load amplification'
            ),
            self::rule(
                'php-partial-failure-takes-whole-page',
                'Wrap a risky partial in its own try or catch — one failing widget should degrade, not 500 the page',
                'A risky sub-section is rendered inline with the main page and no containment: a third-party feed, a recommendation block, a currency or stock lookup, or a widget that calls a remote API during render. The shape to look for is a Blade @include, a Twig embed or include, a view composer, or a controller that builds a fragment by calling an external service or a fragile query, all with no surrounding try or catch. When that section throws or its downstream hangs, the exception propagates out of the view and the whole request becomes a 500. Symptom: an outage or slowness in one non-essential block takes down an otherwise healthy page — the header, the primary content, and the checkout button all vanish because a sidebar recommendation service returned an error or timed out. Direction of the fix is to contain each risky section so a failure there renders a fallback for that block only and leaves the rest of the page intact.',
                Category::INDUSTRY,
                'Laravel docs: view composers and rendering fragments',
                'Symfony docs: rendering controllers as fragments and error handling'
            ),
            self::rule(
                'php-huge-server-rendered-dom',
                'Bound the server-rendered DOM — paginate long lists rather than printing every row and hidden panel',
                'A template prints everything at once: thousands of table rows, every tab panel and modal whether shown or not, or a full select list of tens of thousands of options. The shape to look for is a Blade or Twig foreach over an unpaginated collection, a select element populated from an entire lookup table, or a page that renders all tabs and dialogs up front and hides them with CSS. Because PHP-FPM builds the whole response in the worker before sending, this costs worker memory to assemble, response bytes on the wire, and browser parse, style, layout, and memory once it lands. Symptom: a large HTML payload (hundreds of kilobytes or more), a slow time-to-first-byte because the worker is still assembling the body, worker memory creeping toward memory_limit on the biggest pages, and a sluggish browser on low-end devices from a huge DOM node count. Direction of the fix is to render only what is visible and page or lazy-load the rest.',
                Category::INDUSTRY,
                'web.dev: keep DOM size and node count bounded',
                'Laravel docs: pagination for large result sets'
            ),
            self::rule(
                'php-typeahead-query-unbounded',
                'Cap and cache typeahead queries — set a LIMIT, a minimum length, and drop the leading wildcard',
                'A search or autocomplete endpoint runs on every keystroke with no result cap, no minimum query length, no short-lived cache, and a leading-wildcard LIKE match. The shape to look for is a controller that takes a q parameter and does a where(\'name\', \'like\', \'%\'.$q.\'%\')->get() with no limit, no guard for a one- or two-character query, and no caching, wired to fire on each character the user types. A leading wildcard cannot use a normal btree index, so each call is a full table scan. Symptom: a table scan per character typed, a burst of near-identical slow queries as someone types a word, the slow-query log filling with the same LIKE statement, and FPM workers and DB connections tied up serving keystrokes. Direction of the fix is to cap the result set, require a minimum length, cache briefly, and use an index-friendly match.',
                Category::INDUSTRY,
                'Laravel docs: limiting query results and Eloquent where clauses',
                'MySQL docs: LIKE, leading wildcards and index usage'
            ),
            self::rule(
                'php-per-phase-log-lines',
                'Emit one structured record per request — not a line per phase you cannot correlate afterwards',
                'The code logs performance data as separate lines per phase of a request — one line at start, one when the query finishes, one on a cache miss, one when the response is sent — instead of one structured record per request carrying all the phase totals. The shape to look for is scattered Log::info or a PSR-3 logger->info call at each stage with no shared request id, or Monolog output where each line stands alone. Nothing ties the lines from one request together, and the interesting comparison — how the phases add up and which one dominates — has to be reconstructed by eye across interleaved output from concurrent workers. Symptom: to answer why one request was slow you grep and manually stitch lines from a log where several FPM workers are interleaving their output, and you cannot aggregate total-versus-DB-versus-cache time across requests because the numbers live on different lines. Direction of the fix is a single structured event per request with all the phase timings and a correlation id.',
                Category::INDUSTRY,
                'Monolog docs: structured context and processors',
                'PSR-3: logger interface and message context'
            ),
            self::rule(
                'php-first-or-create-race',
                'Make first-issue creates a single-winner insert — a read-then-write upsert lets two requests both create',
                'A create-if-absent path reads for an existing row and then writes when it finds none, with no unique constraint behind it, so two concurrent requests can both read nothing and both insert. The shape to look for is Eloquent\'s firstOrCreate() or updateOrCreate(), a Doctrine find() followed by persist(), or a hand-rolled SELECT-then-INSERT, used to issue something that must exist exactly once: an account, an order, a subscription, or a first-issue secret. These helpers read as atomic but are not — the check and the insert are two separate statements, and nothing stops a second caller slipping between them. Symptom: under a double-tap, a retry after a timeout, or an at-least-once queue redelivery, the row exists twice (duplicate accounts or orders) or an already-issued secret is silently overwritten by the second request, unauthenticating the first. Direction of the fix is to let the database arbitrate with a unique index and treat the insert as the single winner.',
                Category::INDUSTRY,
                'Laravel docs: firstOrCreate and updateOrCreate concurrency caveats',
                'MySQL docs: unique constraints and INSERT IGNORE behaviour'
            ),
            self::rule(
                'php-deep-clone-on-request-path',
                'Skip serialize/unserialize deep-copies on the request path — PHP arrays are already copy-on-write',
                'Code defensively deep-copies a large array or object graph before passing it around, usually with unserialize(serialize($data)), a hand-written recursive copy helper, or repeated clone calls down a nested structure, to avoid mutating a value that is shared with a caller. This shows up in normalisers, DTO builders, form processors, and cache layers that copy a payload before touching it. Symptom: a route spends real CPU inside unserialize or a copy helper on every request, memory peaks at roughly twice the size of the structure, and a profiler (Xdebug in dev, or the Boosthis sample) shows the copy routine, not the actual work, dominating the flame graph. This is almost always paying for a problem that is not there: PHP arrays are copy-on-write so an assignment or a by-value argument is free until something writes to the copy, and the built-in clone is shallow so a serialize round trip is the wrong tool for a genuinely deep copy anyway. The fix is to stop copying pre-emptively and only clone the narrow piece you actually mutate.',
                Category::INDUSTRY,
                'PHP manual: copy-on-write and reference counting for arrays',
                'PHP manual: object cloning and the clone keyword'
            ),
            self::rule(
                'php-image-processing-in-request',
                'Resize and convert uploaded images on a queue — GD / Imagick work pins an FPM worker for seconds',
                'The controller that accepts an image upload also decodes, resizes, crops, or re-encodes it inline with GD (imagecreatefromjpeg, imagescale, imagejpeg) or Imagick (readImage, thumbnailImage, writeImage), or through an Intervention Image / Imagine call on the request path. Often several derivative sizes are generated in the same request. Symptom: the upload response takes seconds rather than milliseconds, the FPM worker is CPU-bound and its resident memory balloons because a decoded bitmap is far larger than the compressed file, and a burst of uploads or a maliciously large image (a decompression bomb) saturates the pool and can trip memory_limit or max_execution_time. Image processing is CPU and memory heavy and does not belong on the worker that serves the response. The fix moves the derivative work to a background job and returns immediately.',
                Category::INDUSTRY,
                'PHP manual: Imagick and GD image processing',
                'Laravel docs: queues and dispatching jobs'
            ),
            self::rule(
                'php-shell-out-on-request-path',
                'Never shell out on the request path — exec / proc_open blocks the worker and invites injection',
                'A request handler launches an external process with exec, shell_exec, system, passthru, popen, or proc_open — a PDF renderer like wkhtmltopdf, a video transcoder like ffmpeg, an ImageMagick binary, a virus scanner, or a git call. Symptom: the FPM worker is blocked for the entire life of the child process (there is no default timeout, so a hung child hangs the worker until max_execution_time or the front server intervenes), and under load these calls occupy the whole pool. Worse, if any part of the command line is built from request input the same call is a command-injection hole, because the child runs with the web user\'s privileges. Look for user data concatenated into the command string, or arguments passed through the shell rather than as a discrete argument array. The fix moves the work to a queue and, where a call is unavoidable, hardens the argument handling.',
                Category::INDUSTRY,
                'PHP manual: program execution functions (exec, proc_open, escapeshellarg)',
                'OWASP: OS command injection prevention'
            ),
            self::rule(
                'php-password-hash-cost-on-request',
                'Cap deliberately-expensive crypto per request — a login storm turns hashing cost into a pool outage',
                'A hot route runs intentionally expensive cryptography on every hit: password_hash or password_verify with a high bcrypt cost, argon2id with a large memory_cost, sodium_crypto_pwhash tuned to a slow interactive or sensitive profile, or a key-derivation call (hash_pbkdf2, sodium_crypto_pwhash) repeated per request instead of derived once and cached. This appears on login, signup, password reset, and token-minting endpoints, sometimes with the cost factor set far above what the box can absorb. Symptom: each call burns tens to hundreds of milliseconds of pure CPU on an FPM worker, and because the slowness is the security feature, a credential-stuffing burst or a login storm saturates the pool and stalls completely unrelated routes. The distinguishing sign is that CPU, not I/O, dominates the request and the cost is proportional to the tuning parameter. The fix keeps the hash strong while bounding how much of the pool one storm can seize.',
                Category::INDUSTRY,
                'PHP manual: password_hash, cost, and the argon2 memory_cost parameter',
                'OWASP: password storage cheat sheet'
            ),
            self::rule(
                'php-db-error-text-leaked-to-response',
                'Never return raw database driver error text to the client — log it behind a reference instead',
                'An exception handler or a catch block echoes the database driver\'s own message into the HTTP response, for example returning $e->getMessage() from a caught PDOException or QueryException in a JSON error body, or letting a Doctrine or Eloquent exception reach the client with debug rendering on. Symptom: a user-facing error carries the failing SQL statement, real table and column names, constraint names, and sometimes the offending values, handing an attacker a free map of the schema and confirming injection probes. This often coexists with a broadly-permissive error format that was fine in development. Even with APP_DEBUG off, an application-level catch that deliberately surfaces the driver message defeats the framework\'s redaction. The fix logs the full driver detail server-side under a lookup reference and returns only a generic message plus that reference to the client.',
                Category::INDUSTRY,
                'OWASP: improper error handling and information exposure',
                'PHP manual: PDO error handling and PDOException'
            ),
            self::rule(
                'php-singleton-captures-request-state',
                'Never capture the request, user, or tenant in a container singleton — it leaks under Octane / RoadRunner',
                'A service is bound as a shared singleton (Laravel\'s $this->app->singleton or a bind resolved once, a Symfony service left at the default shared true) but its constructor captures request-scoped state and stores it in a property: the current Request, the authenticated user, the active tenant or organisation, the locale, or a per-request cache keyed to one user. The captive value is read on later requests instead of being resolved fresh. Symptom: under classic PHP-FPM nothing looks wrong because the process is torn down after each request, so the singleton is rebuilt every time and the bug hides. Under a persistent application server (Octane with Swoole, or RoadRunner) the worker survives, so the first request\'s user, tenant, or locale is served to every subsequent request on that worker — a serious cross-tenant data leak that surfaces as users seeing each other\'s data. The fix stops the singleton from holding request state, resolving it on demand instead.',
                Category::INDUSTRY,
                'Laravel Octane docs: managing memory and container state between requests',
                'Symfony docs: service container and shared services'
            ),
            self::rule(
                'php-doctrine-hydrate-then-filter',
                'Push where, order, and limit into the query — don\'t findAll then filter or slice in PHP',
                'Code fetches an entire result set and then narrows it in PHP: a Doctrine findAll() or findBy([]) followed by array_filter, usort, or array_slice; an Eloquent all() or an unconstrained get() followed by Collection where, sortBy, or slice; or a manual pager that hydrates every row and then takes one page\'s worth with array_slice. Symptom: the database returns far more rows than the response shows, hydration builds thousands of objects the request discards, memory climbs toward memory_limit, and a page that looks instant on seed data slows linearly as the table grows. The count shown by the pager is often computed by counting a fully-hydrated array rather than asking the database. The work being done in PHP — filtering, ordering, slicing — is exactly what the query engine does with an index. The fix moves the criteria, ordering, and limit into the query and uses the ORM\'s own paginator so the count and the page both come from the database.',
                Category::INDUSTRY,
                'Doctrine ORM docs: pagination with the Paginator and setMaxResults',
                'Laravel docs: query builder where clauses and pagination'
            ),
            self::rule(
                'php-handler-swallows-into-200',
                'Don\'t let a broad catch turn a failure into a 200 — report the error and return a real 5xx',
                'The application\'s own error handling turns a genuine failure into an apparent success: a catch (Throwable $e) that logs nothing and returns an empty response, a global handler or middleware that renders a 200 with a body saying an operation failed, or a Laravel handler render override / Symfony exception listener that maps everything to a soft OK. Sometimes the response is empty JSON, sometimes a success envelope with an error message buried inside it, but the HTTP status is 200. Symptom: uptime probes and error-rate dashboards look perfect because they read the status code, while customers see missing data, blank pages, or silently dropped writes. The failure never reaches your logging or alerting, so it can persist for days. The distinguishing sign is a 2xx status paired with a body that describes a failure, or a catch that returns without re-throwing or reporting. The fix reports the exception and returns an honest 5xx.',
                Category::INDUSTRY,
                'MDN: HTTP response status codes (5xx server errors)',
                'Laravel docs: error handling and the exception handler report method'
            ),
            self::rule(
                'php-download-read-into-memory',
                'Stream file downloads or hand them to the front server — never read the whole file into a string first',
                'A download route reads the entire file into a variable before sending it: file_get_contents() into a string then echo, a Laravel response()->make(file_get_contents(...)), or building the body in an ob_start() buffer. Watch for large media, generated PDFs, exports and backups served this way, and for content that is fetched from S3 or another store fully into memory before it is relayed. Symptom: peak memory on the download route tracks the file size, big files trip Allowed memory size exhausted or push the worker near memory_limit, and the FPM worker stays pinned for the whole transfer because it is copying bytes to a slow client one buffer at a time. On slow connections a handful of large downloads can occupy every worker in the pool at once. The fix is to stream with the built-in primitives, or better, delegate the byte transfer to nginx or Apache with an internal redirect header so the worker is freed the instant it has authorised the request.',
                Category::INDUSTRY,
                'PHP manual: readfile, fpassthru and output streaming',
                'nginx docs: X-Accel-Redirect internal redirects'
            ),
            self::rule(
                'php-retry-without-backoff-jitter',
                'Retry outbound calls with capped exponential backoff plus jitter — a fixed-delay loop is a synchronised outage',
                'A retry loop uses a constant delay and no spread: a for/while loop around a cURL or Guzzle call with sleep(1) between attempts, a queue job whose backoff property is a single fixed number, or a recursive catch that re-fires immediately. Watch also for retries that sleep on the request path, which hold the FPM worker for the whole delay. Symptom: when a dependency wobbles, every worker and every queued job retries in lockstep at the same instants, so the retries themselves become the load that keeps the dependency down, and Boosthis sees bursts of same-target outbound calls clustered at regular intervals. A brief blip turns into a sustained outage and recovery is delayed because the herd re-synchronises on each failure. The direction of the fix is capped exponential backoff with randomised jitter, a hard attempt ceiling, and moving the waiting off the request path.',
                Category::INDUSTRY,
                'AWS Architecture Blog: exponential backoff and jitter',
                'Google SRE Book: handling overload and retry amplification'
            ),
            self::rule(
                'php-string-concat-in-loop',
                'Assemble large strings by collecting parts and imploding once — don\'t hold a growing buffer in the worker',
                'A large document is built one piece at a time in a loop: a report, CSV, XML or JSON blob accumulated with repeated .= into a single variable, or worse a prepend ($out = $piece . $out) or a concatenation made while another variable still references the string, which forces a full copy of the buffer each pass. Watch for building the whole thing in memory before echoing it, especially over thousands of iterations. Symptom: an export that should be quick spends real CPU copying an ever-larger buffer and peaks in memory holding the finished multi-megabyte document, so the route trends toward memory_limit and the profile shows time in string handling rather than the query. The direction of the fix is to collect the parts in an array and implode() once, or to write to the output stream as you go so the whole document never sits in the worker at all.',
                Category::INDUSTRY,
                'PHP manual: strings, copy-on-write and implode',
                'PHP manual: fputcsv and writing to php://output'
            ),
            self::rule(
                'php-webfont-blocking-render',
                'Emit font-display swap and preload the critical webfont — server CSS controls FOIT and LCP',
                'The HTML and CSS your PHP templates render define webfonts without a display strategy: an @font-face block with no font-display, no preload hint for the font the first screenful needs, and several weights or styles pulled in when only one or two are used. Watch for fonts referenced only from a linked stylesheet the browser must download and parse before it even discovers the font, and for icon or brand fonts loaded on every page. Symptom: text is invisible while fonts load (a flash of invisible text) or reflows when they arrive (a flash of unstyled text), Largest Contentful Paint lands on a text block that appears late, and the network waterfall shows the font requested only after CSS parses. Because PHP renders the document head, it owns the fix. The direction is font-display: swap in the @font-face, a rel=preload hint for the single critical font, and trimming the loaded weights.',
                Category::INDUSTRY,
                'MDN: font-display and the flash of invisible text',
                'web.dev: preload critical webfonts to avoid layout shift'
            ),
            self::rule(
                'php-no-next-navigation-hints',
                'Emit conservative speculation rules for the likely next page — a server-rendered site can prefetch it',
                'A server-rendered multi-page PHP site gives the browser no hint about the likely next navigation, so every click starts the next document from nothing: no speculationrules script, no rel=prefetch or rel=prerender links, and no Link header suggesting the next page. This fits paginated lists, product-to-detail flows and wizards where the next step is predictable. Symptom: navigation between pages feels slow even though each page renders quickly on its own, because the browser only begins fetching the next document after the click, and field metrics show a large gap between the click and the next paint. The direction of the fix is to have your templates emit conservative speculation rules or prefetch hints for the most likely next URL, kept to safe idempotent GET pages so a prefetch or prerender never triggers a state-changing request.',
                Category::INDUSTRY,
                'web.dev: the Speculation Rules API for prefetch and prerender',
                'MDN: rel=prefetch and prerender resource hints'
            ),
            self::rule(
                'php-render-full-table-on-first-view',
                'Render only the first screenful and defer the rest — don\'t hydrate a whole table and count it on every page load',
                'A list view renders every row the query returned even though only the first screenful is visible, and pays a full COUNT on each load just to draw the pager: Model::all() or an unbounded ->get() fed straight into the template, a Doctrine findAll() into a loop, or a WP_Query with posts_per_page set to minus one. Watch for a full ->paginate() count on very large tables and for below-the-fold sections rendered eagerly with the initial response. Symptom: the page is fine on seed data but as the table grows the query, hydration, template loop and payload all scale linearly, time-to-first-byte climbs, memory rises, and the pager\'s COUNT becomes its own slow query. The direction of the fix is to fetch only the visible page, use a cursor or simple pager on large tables to avoid the count, and defer below-the-fold content to a second request.',
                Category::INDUSTRY,
                'Laravel docs: pagination, simplePaginate and cursorPaginate',
                'Doctrine ORM docs: pagination with setFirstResult and setMaxResults'
            ),
            self::rule(
                'php-images-emitted-at-full-size',
                'Emit responsive image sizes with a source set — don\'t ship the original upload and let CSS scale it down',
                'Templates output the original uploaded image and rely on CSS width or height to shrink it: an img whose src is the full-resolution upload with no srcset or sizes, a thumbnail or list view pointing at the print-resolution original, or a gallery that emits one giant file per item. In WordPress watch for the_post_thumbnail called with the full size or a custom template echoing the original URL instead of a registered size. Symptom: pages are heavy and slow on mobile even though they look fine, the network panel shows multi-megabyte images downscaled to a few hundred pixels on screen, and Largest Contentful Paint lands late on an oversized hero image. The direction of the fix is to generate the sizes you actually display and emit them with a srcset and sizes so each device downloads a file matched to its rendered dimensions.',
                Category::INDUSTRY,
                'MDN: responsive images with srcset and sizes',
                'WordPress developer docs: image sizes and wp_get_attachment_image'
            ),
            self::rule(
                'php-upload-read-into-memory',
                'Cap and stream uploads and request bodies — reading them whole into memory costs their full size per worker',
                'An upload or request body is pulled whole into a string: file_get_contents(\'php://input\') on a large POST, reading an uploaded file with file_get_contents to hash or forward it, or base64-encoding a blob (which costs a third more again) before storing it. Watch for missing upload_max_filesize, post_max_size and client_max_body_size limits, and for handlers that accept arbitrary body sizes. Symptom: a big upload spikes worker memory to the payload size (plus more for base64), trips Allowed memory size exhausted, and a few concurrent large uploads exhaust the pool\'s memory; an unbounded body is also a denial-of-service vector. The direction of the fix is to set hard size limits at the edge and in php.ini, stream the input in chunks rather than reading it whole, use the framework move helper for uploaded files, and hash from the file on disk rather than from an in-memory string.',
                Category::INDUSTRY,
                'PHP manual: upload_max_filesize, post_max_size and file uploads',
                'PHP manual: fopen php://input and stream_copy_to_stream'
            ),
            self::rule(
                'php-image-decode-memory-bomb',
                'Read image dimensions before decoding and cap them — a tiny compressed file can demand gigabytes to decode',
                'An image is decoded straight from an upload without first checking its pixel dimensions: imagecreatefromstring, imagecreatefromjpeg, a bare new Imagick($path), or an Intervention Image make() on user-supplied files with no width and height guard. Watch for thumbnailing or watermarking that opens the image before validating it, and for no resource limits set on the image library. Symptom: a highly compressed but enormously large image (a decompression bomb) that is small on disk asks for width times height times channels of memory when decoded, so a few-kilobyte file demands gigabytes, trips Allowed memory size exhausted, and one crafted upload can OOM the worker. The direction of the fix is to read the dimensions cheaply first with getimagesize, reject anything past a megapixel cap before decoding, and set the image library\'s own memory and area limits as a backstop.',
                Category::INDUSTRY,
                'PHP manual: getimagesize and GD memory considerations',
                'OWASP: file upload cheat sheet and decompression bombs'
            ),
            self::rule(
                'php-no-dimensions-on-emitted-media',
                'Emit width and height or aspect-ratio on media so the browser reserves space — otherwise content jumps',
                'Server-rendered templates emit images, iframes, embeds and ad slots with no width and height attributes and no CSS aspect-ratio, so the browser cannot reserve the space before the asset arrives. Watch for img tags without intrinsic dimensions, responsive images sized only by CSS, lazy-loaded media, and third-party embeds or ad containers dropped in with no placeholder box. Symptom: the page reflows and content jumps down as each async asset loads, Cumulative Layout Shift is high, and users tap the wrong thing because a late image pushed the layout. Because PHP renders the markup, the fix lives in the templates. The direction of the fix is to emit the intrinsic width and height (or a CSS aspect-ratio) on every element whose content arrives after first paint, so the browser lays out the correct-sized box immediately and the asset fills it without moving anything.',
                Category::INDUSTRY,
                'web.dev: optimise Cumulative Layout Shift with image dimensions',
                'MDN: img width and height and the aspect-ratio CSS property'
            ),
            self::rule(
                'php-http-client-not-centrally-configured',
                'Resolve one configured HTTP client from the container — not a fresh Guzzle client per class',
                'Each service, job, or controller builds its own outbound HTTP client inline — a new Guzzle Client, a bare curl_init, or a Symfony HttpClient created in the constructor or the method — instead of injecting one client configured once in a service provider or services.yaml. The base URI, connect and total timeouts, retry middleware, proxy, TLS options, and default headers (auth, user-agent, correlation id) are then set per call site, so they drift: one integration has a 5s timeout, another has none, and a third forgot the auth header. Symptom: an intermittent hang or a 401 that appears only on one endpoint, timeouts that vary by call site with no single place to change them, and no connection reuse because each ad hoc client opens a fresh TCP and TLS handshake for one request then is discarded. Centralising the client lets every call inherit the same timeouts, retries, and keep-alive.',
                Category::INDUSTRY,
                'Laravel docs: the HTTP client and Http::macro base configuration',
                'Symfony docs: HttpClient as a configured, injected service'
            ),
            self::rule(
                'php-pii-not-scrubbed-before-export',
                'Redact PII in a log processor before diagnostics leave the app — not at each call site',
                'Diagnostics carry personal data out of the app because it is attached at the call site: an error handler that reports the whole request payload, a Monolog context that passes an entire Eloquent model or a raw form array, and traces or breadcrumbs that capture query strings and route parameters verbatim. Typical shapes are Log::info(\'order\', [\'request\' => request()->all()]), an exception handler that serialises the input, or a monitoring integration that ships the full context array. Symptom: emails, addresses, card fragments, and tokens turn up in log files, the APM UI, or the error-tracker payload where support staff and the vendor can read them, and a data-protection review flags personal data outside its intended store. The reliable fix is to redact once at the logging layer with a processor so no individual call site has to remember.',
                Category::INDUSTRY,
                'Monolog docs: processors for enriching and filtering records',
                'OWASP: logging cheat sheet and sensitive data in logs'
            ),
            self::rule(
                'php-pii-in-trace-and-metric-labels',
                'Keep identifiers out of metric labels and transaction names — use route templates, not raw values',
                'High-cardinality identifiers are used as metric labels, tag dimensions, or transaction and span names: an email or customer id passed as a label, a raw path such as the URL with the numeric id baked in used as the transaction name, or a Monolog metric emitter tagging by user. This shows up where code builds a metric key by concatenating a request value, or where the APM auto-names transactions from the literal URI instead of the matched route pattern. Symptom two-fold: personal data such as emails and account ids is exported to the monitoring vendor inside label values, and the number of distinct time series explodes so dashboards slow, the metrics bill climbs, and per-endpoint charts fragment into thousands of near-empty lines. The direction of the fix is to label by bounded, non-personal dimensions and name transactions by the route template.',
                Category::INDUSTRY,
                'OWASP: sensitive data exposure in telemetry',
                'web.dev: metric cardinality and label design'
            ),
            self::rule(
                'php-secrets-in-logs',
                'Add credentials to the framework do-not-report and log redaction lists — never log tokens or DSNs',
                'Secrets reach logs and error reports directly: an Authorization header, cookie, or session id logged with the request, a database DSN or connection string that still contains the password, and the full environment dump some error handlers attach to a 500. Common shapes are Log::debug(\'headers\', request()->headers->all()), an exception page or reporter that serialises server and env, and a stored input log that keeps password and token fields. Symptom: bearer tokens, session values, and passwords appear in log files, in the error-tracker event, or in a stored request payload, so anyone with log access effectively holds live credentials and a secret-scanning audit fails. The fix is to add these keys to the framework dont-flash and dont-report lists and to the log processor redaction denylist so no handler can emit them.',
                Category::INDUSTRY,
                'OWASP: secrets management and logging cheat sheets',
                'Laravel docs: handler dont-flash and dont-report'
            ),
            self::rule(
                'php-stream-clients-have-no-connect-timeout',
                'Set an explicit connect and read timeout on stream and SOAP clients — cURL defaults do not apply',
                'Outbound calls go through the non-cURL clients: file_get_contents or fopen on a remote URL, a stream context, the SoapClient, or a library built on the PHP stream wrappers. These do not use CURLOPT timeouts at all; they inherit a single global default_socket_timeout that covers only socket inactivity, offer no separate connect timeout, and do not bound DNS resolution. Typical shapes are file_get_contents pointed at an http URL, a stream_context_create with no timeout key, or new SoapClient with default options. Symptom: a black-holed or slow-to-resolve host holds a PHP-FPM worker far longer than the configured number suggests, because name resolution and connect setup fall outside default_socket_timeout, and under load the whole pool parks on one dead upstream with no timeout error to alert on. The fix is an explicit per-call timeout plus a bounded connect and resolution step.',
                Category::INDUSTRY,
                'PHP manual: default_socket_timeout and stream context options',
                'PHP manual: SoapClient connection_timeout option'
            ),
            self::rule(
                'php-lookup-table-not-built-for-joins',
                'Index one list into a keyed map before matching against another — nested scans are quadratic',
                'Two lists are matched by scanning: for each element of one array or collection the code searches the other with in_array, array_search, or a nested foreach with an equality check, or a Collection first or firstWhere is called inside a loop. This is quadratic, so it is invisible on seed data and dominant in production. A second trap hides here: in_array and array_search default to loose comparison, which type-juggles so a string can match a number or the wrong row is found. Symptom: an in-memory join over a few thousand rows that should be near-instant takes seconds and the profile is dominated by the scan helpers, or an occasional wrong match because loose comparison equated unlike values. The fix is to index the lookup side once by its key and use direct key access, or to do the join in the database.',
                Category::INDUSTRY,
                'PHP manual: array key lookup, isset and in_array strict flag',
                'Laravel docs: collections keyBy for indexed lookups'
            ),
            self::rule(
                'php-mass-assignment-wide-open',
                'Bind a narrow allow-list on create and update — never guard nothing or pass the whole request',
                'Mass assignment is left open: an Eloquent model sets guarded to an empty array, declares an over-broad fillable list, or a controller passes request all straight into create or update; in a Symfony or Doctrine form the equivalent is binding every field of a request without a whitelist. Because the model then accepts any column present in the input, a field the form never displayed can be set by anyone who can craft the request. Symptom: a user elevates their own role, sets a verified or admin flag, changes a price or balance, or writes a foreign key they should not, simply by adding that field to the posted body, and it surfaces as a privilege-escalation or data-tampering finding rather than an error. The fix is a narrow explicit allow-list of exactly the fields the action may set.',
                Category::INDUSTRY,
                'Laravel docs: mass assignment, fillable and guarded',
                'OWASP: mass assignment and the mass assignment cheat sheet'
            ),
            self::rule(
                'php-eval-or-generated-include-at-runtime',
                'Never eval or write-then-include generated PHP on a request path — OPcache cannot cache it',
                'Code is compiled or evaluated at request time on a hot path: an eval call, a template or expression compiled to a fresh PHP file and included per request, or a config or container assembled and included on each request rather than once at deploy. Because OPcache caches compiled files by path and timestamp, code produced fresh each request is never a cache hit, and if generated files use changing names the cache fills with single-use entries that evict useful ones. Worse, any value interpolated from input into the evaluated string becomes executable code. Symptom: CPU spent in compilation on every request, an OPcache hit rate that will not rise and a cache full of one-shot entries, and in the eval case a remote-code-execution hole where user input reaches the compiler. The fix is to precompile once and remove runtime code generation from the request path.',
                Category::INDUSTRY,
                'PHP manual: OPcache and how the opcode cache is keyed',
                'OWASP: code injection and the dangers of eval'
            ),
            self::rule(
                'php-no-fragment-cache-for-repeated-partials',
                'Cache the expensive impersonal fragments and compose around the personal part — do not skip caching',
                'A page cannot be cached as a whole because one small part is personal, such as the logged-in user name or a basket count, so nothing on the page is cached at all and the expensive impersonal parts are recomputed on every request. This shows up as a Blade or Twig template that renders a heavy partial repeatedly, for example a product card in a loop or a navigation tree or sidebar, each re-running helpers, formatters, and lazy relation reads. Symptom: a listing page whose render time scales with the number of repeated partials and whose query and CPU cost is dominated by rendering the same impersonal fragment over and over, even though only a sliver of the page actually varies per user. The fix is to cache the expensive impersonal fragments and compose them around the small personal section rather than abandoning caching for the whole page.',
                Category::INDUSTRY,
                'Laravel docs: Blade caching and Cache::remember for fragments',
                'Symfony docs: Twig fragment and ESI caching for reusable parts'
            ),
            self::rule(
                'php-queue-job-not-idempotent',
                'Make queue jobs idempotent at the point of effect — at-least-once delivery will replay them',
                'A queued job (a Laravel Job dispatched to Redis/SQS/database, or a Symfony Messenger message handler) performs an external side effect — charging a card, sending a receipt, posting a stock movement, calling a webhook — with no guard that the same job can run more than once. Look for handle() methods that call a payment gateway or fire a mail with no unique key checked at the point of the effect, and for retry configuration (tries, backoff, retryUntil, or Messenger retry_strategy) that silently redelivers. Symptom: a customer is charged twice, a duplicate confirmation email lands, or a ledger shows two movements for one order, always after a worker was killed mid-run, a job timed out (the broker never got the ack), or the process was recycled by pm.max_requests or a deploy. It surfaces intermittently under load and is invisible in tests because a single run looks correct. The fix direction is a durable idempotency key tied to the effect itself, not merely a unique job name.',
                Category::INDUSTRY,
                'Laravel docs: queues, job retries and unique jobs',
                'AWS SQS docs: at-least-once delivery and duplicate messages'
            ),
            self::rule(
                'php-broadcast-inside-write-path',
                'Broadcast and dispatch events after commit and off the request — never inline in the write path',
                'A controller or service writes to the database and then, in the same synchronous request, broadcasts to every subscriber or fans an event out to listeners: a Laravel event that implements ShouldBroadcast dispatched inline, event() fired before DB::transaction() commits, or a Symfony EventDispatcher call that runs heavy listeners in-process. Look for broadcast() or event() calls sitting between a save and the response return, and for broadcast events not marked to run after commit. Symptom: the write endpoint\'s latency tracks the message broker and the number of subscribers rather than the row it changed, so p99 climbs when Pusher, Redis pub/sub or a websocket gateway is slow; and worse, subscribers are told about a change that then rolls back, leaving clients showing a row the database never kept. The fix direction is to defer the fan-out onto a queue and gate it on the transaction committing.',
                Category::INDUSTRY,
                'Laravel docs: broadcasting and ShouldBroadcast queued events',
                'Laravel docs: events dispatched after database commit'
            ),
            self::rule(
                'php-migrations-from-a-request',
                'Run schema migrations in the deploy step — never from a route, middleware or first-run bootstrap',
                'Schema-changing DDL is triggered by a web request rather than the deploy pipeline: an installer or setup route that calls Artisan::call(\'migrate\'), a middleware that runs pending migrations to catch up, a first-run bootstrap that creates or alters tables on the first hit, or a Symfony controller invoking the doctrine:migrations:migrate command. Look for calls to migrate, schema:update or Schema builder calls (Schema::create, Schema::table) reachable from an HTTP handler. Symptom: an ALTER TABLE holds a metadata or table lock that blocks every other request touching that table until it finishes, so a deploy or a single visit to the installer route freezes the site; and because two concurrent requests can enter the same code, two processes attempt the same migration at once and you get duplicate-column or already-exists errors, or a half-applied schema. The fix direction is to make DDL a deploy-time step gated to a single runner.',
                Category::INDUSTRY,
                'Laravel docs: running migrations and deployment',
                'Doctrine Migrations docs: executing migrations at deploy time'
            ),
            self::rule(
                'php-count-per-row',
                'Load related counts in one query with withCount — never COUNT children per row in the render loop',
                'A list view renders a parent per row and asks the database for a child count inside the loop: parent->children()->count() in a Blade foreach, count(parent->comments) that lazily loads the whole relation just to size it, or a Doctrine call that issues a COUNT DQL query per entity being rendered. Look for a ->count() or count() on a relation used inside a template or serializer loop, and for missing withCount at the query. Symptom: the list route fires one COUNT query for every row on the page, so a table of fifty items runs fifty extra round-trips, the query log fills with near-identical COUNTs, and p99 grows linearly with page size while the page looks fine on a handful of seeded rows. The fix direction is to load all the counts in one aggregate query, or maintain a denormalised counter column when the count is read far more often than it changes.',
                Category::INDUSTRY,
                'Laravel docs: withCount and aggregating related models',
                'Doctrine ORM docs: aggregate DQL and counting associations'
            ),
            self::rule(
                'php-cached-objects-break-across-deploys',
                'Cache plain arrays and version the key — serialized objects break when the class shape changes on deploy',
                'Objects are serialized straight into a shared cache (Redis or Memcached) so the cache is coupled to the class definition: Cache::put storing a whole Eloquent model or a domain object, serialize() of an object graph written to the store, or Doctrine\'s second-level cache holding entities, with a cache key that carries no version of the class shape. Look for cached values that are objects rather than plain arrays or scalars, and keys that stay identical across releases. Symptom: after a release renames or removes a property, every cached read returns an object with a missing or wrong field until the cache is manually cleared — an unserialize warning about a changed class, a null where a value should be, or __PHP_Incomplete_Class if the class was renamed — and during a rolling deploy both the old and new class shapes are live at once, each poisoning the other\'s cache reads. The fix direction is to cache plain arrays and scalars and put a shape version in the key.',
                Category::INDUSTRY,
                'PHP manual: serialize, unserialize and __PHP_Incomplete_Class',
                'Laravel docs: cache stores and cache key management'
            ),
            self::rule(
                'php-global-middleware-stack-bloat',
                'Scope middleware to the routes that need it — global entries tax every asset and health-check hit',
                'Middleware and event listeners are registered globally rather than where they apply: entries in Laravel\'s global middleware array (the $middleware property on the HTTP Kernel, or global middleware in bootstrap/app.php) that run for every route including static assets, health probes and simple redirects, and Symfony kernel.request listeners with no check on when they should act. Look for auth-adjacent, locale, feature-flag or logging middleware in the global stack rather than a route group, and for event subscribers that run unconditionally. Symptom: routes that should cost almost nothing — a health endpoint, an asset served through PHP, a bare redirect — still pay for twenty small middleware every hit, so there is a fixed per-request tax with no relationship to the route\'s own work, and it multiplies across your highest-volume, cheapest routes. The fix direction is to move middleware into route groups and gate listeners so only the routes that need them pay.',
                Category::INDUSTRY,
                'Laravel docs: middleware, groups and global middleware',
                'Symfony docs: kernel events and event listener priorities'
            ),
            self::rule(
                'php-static-state-leaks-across-requests',
                'Never store request-scoped data in a static or global — it leaks between requests under Octane and Swoole',
                'Per-request data is stashed in process-wide storage: a static property used as a cache (self::$currentUser, a static $config array filled from the request), a global variable holding the tenant or locale, or a singleton service that caches request-specific state across calls. Look for static properties written during a request, module-level state that is not reset, and singletons holding the current user, tenant, request or auth context. Symptom: under classic PHP-FPM this is harmless because the process state is discarded at the end of every request, so it passes every test and every FPM deployment; but under a persistent application server — Laravel Octane on Swoole or RoadRunner, or a Swoole HTTP server — the process is reused, so the next request (a different customer) inherits the previous request\'s static state, and you get cross-tenant data bleed, the wrong user in an audit log, or a stale config served to someone else. The fix direction is to keep request-scoped state in the request or container, never in a static or global, and to reset any that must exist.',
                Category::INDUSTRY,
                'Laravel Octane docs: memory leaks and resetting state between requests',
                'Swoole docs: coroutine and worker request isolation'
            ),
            self::rule(
                'php-fatal-error-unreported',
                'Register a shutdown handler for fatal errors — an OOM or timeout kill never reaches the framework handler',
                'The app relies solely on the framework\'s exception handler to report failures, so the failures that are not exceptions go unrecorded: an out-of-memory fatal (Allowed memory size exhausted), hitting max_execution_time, or the front server (nginx or PHP-FPM) killing a request that ran past its deadline. Look for an error-reporting setup that only hooks the framework handler or set_exception_handler, with no register_shutdown_function inspecting error_get_last(). Symptom: a route that OOMs or times out returns a blank 500 or a truncated response and leaves nothing in the application\'s own error tracker — the framework never got a chance to run because the process was terminated — so the incident is invisible except as a gap, and you cannot see which route or input caused it. The fix direction is a shutdown handler that inspects the last error so a fatal leaves a report, plus knowing which kills leave no PHP hook at all.',
                Category::INDUSTRY,
                'PHP manual: register_shutdown_function and error_get_last',
                'PHP manual: memory_limit and max_execution_time fatals'
            ),

            // ───────── Operational (46) — deployment/runtime configuration ─────────
            self::rule(
                'php-fpm-pool-sizing',
                'Size the PHP-FPM pool to CPU and memory — not a guessed pm.max_children',
                'A PHP-FPM pool runs with pm.max_children left at a copied default, or pm=dynamic with min/start/max spare values that never matched the box. Symptom: too few children means requests queue in the FPM backlog and time out under load while CPU sits idle; too many means concurrent requests overcommit memory and the OOM killer strikes. Size max_children to (available memory / average process size) and match pm mode to the traffic shape.',
                Category::OPERATIONAL,
                'PHP manual: FPM configuration (pm, pm.max_children)',
                'PHP manual: FPM process manager tuning'
            ),
            self::rule(
                'php-fpm-pm-mode-mismatch',
                'Match the FPM process-manager mode (static/dynamic/ondemand) to the workload',
                'The FPM pm mode is left on a default that fights the workload — ondemand on a busy site (paying fork cost on every burst), or dynamic with spare-server bounds that thrash spawning and killing workers. Symptom: latency spikes correlate with worker spawn/kill churn, or the first requests after an idle period are slow because no workers were kept warm; steady high-traffic pools usually want static or a well-tuned dynamic range.',
                Category::OPERATIONAL,
                'PHP manual: FPM pm modes (static, dynamic, ondemand)',
                'PHP manual: FPM spare-server settings'
            ),
            self::rule(
                'php-fpm-slowlog-off',
                'Enable the FPM slowlog so hung requests leave a stack behind',
                'The FPM pool has no request_slowlog_timeout / slowlog configured, so when a request hangs (a dead upstream, a lock, a runaway loop) there is no artifact showing where it stalled. Symptom: intermittent slow or stuck requests are diagnosed by guesswork because nothing captured the PHP call stack at the moment of the stall; a slowlog threshold records the stack of any request over the limit.',
                Category::OPERATIONAL,
                'PHP manual: FPM request_slowlog_timeout and slowlog',
                'PHP manual: FPM status and diagnostics'
            ),
            self::rule(
                'php-opcache-disabled-or-misconfigured',
                'Enable OPcache in production and size it so nothing is evicted',
                'Production runs with OPcache off, or with opcache.memory_consumption / opcache.max_accelerated_files too small for the codebase (plus vendor), so compiled bytecode is evicted and files are re-compiled on demand. Symptom: recompilation shows up as baseline CPU on every request and cache churn under the OPcache status; enabling OPcache and sizing memory and the file slots above the real file count keeps the whole app compiled once.',
                Category::OPERATIONAL,
                'PHP manual: OPcache configuration (memory_consumption, max_accelerated_files)',
                'PHP manual: OPcache status functions'
            ),
            self::rule(
                'php-opcache-validate-timestamps-prod',
                'Turn off opcache.validate_timestamps in production and clear OPcache on deploy',
                'Production leaves opcache.validate_timestamps=1 (the dev default), so OPcache stats every source file on a revalidate interval to check for changes that never happen in prod. Symptom: an avoidable stat() per included file on the revalidate cadence; setting validate_timestamps=0 removes the checks entirely — with the discipline that a deploy MUST clear OPcache (or reload FPM) so the new code is picked up.',
                Category::OPERATIONAL,
                'PHP manual: opcache.validate_timestamps and revalidate_freq',
                'PHP manual: opcache_reset and deploy invalidation'
            ),
            self::rule(
                'php-realpath-cache-too-small',
                'Raise realpath_cache_size / ttl so path resolution isn\'t re-stated constantly',
                'An app with a deep vendor tree and many includes runs with the default realpath_cache_size and a short realpath_cache_ttl, so PHP re-resolves and re-stats the same absolute paths repeatedly. Symptom: a measurable fraction of request time in filesystem path resolution, worse on network storage; raising the realpath cache size and TTL keeps resolved paths hot across requests.',
                Category::OPERATIONAL,
                'PHP manual: realpath_cache_size and realpath_cache_ttl',
                'PHP manual: filesystem stat cache'
            ),
            self::rule(
                'php-composer-autoloader-not-optimized',
                'Deploy with an optimized, authoritative Composer autoloader',
                'Production is deployed without composer install --no-dev --optimize-autoloader (or dump-autoload -o -a), so the PSR-4 autoloader probes the filesystem to locate each class instead of using a generated classmap, and dev-only packages ship too. Symptom: a burst of file_exists/stat calls at request startup while classes are resolved, plus wasted opcode/memory on dev tooling; an optimized authoritative classmap turns each class load into a single direct include.',
                Category::OPERATIONAL,
                'Composer docs — autoloader optimization levels',
                'Composer docs — installing for production (--no-dev)'
            ),
            self::rule(
                'php-xdebug-loaded-in-prod',
                'Never load Xdebug (or a profiler) in production',
                'The Xdebug extension is loaded in a production PHP build, even in "off" mode, adding hooks to the engine on every function call. Symptom: a large across-the-board slowdown that no application change explains, because Xdebug instruments the runtime itself; production must run without Xdebug (and without other always-on profilers) — enable them only in local/staging.',
                Category::OPERATIONAL,
                'Xdebug docs — production performance impact',
                'PHP manual: enabling extensions per environment'
            ),
            self::rule(
                'php-file-cache-session-on-network-storage',
                'Keep file-based sessions and cache off shared/network storage',
                'Sessions or the file cache are written to a directory that sits on NFS, EFS, or another network/shared filesystem (common with multi-node deploys that "just share the folder"). Symptom: every session read/write and cache hit pays network-filesystem latency and file-lock contention, and locking semantics differ from local disk, so the whole request path slows and sessions occasionally corrupt; a shared in-memory store (Redis/Memcached) is the correct backend for multi-node.',
                Category::OPERATIONAL,
                'PHP manual: session save handlers and storage backends',
                'PHP manual: file locking behaviour on network filesystems'
            ),
            self::rule(
                'php-max-execution-time-overrun',
                'Set max_execution_time to fail slow requests fast — and move long work off the request',
                'A web request routinely runs close to (or is allowed to exceed) max_execution_time doing work that belongs in a job — a big report, a bulk import, an image batch. Symptom: requests are killed mid-flight with a maximum-execution-time fatal, leaving partial writes and a broken response, or the limit is raised so high that a stuck request ties up a worker for minutes; cap the web limit and dispatch long work to a queue where the CLI limit applies.',
                Category::OPERATIONAL,
                'PHP manual: max_execution_time and set_time_limit',
                'PHP manual: CLI vs web SAPI time limits'
            ),
            self::rule(
                'php-error-display-on-in-prod',
                'Turn display_errors off in production and log instead',
                'Production runs with display_errors=On, so warnings and notices are emitted into the response body. Symptom: stray output corrupts JSON payloads and HTML, leaks file paths and internals to clients, and can break already-sent headers; production must set display_errors=Off with log_errors=On so problems are recorded, not shown.',
                Category::OPERATIONAL,
                'PHP manual: display_errors and log_errors',
                'OWASP — improper error handling and information exposure'
            ),
            self::rule(
                'php-no-page-cache',
                'Cache full pages / fragments for anonymous traffic instead of rendering every hit',
                'A content site or read-heavy app re-runs the full render (routing, queries, templating) for every anonymous visitor with no full-page cache and no reverse proxy (Varnish, an FPM/nginx fastcgi cache, or a CDN) in front. Symptom: identical anonymous pages are recomputed on every request while the underlying data barely changes, so traffic that a cached copy could absorb instead lands on PHP and the database; full-page or fragment caching for anonymous traffic offloads it.',
                Category::OPERATIONAL,
                'PHP manual: output caching strategies',
                'MDN — HTTP caching and reverse-proxy caches'
            ),
            self::rule(
                'php-static-assets-served-by-php',
                'Serve static assets from the web server / CDN — not through PHP',
                'Static files (images, CSS, JS, fonts, downloads) are streamed through a PHP controller (readfile() behind a route) or served without long-lived cache headers, so the FPM worker is occupied shuffling bytes the web server or CDN could serve directly. Symptom: workers tied up on asset transfer instead of application work, and repeat downloads that a cache header would eliminate; let the web server serve assets and put a CDN in front with far-future caching.',
                Category::OPERATIONAL,
                'PHP manual: readfile and X-Sendfile / X-Accel-Redirect',
                'MDN — caching static assets with far-future headers'
            ),
            self::rule(
                'php-fpm-metrics-not-exported',
                'Export the FPM status / OPcache metrics so you can see saturation',
                'The FPM pool status page and OPcache statistics are not scraped, so there is no time series for active/idle children, the listen queue, slow requests, or OPcache hit rate and evictions. Symptom: when latency degrades pool-wide you cannot tell whether the pool saturated, the listen backlog filled, or OPcache started thrashing, because there is nothing to correlate with the per-route timing; enable and scrape the FPM status and OPcache metrics.',
                Category::OPERATIONAL,
                'PHP manual: FPM pm.status_path and status metrics',
                'PHP manual: opcache_get_status metrics'
            ),
            self::rule(
                'php-boosthis-not-mounted-in-mcp',
                'Wire Boosthis into your AI editor\'s MCP config so the agent can read live perf state',
                "The project uses Boosthis for PHP perf tracking but the developer's AI editor has no Boosthis MCP server configured, so the assistant can't see live per-route timings, the rule book, or session summaries and instead guesses at performance. Symptom: AI-suggested changes ignore what is actually slow in this app. Add the local Boosthis stdio MCP server to the editor's MCP config so the agent reads real state before advising.",
                Category::OPERATIONAL,
                'Model Context Protocol docs — configuring MCP servers',
                'Boosthis README — MCP setup'
            ),
            self::rule(
                'php-unbounded-request-body-size',
                'Cap request body and upload size at the edge — an unbounded body is a memory and DoS risk',
                "No explicit limit is set on request body size: post_max_size / upload_max_filesize are left at defaults (or raised carelessly), and the web server (client_max_body_size in nginx, LimitRequestBody in Apache) plus framework validation impose no earlier ceiling. Symptom: an oversized JSON or multipart upload is buffered into a PHP-FPM worker's memory before any userland code runs, so a single large or malicious request pushes a worker toward memory_limit and ties it up; enough of them exhaust the pool and the site stalls. Rule/Fix shape: set post_max_size / upload_max_filesize to the real maximum the app accepts, enforce a matching (slightly smaller) limit at the web server so bodies are rejected BEFORE reaching PHP, and validate declared sizes/counts in the framework — reject early rather than buffer-then-fail.",
                Category::OPERATIONAL,
                'PHP manual: post_max_size, upload_max_filesize and enctype handling',
                'nginx docs — client_max_body_size / Apache LimitRequestBody'
            ),
            self::rule(
                'php-db-statement-no-timeout',
                'Set a per-statement DB timeout — one slow query pins a PHP-FPM worker and its connection',
                "Database calls run with no per-statement/session timeout: PDO or the driver is left with an unbounded (or very long) query timeout, and no server-side statement_timeout / MAX_EXECUTION_TIME is set. Symptom: a single pathological query — a missing index under load, a lock wait, a runaway report — blocks its PHP-FPM worker for the full duration (up to max_execution_time, which does NOT interrupt time spent inside the DB driver), holding a DB connection the whole time; a handful of these drain the FPM pool and the connection pool and the whole site appears to hang. Rule/Fix shape: set an explicit statement timeout close to the route's real budget (Postgres statement_timeout / MySQL max_execution_time via SET, or a driver read timeout) so a slow query is killed and surfaced as a retryable error instead of silently occupying a worker.",
                Category::OPERATIONAL,
                'PHP manual: PDO::ATTR_TIMEOUT and driver read timeouts',
                'PostgreSQL docs — statement_timeout / MySQL max_execution_time'
            ),
            self::rule(
                'php-jit-and-modern-runtime',
                'Run PHP 8.x with OPcache (and JIT where it helps) before micro-optimising userland',
                "The app runs on an older PHP (7.x or an early 8.0) or ships PHP 8.x without OPcache/JIT tuned, while effort goes into hand-optimising userland hot loops. Modern PHP 8.x with OPcache — and JIT (opcache.jit / opcache.jit_buffer_size) for CPU-bound work — plus current PHP-FPM settings typically beats userland micro-tuning by a wide margin, and JIT's benefit is concentrated in compute-heavy code (not I/O-bound request handlers). Symptom: baseline CPU and per-request latency are higher than the same code on a current runtime, and time is spent optimising code the engine could speed up for free. Rule/Fix shape: upgrade to a supported PHP 8.x, enable and size OPcache, set a JIT buffer only where profiling shows CPU-bound wins (leave it off / small for typical I/O-bound web workloads), and re-profile before micro-optimising userland.",
                Category::OPERATIONAL,
                'PHP manual: OPcache JIT (opcache.jit, jit_buffer_size) and when JIT helps',
                'PHP.net — supported versions and the PHP 8.x performance/JIT notes'
            ),
            self::rule(
                'php-fpm-worker-memory-not-monitored',
                'Export FPM worker RSS and OOM/recycle rates — a per-request leak shows up as worker growth, not a stack trace',
                "PHP-FPM worker memory is unmonitored: there is no export of per-worker RSS, memory_limit-exhaustion (fatal) rate, or pm.max_requests recycle churn, so a slow per-request leak or an occasional oversized allocation is invisible until it hurts. Symptom: workers quietly grow between recycles, 'Allowed memory size exhausted' fatals appear intermittently on unrelated routes, and the pool thrashes as pm.max_requests recycles workers faster than expected — with nothing in the app logs pointing at the cause because the fatal aborts mid-request. Rule/Fix shape: scrape the FPM status page (pm.status_path) and process RSS, alert on rising per-worker memory and OOM-fatal rate, keep pm.max_requests as a leak safety-net (not a substitute for finding the leak), and correlate growth to the routes that allocate most.",
                Category::OPERATIONAL,
                'PHP manual: PHP-FPM status page (pm.status_path) and pm.max_requests',
                'PHP manual: memory_limit exhaustion fatals and memory_get_peak_usage'
            ),
            self::rule(
                'php-heavy-health-check',
                'Keep health/readiness probes cheap — don\'t boot the DB and cache on every kubelet poll',
                "The health/readiness route (Laravel/Symfony/WordPress /health or /up) does real work on every hit: opens a DB connection, pings Redis, runs migrations-pending checks, or boots heavy service providers. Because orchestrators (Kubernetes, load balancers) poll these routes every few seconds across every instance, the probe traffic becomes a constant load that competes with real requests and, worse, can flap the whole fleet unhealthy the instant a dependency has a blip — even when the app could still serve cached/queued work. Symptom: a brief DB or cache hiccup marks every pod unhealthy at once and triggers a cascading restart/removal, and health-check DB connections show up as a steady baseline. Rule/Fix shape: make the liveness/readiness route return process/app readiness from in-memory bootstrap state (cheap and dependency-free), and keep deep dependency checks on a SEPARATE, less-frequent diagnostic endpoint that degrades gracefully rather than failing the whole probe.",
                Category::OPERATIONAL,
                'Kubernetes docs — liveness/readiness/startup probes and probe frequency',
                'Google SRE Book — health checking and cascading failure from dependency checks'
            ),
            self::rule(
                'php-drop-correlation-headers-at-proxy',
                'Forward correlation/tracing headers through nginx, Apache and your PHP HTTP clients',
                "Incoming correlation and tracing headers (x-boosthis-*, traceparent, X-Request-Id) are lost in transit: nginx/Apache don't pass the custom headers to PHP-FPM (fastcgi_param / underscore-in-header dropping), or the app's own outbound HTTP clients (Guzzle, cURL, Symfony HttpClient) don't re-attach them on downstream calls. Under PHP-FPM every request is a fresh process, so the ONLY way trace context survives is if each hop explicitly relays it. Symptom: a single user action can't be stitched into one trace across the proxy, the app, and downstream services — spans appear as disconnected roots and root-cause analysis stalls because the correlation id vanishes at a boundary. Rule/Fix shape: configure the web server to pass the tracing headers through to PHP (nginx underscores_in_headers / explicit fastcgi_param, Apache mod_headers), and propagate the incoming correlation headers onto every outbound client request so context flows end-to-end.",
                Category::OPERATIONAL,
                'W3C Trace Context — traceparent propagation across services',
                'nginx docs — underscores_in_headers and fastcgi_param header passing'
            ),
            self::rule(
                'php-post-max-size-truncation-invisible',
                'Keep the edge and PHP body limits in agreement — a body over post_max_size is silently discarded',
                'The app accepts POST bodies or uploads and relies on the request superglobals without checking whether the body was truncated. When a POST exceeds post_max_size, PHP does not raise an error to the handler: it discards the entire body, so the form-data and file superglobals arrive EMPTY and the code treats a large submission as a blank form. The related trap is a mismatch between the front server\'s body limit (nginx client_max_body_size or Apache LimitRequestBody) and PHP\'s post_max_size and upload_max_filesize, so a request is rejected at one layer with a 413 or silently emptied at another. Symptom: users report that large uploads or long forms save as empty or vanish with no error, and logs show a populated request line but empty parsed input. The fix is to align the limits and detect the truncation explicitly.',
                Category::OPERATIONAL,
                'PHP manual: post_max_size and upload_max_filesize',
                'nginx docs: client_max_body_size'
            ),
            self::rule(
                'php-no-per-release-perf-comparison',
                'Compare each release against the previous one by tag — a regression should gate rollout, not wait for complaints',
                'Releases go out with no per-release performance comparison. Response time, error rate, memory per request and queries per request are never grouped by the deployed version or release tag, so there is no before-and-after view across a deploy. Symptom: a change that adds an N+1, drops an index, or doubles memory per request ships unnoticed and is only discovered days later when customers complain or a bill spikes, because the aggregate dashboards blend the new release with the old and the regression is diluted. There is also no gate: a wide rollout proceeds even when the new version is measurably worse than the one before it. The fix is to tag releases and compare each against its predecessor before promoting it to all traffic.',
                Category::OPERATIONAL,
                'web.dev: performance budgets and regression tracking',
                'Google SRE Book: release engineering and canary analysis'
            ),
            self::rule(
                'php-db-transaction-left-open',
                'Detect work that exits without releasing the database — an open transaction holds row locks',
                'There is no detection for a request or job that begins a database transaction and then leaves on an exception path without committing or rolling back. A manual beginTransaction without a matching commit or rollback in a finally, or an early return between them, holds the transaction and its row locks open until the connection is torn down at request end. Symptom: unrelated requests block waiting on those locks, showing up as slow queries or lock-wait timeouts that seem to come from nowhere, and the leak is invisible in the code that caused it because nothing errors at the leak site. The angle is the missing safety net: leak detection is not turned on outside production and there is no alert on it in production. The fix is to close transactions with a guaranteed path and to detect leaks.',
                Category::OPERATIONAL,
                'Laravel docs: database transactions and the transaction closure',
                'PHP manual: PDO beginTransaction, commit and rollBack'
            ),
            self::rule(
                'php-recompress-static-assets-per-request',
                'Pre-compress static assets at build time — don\'t make a PHP-FPM worker gzip the same CSS on every hit',
                'Static JS, CSS, SVG or font files are compressed on demand for every request instead of once. Two shapes trip this: a PHP controller that reads an asset with file_get_contents() or readfile() and wraps it in gzencode() or ob_gzhandler before sending, and an nginx or Apache config that has gzip on for static locations but no gzip_static on, so the front server recompresses identical bytes each hit rather than serving a prebuilt .gz. On Apache the equivalent is mod_deflate running on assets with no mod_negotiation MultiViews serving a precompressed variant. Symptom: FPM worker CPU or nginx worker CPU is spent compressing unchanging files, time-to-first-byte on assets is higher than it should be, and under load the pool saturates serving files a cached compressed copy would serve for free; the compression ratio is identical every time because the input never changes, which is the tell that the work is wasted.',
                Category::OPERATIONAL,
                'nginx docs: ngx_http_gzip_static_module (gzip_static)',
                'MDN: HTTP compression and precompressed content'
            ),
            self::rule(
                'php-shared-install-id-baked-into-config',
                'Mint the install ID at first contact and share it fleet-wide — never bake one identity into the image or .env',
                'One install or instance identifier is a fixed literal every replica reports as its own. The shapes: a constant in a config file, an INSTALL_ID or CLIENT_ID line committed in .env or baked into the container image, a value read from a database seeder that runs identically on every deploy, or an id generated per process (in a worker boot or a static initialiser) so each FPM pool and each queue worker invents a different one. The first case makes the whole fleet look like a single install; the second makes one logical install look like many. Symptom: server-side dashboards show every node under one identity, or conversely the count of installs balloons as replicas scale, quotas and per-install limits misbehave, and a first-contact handshake keyed on the id either collides across nodes or never converges. This differs from minting a fresh id per real installation — here the fault is where the id lives and who shares it.',
                Category::OPERATIONAL,
                'PHP manual: random_bytes and cryptographically secure identifiers',
                'RFC 4122: UUID generation'
            ),
            self::rule(
                'php-max-children-raised-to-hide-blocking',
                'Fix blocking I/O rather than raising pm.max_children — more FPM workers just move the queue to the database',
                'pm.max_children in a PHP-FPM pool has been raised well above what the box\'s memory and CPU support, as a reaction to a saturated pool or a max-children-reached log line, rather than fixing the underlying slow work. The tell in config is a max_children value that, multiplied by the real per-worker RSS, exceeds available memory, or a static process manager sized to hide a slow downstream. PHP has no in-process thread pool and no async event loop for web requests: each request occupies one whole worker process for its full duration, so a request blocked on a slow query or a slow outbound call ties up a worker doing nothing. Symptom: adding workers briefly clears the FPM listen queue but the slowness reappears, database connections climb because each extra worker opens its own connection, and eventually a traffic spike drives the box into swap or the OOM killer, turning a slow page into a crashed pool; the FPM slowlog shows workers parked in the same downstream call.',
                Category::OPERATIONAL,
                'PHP manual: FPM configuration (pm, pm.max_children)',
                'PHP manual: FPM slowlog (request_slowlog_timeout)'
            ),
            self::rule(
                'php-worker-cold-start-budget',
                'Budget and measure the first request into a fresh worker — cold boot pays autoloader, container and OPcache warmup',
                'There is no explicit budget for the FIRST request handled by a freshly started PHP-FPM worker or a newly launched container, so cold latency is invisible and untracked. On a cold process the Composer classmap has not been loaded, OPcache holds no compiled bytecode so every included file is parsed and compiled, the framework container and the route, config and view caches are read and rebuilt from disk on first touch, and the first database connection pays its full handshake. This is a real PHP concern precisely because of the shared-nothing model: a scaled-in worker or a rolled deploy hands the first user a cold process. Symptom: the first request after a deploy, an autoscale event, or an ondemand worker spawn is many times slower than steady state, p99 spikes at deploy and scale-out even though average latency looks fine, and warm-traffic averages hide it entirely; the effect is largest with a big vendor tree and heavy bootstrap.',
                Category::OPERATIONAL,
                'PHP manual: OPcache configuration and preloading',
                'Laravel docs: optimize (config, route, view and event caching)'
            ),
            self::rule(
                'php-endpoint-latency-budget',
                'Set a server response-time budget per endpoint and fail builds or alert when a route crosses it',
                'There is no written server-side latency target for endpoints, so no response is ever considered too slow and nothing can fail on it. Under PHP-FPM or mod_php each request must finish fast because it occupies a whole worker for its duration, yet the codebase has no budget in continuous integration and no production alert threshold, so a route can degrade for months unnoticed. The shape is the absence of a target: no p95 or p99 goal per route, middleware and template rendering untimed, database and cache time not attributed. Symptom: latency drifts up release by release with no single culprit and no alarm, a Laravel or Symfony middleware or an N-plus-one query slips in and only a customer complaint surfaces it, and there is no number to point at when deciding whether a change regressed performance; the fix direction is to define the budget and treat crossing it as a defect.',
                Category::OPERATIONAL,
                'web.dev: performance budgets',
                'Symfony docs: profiler and Stopwatch for timing'
            ),
            self::rule(
                'php-unreachable-registered-routes',
                'Reconcile the route table against real traffic — delete or fix routes nothing ever reaches',
                'The application\'s route table contains entries that no real entry path, link, redirect, form action or documented external callback ever reaches. The shapes: a Laravel route in routes web or api, a Symfony route annotation or YAML entry, or a WordPress rewrite or register_rest_route call that survives after the URL was renamed and nothing links to the old one, an old API version left mounted, or a webhook callback route whose provider was never actually configured to call it. Because the route still compiles into the cached route table, it looks alive but receives no traffic. Symptom: php artisan route:list or the Symfony debug:router output shows more routes than production access logs ever hit, dead controllers accrete maintenance and security surface, and a webhook that was supposed to fire silently never does because the provider points nowhere; the way to see it is to diff the registered route list against real access-log paths over a representative window.',
                Category::OPERATIONAL,
                'Laravel docs: route:list artisan command',
                'Symfony docs: debug:router and routing'
            ),
            self::rule(
                'php-funnel-routes-not-prioritised',
                'Rank optimisation by traffic times latency — fix the shared funnel route before the loudest one',
                'Performance work is aimed at whatever route is most visible or most recently complained about rather than the routes every customer journey actually passes through: the auth middleware stack, the home controller, the list page that feeds every detail view, or a shared internal API endpoint. The tell is that nobody has ranked routes by request volume multiplied by per-request time, so effort lands on a rarely-hit admin export while the login and dashboard routes that gate every session go untuned. In PHP-FPM each request is an isolated process with no shared warm cache between them, so a slow shared route pays its full cost on every single hit across the whole pool. Symptom: after a round of optimisation the aggregate p95 and the FPM pool saturation barely move because the fixed route was a small slice of total time, while the funnel steps that dominate wall-clock across all users were never touched. Direction of the fix is to measure total time per route and reorder the work by leverage.',
                Category::OPERATIONAL,
                'Google SRE Book: identifying the critical user journey',
                'web.dev: prioritising work by user impact and traffic'
            ),
            self::rule(
                'php-highest-leverage-page-first',
                'Optimise the highest-leverage page first — centrality times latency beats a big win nobody loads',
                'Optimisation effort is spread evenly or aimed at whichever page is easiest to change, rather than at the page with the highest reach: the login wall every session starts at, the list or search page that every detail view is opened from, or the shared layout partial rendered on every response. The tell is that no one has identified which page has both high centrality (most journeys pass through it or fan out from it) and meaningful latency. In PHP-FPM there is no shared warm render cache between requests, so a heavy shared partial or a slow entry page pays its full cost on every load across every user. Symptom: a large, satisfying saving lands on a low-traffic page and the overall experience and pool pressure barely change, while the entry and hub pages that gate or feed most navigation stay slow. Direction of the fix is to score pages by reach times latency and take the top one first.',
                Category::OPERATIONAL,
                'web.dev: measuring and prioritising by real-user impact',
                'Google SRE Book: the critical path and highest-leverage work'
            ),
            self::rule(
                'php-composer-dependency-bloat',
                'Trim dependencies that cost per request — dev tools in prod and eager service providers tax every hit',
                'The Composer install carries packages that cost something on every request rather than only what the app uses: development tools shipped into production, packages whose Laravel service providers register and boot on each request, or an install done without the production-only flag so dev dependencies are autoloaded. The shape to look for is require-dev packages (Debugbar, Faker, a profiler, testing libraries) present in the deployed vendor tree, a long list of auto-discovered providers each doing work in register() and boot(), or a composer install run without the no-dev flag. In PHP-FPM every request boots the framework fresh with no shared warm state, so each provider\'s boot cost and each autoloaded class is paid again on every hit. Symptom: a baseline per-request overhead that has nothing to do with the route\'s own work, plus a larger classmap and slower cold boot after deploy. Direction of the fix is a production-only install and deferring or removing providers that do not need to run every request.',
                Category::OPERATIONAL,
                'Composer docs: install with the no-dev and optimize-autoloader flags',
                'Laravel docs: deferred service providers and package discovery'
            ),
            self::rule(
                'php-inline-gzip-of-dynamic-payloads',
                'Let the front server compress responses — don\'t gzencode a large dynamic payload inside PHP',
                'A handler builds a large dynamic body (a CSV or JSON export, a report, an API bundle, a backup archive) and compresses it in PHP itself with gzencode, gzcompress, the brotli extension, or a ZipArchive built entirely in memory before sending it. Sometimes this is doubled up with zlib.output_compression left on as well. Symptom: the route holds a PHP-FPM worker for the full compression pass on a body that may be tens of megabytes, CPU spikes on that worker, memory climbs toward memory_limit because both the raw and compressed copies exist at once, and time-to-first-byte is delayed until compression finishes. Under load a handful of these exports can occupy the whole pool. Compression of an HTTP response is the front server\'s job, and one-off large artefacts belong on a queue rather than inline in the request. The direction of the fix is to move the byte-squeezing off the worker.',
                Category::OPERATIONAL,
                'nginx docs: ngx_http_gzip_module and gzip_static',
                'MDN: HTTP compression (Content-Encoding)'
            ),
            self::rule(
                'php-persistent-connection-not-capped',
                'Cap persistent DB connections to fit max_connections — and reset the state each one carries forward',
                'The app opens the database with PDO::ATTR_PERSISTENT set true or the mysqli p: host prefix, or runs under a long-lived worker (Octane, RoadRunner, a queue worker) that holds a link across many requests, without accounting for how the connections multiply. Symptom: total open connections equal FPM workers times the number of distinct connection configs times the number of app servers, each link stays open indefinitely, and the database rejects new work with too many connections once that product exceeds its max_connections. A second, subtler symptom is state bleeding across requests on a reused link: SET session variables, temporary tables, user-level locks, an unconsumed unbuffered result, or a transaction left open after a fatal error all persist into whatever request next reuses that connection. The fix sizes the pool to the real worker count and makes each request start from a clean session state.',
                Category::OPERATIONAL,
                'PHP manual: PDO::ATTR_PERSISTENT and persistent connections',
                'MySQL docs: max_connections and connection handling'
            ),
            self::rule(
                'php-debug-packages-shipped-to-prod',
                'Install production deps without dev packages — don\'t ship Whoops or the profiler so a flag can enable it',
                'The production deployment installs the full dependency set including require-dev packages — Whoops, Ignition, Symfony\'s web-profiler-bundle and debug pack, Laravel Debugbar, Telescope, or a var-dumper server — so the pretty error page and profiler code physically exist on the production box. Look for a deploy that runs composer install without --no-dev, a build that ships the whole vendor tree, or a container image built from the dev dependencies. Symptom: a single mis-read environment flag (APP_DEBUG left true, APP_ENV at local, or display_errors on) is enough to render a full stack trace with environment values, database credentials, and query text to whoever triggered the error, because the rendering code is present and one setting away from active. The safe posture is that the debug code is not on the machine at all. The fix removes dev packages from the production artefact and asserts they are absent.',
                Category::OPERATIONAL,
                'Composer docs: install --no-dev and require-dev',
                'OWASP: improper error handling and information exposure'
            ),
            self::rule(
                'php-edge-limits-unset',
                'Set the front server\'s timeouts and rate limits — PHP can\'t defend a worker from a slow client',
                'The nginx, Apache, or PHP-FPM configuration leaves the protective limits at their defaults: no client header and body read timeouts (client_header_timeout, client_body_timeout, send_timeout), a long or default keep-alive timeout, no per-IP connection or request-rate limiting (limit_conn, limit_req), no cap on request body size, and no FPM request_terminate_timeout to kill a request that has run past its deadline. Symptom: a slow-reading or slow-sending client (a Slowloris-style attacker, or just a bad mobile link) holds an FPM worker open for as long as it likes, and because the pool is finite a handful of such clients exhaust every worker and the site stops responding — with nothing in the PHP logs, because PHP never got to run. PHP itself cannot enforce any of these; only the process in front of it can time out or shed a slow connection. The fix sets those edge limits and pairs them with an FPM terminate timeout.',
                Category::OPERATIONAL,
                'nginx docs: core timeouts and the limit_req / limit_conn modules',
                'PHP manual: FPM configuration (request_terminate_timeout)'
            ),
            self::rule(
                'php-no-asset-cache-headers',
                'Fingerprint static assets and serve a long Cache-Control — don\'t make browsers re-validate every CSS and JS',
                'CSS, JS, images and fonts are served without a far-future Cache-Control and without a content hash in the URL, so every page load re-validates them with a conditional request, or the opposite trap: a long max-age on an unfingerprinted filename, so a release ships new HTML against a browser\'s week-old cached CSS. Watch for asset URLs with no hash or version query, missing Cache-Control and immutable directives, and templates that reference styles by a bare path. Symptom: the network panel shows a stream of 304 Not Modified round-trips for unchanged assets on every navigation, time-to-interactive is dominated by revalidation rather than download, or users report broken styling after a deploy until they hard-refresh. The direction of the fix is a fingerprinted filename plus a one-year immutable Cache-Control served by the web server or CDN, so the browser reuses assets with no request at all and a new release simply produces a new URL.',
                Category::OPERATIONAL,
                'MDN: HTTP caching, Cache-Control and immutable',
                'web.dev: love your cache, long-lived fingerprinted assets'
            ),
            self::rule(
                'php-optimise-by-total-time-not-worst-case',
                'Rank optimisation work by requests times time saved — not by the single worst-case handler',
                'Optimisation effort is aimed at the scariest single number rather than where users spend time: a nightly export or a rare admin report at two seconds gets the attention while forty milliseconds on the endpoint every visitor loads is ignored. In a PHP-FPM shared-nothing app each request runs in its own process, so total worker-seconds burned across the fleet is dominated by high-traffic routes, not by the tallest p99 of a cold path. Symptom: a lot of tuning ships but the busy pages and the overall throughput barely move, the pool still saturates at peak, and the routes actually consuming the FPM workers never appear on the work list. The direction of the fix is to rank candidates by request rate multiplied by time saved per request, fix the common paths first, and treat rare heavy jobs as capacity to move off the request path rather than to micro-optimise.',
                Category::OPERATIONAL,
                'Google SRE Book: measuring and optimising by aggregate cost',
                'web.dev: optimise the paths real users take most'
            ),
            self::rule(
                'php-one-latency-budget-for-every-endpoint',
                'Budget latency per endpoint class — a single global target lies about probes, renders and exports',
                'One latency threshold is applied to every route: the alert, the SLO, or the max_execution_time is a single number regardless of whether the route is a health probe, a page render, a search, a write, an export, or a webhook receiver. This shows up as a global alert rule keyed only on overall p95, or a uniform timeout in nginx and PHP-FPM applied across all locations. Symptom: either the target is set high enough that the health check target is meaningless and real regressions on cheap routes hide, or it is set low enough that a legitimate export or report handler is permanently over budget and pages on-call every run. A single global number cannot be right for a 5ms probe and a 20s report at once. The fix is to group routes into classes and budget each class separately.',
                Category::OPERATIONAL,
                'Google SRE Book: service level objectives and latency targets',
                'web.dev: defining and measuring performance budgets'
            ),
            self::rule(
                'php-single-process-batch-work',
                'Fan batch work across worker processes sized to cores and the database — one script uses one core',
                'Heavy batch work is written as one sequential script: an import, a backfill, a nightly recalculation, or an export run as a single php artisan command or CLI script that loops over every row in one process. Because PHP under FPM and CLI is shared-nothing with no in-process thread pool for the work, one process uses exactly one CPU core, so a job that could saturate an eight-core box crawls on one eighth of it. Symptom: an import that ought to finish in minutes runs for hours while system load sits near 1.0 on a multi-core machine and the other cores idle, and the run cannot keep up with incoming volume. The fix is to split the work into ranges across several worker processes or several queue consumers, sizing the fan-out to the cores and the database rather than to the item count.',
                Category::OPERATIONAL,
                'Laravel docs: queue workers and running multiple consumers',
                'PHP manual: CLI process model and pcntl parallelism'
            ),
            self::rule(
                'php-framework-auto-reload-in-prod',
                'Turn off dev file-watching in production — auto-reload re-stats templates and rebuilds per request',
                'Development conveniences that watch the filesystem are still on in production: Twig or Blade template auto-reload that stats every template for changes, Doctrine proxy or metadata auto-generation set to generate at runtime, attribute or annotation metadata re-read each request, and the debug toolbar or profiler left enabled. The tell-tale config is APP_DEBUG or APP_ENV left at dev, twig auto_reload true, Doctrine auto_generate_proxy_classes set to always, or an uncached container and route table. Symptom: a fixed per-request tax that has nothing to do with the route work, a burst of filesystem stat calls per request visible under strace, cold latency after every request rather than only after deploy, and proxy or cache files being written at runtime. The fix is to disable the watchers and serve from a warmed, cached, timestamp-frozen build.',
                Category::OPERATIONAL,
                'Symfony docs: configuring the prod environment and cache warmup',
                'Doctrine ORM docs: proxy generation and metadata caching'
            ),
            self::rule(
                'php-queue-wait-time-not-watched',
                'Alert on queue wait time and oldest-pending-job age — a saturated worker fleet fails silently',
                'The queue is monitored only for failed jobs, not for backlog: dashboards track the failed_jobs table or a failure rate but nothing measures how long jobs wait before a worker picks them up. Look for a Laravel app running queue:work or Horizon with no alert on wait time, or a Symfony Messenger transport with no metric on the age of the oldest unconsumed message. Symptom: mail, webhooks and receipts arrive an hour late while every dashboard is green, because the jobs are not failing — they are simply queued behind a saturated fleet; the queue depth and the oldest pending job\'s age keep climbing but nothing pages, and you only learn of it from a customer asking where their email went. The fix direction is to measure and alert on per-queue wait time and backlog age, not just failures.',
                Category::OPERATIONAL,
                'Laravel Horizon docs: wait time thresholds and metrics',
                'Symfony Messenger docs: monitoring transports and failed messages'
            ),
            self::rule(
                'php-log-level-debug-in-prod',
                'Set the production Monolog level to info or warning — debug logging floods disk and leaks personal data',
                'Production is left at the most verbose logging level and query logging is on: LOG_LEVEL=debug or a Monolog channel configured at Debug, DB::listen or the Doctrine SQL logger writing every statement, or a framework log_queries flag left enabled. Look for a Monolog handler with a Debug level in a prod config, and for query or full-payload logging in the request lifecycle. Symptom: every request writes many lines to disk so the log volume and IO climb with traffic, personal data and request payloads (emails, tokens, card fragments) land in plain-text logs where they should not be, and a long-running queue worker or an Octane process accumulates the buffered log records in memory until it is recycled. Debug-level logging also masks real warnings in the noise. The fix direction is to raise the production level to info or warning and turn structured query logging off on the hot path.',
                Category::OPERATIONAL,
                'Monolog docs: logging levels and handlers',
                'OWASP: logging cheat sheet and sensitive data in logs'
            ),
            self::rule(
                'php-composer-audit-not-in-ci',
                'Run composer audit in CI and pin the security-advisories package — block a known-vulnerable dependency',
                'The CI pipeline installs dependencies but never checks them against published advisories: no composer audit step, and the roave security-advisories package is not required, so a transitive package with a known vulnerability can be installed and shipped. Look for a build that runs composer install with no audit gate, and a composer.json whose require-dev has no security-advisories entry. Symptom: a package deep in the dependency tree carries a public advisory (a deserialization flaw, an SQL injection, an XSS in a templating helper) and it sails through to production because nothing in the pipeline fails on it; you find out from a vulnerability scanner or an incident rather than from a red build. The fix direction is to make the pipeline list and block known-vulnerable packages, and to have the advisories package refuse to install them at all.',
                Category::OPERATIONAL,
                'Composer docs: the audit command and vulnerability reporting',
                'OWASP: dependency management and vulnerable components'
            ),
            self::rule(
                'php-secrets-committed-in-config',
                'Keep secrets in env or a secret store and out of the web root — a committed key must be rotated, not deleted',
                'Credentials live in the repository or are reachable over the web: a database password, API key or app key written into a committed config file (config/database.php with a literal, a services config with a hard-coded token), or an .env file that is not git-ignored, or worse an .env served over HTTP because the web server\'s document root points at the project root rather than the public directory. Look for literal secrets in versioned files, an .env missing from .gitignore, and an nginx or Apache root that exposes files above public. Symptom: anyone with repo access reads production credentials, and if the docroot is misconfigured a plain request for the env file returns it; and because git keeps history, deleting the secret from the current file does not remove it from earlier commits. The fix direction is to read every secret from the environment or a secret manager, keep the web root at the public directory, and rotate anything that was ever committed.',
                Category::OPERATIONAL,
                'Laravel docs: environment configuration and the env file',
                'OWASP: secrets management and hardcoded credentials'
            ),
            // ───────── Case Study (4) — cross-runtime batch (vendored drift, restart port hold, detached observer, fixed-ms timing assert) ─────────
            self::rule(
                'php-vendored-copy-drifts-from-source',
                'Regenerate generated/vendored artefacts in CI and fail on diff — never hand-edit the copy',
                "The repo embeds a GENERATED or COPIED artefact that duplicates a source of truth — a committed composer.lock, a compiled autoloader (vendor/composer/autoload_classmap.php from `composer dump-autoload -o`), a cached container/route/config file (Laravel's bootstrap/cache/*.php, `route:cache`, `config:cache`), a published vendor asset copied into public/, or a vendored package under vendor/ that was patched in place. Symptom: nothing errors and the page renders, but PHP loads the STALE cached/generated copy — an old cached routes file that ignores a new route, an optimized classmap that resolves an old class, a config cache that overrides an edited .env — so the source change appears to do nothing. Both files are valid PHP, so the drift never throws; it just serves the past until someone clears the cache or re-dumps by hand.",
                Category::CASE_STUDY,
                'Boosthis production incident (Aug 2026) — an edited route was ignored because the committed route:cache file was not regenerated and diffed in CI',
                'Laravel/Composer docs — cached routes/config and the optimized autoloader must be regenerated by their command and diffed in CI, never hand-edited'
            ),
            self::rule(
                'php-restart-old-process-holds-port',
                'A reload must serve the NEW code — fail loud when an old PHP-FPM/worker keeps the port or opcache',
                "A restart/reload leaves OLD PHP still answering: PHP-FPM is reloaded but a graceful worker keeps serving in-flight requests with the previous code, OPcache still holds the old compiled bytecode (opcache.validate_timestamps off or a long revalidate_freq), or a long-running worker (Laravel Octane/Swoole/RoadRunner, a queue:work daemon) keeps its bootstrapped app in memory and never picks up the new files. For a Swoole/RoadRunner HTTP server the classic form is the OLD server process still bound to the port while the new one either fails to bind or falls back to another port. Symptom: a deploy reports success and health checks pass, but requests run the PREVIOUS build because the old worker/opcache/server is still live; the version running is not the version deployed.",
                Category::CASE_STUDY,
                'Boosthis production incident (Aug 2026) — a deploy left an Octane/Swoole worker holding the port and serving stale bootstrapped code after php artisan octane:reload was skipped',
                'PHP-FPM / OPcache / Laravel Octane docs — reload workers and reset opcache on deploy; a bind failure must abort, not silently rebind'
            ),
            self::rule(
                'php-observer-never-attached',
                'Assert middleware/listeners are registered at boot and prove one real request moves the metric',
                "A middleware, an event listener/subscriber, a terminable middleware timing hook, or a metrics recorder (a prometheus/statsd counter) is written and autoloads fine, but nothing registers it — the middleware is never added to the HTTP kernel's \$middleware/\$middlewareGroups, the listener is never mapped in EventServiceProvider (or Symfony's tagged event subscriber is never tagged), or the counter is created but never incremented on the served path. Symptom: the metric reads zero forever, its dashboard tile is empty or perpetually warming, and unit tests pass because the middleware/listener is exercised in isolation rather than through a real request routed by the full kernel.",
                Category::CASE_STUDY,
                'Boosthis production incident (Aug 2026) — a latency middleware autoloaded and unit-tested green but was never added to the HTTP kernel, so its metric read zero in prod',
                'Laravel/Symfony docs — middleware and event subscribers only run once registered in the kernel/provider; assert registration and drive a real request through the app'
            ),
            self::rule(
                'php-fixed-ms-timing-assert',
                'Poll to a deadline instead of usleep plus a hardcoded millisecond assertion',
                "A PHPUnit test, a readiness gate or a health probe hinges on a FIXED number of milliseconds — it usleep()s or sleep()s a constant and then asserts, or it takes an hrtime(true)/microtime(true) delta and asserts the operation \"finished in under N ms\", or a probe's timeout is tuned to a machine-specific constant. On a shared or busy machine (a CI runner, a throttled container, a laptop mid-build, a first run where opcache is still warming up the compiled bytecode) wall-clock time measures the MACHINE, not the code — so the check fails when nothing is wrong AND, on an idle machine, stays green even when the code got slower; both failure directions bite. The fix: poll for the CONDITION with a retry-with-deadline helper up to a generous deadline instead of usleep-then-assert; assert ordering/causality or the operation's OWN reported duration rather than the test's wall clock; keep real latency budgets as percentiles from production telemetry, never a constant in a unit test; and where a hard timeout must exist (a health-probe timeout, max_execution_time) make it a generous bound on HANGING in seconds, not a performance assertion. Freeze time with a clock abstraction / ClockInterface (Symfony Clock, a mock clock) so scheduled logic is deterministic instead of sleeping.",
                Category::CASE_STUDY,
                'Boosthis production incident (Aug 2026) — a gate asserted a finish-time by reading microtime the instant it ran instead of polling for it to a bounded deadline, so it failed on a loaded runner while the code was correct',
                'PHPUnit / Symfony Clock docs — freeze time with a mock ClockInterface and poll a condition to a deadline; never assert a hardcoded usleep-plus-millisecond budget in a unit test'
            ),
            self::rule(
                'php-credentialed-refusal-signin-challenge',
                'Do not challenge a caller whose credential was understood',
                'In Laravel Symfony or PSR 15 middleware, a request that carried an authorization header receives status 401 plus an authenticate challenge even though the credential was understood but expired out of scope or forbidden for this resource. The caller discards a usable identity and treats the fast refusal as an outage.',
                Category::OPERATIONAL,
                'Laravel Symfony and PSR 15 authentication middleware documentation',
                'HTTP authentication status and challenge semantics'
            ),
            self::rule(
                'php-generic-refusal-message',
                'Give every refusal a distinct actionable code and message',
                'In Laravel Symfony or PSR 15 middleware, different refusal causes return byte identical bodies with no stable machine code. Callers cannot distinguish expired credentials missing scope throttling and policy denial, so every refusal looks like the same outage.',
                Category::OPERATIONAL,
                'Laravel and Symfony exception handler documentation',
                'HTTP problem detail and stable error code guidance'
            ),
            self::rule(
                'php-strict-credential-scheme-parsing',
                'Normalize credential schemes and token whitespace before parsing',
                'In PHP authentication middleware, credential parsing compares the scheme with exact letter case or splits on one literal space and leaves surrounding token whitespace quotes or line breaks intact. A harmless paste variation is refused as if the service were down.',
                Category::OPERATIONAL,
                'Laravel Symfony and PSR 7 request header documentation',
                'HTTP authentication scheme parsing guidance'
            ),
            self::rule(
                'php-shared-allowance-no-caller-key',
                'Partition shared allowances by caller and keep a global backstop',
                'In PHP, Laravel RateLimiter or Symfony RateLimiter uses one application wide or route wide counter for every caller. One noisy caller exhausts the allowance and unrelated callers receive refusals until the shared window resets.',
                Category::OPERATIONAL,
                'Laravel and Symfony rate limiter key documentation',
                'OWASP denial of service and rate limiting guidance'
            ),
            self::rule(
                'php-silent-protection-fail-open',
                'Surface every degraded protection even when it fails open',
                'In PHP, an authorization quota validation or rate limit middleware catches a backing store timeout missing configuration or dependency failure and continues the request with no metric log or health signal. The protection disappears while the service still looks healthy.',
                Category::OPERATIONAL,
                'Laravel Symfony and PSR 15 middleware error handling documentation',
                'Security control degraded mode observability guidance'
            ),
            self::rule(
                'php-validated-host-resolved-again',
                'Do not validate a host and then let cURL resolve it again',
                'Code accepts a webhook callback import or proxy URL, checks its host with dns_get_record or gethostbyname, then passes the original URL to cURL, Guzzle, or a Laravel HTTP client. cURL performs another lookup when it connects, and automatic redirect following can introduce a completely unchecked host, so DNS rebinding or a redirect can land on loopback, a private subnet, or a link local metadata address. This applies when CURLOPT_RESOLVE or CURLOPT_CONNECT_TO is not used to pin an approved address, or CURLOPT_FOLLOWLOCATION and Guzzle allow_redirects follow hops without resolving and judging each destination again.',
                Category::INDUSTRY,
                'PHP manual — dns_get_record and cURL CURLOPT_RESOLVE',
                'PHP manual — cURL redirect handling and CURLOPT_FOLLOWLOCATION',
                'OWASP SSRF prevention guidance — validate and pin every resolved address and redirect'
            ),
            self::rule(
                'php-global-error-logging-replaced-not-chained',
                'Do not replace the host error and logging pipeline',
                'A package or bootstrap calls set_error_handler or set_exception_handler and ignores the previous callable those functions return, calls Monolog setHandlers instead of adding or wrapping a handler, or uses Laravel Log swap after the host configured channels. Later errors bypass the hosts level format destination redaction and reporting integration. The same takeover occurs when a Symfony kernel exception listener stops propagation before the applications existing listener or reporter can run.',
                Category::OPERATIONAL,
                'PHP manual — set_error_handler and set_exception_handler return the previously defined handler',
                'Monolog docs — pushHandler and setHandlers',
                'Laravel logging docs — Log extend and Log swap'
            ),
            self::rule(
                'php-nonreentrant-lock-reacquired',
                'Do not reacquire a non reentrant PHP lock from its holder',
                'Code holds a Swoole Coroutine Mutex, an Amp LocalMutex lock, or a Laravel Cache lock and directly or through an event listener callback accessor or service call attempts to acquire the same lock again. These ownership paths are not recursive: the coroutine or fiber suspends waiting for itself, while Laravel block eventually throws LockTimeoutException. The trigger includes calling lock or acquire from a helper whose caller already owns the same mutex or cache lock key.',
                Category::INDUSTRY,
                'Swoole docs — Coroutine Mutex locking is non reentrant',
                'Amp Sync docs — LocalMutex acquisition and Lock release',
                'Laravel cache docs — atomic locks block and LockTimeoutException'
            ),
            self::rule(
                'php-retry-leaves-attempt-running',
                'Do not retry while an asynchronous attempt is still running',
                'Async PHP code races an Amp Future, Guzzle Promise, ReactPHP Promise, or cURL multi transfer against a timer and starts a retry when the timer wins without cancelling the losing operation. The abandoned transfer keeps its socket curl handle fiber or connection pool slot and can finish work nobody consumes, so repeated timeouts multiply concurrent calls. This applies when Amp work has no Cancellation token, a cancellable Guzzle or React promise never receives cancel, or a timed out cURL multi handle is left in the multi handle instead of being removed and closed before retry.',
                Category::OPERATIONAL,
                'Amp docs — Cancellation and DeferredCancellation',
                'Guzzle promises docs — cancellation propagation',
                'PHP manual — curl_multi_remove_handle removes an active transfer'
            ),
            self::rule(
                'php-dependency-call-no-timeout',
                'Put a deadline on every outside-service call — sign-in first',
                'A provider call is made through a client with no explicit timeouts: a Guzzle client or request with no connect_timeout and no timeout (Guzzle defaults both to 0, meaning wait forever), a bare cURL handle with neither CURLOPT_CONNECTTIMEOUT nor CURLOPT_TIMEOUT, or file_get_contents on an http stream relying on default_socket_timeout. Symptom: under PHP-FPM every stalled call holds an entire worker for the whole wait, so a handful of them exhaust the pool and the site stops answering while CPU sits idle. Distinct from php-blocking-curl-no-timeout in what it protects: the stalled party here is an outside service you do not control, and sign-in is the worst place for it because every session waits behind it.',
                Category::INDUSTRY,
                'Guzzle request options — timeout and connect_timeout default to 0, meaning wait indefinitely',
                'PHP manual — CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT'
            ),
            self::rule(
                'php-dependency-retry-uncapped',
                'Cap the retries against an outside service — an uncapped retry holds a worker per attempt',
                'Guzzle retry middleware, a queue job\'s retry configuration, or a hand-written loop retries an outside-service call with no attempt ceiling, no total budget, no jitter and no Retry-After handling: a RetryMiddleware decider that returns true on any failure, a job with unlimited tries that calls a provider, or a recursive catch that re-fires immediately. Symptom: every worker retries the struggling provider on the same cadence, so the retries become the load that keeps it down, and on a request path each attempt\'s sleep holds the FPM worker for the whole delay, so the retry ceiling and the worker pool are the same resource.',
                Category::INDUSTRY,
                'RFC 9110 — Retry-After',
                'AWS Architecture Blog — exponential backoff and jitter'
            ),
            self::rule(
                'php-dependency-calls-serial',
                'Run independent outside-service calls concurrently — PHP has no async model, but concurrent HTTP exists',
                'One request makes several calls to different outside services one after another where no call consumes the previous one\'s result — verify the token, then read the object\'s metadata, then load the customer at the gateway — as consecutive client request calls or consecutive curl_exec handles. Symptom: the request\'s wait is the SUM of every provider\'s latency rather than the slowest, and under FPM the worker is held for that whole sum, so the same number of visitors needs three times the workers. PHP has no per-request async model, but concurrent HTTP does exist here: a Guzzle promise pool and curl_multi run independent transfers together without changing anything else about the request.',
                Category::INDUSTRY,
                'Guzzle documentation — concurrent requests with Pool and Utils::settle',
                'PHP manual — curl_multi_exec runs several transfers in parallel'
            ),
            self::rule(
                'php-dependency-on-visitor-path',
                'Take an outside service off the request the visitor is waiting on',
                'A controller the visitor is waiting on calls an outside service whose answer never reaches the response — mail sent synchronously rather than queued, a CRM or webhook POST made inline, an audit event pushed to a logging vendor. Symptom: a slow mail provider makes the page slow and a broken one turns a successful signup into an error page, and under FPM the worker is held for the whole third-party round trip, so a provider having a bad minute costs the site its worker pool. Distinct from php-sync-mail-http-in-request in emphasis: the finding here is not that the work needs a runner, but that a dependency whose answer the visitor never sees is on their path at all.',
                Category::INDUSTRY,
                'Laravel documentation — queued mail and dispatching jobs after the response',
                'Transactional outbox pattern — the effect is recorded with the work that caused it and delivered by a worker'
            ),
            self::rule(
                'php-job-without-time-limit',
                'Give every queued job a deadline of its own, shorter than the queue\'s visibility window',
                'A queue job has no timeout property of its own, so it inherits the worker\'s timeout option or none at all when the worker was started without one, and the HTTP and database calls inside it have no timeouts either. Symptom: a run that hangs holds its worker slot until someone restarts the process, with nothing logged because the job is still notionally working. Where a job timeout IS set but exceeds the queue\'s retry_after or visibility window, the opposite failure appears: the same job is handed to a second worker while the first is still running it, so it executes twice.',
                Category::INDUSTRY,
                'Laravel queues documentation — the job timeout must be shorter than retry_after',
                'PHP manual — set_time_limit does not count time spent in blocking external calls on most platforms'
            ),
            self::rule(
                'php-job-retries-forever',
                'Cap job retries and give the last failure a destination',
                'A queue job is configured with unlimited tries (zero means unlimited in Laravel) or its worker runs with tries set to zero, and nothing distinguishes a permanent failure from a transient one; or a handler catches its own failure and dispatches itself again. Symptom: a job that can never succeed — a deleted model, a rejected payload, a revoked API credential — is retried until someone deletes it by hand, consuming a worker slot on every cycle and costing money at every provider call it makes, while the queue depth stays flat so no backlog alarm fires.',
                Category::INDUSTRY,
                'Laravel queues documentation — tries, backoff and the failed_jobs table as the terminal destination',
                'AWS Architecture guidance — dead-letter destinations exist so a poison message leaves the queue'
            ),
            self::rule(
                'php-ai-call-no-timeout',
                'Put a deadline on every AI provider call',
                'PHP has no first-party OpenAI or Anthropic SDK, and code calls an AI provider through Guzzle or cURL without both a connection timeout and a total timeout: a Guzzle request with no connect_timeout and timeout, or a cURL handle with neither CURLOPT_CONNECTTIMEOUT nor CURLOPT_TIMEOUT. AI generation can take minutes or stall, so under PHP-FPM one slow answer holds an entire worker and its outbound socket for the whole wait. Distinct from php-dependency-call-no-timeout in scope: this finding targets token-generating AI calls, whose normal latency is already long and whose streaming responses must remain bounded too.',
                Category::INDUSTRY,
                'Guzzle request options — timeout and connect_timeout',
                'PHP manual — CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT'
            ),
            self::rule(
                'php-ai-retry-ignores-retry-after',
                'Honor the AI provider\'s Retry-After before retrying',
                'PHP code using Guzzle or cURL catches an AI provider HTTP 429 or overloaded response and retries after sleep, usleep, or a fixed exponential delay without first reading the Retry-After response header as delta-seconds or an HTTP date. Retrying before the provider\'s published deadline sustains the rate limit, spends paid-call attempts on guaranteed refusals, and holds an FPM worker during every sleep. Distinct from php-dependency-retry-uncapped in scope: attempts may already be capped here; the defect is ignoring the AI provider\'s explicit next-safe-attempt time.',
                Category::INDUSTRY,
                'RFC 9110 — Retry-After',
                'OpenAI API documentation — rate limits'
            ),
            self::rule(
                'php-ai-calls-serial',
                'Run independent AI calls concurrently with Guzzle promises or cURL multi',
                'A PHP request sends independent AI completions, embeddings, classifications, or moderation calls one after another through consecutive Guzzle request calls or curl_exec handles even though no call consumes an earlier result. Since each generation can take seconds, the request waits for the SUM of their latencies and holds its FPM worker throughout; start Guzzle getAsync or postAsync promises before waiting, or run the handles together with curl_multi. Distinct from php-dependency-calls-serial in scope: this finding recognises multiple independent AI operations against the same provider as well as calls to different services.',
                Category::INDUSTRY,
                'Guzzle documentation — asynchronous requests and promise utilities',
                'PHP manual — curl_multi_exec runs several transfers in parallel'
            ),
            self::rule(
                'php-ai-duplicate-prompt',
                'Send each logical AI prompt only once per request',
                'One PHP request sends the same messages or prompt and parameters to the same AI model more than once through Guzzle, cURL, duplicate service helpers, or both a controller and listener path; or a retry runs after a successful response was already received. PHP-FPM request state is isolated, so the safe deduplication boundary is a request-scoped service or array keyed by a digest of provider, model, normalized parameters and prompt bytes. The duplicate pays for the same input and output tokens twice and usually discards one answer.',
                Category::INDUSTRY,
                'OpenAI API documentation — token usage',
                'Anthropic API documentation — usage fields'
            ),
            self::rule(
                'php-ai-prompt-cache-cold',
                'Keep large shared prompt prefixes stable so provider caching hits',
                'Large system instructions, tool schemas, or retrieved documents are repeatedly encoded into Guzzle or cURL JSON request bodies, but cached-input usage remains zero because timestamps, request IDs, associative-array insertion order, random tool order, or visitor-specific text changes bytes near the front. Keep the reusable prefix deterministically ordered and byte-stable, put changing content last, and use Anthropic cache_control blocks where supported. This is provider prompt caching, not PHP OPcache or an application response cache.',
                Category::INDUSTRY,
                'OpenAI API documentation — prompt caching',
                'Anthropic documentation — prompt caching'
            ),
            self::rule(
                'php-ai-stream-usage-not-requested',
                'Request usage metadata on every streamed AI response',
                'An OpenAI-family streamed completion sent as JSON through Guzzle or cURL sets stream to true but omits stream_options with include_usage true, or the PHP SSE-reading loop stops at the finish reason before consuming the terminal usage-only chunk. The application can forward token deltas from the response body or CURLOPT_WRITEFUNCTION callback but cannot measure or attribute the call\'s input, output, cached tokens, or cost. PHP has no first-party OpenAI SDK, so this is the provider\'s wire-level stream contract.',
                Category::INDUSTRY,
                'OpenAI API reference — stream_options.include_usage',
                'PHP manual — CURLOPT_WRITEFUNCTION for streaming response data'
            ),

            // ── Account-size scaling — a page whose cost grows with the tenant ──
            self::rule(
                'php-page-cost-scales-with-account',
                'Keep a page at a fixed query count as the account grows — judge it against a large tenant, not seed data',
                'A controller or Blade/Twig view does more work for a bigger tenant: a relation is touched inside a foreach so Eloquent or Doctrine lazily fires one SELECT per row while the template renders, or the page header shows a count over the whole tenant history that is recomputed on every request. Symptom: the page is instant on a seeded local database and no single statement reaches the slow log, yet it takes visibly longer for the customer with the largest account and creeps upward every month. Because PHP-FPM holds one worker for the whole request, a page whose query count grows also holds that worker and its database connection for longer, so the biggest tenants are the ones who drain the pool. Distinct from the eager-loading rule, which fixes one named relation, and from the unbounded-result rule, which caps the rows a single query returns — this asks whether the page as a whole holds a fixed budget as the tenant grows, and whether anyone has rendered it against a large tenant to find out.',
                Category::INDUSTRY,
                'Laravel documentation — withCount and eager loading resolve per-parent counts inside the parent query rather than one query per row',
                'Doctrine ORM documentation — fetch strategies, the N+1 problem and Paginator'
            ),
        ];

        return self::$cache;
    }

    /**
     * Find one rule by its stable id, or null when unknown. A small matcher
     * hook parallel to the accessors the sibling runtimes expose.
     */
    public static function findById(string $id): ?Entry
    {
        foreach (self::all() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * All rules in one category. A matcher hook for cross-runtime tooling that
     * groups rules the way the Java sibling's Category buckets do.
     *
     * @return list<Entry>
     */
    public static function byCategory(Category $category): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            if ($entry->category === $category) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Build one rule. Detection metadata only — never a fixTemplate. Mirrors the
     * private rule() helper in every sibling runtime; languages is fixed to
     * ["php"] for cross-runtime symmetry.
     *
     * @param string ...$evidence Plain reference text. No URLs.
     */
    private static function rule(
        string $id,
        string $title,
        string $whenToApply,
        Category $category,
        string ...$evidence
    ): Entry {
        return new Entry($id, $title, $whenToApply, array_values($evidence), $category);
    }
}
