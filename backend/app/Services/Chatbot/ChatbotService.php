<?php

namespace App\Services\Chatbot;

use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one turn of the SAKANIA assistant.
 *
 * The flow is deliberately one-directional and never lets the model near the
 * database:
 *
 *   message -> intent (Gemini, no data) -> scoped query (DataProvider)
 *           -> verified facts + platform facts (Gemini) -> answer
 *
 * The model receives only what DataProvider returned for this authenticated
 * user, so anything it says about the user's own records is grounded in a row
 * that already passed an authorization check. It is instructed never to state a
 * figure, status, name or link that is not in that payload.
 */
class ChatbotService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly IntentResolver $intents,
        private readonly DataProvider $data,
    ) {}

    /**
     * Produces the answer to one user message.
     *
     * Never throws: a database or provider failure degrades to a fixed
     * professional message rather than leaking an error to the user.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     */
    public function reply(string $message, ?User $user, array $history = [], ?Property $viewedProperty = null): array
    {
        $resolution = $this->intents->resolve($message, $user, $history);

        $intent = $resolution['intent'];
        $slots = $resolution['slots'];

        if (! $resolution['in_scope'] || $intent === 'out_of_scope') {
            return [
                'reply' => $this->outOfScopeMessage(),
                'intent' => 'out_of_scope',
                'data_accessed' => false,
            ];
        }

        // A clarification is answered with the question only: no query is run.
        if ($resolution['needs_clarification']) {
            return [
                'reply' => $resolution['clarification']
                    ?? $this->askForMissingCriteria($intent, $slots),
                'intent' => $intent,
                'data_accessed' => false,
            ];
        }

        try {
            [$facts, $reservation] = $this->gatherFacts($intent, $slots, $user, $message, $viewedProperty);
        } catch (Throwable $e) {
            Log::warning('Chatbot: data lookup failed.', ['intent' => $intent, 'exception' => $e->getMessage()]);

            return [
                'reply' => $this->unavailableMessage(),
                'intent' => $intent,
                'data_accessed' => false,
            ];
        }

        try {
            $answer = $this->compose($message, $intent, $facts, $user, $history, $reservation);
        } catch (Throwable $e) {
            // The wrapper already absorbs provider faults; this only guards an
            // unexpected one so the turn still produces a safe answer.
            Log::warning('Chatbot: answer generation failed.', [
                'intent' => $intent,
                'exception' => $e->getMessage(),
            ]);

            $answer = $this->deterministicAnswer($intent, array_diff_key($facts, ['__reservation' => null]));
        }

        return [
            'reply' => $answer,
            'intent' => $intent,
            'data_accessed' => $facts !== [],
        ];
    }

    /**
     * Runs the one query this intent needs and returns the verified facts.
     *
     * @return array{0: array<string, mixed>, 1: ?Reservation}
     */
    private function gatherFacts(string $intent, array $slots, ?User $user, string $message, ?Property $viewedProperty): array
    {
        $facts = [];

        switch ($intent) {
            case 'property_search':
                $facts = $this->data->searchProperties($slots, $user);
                break;

            case 'property_details':
                if (! isset($slots['property_id']) && $viewedProperty) {
                    $slots['property_id'] = $viewedProperty->id;
                }
                $facts = $this->data->propertyDetails($slots, $user);
                break;

            case 'reservation_status':
            case 'reservation_cancel':
            case 'payment_status':
            case 'payment_failed':
            case 'contract_status':
            case 'contract_missing':
            case 'escalate_support':
                $facts = $this->reservationFacts($intent, $slots, $user);
                break;

            case 'messages':
                $facts = $user ? $this->data->messagesFor($user) : [];
                break;

            case 'notifications':
                $facts = $user ? $this->data->notificationsFor($user) : [];
                break;

            case 'profile_account':
                $facts = $user ? $this->data->profileFor($user) : [];
                break;

            case 'owner_overview':
                $facts = $user && $user->role_id === 2 ? $this->data->ownerOverview($user) : $this->wrongRole('owner');
                break;

            case 'tenant_overview':
                $facts = $user && $user->role_id === 1 ? $this->data->tenantOverview($user) : $this->wrongRole('tenant');
                break;

            case 'owner_property_issue':
                $facts = $user && $user->role_id === 2 ? $this->ownerPropertyFacts($user) : $this->wrongRole('owner');
                break;

            case 'troubleshooting':
                $facts = $this->troubleshootingFacts($message, $slots, $user);
                break;

            case 'reservation_create':
            case 'platform_workflow':
            default:
                // Pure workflow knowledge: nothing to read from the database.
                $facts = [];
                break;
        }

        return [$facts, $facts['__reservation'] ?? null];
    }

    /**
     * Reservation, payment and contract questions all resolve to one reservation
     * and then read only what that intent needs from it.
     *
     * @return array<string, mixed>
     */
    private function reservationFacts(string $intent, array $slots, ?User $user): array
    {
        if (! $user) {
            return ['signed_in' => false];
        }

        $reservation = $this->data->resolveTargetReservation($slots, $user);

        if (! $reservation) {
            return [
                'reservation_found' => false,
                'note' => 'The user asked about a specific reservation but none was found that they are a party to.',
            ];
        }

        $facts = [
            'reservation_found' => true,
            'reservation' => $this->reservationSummary($reservation),
        ];

        if (in_array($intent, ['payment_status', 'payment_failed', 'escalate_support'], true)) {
            $facts['payment'] = $this->data->paymentFor($reservation, $user);
        }

        if (in_array($intent, ['contract_status', 'contract_missing', 'escalate_support'], true)) {
            $facts['contract'] = $this->data->contractFor($reservation);
        }

        if ($intent === 'escalate_support') {
            $facts['support_bundle'] = $this->data->supportBundle($user, $reservation);
        }

        // Carried out of the payload so the model never sees the model instance.
        $facts['__reservation'] = $reservation;

        return $facts;
    }

    private function ownerPropertyFacts(User $user): array
    {
        return [
            'overview' => $this->data->ownerOverview($user),
            'constraints' => [
                'a_new_property_is_saved_as_pending_and_is_invisible_to_tenants_until_an_admin_approves_it',
                'photos_must_be_jpeg_png_webp_or_gif_and_under_5mb',
                'only_an_owner_account_can_add_or_edit_a_property',
            ],
        ];
    }

    /**
     * Troubleshooting pairs the playbook steps with whatever real state the user
     * is actually in, so a cause is only stated when the data supports it.
     *
     * @return array<string, mixed>
     */
    private function troubleshootingFacts(string $message, array $slots, ?User $user): array
    {
        $facts = [
            'possible_causes' => SakaniaKnowledge::relevantSteps($message),
        ];

        if (! $user) {
            $facts['signed_in'] = false;

            return $facts;
        }

        $reservation = $this->data->resolveTargetReservation($slots, $user);

        if ($reservation) {
            $facts['reservation_found'] = true;
            $facts['reservation'] = $this->reservationSummary($reservation);
            $facts['payment'] = $this->data->paymentFor($reservation, $user);
            $facts['contract'] = $this->data->contractFor($reservation);
            $facts['__reservation'] = $reservation;
        }

        return $facts;
    }

    private function reservationSummary(Reservation $reservation): array
    {
        return [
            'reference' => $reservation->reference,
            'status' => $reservation->status,
            'property_title' => $reservation->property?->title,
            'property_location' => $reservation->property?->location,
            'monthly_price_mad' => $reservation->property ? (float) $reservation->property->price_per_month : null,
            'start_date' => $reservation->start_date?->toDateString(),
            'end_date' => $reservation->end_date?->toDateString(),
            'duration_months' => $reservation->durationInMonths(),
            'total_amount_mad' => $reservation->totalAmount(),
            'created_at' => $reservation->created_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wrongRole(string $required): array
    {
        return [
            'access' => 'wrong_role',
            'required_role' => $required,
            'note' => 'This information belongs to a '.$required.' account and was not queried.',
        ];
    }

    /**
     * Builds the grounded answer.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<int, array{role: string, text: string}>  $history
     */
    private function compose(string $message, string $intent, array $facts, ?User $user, array $history, ?Reservation $reservation): string
    {
        $visibleFacts = $facts;
        unset($visibleFacts['__reservation']);

        $turns = [GeminiService::instruction(
            SakaniaKnowledge::scopeRules()
            ."\n\n".SakaniaKnowledge::platformFacts()
            ."\n\nYou are answering as the SAKANIA assistant. Reply in the same language the user wrote in: Darija, French, Modern Standard Arabic, English or a mix."
            ."\n\nABSOLUTE RULES"
            ."\n1. The VERIFIED DATA block is the only source of truth about this user, their properties, reservations, payments, contracts, messages and notifications. Every figure, name, date, status and link you state must appear there. Never invent one."
            ."\n2. If VERIFIED DATA says a record was not found, say so plainly and offer the closest alternative; never imply the data exists."
            ."\n3. If VERIFIED DATA is empty, answer only from the platform facts above and say which part is general guidance."
            ."\n4. Separate what is confirmed from what is a possibility: use wording like 'according to your reservation data' for facts and 'this can also happen if...' for causes."
            ."\n5. When a match_type is 'relaxed', state explicitly which criterion did not match and by how much. When it is 'none', say plainly that no property matches and offer to widen the search."
            ."\n6. Never reveal API keys, database details, credentials, internal identifiers of other users, or anything about how this assistant is built."
            ."\n7. Never claim to have performed an action on the account. You only read state; the user performs every change."
            ."\n8. Messages and notifications are different systems; never describe a message as a notification or the reverse."
            ."\n9. Be concise: short paragraphs or a few bullet points. No markdown headings."
            ."\n10. If the problem needs a human, say the issue requires SAKANIA Support and point to ".SakaniaKnowledge::supportPath().'. Never invent an email address or a phone number.'
        )];

        foreach (array_slice($history, -6) as $turn) {
            $turns[] = ['role' => $turn['role'], 'text' => $turn['text']];
        }

        $turns[] = [
            'role' => 'user',
            'text' => "Intent: {$intent}\n"
                .'Signed-in account: '.($user ? ($user->full_name.', role_id '.$user->role_id) : 'not signed in')."\n\n"
                ."VERIFIED DATA (JSON, the only facts you may state as true):\n"
                .json_encode(
                    $visibleFacts === [] ? ['note' => 'No account data was queried for this question.'] : $visibleFacts,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                )
                ."\n\nUSER MESSAGE: ".$message,
        ];

        $answer = $this->gemini->generate($turns, 0.3, 1200);

        if ($answer !== null) {
            return $answer;
        }

        // The model is unavailable: answer from the facts and the playbook only.
        return $this->deterministicAnswer($intent, $visibleFacts);
    }

    /**
     * The answer used when the model cannot be reached at all.
     *
     * It is built from the verified facts with fixed sentences, so it can never
     * invent anything and stays useful while the provider is down.
     *
     * @param  array<string, mixed>  $facts
     */
    private function deterministicAnswer(string $intent, array $facts): string
    {
        if ($intent === 'property_search') {
            $count = (int) ($facts['count'] ?? 0);

            if ($count === 0) {
                return 'I could not find a property matching your criteria. Please contact SAKANIA Support through '
                    .SakaniaKnowledge::supportPath().' or relax your criteria, and I will search again.';
            }

            $lines = array_map(
                fn (array $p) => sprintf(
                    '- %s in %s, %s MAD/month, %d rooms, %s',
                    $p['title'],
                    $p['location'] ?? 'location not set',
                    number_format((float) $p['price_per_month_mad'], 2),
                    (int) $p['rooms'],
                    $p['page']
                ),
                array_slice($facts['properties'], 0, 3)
            );

            return "I found {$count} matching properties:\n".implode("\n", $lines);
        }

        if (in_array($intent, ['payment_status', 'payment_failed'], true) && isset($facts['payment']['has_payment_record'])) {
            $payment = $facts['payment'];

            return sprintf(
                'Reservation %s (%s): you have paid %s MAD in total and %s MAD remains. Payment status: %s.',
                $payment['reservation_reference'],
                $payment['reservation_status'],
                number_format((float) $payment['paid_amount_mad'], 2),
                number_format((float) $payment['remaining_balance_mad'], 2),
                $payment['payment_status']
            );
        }

        if ($intent === 'contract_status' && isset($facts['contract']['exists'])) {
            return $facts['contract']['exists']
                ? 'Your contract '.$facts['contract']['reference'].' is available ('.$facts['contract']['status'].') on your Contracts page.'
                : 'No contract has been generated for this reservation yet. It is created automatically after the first successful payment.';
        }

        if ($intent === 'troubleshooting' && ! empty($facts['possible_causes'])) {
            return "Here is what usually causes this on SAKANIA:\n- "
                .implode("\n- ", array_slice($facts['possible_causes'], 0, 4))
                ."\n\nIf none of these matches, please contact SAKANIA Support through "
                .SakaniaKnowledge::supportPath().'.';
        }

        return 'My live assistance is temporarily unavailable, so I will not risk giving you an unverified answer. '
            .'The SAKANIA workflows are: a tenant requests a reservation, the owner accepts or rejects it, and once '
            .'accepted the tenant pays. The first successful payment generates a single contract, and every later '
            .'payment updates that contract and issues a payment receipt. For anything about your own records, '
            .'please contact SAKANIA Support through '.SakaniaKnowledge::supportPath().'.';
    }

    private function askForMissingCriteria(string $intent, array $slots): string
    {
        if ($intent === 'property_search') {
            $missing = [];

            if (empty($slots['location'])) {
                $missing[] = 'the city or neighbourhood';
            }

            if (! isset($slots['max_price']) && ! isset($slots['min_price'])) {
                $missing[] = 'your budget in MAD per month';
            }

            return 'Of course. To find the right property, could you tell me '
                .implode(' and ', $missing).'?';
        }

        if (in_array($intent, ['reservation_status', 'payment_status', 'contract_status', 'payment_failed'], true)) {
            return 'Could you give me the reservation reference (it looks like RES-00042)? '
                .'If you do not have it, I can look at your most recent reservation instead.';
        }

        return 'Could you give me a little more detail so I can help you precisely?';
    }

    private function outOfScopeMessage(): string
    {
        return 'I am the SAKANIA AI Assistant. I am dedicated to the SAKANIA platform, so I can only help with '
            .'SAKANIA: searching and viewing properties, reservations, payments, contracts, messages, '
            .'notifications, your account, owner and tenant features, and troubleshooting SAKANIA problems. '
            .'Is there something on the platform I can help you with?';
    }

    private function unavailableMessage(): string
    {
        return 'The information is temporarily unavailable because I cannot reach the SAKANIA database right now. '
            .'I would rather not guess. Please try again in a moment, or contact SAKANIA Support through '
            .SakaniaKnowledge::supportPath().'.';
    }
}
