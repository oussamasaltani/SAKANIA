<?php

namespace App\Services\Chatbot;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns a free text message into a routing decision and a set of search slots.
 *
 * Two things happen here. A cheap keyword pre-filter decides whether the message
 * could plausibly be about SAKANIA at all, so obvious off-topic questions are
 * refused without a model call. Everything else goes to Gemini, which returns a
 * single JSON object: the intent, the slots, and whether clarification is needed.
 *
 * The model never receives or returns database rows here. It only decides what to
 * look up; the lookup itself happens in DataProvider.
 */
class IntentResolver
{
    /**
     * Words that are strong enough on their own to prove the message is about
     * the platform, so the model call can be skipped for a plain greeting.
     */
    private const SAKANIA_HINTS = [
        'sakania', 'reservation', 'réservation', 'reserve', 'réserver', 'rent', 'rental', 'location',
        'landlord', 'propriétaire', 'proprietaire', 'owner', 'tenant', 'locataire', 'payment', 'paiement',
        'payer', 'pay', 'khlss', 'khless', 'khaless', 'contract', 'contrat', 'message', 'notification',
        'property', 'propriété', 'propriete', 'appartement', 'apartment', 'studio', 'villa', 'house',
        'maison', 'room', 'chambre', 'bedroom', 'chambre a coucher', 'bghit', 'bgheet', 'wach', 'kifach',
        'how does', 'comment', 'ma9dertch', 'ma drtch', 'where is', 'fin kayn', 'ch7al', '3lach',
        'madegh', 'mada mkhless', 'modal', 'mo9der', 'tanger', 'tangier', 'casablanca', 'rabat', 'marrakech',
        'agadir', 'fes', 'fez', 'salé', 'sale', 'kenitra', 'safi', 'oujda', 'account', 'compte', 'profile',
        'profil', 'login', 'password', 'mot de passe', 'support', 'platform', 'plateforme', 'upload',
        'photo', 'image', 'favoris', 'favorites', 'mad',
    ];

    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * Resolves the intent of a message.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     * @return array{intent: string, slots: array<string, mixed>, needs_clarification: bool, clarification: ?string, in_scope: bool}
     */
    public function resolve(string $message, ?User $user, array $history = []): array
    {
        if (! $this->looksLikeSakania($message)) {
            return [
                'intent' => 'out_of_scope',
                'slots' => [],
                'needs_clarification' => false,
                'clarification' => null,
                'in_scope' => false,
            ];
        }

        $schema = <<<'JSON'
{
  "intent": "one of: property_search, property_details, reservation_create, reservation_status, reservation_cancel, payment_status, payment_failed, contract_status, contract_missing, messages, notifications, profile_account, owner_overview, tenant_overview, owner_property_issue, troubleshooting, platform_workflow, escalate_support, out_of_scope",
  "in_scope": true,
  "needs_clarification": false,
  "clarification": null,
  "slots": {
    "location": "city or neighbourhood mentioned, or null",
    "property_type": "apartment | studio | house | villa | duplex, or null",
    "max_price": "number in MAD per month, or null",
    "min_price": "number in MAD per month, or null",
    "rooms": "integer number of bedrooms, or null",
    "bathrooms": "integer number of bathrooms, or null",
    "min_area": "number in m2, or null",
    "duration_months": "integer number of months, or null",
    "start_date": "YYYY-MM-DD when the user names one, or null",
    "reservation_id": "integer when the user gives a real-looking reservation number, or null",
    "property_id": "integer when the user refers to a specific property by id, or null"
  }
}
JSON;

        $turns = [GeminiService::instruction(
            SakaniaKnowledge::scopeRules()
            ."\n\nClassify the user message into exactly one SAKANIA intent and extract only the search slots the user explicitly stated. "
            .'Do not guess a value the user did not give: leave it null. Set needs_clarification to true only when the request '
            .'cannot be acted on without one more detail (for example a property search with no location and no budget). '
            .'Reply in the same language as the user.'
        )];

        foreach (array_slice($history, -6) as $turn) {
            $turns[] = ['role' => $turn['role'], 'text' => $turn['text']];
        }

        $turns[] = [
            'role' => 'user',
            'text' => 'Signed-in account role: '.$this->roleLabel($user)."\nMessage: ".$message,
        ];

        $resolved = $this->gemini->generateJson($turns, $schema);

        if ($resolved === null) {
            // The classifier is down: fall back to the keyword playbook rather
            // than guessing an intent.
            return [
                'intent' => SakaniaKnowledge::relevantSteps($message) ? 'troubleshooting' : 'platform_workflow',
                'slots' => $this->keywordSlots($message),
                'needs_clarification' => false,
                'clarification' => null,
                'in_scope' => true,
            ];
        }

        $intent = $resolved['intent'] ?? 'platform_workflow';

        if (! in_array($intent, SakaniaKnowledge::INTENTS, true)) {
            $intent = 'platform_workflow';
        }

        return [
            'intent' => $intent,
            'slots' => $this->normaliseSlots(is_array($resolved['slots'] ?? null) ? $resolved['slots'] : []),
            'needs_clarification' => (bool) ($resolved['needs_clarification'] ?? false),
            'clarification' => is_string($resolved['clarification'] ?? null) ? $resolved['clarification'] : null,
            'in_scope' => (bool) ($resolved['in_scope'] ?? true),
        ];
    }

