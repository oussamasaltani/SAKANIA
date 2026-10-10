<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * MCP per-tool measurement — PHP sibling of the Node runtime's mcpMeasure.ts
 * (the canonical cross-runtime source) and app.boosthis.McpMeasure (Java),
 * label-for-label with the Go / Python / RN siblings.
 *
 * An MCP server over HTTP is half-visible already: the request decorator times
 * POST .../mcp as ONE opaque route, so a 40ms tools/list and a 4s tool call are
 * the same row. This class derives a PER-TOOL label so they can be told apart,
 * mirroring the label-bounding approach of the hosted endpoint verbatim:
 *
 * CARDINALITY + PII ARE BOUNDED ON PURPOSE. Both the JSON-RPC method and the
 * tool name arrive from an UNTRUSTED caller. Methods outside a fixed protocol
 * set collapse to mcp.other; tool names outside the developer-registered roster
 * collapse to mcp.call.other. Tool ARGUMENTS are never read. An open label set
 * would be a denial-of-service on our own fixed-size sample ring — a caller
 * minting endless distinct names would evict every real sample.
 *
 * Coverage model (honest, by transport):
 *   - HTTP MCP servers: AUTO. The request decorator relabels POST <path>/mcp
 *     per tool when the request JSON body parses.
 *   - stdio MCP servers: EXPLICIT. No HTTP layer exists, so the developer wraps
 *     each handler in mcpTool() — one line.
 *
 * Registering a tool name is developer CODE (not caller input), so mcpTool()
 * self-registers its name and registerMcpTools() pre-registers a roster for the
 * auto-HTTP path. Both are capped and validated.
 *
 * PHP DIFFERENCE (documented per the port rules): the Java sibling passes an
 * explicit rating into Samples.record and rates a failed call "poor". The PHP
 * Samples ring stores compact triples and derives the rating from the duration,
 * so a failure is distinguished by its distinct .error label rather than by an
 * injected rating — the label still separates per-tool error rate from latency,
 * which is the point.
 */
final class McpMeasure
{
    private function __construct()
    {
    }

    /** Fixed JSON-RPC protocol methods worth timing separately. CLOSED set. */
    private const MCP_METHODS = [
        'initialize',
        'initialized',
        'ping',
        'tools/list',
        'tools/call',
        'resources/list',
        'resources/read',
        'prompts/list',
        'prompts/get',
        'notifications/initialized',
        'notifications/cancelled',
    ];

    /** Label prefix for tool-call samples; the axis + dashboard group on this. */
    public const MCP_CALL_PREFIX = 'mcp.call.';
    /** Suffix marking a FAILED tool call (handler threw / 5xx). Closed 2x set. */
    public const MCP_ERROR_SUFFIX = '.error';

    /** Roster ceiling — even developer code can't mint unbounded labels. */
    public const MCP_MAX_TOOLS = 64;
    /** Tool names must look like code identifiers: short, no spaces, no PII. */
    private const TOOL_NAME_RE = '/^[A-Za-z0-9_.-]{1,64}$/';

    /** @var array<string,bool> the developer-registered roster (insertion-ordered). */
    private static array $registered = [];

