<?php

namespace App\Services\Chatbot;

/**
 * The SAKANIA platform knowledge the assistant is allowed to rely on.
 *
 * Everything here is written from the real workflows implemented in the
 * controllers (ReservationController, OwnerController, PaymentController,
 * ContractService, PaymentReceiptService, MessageController). The assistant is
 * given these facts as static text; anything that lives in the database is
 * never described here, it is queried instead.
 */
class SakaniaKnowledge
{
    /**
     * Intents the assistant is allowed to route to. Anything outside this list
     * is either general platform knowledge or out of scope.
     */
    public const INTENTS = [
        'property_search',
        'property_details',
        'reservation_create',
        'reservation_status',
        'reservation_cancel',
        'payment_status',
        'payment_failed',
        'contract_status',
        'contract_missing',
        'messages',
        'notifications',
        'profile_account',
        'owner_overview',
        'tenant_overview',
        'owner_property_issue',
        'troubleshooting',
        'platform_workflow',
        'escalate_support',
        'out_of_scope',
    ];

    /**
     * Hard scope boundary. Out of scope questions are refused without ever
     * reaching the database or the answer model.
     */
    public static function scopeRules(): string
    {
        return <<<'TEXT'
You are the SAKANIA AI Assistant. SAKANIA is a Moroccan property rental platform
for tenants and property owners. You are dedicated to SAKANIA and nothing else.

In scope: how SAKANIA works, searching and viewing properties, reservations,
payments, contracts and receipts, messages, notifications, profile and account
questions, owner features, tenant features, platform rules and workflows, and
troubleshooting SAKANIA problems.

Out of scope: general knowledge, programming, schoolwork, sports, politics,
entertainment, or any other product. For those, set intent to "out_of_scope" and
do not answer.
TEXT;
    }

    /**
     * The authoritative description of how the platform behaves. This is what
     * stops the model from inventing a feature that does not exist.
     */
    public static function platformFacts(): string
    {
        return <<<'TEXT'
SAKANIA has exactly three roles: tenant (role_id 1), owner (role_id 2) and
admin (role_id 3). Admins approve or reject properties and moderate content.

PROPERTY LIFECYCLE
An owner creates a property from Owner > Properties > Add. It is saved with
status "pending" and is invisible to tenants until an admin approves it. Approved
properties appear in search and can be reserved. A rejected property is not
listed. Photo uploads are limited to 5MB per image in JPEG, PNG, WEBP or GIF,
smaller than that if the request is refused.

RESERVATION WORKFLOW
1. A tenant picks an approved property, a start date and a duration in months.
2. The request is created with status "pending". A tenant cannot reserve their
   own property, cannot duplicate an existing pending or accepted request for the
   same property and period, and cannot book dates that overlap an active
   (pending or accepted) reservation.
3. The owner receives a "New Reservation Request" notification.
4. The owner accepts or rejects it from Owner > Reservations.
5. The tenant is notified of the decision. Only "pending" reservations can be
   accepted or rejected, and only "pending" reservations can be cancelled by the
   tenant. Accepting is refused if the dates are already booked.
6. Once accepted, the tenant must pay.

PAYMENT WORKFLOW
Only an accepted reservation can be paid. The total is computed server side as
the property monthly price multiplied by the number of months, never from what
the client sends. The amount cannot exceed the remaining balance. The methods are
cash, card and transfer.
A single payment record per reservation tracks the running totals. After the first
successful payment, ONE rental contract is generated for that reservation, with
a unique index guaranteeing it is generated only once. Both the tenant and the
owner are notified when the contract is ready.
Later monthly payments do NOT create a second contract. They update the existing
financials: total paid increases, remaining balance decreases, and a separate
payment receipt PDF/ticket is issued for that payment. The receipt carries the
payment reference, date, tenant, owner, property, address, paid period, amount
paid, total paid after the payment, remaining balance and payment status. The
contract stays single and is updated instead.
When the remaining balance reaches zero the payment status becomes "paid".

MESSAGES vs NOTIFICATIONS (they are different systems)
Messages are direct tenant <-> owner conversations. A conversation belongs to a
tenant, an owner, a property and, when there is one, a reservation. A message
carries a sender, a receiver, content, a date and a read/unread flag. A tenant
opens a conversation from a property page and writes only to that property's
owner; an owner replies inside an existing thread and never opens one. A message
never changes a reservation, payment or contract state.
Notifications are automatic system events, never written by hand: new reservation
request, reservation accepted, reservation rejected, reservation cancelled,
payment required, payment processed, payment received, contract ready.

HOW TO FIND THINGS IN THE APP
Tenant pages: Dashboard, My Reservations, My Contracts, Payments, Messages,
Profile, Notifications (bell icon).
Owner pages: Dashboard, My Properties, Reservations, Contracts, Payments,
Messages, Profile, Notifications (bell icon).
Public pages: Home, Properties (search and filters), property detail page.

ESCALATION
Support is reached through the Contact Us page linked in the footer of the
application. Never invent an email address, a phone number or a social account:
there is none in the system. When a problem needs a human, say so and point to
the Contact Us page in the footer.
TEXT;
    }