    /**
     * Cheap gate in front of the model call.
     *
     * A message with no SAKANIA signal at all is refused; a message with a signal
     * is classified properly, which is what lets "ch7al ba9i 3lia nkhless?" reach
     * the payment intent without a hint of the word "payment".
     */
    private function looksLikeSakania(string $message): bool
    {
        $normalized = mb_strtolower($message);

        foreach (self::SAKANIA_HINTS as $hint) {
            if (str_contains($normalized, mb_strtolower($hint))) {
                return true;
            }
        }

        // Very short greetings are answered without touching the database.
        return (bool) preg_match('/^\s*(salut|bonjour|bonsoir|hello|hi|salam|slm|bslm|أهلا|السلام)\b/u', $normalized);
    }

    /**
     * Coerces the slots to the scalar types the query builder expects, so a
     * model returning "3500" or "two" cannot break a comparison.
     *
     * @return array<string, mixed>
     */
    private function normaliseSlots(array $slots): array
    {
        $numeric = ['max_price', 'min_price', 'min_area', 'rooms', 'bathrooms', 'duration_months', 'reservation_id', 'property_id'];

        $clean = [];

        foreach ($slots as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (in_array($key, $numeric, true)) {
                $number = $this->toNumber($value);

                if ($number !== null) {
                    $clean[$key] = in_array($key, ['rooms', 'bathrooms', 'duration_months', 'reservation_id', 'property_id'], true)
                        ? (int) $number
                        : $number;
                }

                continue;
            }

            if ($key === 'start_date') {
                $date = $this->toDate($value);

                if ($date !== null) {
                    $clean[$key] = $date;
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $clean[$key] = trim($value);
            }
        }

        if (isset($clean['property_type'])) {
            $clean['property_type'] = $this->normaliseType((string) $clean['property_type']);
        }

        return $clean;
    }

    private function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        // Handles "3500", "3 500", "3.5k" and "3000dh".
        $text = strtolower(trim($value));
        $text = preg_replace('/(\d)\s*k\b/', '$1000', $text) ?? $text;

        if (! preg_match('/-?\d[\d\s.,]*/', $text, $matches)) {
            return null;
        }

        $digits = preg_replace('/[^\d]/', '', $matches[0]) ?? '';

        return $digits === '' ? null : (float) $digits;
    }

    private function toDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseType(string $type): ?string
    {
        $type = mb_strtolower(trim($type));

        $map = [
            'appartement' => 'apartment',
            'appart' => 'apartment',
            'flat' => 'apartment',
            'maison' => 'house',
            'villa' => 'villa',
            'studio' => 'studio',
            'duplex' => 'duplex',
            'apartment' => 'apartment',
            'house' => 'house',
        ];

        return $map[$type] ?? (in_array($type, ['apartment', 'house', 'studio', 'villa', 'duplex'], true) ? $type : null);
    }

    /**
     * Slots recovered from the raw text when the classifier is unavailable.
     *
     * @return array<string, mixed>
     */
    private function keywordSlots(string $message): array
    {
        $slots = [];

        if (preg_match('/(\d[\d\s]{2,})\s*(?:mad|dh|dirham)/u', $message, $matches)) {
            $slots['max_price'] = (float) preg_replace('/[^\d]/', '', $matches[1]);
        }

        return $slots;
    }

    private function roleLabel(?User $user): string
    {
        return match ($user?->role_id) {
            1 => 'tenant',
            2 => 'owner',
            3 => 'admin',
            default => 'not signed in',
        };
    }
}
