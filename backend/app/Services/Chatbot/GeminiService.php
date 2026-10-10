<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin, fault tolerant wrapper around the Gemini REST API.
 *
 * The key is only ever read from configuration and appended to the URL here, so
 * no caller can leak it into a prompt, a log line or a response body. Every
 * failure is reported as a boolean/exception-free result: the caller decides on
 * a fallback, and no raw provider payload ever reaches the user.
 */
class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const MODEL = 'gemini-2.5-flash';

    private const TIMEOUT = 25;

    /**
     * Marks the leading turn as the system instruction rather than dialogue.
     */
    private const INSTRUCTION_PREFIX = '[INSTRUCTION]';

    /**
     * Runs a generation. Returns null on any provider failure.
     *
     * @param  array<int, array{role: string, text: string}>  $turns
     */
    public function generate(array $turns, float $temperature = 0.3, ?int $maxTokens = 1200): ?string
    {
        $key = config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            Log::warning('Chatbot: Gemini is not configured.');

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->post(self::ENDPOINT.'/'.self::MODEL.':generateContent?key='.$key, [
                    'contents' => $this->formatContents($turns),
                    'systemInstruction' => [
                        'parts' => [['text' => $this->systemInstruction($turns)]],
                    ],
                    'generationConfig' => [
                        'temperature' => $temperature,
                        'maxOutputTokens' => $maxTokens,
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Chatbot: Gemini request failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // Only the status is kept: the body can echo back request details.
            Log::warning('Chatbot: Gemini returned an error status.', ['status' => $response->status()]);

            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            // A finishReason of SAFETY/RECITATION is the usual cause here.
            Log::warning('Chatbot: Gemini returned no usable text.', [
                'finish_reason' => $response->json('candidates.0.finishReason'),
            ]);

            return null;
        }

        return trim($text);
    }

    /**
     * Asks for a strict JSON object and decodes it.
     *
     * The schema is described in the prompt and the answer is parsed defensively:
     * anything that is not a JSON object is a failure, not a partial result.
     *
     * @param  array<int, array{role: string, text: string}>  $turns
     */
    public function generateJson(array $turns, string $schemaHint, float $temperature = 0.0): ?array
    {
        // The shape goes into the system instruction rather than into a new turn:
        // Gemini expects the roles to alternate, and appending a user turn here
        // would place two user turns next to each other.
        $shape = "Answer strictly with one JSON object and nothing else, no markdown, no prose.\n"
            .'Required shape:'."\n".$schemaHint;

        $turns[0]['text'] .= "\n\n".$shape;

        $raw = $this->generate($turns, $temperature, 600);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($this->stripFences($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Gemini accepts the prompt turns as contents and the behavioural rules as a
     * separate system instruction. The leading turn carries that instruction and
     * is therefore left out of the contents.
     *
     * @param  array<int, array{role: string, text: string}>  $turns
     */
    private function formatContents(array $turns): array
    {
        $contents = [];

        foreach ($turns as $index => $turn) {
            // Only the leading turn may carry the instruction marker, so a user
            // message that happens to start with the same text is never dropped.
            if ($index === 0 && self::isInstruction($turn['text'])) {
                continue;
            }

            $contents[] = [
                'role' => $turn['role'] === 'model' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ];
        }

        return $contents;
    }

    private function systemInstruction(array $turns): string
    {
        if (! isset($turns[0]) || ! self::isInstruction($turns[0]['text'])) {
            return 'You are the SAKANIA assistant.';
        }

        $instruction = trim(substr($turns[0]['text'], strlen(self::INSTRUCTION_PREFIX)));

        return $instruction !== '' ? $instruction : 'You are the SAKANIA assistant.';
    }

    private static function isInstruction(string $text): bool
    {
        return str_starts_with($text, self::INSTRUCTION_PREFIX);
    }

    /**
     * Marks a turn as belonging to the system instruction rather than the dialogue.
     *
     * @return array{role: string, text: string}
     */
    public static function instruction(string $text): array
    {
        return ['role' => 'user', 'text' => self::INSTRUCTION_PREFIX.$text];
    }

    private function stripFences(string $raw): string
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }
}