    /**
     * Validate one developer-supplied tool name. Returns the canonical name or
     * null when it can't be a label (bad shape / PII-shaped).
     */
    private static function validToolName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);
        if (preg_match(self::TOOL_NAME_RE, $trimmed) !== 1) {
            return null;
        }
        if (Pii::checkRouteLabel(self::MCP_CALL_PREFIX . $trimmed) !== null) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Pre-register the MCP tool roster (developer code — a CLOSED set) so the
     * auto-HTTP path can label tools/call per tool. Invalid or over-cap names
     * are silently skipped; their calls bucket to mcp.call.other. A null list is
     * a no-op. Never throws.
     *
     * @param list<string>|null $names
     */
    public static function registerMcpTools(?array $names): void
    {
        if ($names === null) {
            return;
        }
        foreach ($names as $n) {
            if (count(self::$registered) >= self::MCP_MAX_TOOLS) {
                return;
            }
            $ok = self::validToolName(is_string($n) ? $n : null);
            if ($ok !== null) {
                self::$registered[$ok] = true;
            }
        }
    }

    /** @internal test hook. */
    public static function resetForTests(): void
    {
        self::$registered = [];
    }

    /**
     * Derive the bounded sample label for one inbound JSON-RPC message (as
     * produced by the kit's JSON parser: an associative array for objects, a
     * list for arrays). Returns null when the value isn't a JSON-RPC-shaped
     * object at all (so the caller can skip recording rather than mint a
     * meaningless bucket). Batch arrays collapse to mcp.batch — one request, one
     * sample.
     */
    public static function mcpAutoLabel(mixed $msg): ?string
    {
        if (is_array($msg) && array_is_list($msg)) {
            return count($msg) > 0 ? 'mcp.batch' : null;
        }
        if (!is_array($msg)) {
            return null;
        }
        $method = $msg['method'] ?? '';
        if (!is_string($method) || $method === '') {
            return null;
        }
        if (!in_array($method, self::MCP_METHODS, true)) {
            return 'mcp.other';
        }
        if ($method !== 'tools/call') {
            return 'mcp.' . str_replace('/', '_', $method);
        }
        $raw = '';
        $params = $msg['params'] ?? null;
        if (is_array($params) && isset($params['name']) && is_string($params['name'])) {
            $raw = $params['name'];
        }

        return self::labelForTool($raw);
    }

    /**
     * Bounded label for a tool name: registered → mcp.call.<name>, anything else
     * → mcp.call.other. NEVER echoes unregistered caller text.
     */
    public static function labelForTool(?string $name): string
    {
        if ($name !== null && isset(self::$registered[$name])) {
            return self::MCP_CALL_PREFIX . $name;
        }

        return self::MCP_CALL_PREFIX . 'other';
    }

    /**
     * Record one MCP sample. Failed calls land under a distinct .error label so
     * per-tool ERROR RATE is visible next to latency. Recording failures are
     * swallowed (guest-safety).
     */
    public static function recordMcpSample(string $label, int $durationMs, bool $isError): void
    {
        try {
            $name = $isError ? $label . self::MCP_ERROR_SUFFIX : $label;
            // Labels here are closed-set by construction, but keep the shared
            // guard as the final backstop (same posture as the HTTP decorator).
            if (Pii::checkRouteLabel($name) === null) {
                Samples::record($name, (float) $durationMs);
            }
        } catch (\Throwable $ignored) {
            // instrumentation must never take down the host.
        }
    }

    /**
     * Explicit per-tool wrapper (the stdio path — also fine over HTTP). Wraps a
     * tool handler, self-registers the name so the auto-HTTP path recognizes it
     * too, times the call, and records one MCP sample. A throwing handler
     * records under <label>.error and RE-RAISES the ORIGINAL throwable
     * unchanged. Recording failures are swallowed. A null callable is a no-op
     * and returns null.
     *
     * @template T
     * @param callable():T $fn
     * @return T|null the handler's return value (null for a null callable)
     */
    public static function mcpTool(string $name, ?callable $fn): mixed
    {
        if ($fn === null) {
            return null;
        }
        self::registerMcpTools([$name]);
        $canonical = self::validToolName($name);
        $label = self::labelForTool($canonical);
        $start = hrtime(true);
        $failed = false;
        $thrown = null;
        $result = null;
        try {
            $result = $fn();
        } catch (\Throwable $t) {
            $failed = true;
            $thrown = $t;
        } finally {
            self::recordMcpSample($label, self::elapsedMs($start), $failed);
        }
        if ($thrown !== null) {
            // Re-raise the ORIGINAL throwable unchanged, preserving its identity
            // and stack trace.
            throw $thrown;
        }

        return $result;
    }

    /**
     * True when a request path is an MCP endpoint (/mcp or any path ending in
     * /mcp).
     */
    public static function isMcpPath(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        return $path === '/mcp' || str_ends_with($path, '/mcp');
    }

    private static function elapsedMs(int $startNanos): int
    {
        return (int) round((hrtime(true) - $startNanos) / 1_000_000.0);
    }
}
