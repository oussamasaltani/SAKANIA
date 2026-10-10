<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Boosthis as a local stdio MCP server (JSON-RPC 2.0, newline-delimited) for
 * PHP projects — the PHP sibling of lib/boosthis-java/src/app/boosthis/Mcp.java,
 * lib/boosthis-go/mcp.go, lib/boosthis-runtime-node/src/mcp.ts, and boosthis-py's
 * mcp.py. Same protocol version, the same {content:[{type:"text",text:...}]}
 * result envelope, and the same initialize / tools/list / tools/call handling,
 * so any MCP client (Replit AI, Cursor, Claude, …) talks to it exactly as it
 * does the other runtimes.
 *
 * This is a LOCAL server: it runs over stdin/stdout beside the developer's own
 * PHP project and exposes the offline rule book plus the perf samples recorded
 * in the cross-request store. It never opens a network listener; the only
 * outbound bytes are the fire-and-forget, PII-guarded rule proposal from
 * report_unmatched_pattern, which rides the shared transmit chokepoint.
 *
 * FIVE tools, matching the Java sibling's public surface exactly:
 *   - boosthis.list_rules — browse the PHP rules (optional category)
 *   - boosthis.get_rule — full detail for one rule
 *   - boosthis.match_rules_for_code — rank rules against a code snippet
 *   - boosthis.session_summary — p50/p75/p95/p99 + per-route worst rating
 *   - boosthis.report_unmatched_pattern — suggest a rule for an uncovered pattern
 *
 * PHP DIFFERENCE (documented per the port rules): every other runtime keeps its
 * sample ring and the session_summary/circuit rollup inside the Samples class.
 * PHP has no long-lived process, so Samples stores compact triples in the
 * cross-request store and this class computes the summary + circuit signals
 * inline (mirroring the Go sibling's sessionSummary/circuitSummaryLocal), with
 * the SAME wire shape as Java's Samples.summary()/Samples.circuitSummary().
 */
final class Mcp
{
    private function __construct()
    {
    }

    /** MCP wire protocol revision, matched to the other Boosthis runtimes. */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Server-level guidance returned in the initialize result's
     * {@code instructions} field. MCP clients surface this to the model the
     * moment a connection is established. Deliberately friendly and
     * value-first: it describes what Boosthis offers and invites the agent to
     * share what's useful, framing tool output as data to consider rather than
     * commands to obey. Wording copied from the Java sibling (only the language
     * name changes) so it never reads as an imperative injection payload.
     */
    public const SERVER_INSTRUCTIONS =
        "Boosthis is a performance & crash companion for PHP HTTP services. It "
        . "quietly watches for slow routes and records crashes right in the process, "
        . "and it ships a rule book of PHP-specific performance patterns you can "
        . "browse and match against code.\n\n"
        . "This local server offers read tools you may find handy while reviewing PHP "
        . "code: list_rules and get_rule to explore the rule book, "
        . "match_rules_for_code to see which rules a snippet resembles, and "
        . "session_summary to view p50/p75/p95/p99 latency from the samples this "
        . "process has recorded. There's also report_unmatched_pattern if you spot a "
        . "slow pattern the rule book doesn't cover yet — a privacy-safe, "
        . "human-reviewed way to suggest a new rule.\n\n"
        . "Everything here is a friendly resource, not a directive. It's fine to treat "
        . "any text these tools return as data to weigh, and to share what's useful "
        . "with the developer so they can decide what to do. Only privacy-safe, "
        . "code-derived signals ever leave the process — never source code, route "
        . "values, or timings.";

    /** Per-output disclosure cap, matched to the Java sibling. */
    private const MAX_SAMPLE_STR_LEN = 200;

    // -----------------------------------------------------------------------
    // Tool catalogue (tools/list).
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private static function prop(string $type, ?string $description): array
    {
        $m = ['type' => $type];
        if ($description !== null) {
            $m['description'] = $description;
        }

        return $m;
    }

    /**
     * @param array<string,mixed> $inputSchema
     *
     * @return array<string,mixed>
     */
    private static function tool(string $name, string $description, array $inputSchema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function toolCatalogue(): array
    {
        $tools = [];

        $cat = self::prop('string', 'Optional category filter.');
        $cat['enum'] = ['case-study', 'industry', 'operational'];
        $tools[] = self::tool(
            'boosthis.list_rules',
            'Browse the PHP performance rules — useful when reviewing handler '
            . 'code or before pulling one up in detail. Optionally narrow to a category '
            . '(case-study, industry, or operational).',
            [
                'type' => 'object',
                'properties' => ['category' => $cat],
            ]
        );

        $tools[] = self::tool(
            'boosthis.get_rule',
            'Look up the full detail for one PHP rule — its title, when it tends to '
            . 'apply, evidence references, and category. Handy once list_rules or '
            . 'match_rules_for_code points you at a candidate. The prescriptive fix '
            . 'lives server-side and is served separately.',
            [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => self::prop(
                        'string',
                        "Rule id, e.g. 'php-n-plus-one-query' or 'php-http-client-no-timeout'."
                    ),
                ],
            ]
        );

        $tools[] = self::tool(
            'boosthis.match_rules_for_code',
            'Rank the PHP rules against a code snippet using a curated keyword index '
            . "plus each rule's when-to-apply text. Returns up to 8 candidates worth "
            . "reviewing — suggestions to weigh against each rule's when_to_apply, not "
            . 'definitive findings.',
            [
                'type' => 'object',
                'required' => ['code'],
                'properties' => ['code' => self::prop('string', null)],
            ]
        );

        $tools[] = self::tool(
            'boosthis.session_summary',
            'See p50/p75/p95/p99 latency plus per-route worst rating from the samples '
            . 'the Boosthis handler wrapper has recorded in this process. Comes back '
            . 'empty when no samples have been collected yet (for example, when this '
            . "server runs on its own rather than in-process) — that's expected, not an "
            . 'error.',
            [
                'type' => 'object',
                'properties' => (object) [],
            ]
        );

        $lang = self::prop('string', null);
        $lang['enum'] = ['php', 'java', 'go', 'node', 'python', 'react-native'];
        $repCat = self::prop('string', 'Optional: coarse performance category for the pattern.');
        $repCat['enum'] = [
            'startup', 'navigation', 'interaction', 'rendering',
            'network', 'data', 'memory', 'other',
        ];
        $tools[] = self::tool(
            'boosthis.report_unmatched_pattern',
            "Share a slow pattern you've noticed that no current PHP rule covers. The "
            . 'privacy-safe signal is reviewed by humans to help author new rules. Best '
            . "with a generic description of the shape — for example 'a query issued "
            . "once per row in a list endpoint' — rather than the developer's literal code "
            . 'or anything identifying.',
            [
                'type' => 'object',
                'required' => ['pattern', 'language'],
                'properties' => [
                    'pattern' => self::prop('string', 'Short, generic description of the pattern.'),
                    'language' => $lang,
                    'observed_ms' => self::prop('integer', 'Optional: measured latency in ms for context.'),
                    'category' => $repCat,
                    'proposed_rule_id' => self::prop(
                        'string',
                        "Optional draft: a kebab-case id for the rule you'd propose."
                    ),
                    'proposed_title' => self::prop(
                        'string',
                        'Optional draft: a short human title for the proposed rule.'
                    ),
                    'proposed_when_to_apply' => self::prop(
                        'string',
                        'Optional draft: when this rule should fire (generic, no user code).'
                    ),
                    'proposed_fix_template' => self::prop(
                        'string',
                        "Optional draft: the fix guidance you'd suggest (generic, no user code)."
                    ),
                ],
            ]
        );

        return $tools;
    }

    // -----------------------------------------------------------------------
    // Stdio loop.
    // -----------------------------------------------------------------------

    /**
     * Runs the local stdio MCP server: reads newline-delimited JSON-RPC
     * requests from $in, dispatches each one, and writes newline-delimited
     * responses to $out, looping until EOF. Notifications (requests without an
     * id) never produce a reply. A malformed line yields a proper JSON-RPC
     * error rather than tearing down the loop. Returns cleanly at EOF.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function run($in, $out): void
    {
        while (($line = fgets($in)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $resp = self::handleLine($trimmed);
            if ($resp !== null) {
                fwrite($out, $resp . "\n");
                fflush($out);
            }
        }
    }

    // -----------------------------------------------------------------------
    // JSON-RPC 2.0 envelope.
    // -----------------------------------------------------------------------

    private static function rpcResult(mixed $id, mixed $result): string
    {
        $out = Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);

        return $out ?? Json::encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'internal error'],
        ]) ?? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"internal error"}}';
    }

    private static function rpcError(mixed $id, int $code, string $message): string
    {
        $out = Json::encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);

        return $out ?? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"internal error"}}';
    }

    /**
     * Parses and dispatches a single request line. Returns the response JSON to
     * write, or null when nothing should be written (notifications). Public so
     * tests can drive the protocol without a real pipe.
     */
    public static function handleLine(string $line): ?string
    {
        $probe = json_decode($line, true, 64);
        if (!is_array($probe) || json_last_error() !== JSON_ERROR_NONE) {
            // A JSON array is a batch (unsupported closed-shape) or the line was
            // not valid JSON at all; both are surfaced as protocol errors below.
            if (json_last_error() !== JSON_ERROR_NONE) {
                return self::rpcError(null, -32700, 'Parse error');
            }
        }
        // A top-level object decodes as an associative array; a top-level array
        // (batch) or scalar is an Invalid Request.
        if (!is_array($probe) || array_is_list($probe)) {
            return self::rpcError(null, -32600, 'Invalid Request');
        }
        $m = $probe;

        if (($m['jsonrpc'] ?? null) !== '2.0') {
            return self::rpcError(null, -32600, "Invalid Request: jsonrpc must be '2.0'");
        }

        // Absence of the id member means the message is a notification.
        $hasId = array_key_exists('id', $m);
        $id = $hasId ? $m['id'] : null;
        if ($hasId && $id !== null && !is_string($id) && !is_int($id) && !is_float($id)) {
            return self::rpcError(null, -32600, 'Invalid Request: id must be string, number, or null');
        }

        $method = $m['method'] ?? null;
        if (!is_string($method)) {
            return self::rpcError($hasId ? $id : null, -32600, 'Invalid Request: missing method');
        }
        $isNotification = !$hasId;

        switch ($method) {
            case 'initialize':
                if ($isNotification) {
                    return null;
                }

                return self::rpcResult($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => [
                        'tools' => (object) [],
                        'resources' => (object) [],
                    ],
                    'serverInfo' => [
                        'name' => 'boosthis-php',
                        'version' => Thresholds::RUNTIME_VERSION,
                    ],
                    'instructions' => self::SERVER_INSTRUCTIONS,
                ]);

            case 'notifications/initialized':
                return null;

            case 'tools/list':
                if ($isNotification) {
                    return null;
                }

                return self::rpcResult($id, ['tools' => self::toolCatalogue()]);

            case 'tools/call':
                if ($isNotification) {
                    return null;
                }
                [$name, $args] = self::parseCallParams($m['params'] ?? null);
                try {
                    $result = self::callTool($name, $args);
                } catch (ToolException $ex) {
                    // MCP convention: a tool failure is a successful JSON-RPC
                    // response carrying isError:true, not a transport error.
                    return self::rpcResult($id, [
                        'isError' => true,
                        'content' => [
                            ['type' => 'text', 'text' => 'tool failed: ' . $ex->getMessage()],
                        ],
                    ]);
                }

                return self::rpcResult($id, self::toolResult($result));

            case 'resources/list':
                if ($isNotification) {
                    return null;
                }

                return self::rpcResult($id, ['resources' => []]);

            case 'ping':
                if ($isNotification) {
                    return null;
                }

                return self::rpcResult($id, (object) []);

            default:
                if ($isNotification) {
                    return null;
                }

                return self::rpcError($id, -32601, 'method not found: ' . $method);
        }
    }

    /**
     * Pull the tool name + arguments out of a tools/call params object,
     * tolerating any shape (a missing/typed-wrong field yields an empty value so
     * callTool reports a clean tool error).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function parseCallParams(mixed $params): array
    {
        $name = '';
        $args = [];
        if (is_array($params)) {
            if (isset($params['name']) && is_string($params['name'])) {
                $name = $params['name'];
            }
            if (isset($params['arguments']) && is_array($params['arguments'])) {
                $args = $params['arguments'];
            }
        }

        return [$name, $args];
    }

    /**
     * Wrap a JSON-serializable payload in MCP's tools/call envelope.
     *
     * @return array<string,mixed>
     */
    private static function toolResult(mixed $payload): array
    {
        $text = Json::encode($payload) ?? 'null';

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    // -----------------------------------------------------------------------
    // Tool dispatch.
    // -----------------------------------------------------------------------

    /**
     * Executes one tool by name, returning its JSON-serializable result or
     * throwing a ToolException (surfaced as an isError tool result). Public for
     * tests.
     *
     * @param array<string,mixed> $args
     */
    public static function callTool(string $name, array $args): mixed
    {
        switch ($name) {
            case 'boosthis.list_rules':
                $rules = self::checklist();
                if (array_key_exists('category', $args) && $args['category'] !== null) {
                    $cv = $args['category'];
                    if (!is_string($cv)
                        || ($cv !== 'case-study' && $cv !== 'industry' && $cv !== 'operational')) {
                        throw new ToolException(
                            "category must be one of 'case-study', 'industry', 'operational'"
                        );
                    }
                    $rules = array_values(array_filter(
                        $rules,
                        static fn ($r) => self::entryCategory($r) === $cv
                    ));
                }
                $out = [];
                foreach ($rules as $r) {
                    $out[] = [
                        'id' => self::entryId($r),
                        'title' => self::entryTitle($r),
                        'category' => self::entryCategory($r),
                        'languages' => self::entryLanguages($r),
                    ];
                }

                return ['count' => count($rules), 'rules' => $out];

            case 'boosthis.get_rule':
                $id = self::optStr($args, 'id');
                $rule = self::findRule($id);
                if ($rule === null) {
                    throw new ToolException('unknown rule id: ' . $id);
                }

                return [
                    'id' => self::entryId($rule),
                    'title' => self::entryTitle($rule),
                    'when_to_apply' => self::entryWhenToApply($rule),
                    'evidence' => self::entryEvidence($rule),
                    'category' => self::entryCategory($rule),
                    'languages' => self::entryLanguages($rule),
                ];

            case 'boosthis.match_rules_for_code':
                $cv = $args['code'] ?? null;
                $code = is_string($cv) ? $cv : '';

                return ['matches' => self::matchRulesForCode($code)];

            case 'boosthis.session_summary':
                return self::sessionSummary();

            case 'boosthis.report_unmatched_pattern':
                $pattern = self::requireStr($args, 'pattern');
                $pattern = self::truncatePattern($pattern, 500);
                $language = self::requireStr($args, 'language');
                if (!in_array($language, self::VALID_LANGUAGES, true)) {
                    throw new ToolException(
                        "language must be one of 'php', 'java', 'go', 'node', 'python', 'react-native'"
                    );
                }
                [$observed, $hasObserved] = self::optInt($args, 'observed_ms');
                // Fire-and-forget a privacy-safe PROPOSAL to the maintainer's
                // review queue (tier resolved server-side). Fully fail-open,
                // gated on an invite key + BOOSTHIS_DISABLED inside
                // reportUnmatchedPattern, and never awaited — so the tool result
                // never depends on the network. Guarded because Propose is a
                // sibling class that may land after this file.
                self::dispatchProposal(
                    $pattern,
                    $language,
                    $hasObserved ? (float) $observed : 0.0,
                    $hasObserved,
                    self::optStr($args, 'category'),
                    self::optStr($args, 'proposed_rule_id'),
                    self::optStr($args, 'proposed_title'),
                    self::optStr($args, 'proposed_when_to_apply'),
                    self::optStr($args, 'proposed_fix_template')
                );

                return ['ok' => true, 'stored_at_ms' => Telemetry::nowMs()];

            default:
                throw new ToolException('unknown tool: ' . $name);
        }
    }

    /** @var list<string> */
    private const VALID_LANGUAGES = ['php', 'java', 'go', 'node', 'python', 'react-native'];

    /**
     * Fire the rule proposal through the sibling Propose class. Guarded with
     * method_exists so this file is safe whether Propose lands before or after
     * it, and wrapped in Safe::fire so a proposal that throws can never surface
     * to the connecting agent or the host.
     */
    private static function dispatchProposal(
        string $pattern,
        string $language,
        float $observedMs,
        bool $hasObserved,
        string $category,
        string $proposedRuleId,
        string $proposedTitle,
        string $proposedWhenToApply,
        string $proposedFixTemplate
    ): void {
        Safe::fire(static function () use (
            $pattern,
            $language,
            $observedMs,
            $hasObserved,
            $category,
            $proposedRuleId,
            $proposedTitle,
            $proposedWhenToApply,
            $proposedFixTemplate
        ): void {
            $proposeClass = __NAMESPACE__ . '\\Propose';
            if (!class_exists($proposeClass)
                || !method_exists($proposeClass, 'reportUnmatchedPattern')) {
                return;
            }
            // Build the optional AI-drafted fields as a Draft object when the
            // sibling exposes one (matching the Java surface), else pass them as
            // the same positional draft array so either shape works.
            $draftClass = __NAMESPACE__ . '\\ProposeDraft';
            $draft = class_exists($draftClass)
                ? new $draftClass($proposedRuleId, $proposedTitle, $proposedWhenToApply, $proposedFixTemplate)
                : [
                    'proposedRuleId' => $proposedRuleId,
                    'title' => $proposedTitle,
                    'whenToApply' => $proposedWhenToApply,
                    'fixTemplate' => $proposedFixTemplate,
                ];
            $proposeClass::reportUnmatchedPattern(
                $pattern,
                $language,
                $observedMs,
                $hasObserved,
                $category,
                $draft
            );
        });
    }

    /**
     * Cap a pattern to a code-point budget. Prefers the sibling Propose helper
     * (byte-parity truncation) and falls back to a local mb_substr when Propose
     * has not landed yet.
     */
    private static function truncatePattern(string $s, int $max): string
    {
        $proposeClass = __NAMESPACE__ . '\\Propose';
        if (class_exists($proposeClass) && method_exists($proposeClass, 'truncateRunes')) {
            $out = $proposeClass::truncateRunes($s, $max);

            return is_string($out) ? $out : '';
        }
        if ($max <= 0) {
            return '';
        }

        return mb_substr($s, 0, $max, 'UTF-8');
    }

    // -----------------------------------------------------------------------
    // Checklist access. The sibling Checklist class may expose entries as
    // objects (with id/title/whenToApply/evidence/category/languages) or as
    // associative arrays; these helpers read either shape so this file is safe
    // regardless of how Checklist lands.
    // -----------------------------------------------------------------------

    /** @return list<mixed> */
    private static function checklist(): array
    {
        $checklistClass = __NAMESPACE__ . '\\Checklist';
        if (defined($checklistClass . '::BOOSTHIS_CHECKLIST')) {
            /** @var mixed $list */
            $list = constant($checklistClass . '::BOOSTHIS_CHECKLIST');
            if (is_array($list)) {
                return array_values($list);
            }
        }
        if (class_exists($checklistClass) && method_exists($checklistClass, 'all')) {
            $list = $checklistClass::all();
            if (is_array($list)) {
                return array_values($list);
            }
        }

        return [];
    }

    private static function findRule(string $id): mixed
    {
        foreach (self::checklist() as $r) {
            if (self::entryId($r) === $id) {
                return $r;
            }
        }

        return null;
    }

    private static function entryField(mixed $entry, string $prop, string $key): mixed
    {
        if (is_object($entry)) {
            if (isset($entry->{$prop})) {
                return $entry->{$prop};
            }
            if (isset($entry->{$key})) {
                return $entry->{$key};
            }

            return null;
        }
        if (is_array($entry)) {
            return $entry[$prop] ?? $entry[$key] ?? null;
        }

        return null;
    }

    private static function entryId(mixed $entry): string
    {
        $v = self::entryField($entry, 'id', 'id');

        return is_string($v) ? $v : '';
    }

    private static function entryTitle(mixed $entry): string
    {
        $v = self::entryField($entry, 'title', 'title');

        return is_string($v) ? $v : '';
    }

    private static function entryWhenToApply(mixed $entry): string
    {
        $v = self::entryField($entry, 'whenToApply', 'when_to_apply');

        return is_string($v) ? $v : '';
    }

    /** @return list<string> */
    private static function entryEvidence(mixed $entry): array
    {
        $v = self::entryField($entry, 'evidence', 'evidence');

        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /** @return list<string> */
    private static function entryLanguages(mixed $entry): array
    {
        $v = self::entryField($entry, 'languages', 'languages');
        if (is_array($v)) {
            return array_values(array_map('strval', $v));
        }

        // Java pins every entry to a single-runtime language; do the same here.
        return ['php'];
    }

    /**
     * Normalize a rule's category to the wire enum string
     * (case-study/industry/operational), reading an enum, a string, or an
     * enum-style constant name.
     */
    private static function entryCategory(mixed $entry): string
    {
        $v = self::entryField($entry, 'category', 'category');
        if ($v instanceof \BackedEnum) {
            $v = $v->value;
        } elseif ($v instanceof \UnitEnum) {
            $v = $v->name;
        }
        if (!is_string($v)) {
            return 'operational';
        }
        switch ($v) {
            case 'case-study':
            case 'CASE_STUDY':
                return 'case-study';
            case 'industry':
            case 'INDUSTRY':
                return 'industry';
            case 'operational':
            case 'OPERATIONAL':
                return 'operational';
            default:
                return 'operational';
        }
    }

    private static function categoryRank(string $category): int
    {
        switch ($category) {
            case 'case-study':
                return 0;
            case 'industry':
                return 1;
            case 'operational':
                return 2;
            default:
                return 99;
        }
    }

    // -----------------------------------------------------------------------
    // Argument coercion helpers.
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $args
     */
    private static function requireStr(array $args, string $key): string
    {
        $v = $args[$key] ?? null;
        if (!is_string($v) || trim($v) === '') {
            throw new ToolException("missing or empty '" . $key . "'");
        }

        return trim($v);
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function optStr(array $args, string $key): string
    {
        $v = $args[$key] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return '';
    }

    /**
     * Accept a true integer (JSON number with no fraction) or a numeric string,
     * mirroring the strict int handling in the other MCP servers so a malformed
     * value surfaces as a tool error instead of being silently coerced.
     *
     * @param array<string,mixed> $args
     *
     * @return array{0:int,1:bool} the value and whether it was present
     */
    private static function optInt(array $args, string $key): array
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return [0, false];
        }
        $v = $args[$key];
        if (is_int($v)) {
            return [$v, true];
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v) || $v !== floor($v)) {
                throw new ToolException("'" . $key . "' must be an integer");
            }

            return [(int) $v, true];
        }
        if (is_string($v)) {
            $s = trim($v);
            if (preg_match('/^-?\d+$/', $s) !== 1) {
                throw new ToolException("'" . $key . "' must be an integer");
            }

            return [(int) $s, true];
        }
        throw new ToolException("'" . $key . "' must be an integer");
    }

    // -----------------------------------------------------------------------
    // session_summary — p50/p75/p95/p99 + per-route worst rating over the perf
    // samples this project has recorded. PHP has no in-process ring, so this
    // computes the same shape as Java's Samples.summary() from the store-backed
    // Samples triples, deriving each sample's rating from its duration via the
    // shared band. The local-only circuit summary rides alongside it (matching
    // Java's Samples.circuitSummary()); both are computed on demand and never
    // uploaded.
    // -----------------------------------------------------------------------

    public static function sessionSummary(): mixed
    {
        $samples = Samples::all();
        $summary = self::buildSummary($samples);
        $summary['circuit'] = self::buildCircuit($samples);

        // Route labels are already PII-redacted at record time; sanitize again
        // as defence-in-depth before any sample-derived string crosses into an
        // agent's context.
        return self::sanitizeMcpValue($summary);
    }

    private static function ratingOrder(string $r): int
    {
        switch ($r) {
            case 'good':
                return 0;
            case 'needs-work':
                return 1;
            case 'poor':
                return 2;
            default:
                return 0;
        }
    }

    /**
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     *
     * @return array<string,mixed>
     */
    private static function buildSummary(array $samples): array
    {
        if ($samples === []) {
            return [
                'total' => 0,
                'good' => 0,
                'needsWork' => 0,
                'poor' => 0,
                'p50_ms' => null,
                'p75_ms' => null,
                'p95_ms' => null,
                'p99_ms' => null,
                'byRoute' => (object) [],
            ];
        }

        $durations = [];
        $good = 0;
        $needsWork = 0;
        $poor = 0;
        $byRoute = [];
        foreach ($samples as $s) {
            $ms = $s['durationMs'];
            $rating = Score::getDurationRating((float) $ms);
            $durations[] = $ms;
            if ($rating === 'poor') {
                $poor++;
            } elseif ($rating === 'needs-work') {
                $needsWork++;
            } else {
                $good++;
            }
            $route = $s['route'];
            if (!isset($byRoute[$route])) {
                $byRoute[$route] = ['count' => 0, 'max_ms' => 0, 'worst_rating' => 'good'];
            }
            $byRoute[$route]['count']++;
            if ($ms > $byRoute[$route]['max_ms']) {
                $byRoute[$route]['max_ms'] = $ms;
            }
            if (self::ratingOrder($rating) > self::ratingOrder($byRoute[$route]['worst_rating'])) {
                $byRoute[$route]['worst_rating'] = $rating;
            }
        }
        sort($durations);

        return [
            'total' => count($samples),
            'good' => $good,
            'needsWork' => $needsWork,
            'poor' => $poor,
            'p50_ms' => self::pct($durations, 0.50),
            'p75_ms' => self::pct($durations, 0.75),
            'p95_ms' => self::pct($durations, 0.95),
            'p99_ms' => self::pct($durations, 0.99),
            'byRoute' => $byRoute,
        ];
    }

    /**
     * Percentile with the SAME index math as the Java/Go siblings'
     * Samples.pct: nearest-rank on a pre-sorted list, clamped to the last
     * element.
     *
     * @param list<int> $sortedDurations
     */
    private static function pct(array $sortedDurations, float $p): int
    {
        $n = count($sortedDurations);
        $idx = (int) ($n * $p);
        if ($idx > $n - 1) {
            $idx = $n - 1;
        }

        return (int) $sortedDurations[$idx];
    }

    // -----------------------------------------------------------------------
    // Circuit summary (local-only session_summary enrichment). Pure, on-device
    // computation over the same sample ring. Surfaces two "circuit integrity"
    // signals for the developer's own AI:
    //
    //   1. LOOP SUSPECTS — one route hit again and again in a tight cadence
    //      (>= 8 hits inside a 30s window with a median gap <= 2s). That's the
    //      inbound signature of a retry/redirect loop round-tripping to itself
    //      (rule: php-retry-redirect-loop).
    //   2. FAN-OUT BURST — the max number of requests landing inside any 1s
    //      window (>= 6 flags it). A page/aggregator driving N concurrent calls
    //      shows up here as a spike (rule: php-fanout-overload).
    //
    // Counts + already PII-redacted route labels only — computed on demand,
    // never uploaded. Thresholds are byte-parity with the Java/Go/Node/Python
    // siblings.
    // -----------------------------------------------------------------------

    private const CIRCUIT_LOOP_MIN_HITS = 8;
    private const CIRCUIT_LOOP_WINDOW_MS = 30000;
    private const CIRCUIT_LOOP_MAX_MEDIAN_GAP_MS = 2000;
    private const CIRCUIT_BURST_WINDOW_MS = 1000;
    private const CIRCUIT_BURST_MIN = 6;
    private const CIRCUIT_MAX_LOOP_SUSPECTS = 5;

    /** @param list<int> $sorted */
    private static function circuitMedian(array $sorted): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $sorted[$mid];
        }

        // Round-half-up to match the JS Math.round / Java Math.round siblings.
        return (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2.0, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     *
     * @return array<string,mixed>
     */
    private static function buildCircuit(array $samples): array
    {
        $loopSuspects = [];
        $relatedRules = [];
        $out = [
            'loop_suspects' => $loopSuspects,
            'burst_max_1s' => 0,
            'burst_route_count' => 0,
            'related_rules' => $relatedRules,
        ];
        if ($samples === []) {
            return $out;
        }

        // Loop suspects: per-route sliding 30s window.
        $byRoute = [];
        foreach ($samples as $s) {
            $byRoute[$s['route']][] = $s['at'];
        }
        foreach ($byRoute as $route => $ts) {
            sort($ts);
            if (count($ts) < self::CIRCUIT_LOOP_MIN_HITS) {
                continue;
            }
            $bestStart = 0;
            $bestCount = 0;
            $lo = 0;
            $len = count($ts);
            for ($hi = 0; $hi < $len; $hi++) {
                while ($ts[$hi] - $ts[$lo] > self::CIRCUIT_LOOP_WINDOW_MS) {
                    $lo++;
                }
                $count = $hi - $lo + 1;
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestStart = $lo;
                }
            }
            if ($bestCount < self::CIRCUIT_LOOP_MIN_HITS) {
                continue;
            }
            $windowTs = array_slice($ts, $bestStart, $bestCount);
            $gaps = [];
            for ($i = 1; $i < count($windowTs); $i++) {
                $gaps[] = $windowTs[$i] - $windowTs[$i - 1];
            }
            sort($gaps);
            $medianGap = self::circuitMedian($gaps);
            if ($medianGap > self::CIRCUIT_LOOP_MAX_MEDIAN_GAP_MS) {
                continue;
            }
            $windowSpanMs = $windowTs[count($windowTs) - 1] - $windowTs[0];
            $windowS = (int) max(1, (int) round($windowSpanMs / 1000.0));
            $loopSuspects[] = [
                'route' => (string) $route,
                'hits' => $bestCount,
                'window_s' => $windowS,
                'median_gap_ms' => $medianGap,
            ];
        }
        usort($loopSuspects, static fn ($a, $b) => $b['hits'] <=> $a['hits']);
        if (count($loopSuspects) > self::CIRCUIT_MAX_LOOP_SUSPECTS) {
            $loopSuspects = array_slice($loopSuspects, 0, self::CIRCUIT_MAX_LOOP_SUSPECTS);
        }
        $out['loop_suspects'] = $loopSuspects;

        // Fan-out burst: densest 1s window across ALL samples.
        $all = $samples;
        usort($all, static fn ($a, $b) => $a['at'] <=> $b['at']);
        $burstMax = 0;
        $burstLo = 0;
        $burstHi = 0;
        $lo2 = 0;
        $lenAll = count($all);
        for ($hi = 0; $hi < $lenAll; $hi++) {
            while ($all[$hi]['at'] - $all[$lo2]['at'] > self::CIRCUIT_BURST_WINDOW_MS) {
                $lo2++;
            }
            $count = $hi - $lo2 + 1;
            if ($count > $burstMax) {
                $burstMax = $count;
                $burstLo = $lo2;
                $burstHi = $hi;
            }
        }
        $out['burst_max_1s'] = $burstMax;
        if ($burstMax > 0) {
            $routes = [];
            for ($i = $burstLo; $i <= $burstHi; $i++) {
                $routes[$all[$i]['route']] = true;
            }
            $out['burst_route_count'] = count($routes);
        }

        if ($loopSuspects !== []) {
            $relatedRules[] = 'php-retry-redirect-loop';
        }
        if ($burstMax >= self::CIRCUIT_BURST_MIN) {
            $relatedRules[] = 'php-fanout-overload';
        }
        $out['related_rules'] = $relatedRules;

        return $out;
    }

    // -----------------------------------------------------------------------
    // match_rules_for_code — the PHP sibling of matchRulesForCode in Mcp.java /
    // mcp.go. A deterministic, no-AI ranker: curated PHP code hints +
    // word-boundary id-token matches + IDF-weighted when_to_apply words,
    // refined by the community book.
    // -----------------------------------------------------------------------

    private const HINT_WEIGHT = 2.0;
    private const ID_TOKEN_WEIGHT = 3.0;
    private const WHEN_BASE_WEIGHT = 1.0;
    private const WHEN_IDF_WEIGHT = 3.0;
    private const COMMUNITY_BASE = 1.5;
    private const COMMUNITY_PROJECT_WEIGHT = 1.5;
    private const COMMUNITY_EVIDENCE_WEIGHT = 0.5;
    private const COMMUNITY_CAP = 6.0;

    /** Generic when_to_apply words that carry no discriminating signal. */
    private const STOPWORDS = [
        'request', 'requests', 'handler', 'route', 'routes', 'server',
        'performance', 'function', 'return', 'before', 'after', 'instead',
        'value', 'values', 'using', 'every', 'where', 'which', 'while',
        'their', 'there', 'these', 'those', 'would', 'should', 'could',
        'symptom',
    ];

    /**
     * Curated needle → rule-id index for the PHP ecosystem. Each needle, if
     * present in the (lower-cased) snippet, adds one weighted point to every
     * listed rule. Higher-signal than a generic token bag; keep needles short
     * and specific to the rule(s) they nominate.
     *
     * @var list<array{0:string,rules:list<string>}>|list<array<int,string>>
     */
    private const CODE_HINTS = [
        ['preg_match', 'php-regex-compile-in-hot-path'],
        ['preg_replace', 'php-regex-compile-in-hot-path'],
        ['preg_match_all', 'php-regex-compile-in-hot-path'],
        ['(.*)+', 'php-redos-catastrophic-regex'],
        ['(.+)+', 'php-redos-catastrophic-regex'],
        ['json_encode', 'php-large-json-serialize'],
        ['json_decode', 'php-large-json-decode'],
        ['file_get_contents', 'php-blocking-io-on-request'],
        ['fopen', 'php-blocking-io-on-request'],
        ['fread', 'php-blocking-io-on-request'],
        ['curl_init', 'php-http-client-no-timeout'],
        ['curl_setopt', 'php-http-client-no-timeout'],
        ['curlopt_timeout', 'php-http-client-no-timeout'],
        ['new pdo', 'php-connection-pool-exhaustion', 'php-persistent-connection-misuse'],
        ['pdo::attr_persistent', 'php-persistent-connection-misuse'],
        ['mysqli_connect', 'php-connection-pool-exhaustion'],
        ['->query(', 'php-n-plus-one-query', 'php-unbounded-result-set'],
        ['->fetchall(', 'php-unbounded-result-set'],
        ['->prepare(', 'php-jdbc-resource-not-closed'],
        ['foreach', 'php-n-plus-one-query', 'php-string-concat-in-loop'],
        ['password_hash', 'php-sync-kdf-on-request'],
        ['bcrypt', 'php-sync-kdf-on-request'],
        ['argon2', 'php-sync-kdf-on-request'],
        ['error_log', 'php-logging-in-hot-loop'],
        ['syslog', 'php-logging-in-hot-loop'],
        ['.= ', 'php-string-concat-in-loop'],
        ['array_merge', 'php-array-merge-in-loop'],
        ['in_array', 'php-linear-scan-in-loop'],
        ['apcu_store', 'php-unbounded-in-memory-cache'],
        ['apcu_fetch', 'php-unbounded-in-memory-cache'],
        ['memory_limit', 'php-memory-limit-unsized'],
        ['ini_set', 'php-memory-limit-unsized'],
        ['gzencode', 'php-response-compression-missing'],
        ['ob_gzhandler', 'php-response-compression-missing'],
        ['header(', 'php-missing-cache-headers'],
        ['cache-control', 'php-missing-cache-headers'],
        ['etag', 'php-missing-cache-headers'],
        ['sleep(', 'php-blocking-sleep-on-request'],
        ['usleep(', 'php-blocking-sleep-on-request'],
        ['set_time_limit', 'php-request-timeout-unbounded'],
        ['session_start', 'php-session-lock-contention'],
        ['serialize(', 'php-large-object-serialize'],
        ['unserialize(', 'php-large-object-serialize'],
    ];

    /**
     * @return array<string,bool>
     */
    private static function wordSetFrom(string $text, string $re): array
    {
        $out = [];
        if (preg_match_all($re, strtolower($text), $mm) && isset($mm[0])) {
            foreach ($mm[0] as $w) {
                $out[$w] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<string,bool>
     */
    private static function distinctiveWords(string $text): array
    {
        $out = [];
        foreach (self::wordSetFrom($text, '/[a-z][a-z0-9]{4,}/') as $w => $_) {
            if (!in_array($w, self::STOPWORDS, true)) {
                $out[$w] = true;
            }
        }

        return $out;
    }

    /** @var array<string,int>|null */
    private static ?array $wordDf = null;

    /**
     * Inverse-document-frequency table: how many rules each distinctive
     * when_to_apply word appears in. Built once from the static corpus.
     *
     * @return array<string,int>
     */
    private static function wordDocFreq(): array
    {
        if (self::$wordDf === null) {
            $df = [];
            foreach (self::checklist() as $r) {
                foreach (self::distinctiveWords(self::entryWhenToApply($r)) as $w => $_) {
                    $df[$w] = ($df[$w] ?? 0) + 1;
                }
            }
            self::$wordDf = $df;
        }

        return self::$wordDf;
    }

    /**
     * @param array{projects:int,evidence:int} $c
     */
    private static function communityBoost(array $c): float
    {
        $boost = self::COMMUNITY_BASE
            + self::COMMUNITY_PROJECT_WEIGHT * (log(1 + $c['projects']) / log(2))
            + self::COMMUNITY_EVIDENCE_WEIGHT * (log(1 + $c['evidence']) / log(2));

        return min($boost, self::COMMUNITY_CAP);
    }

    /**
     * Roll up the raw community rows (as returned by Community::readCommunityCache)
     * per rule id. Mirrors the rollup Java folds into Community.readCommunityCache:
     * projects is the strongest single-circumstance count, evidence accumulates,
     * and circumstances is the de-duplicated list of severity/volume descriptions.
     *
     * @return array<string,array{projects:int,evidence:int,circumstances:list<string>}>
     */
    private static function communityByRule(): array
    {
        $out = [];
        foreach (Community::readCommunityCache() as $r) {
            if (!is_array($r)) {
                continue;
            }
            $ruleId = isset($r['ruleId']) && is_string($r['ruleId']) ? $r['ruleId'] : '';
            if ($ruleId === '') {
                continue;
            }
            if (!isset($out[$ruleId])) {
                $out[$ruleId] = ['projects' => 0, 'evidence' => 0, 'circumstances' => []];
            }
            $p = (int) (is_numeric($r['projects'] ?? null) ? $r['projects'] : 0);
            if ($p > $out[$ruleId]['projects']) {
                $out[$ruleId]['projects'] = $p;
            }
            $out[$ruleId]['evidence'] += (int) (is_numeric($r['evidence'] ?? null) ? $r['evidence'] : 0);
            $sev = isset($r['severityBucket']) && is_string($r['severityBucket']) ? $r['severityBucket'] : '';
            $ct = isset($r['countBucket']) && is_string($r['countBucket']) ? $r['countBucket'] : '';
            $desc = self::describeCircumstance($sev, $ct);
            if (!in_array($desc, $out[$ruleId]['circumstances'], true)) {
                $out[$ruleId]['circumstances'][] = $desc;
            }
        }

        return $out;
    }

    /**
     * Render one severity/volume circumstance string. Byte-identical to Java's
     * Community.describeCircumstance (including the middot separator).
     */
    private static function describeCircumstance(string $sev, string $ct): string
    {
        $s = $sev === '' ? 'any severity' : $sev . ' severity';
        $c = $ct === '' ? 'any volume' : $ct . ' occurrences';

        return $s . " \u{00b7} " . $c;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function matchRulesForCode(string $code): array
    {
        $lower = strtolower($code);
        // Read the disk-cached community book (fail-open to empty) and kick off
        // a best-effort background refresh.
        Community::refreshCommunityCacheIfStale();
        $community = self::communityByRule();
        $codeWords = self::wordSetFrom($code, '/[a-z][a-z0-9]{3,}/');
        $df = self::wordDocFreq();

        // Curated hints — phrase patterns, matched as substrings by design.
        $hintScore = [];
        foreach (self::CODE_HINTS as $h) {
            $needle = $h[0];
            if (str_contains($lower, $needle)) {
                for ($i = 1; $i < count($h); $i++) {
                    $rid = $h[$i];
                    $hintScore[$rid] = ($hintScore[$rid] ?? 0.0) + self::HINT_WEIGHT;
                }
            }
        }

        $scored = [];
        foreach (self::checklist() as $r) {
            $id = self::entryId($r);
            $relevance = $hintScore[$id] ?? 0.0;

            // id tokens (>= 4 chars), word-boundary.
            foreach (explode('-', $id) as $tok) {
                if (strlen($tok) >= 4 && isset($codeWords[$tok])) {
                    $relevance += self::ID_TOKEN_WEIGHT;
                }
            }

            // distinctive when_to_apply words, weighted by IDF (rarer -> sharper).
            foreach (self::distinctiveWords(self::entryWhenToApply($r)) as $w => $_) {
                if (!isset($codeWords[$w])) {
                    continue;
                }
                $freq = max(1, $df[$w] ?? 1);
                $relevance += self::WHEN_BASE_WEIGHT + self::WHEN_IDF_WEIGHT / $freq;
            }

            if ($relevance <= 0) {
                continue;
            }

            // Community boost only ever refines a rule that already fits
            // (relevance > 0) — never injects a proven-but-irrelevant rule.
            $score = $relevance;
            $projects = 0;
            if (isset($community[$id])) {
                $score += self::communityBoost($community[$id]);
                $projects = $community[$id]['projects'];
            }
            $scored[] = [
                'id' => $id,
                'score' => $score,
                'projects' => $projects,
                'categoryRank' => self::categoryRank(self::entryCategory($r)),
                'evidenceCount' => count(self::entryEvidence($r)),
            ];
        }

        // Impact-aware, fully deterministic ordering: score desc, projects desc,
        // category rank asc, evidence desc, id asc.
        usort($scored, static function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['projects'] !== $b['projects']) {
                return $b['projects'] <=> $a['projects'];
            }
            if ($a['categoryRank'] !== $b['categoryRank']) {
                return $a['categoryRank'] <=> $b['categoryRank'];
            }
            if ($a['evidenceCount'] !== $b['evidenceCount']) {
                return $b['evidenceCount'] <=> $a['evidenceCount'];
            }

            return strcmp($a['id'], $b['id']);
        });

        $out = [];
        foreach ($scored as $s) {
            if (count($out) >= 8) {
                break;
            }
            $r = self::findRule($s['id']);
            if ($r === null) {
                continue;
            }
            $m = [
                'id' => self::entryId($r),
                'title' => self::entryTitle($r),
                'category' => self::entryCategory($r),
                'match_score' => round($s['score'] * 100) / 100.0,
                'when_to_apply' => self::entryWhenToApply($r),
            ];
            if (isset($community[$s['id']])) {
                $c = $community[$s['id']];
                $m['community_proven'] = true;
                $m['community_projects'] = $c['projects'];
                $m['community_evidence'] = $c['evidence'];
                $circ = $c['circumstances'];
                if (count($circ) > 3) {
                    $circ = array_slice($circ, 0, 3);
                }
                $m['community_circumstances'] = array_values($circ);
            }
            $out[] = $m;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Prompt-injection sanitization for sample-derived strings — the PHP
    // sibling of sanitizeMcpString in Mcp.java / sanitizeMCPString in mcp.go.
    // Caps length, strips C0/C1 control characters, neutralises common
    // role-injection markers, and escapes angle brackets before any
    // host-app-derived string crosses into an AI agent's context. Defence in
    // depth on top of the PII denylist; shape, not semantics.
    // -----------------------------------------------------------------------

    /** @var list<string> */
    private const INJECTION_MARKERS = [
        '<|im_start|>', '<|im_end|>', '<|system|>', '<|user|>',
        '<|assistant|>', '<|endoftext|>', '###system', '###instruction',
    ];

    public static function sanitizeMcpString(string $value): string
    {
        $s = $value;
        foreach (self::INJECTION_MARKERS as $marker) {
            $lowerMarker = strtolower($marker);
            while (($idx = strpos(strtolower($s), $lowerMarker)) !== false) {
                $s = substr($s, 0, $idx) . '[redacted]' . substr($s, $idx + strlen($marker));
            }
        }
        // Strip C0/C1 control characters other than newline and tab. Operate on
        // Unicode code points so multibyte text is preserved.
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $chars = $chars === false ? [] : $chars;
        $keptChars = [];
        foreach ($chars as $ch) {
            if ($ch === "\n" || $ch === "\t") {
                $keptChars[] = $ch;
                continue;
            }
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp !== false && $cp >= 0x20 && !($cp >= 0x7F && $cp <= 0x9F)) {
                $keptChars[] = $ch;
            }
        }
        $cleaned = str_replace(['<', '>'], ['&lt;', '&gt;'], implode('', $keptChars));

        if (mb_strlen($cleaned, 'UTF-8') > self::MAX_SAMPLE_STR_LEN) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_SAMPLE_STR_LEN, 'UTF-8') . "\u{2026}[truncated]";
        }

        return $cleaned;
    }

    /**
     * Recursively sanitize every string (and map key) in a sample-derived
     * value. Non-string scalars pass through unchanged.
     */
    public static function sanitizeMcpValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeMcpString($value);
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $k => $v) {
                if ($isList) {
                    $out[] = self::sanitizeMcpValue($v);
                } else {
                    $out[self::sanitizeMcpString((string) $k)] = self::sanitizeMcpValue($v);
                }
            }

            return $out;
        }
        if (is_object($value)) {
            // An empty stdClass (the byRoute placeholder) has no members to walk;
            // return it as-is so it still serializes as a JSON object.
            $out = [];
            foreach (get_object_vars($value) as $k => $v) {
                $out[self::sanitizeMcpString((string) $k)] = self::sanitizeMcpValue($v);
            }

            return $out === [] ? $value : (object) $out;
        }

        return $value;
    }
}

/**
 * Tool-level failure — becomes an isError:true tool result. The Java sibling
 * nests this as Mcp.ToolException; PHP keeps it in the same file (rather than a
 * fourth src/ file) so it is always defined the moment Mcp is loaded. It is not
 * autoloadable on its own by design — only Mcp throws or catches it.
 */
final class ToolException extends \Exception
{
}