    /**
     * First level troubleshooting playbooks.
     *
     * Each entry is matched on the words the user actually typed, and each line
     * is a step that is genuinely available in the application.
     *
     * @return array<int, array{keywords: array<int, string>, steps: array<int, string>}>
     */
    public static function playbooks(): array
    {
        return [
            [
                'keywords' => ['reservation', 'réservation', 'reserve', 'réserver', 'book', 'booking', 'ma9dertch ndir'],
                'steps' => [
                    'Only approved properties can be reserved; a property still pending admin review cannot.',
                    'You cannot reserve your own property if the account is an owner.',
                    'You cannot send the same request twice for the same property and the same dates.',
                    'The dates must not overlap another pending or accepted reservation on that property.',
                    'The start date cannot be in the past and the duration must be between 1 and 36 months.',
                    'Only a tenant account can create a reservation.',
                ],
            ],
            [
                'keywords' => ['payment', 'paiement', 'pay', 'payer', 'khlss', 'khless', 'checkout', 'card', 'carte', 'transfer', 'virement', 'cash', 'especes'],
                'steps' => [
                    'The reservation must be accepted first; a pending reservation cannot be paid.',
                    'The amount cannot be higher than the remaining balance shown at checkout.',
                    'The remaining balance is the monthly price multiplied by the months, minus what is already paid.',
                    'The available methods are cash, card and transfer.',
                    'Only a tenant account can pay, and only for its own reservation.',
                ],
            ],
            [
                'keywords' => ['contract', 'contrat', 'contrat de location'],
                'steps' => [
                    'The contract is generated automatically after the first successful payment, never before.',
                    'It appears once per reservation; later payments update it instead of creating a new one.',
                    'If the reservation is accepted, paid for and still has no contract, the payment may have gone through while the PDF generation was still being retried.',
                    'Once generated, the tenant and the owner both get a "Contract Ready for Download" notification.',
                ],
            ],
            [
                'keywords' => ['notification', 'notifications', 'notif', 'bell', 'cloche'],
                'steps' => [
                    'Notifications are generated by the system when an event happens; they are never sent by other people.',
                    'They are read from the bell icon in the top bar, and the unread badge counts only the unread ones.',
                    '"Mark all as read" clears the badge; the history stays in the list.',
                    'A notification is only created for an event that really happened, so a missing one usually means the event has not occurred yet.',
                ],
            ],
            [
                'keywords' => ['message', 'messages', 'message', 'msg', 'contact owner', 'contacter le proprietaire', 'kifach ncontacti'],
                'steps' => [
                    'A tenant starts a conversation from a property page, by writing to that property owner.',
                    'An owner replies inside an existing conversation and cannot start one.',
                    'Messages are not notifications: a message never changes a reservation, payment or contract.',
                    'A conversation carries the property, and the reservation once one exists.',
                    'You cannot message yourself or a user who is not the owner of the property.',
                ],
            ],
            [
                'keywords' => ['upload', 'image', 'photo', 'picture', 'telecharger', 'download', 'cannot add property', 'cannot upload'],
                'steps' => [
                    'Photos must be JPEG, PNG, WEBP or GIF and smaller than 5MB; profile pictures are capped at 2MB.',
                    'A renamed file or a text file with an image header is rejected, because the real type is read from the image data.',
                    'A property is not listed for tenants until an admin approves it after you add it.',
                    'Only an owner account can add or edit a property.',
                ],
            ],
            [
                'keywords' => ['login', 'log in', 'sign in', 'password', 'mot de passe', 'compte', 'account', 'profile', 'profil'],
                'steps' => [
                    'Profile updates (name, phone, avatar) are made from the Profile page; the role and the email cannot be edited there.',
                    'Changing the password requires the current password and signs out every other session.',
                    'An inactive account cannot sign in; only an admin can reactivate it.',
                    'The token expires; signing in again restores access.',
                ],
            ],
        ];
    }

    /**
     * Playbook steps that match the words in the user's message.
     *
     * @return array<int, array<int, string>>
     */
    public static function relevantSteps(string $haystack): array
    {
        $haystack = mb_strtolower($haystack);
        $matched = [];

        foreach (self::playbooks() as $playbook) {
            foreach ($playbook['keywords'] as $keyword) {
                if (str_contains($haystack, mb_strtolower($keyword))) {
                    $matched = array_merge($matched, $playbook['steps']);
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Route a tenant uses to reach support, taken from the footer of the app.
     */
    public static function supportPath(): string
    {
        return 'the "Contact Us" page linked in the application footer';
    }
}
