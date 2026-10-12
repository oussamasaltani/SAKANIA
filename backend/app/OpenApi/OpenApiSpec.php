<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Root of the SAKANAI OpenAPI document.
 *
 * swagger-php only needs the attributes somewhere inside the scanned tree
 * (config/l5-swagger.php points the scanner at app/), so this file carries the
 * document level metadata, the tag list, the reusable schemas and the reusable
 * responses that the API controllers reference by name. Keeping them here keeps
 * the controllers focused on their routes and avoids repeating the same error
 * shape on every operation.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SAKANAI API',
    description: <<<'TEXT'
    REST API for the SAKANAI rental platform.

    It covers authentication (email/password and Google), property browsing and
    owner-side management, reservations, payments, contracts, tenant <-> owner
    messaging, notifications, the SAKANIA chatbot and the administration
    endpoints.

    Authenticate by calling `POST /api/login` (or `POST /api/register`), then
    click **Authorize** and paste the returned `token`.

    Role scoping (tenant = role_id 1, owner = 2, admin = 3) is enforced by
    server-side middleware and is reflected in the endpoints below.
    TEXT,
    contact: new OA\Contact(email: 'support@sakania.ma'),
    license: new OA\License(name: 'Proprietary'),
)]
#[OA\Tag(name: 'Authentication', description: 'Registration, login, logout and Google sign-in.')]
#[OA\Tag(name: 'Profile', description: 'The signed-in account: personal information, avatar and password.')]
#[OA\Tag(name: 'Properties', description: 'Public property catalogue and search.')]
#[OA\Tag(name: 'Reservations', description: 'Tenant reservation requests and owner decisions.')]
#[OA\Tag(name: 'Payments', description: 'Checkout, payment processing and receipts.')]
#[OA\Tag(name: 'Contracts', description: 'Rental contract details and PDF download.')]
#[OA\Tag(name: 'Messaging', description: 'Tenant <-> owner conversations.')]
#[OA\Tag(name: 'Notifications', description: 'In-app notifications.')]
#[OA\Tag(name: 'Favorites', description: 'Properties saved by the signed-in user.')]
#[OA\Tag(name: 'Chatbot', description: 'The SAKANIA assistant.')]
#[OA\Tag(name: 'Tenant', description: 'Tenant dashboard and tenant-scoped listings.')]
#[OA\Tag(name: 'Owner', description: 'Owner dashboard, property management and incoming bookings.')]
#[OA\Tag(name: 'Admin', description: 'Administration of users, properties, reservations, contracts and payments.')]
class OpenApiSpec
{
    /*
     * ---------------------------------------------------------------------
     * Reusable responses
     * ---------------------------------------------------------------------
     */

    #[OA\Response(
        response: 'Unauthorized',
        description: 'Authentication required or the access token is invalid.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
    )]
    #[OA\Response(
        response: 'Forbidden',
        description: 'The account is authenticated but is not allowed to perform this action.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
    )]
    #[OA\Response(
        response: 'NotFound',
        description: 'The requested resource does not exist or is not visible to the caller.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
    )]
    #[OA\Response(
        response: 'ValidationError',
        description: 'The submitted payload failed validation.',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
    )]
    #[OA\Response(
        response: 'ServerError',
        description: 'An unexpected server-side error occurred.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
    )]
    public function responses(): void
    {
    }

    /*
     * ---------------------------------------------------------------------
     * Reusable parameters
     * ---------------------------------------------------------------------
     */

    #[OA\Parameter(
        parameter: 'PageParameter',
        name: 'page',
        in: 'query',
        description: 'Page number of the paginated result.',
        schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
    )]
    #[OA\Parameter(
        parameter: 'PerPageParameter',
        name: 'per_page',
        in: 'query',
        description: 'Number of items per page.',
        schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15),
    )]
    #[OA\Parameter(
        parameter: 'SearchParameter',
        name: 'search',
        in: 'query',
        description: 'Free-text search term.',
        schema: new OA\Schema(type: 'string'),
    )]
    public function parameters(): void
    {
    }

    /*
     * ---------------------------------------------------------------------
     * Schemas
     * ---------------------------------------------------------------------
     */

    #[OA\Schema(
        schema: 'ErrorMessage',
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Property not found.'),
        ],
    )]
    #[OA\Schema(
        schema: 'ValidationErrorResponse',
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'The email field is required.'),
            new OA\Property(
                property: 'errors',
                type: 'object',
                additionalProperties: new OA\AdditionalProperties(
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                ),
                example: ['email' => ['The provided credentials are incorrect.']],
            ),
        ],
    )]
    #[OA\Schema(
        schema: 'Role',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'tenant'),
        ],
    )]
    #[OA\Schema(
        schema: 'User',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 12),
            new OA\Property(property: 'full_name', type: 'string', example: 'Amina El Idrissi'),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amina@example.com'),
            new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+212600000000'),
            new OA\Property(property: 'role_id', type: 'integer', enum: [1, 2, 3], example: 1),
            new OA\Property(property: 'is_active', type: 'boolean', example: true),
            new OA\Property(property: 'avatar', type: 'string', nullable: true, example: 'uploads/profiles/abc.png'),
            new OA\Property(property: 'avatar_url', type: 'string', nullable: true, example: '/uploads/profiles/abc.png'),
            new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Atlas Immo'),
            new OA\Property(property: 'response_rate', type: 'string', nullable: true, example: '95.00'),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'role', type: 'string', nullable: true, example: 'tenant'),
            new OA\Property(property: 'has_password', type: 'boolean', example: true),
        ],
    )]
    #[OA\Schema(
        schema: 'AuthResponse',
        properties: [
            new OA\Property(property: 'user', ref: '#/components/schemas/User'),
            new OA\Property(property: 'token', type: 'string', example: '3|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
            new OA\Property(property: 'created', type: 'boolean', description: 'Present on the Google exchange response only.'),
        ],
    )]
    #[OA\Schema(
        schema: 'PropertyImage',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 5),
            new OA\Property(property: 'property_id', type: 'integer', example: 3),
            new OA\Property(property: 'image_url', type: 'string', example: '/uploads/properties/abc.jpg'),
        ],
    )]
    #[OA\Schema(
        schema: 'Property',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 3),
            new OA\Property(property: 'owner_id', type: 'integer', example: 7),
            new OA\Property(property: 'title', type: 'string', example: 'Modern apartment in Gueliz'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'location', type: 'string', example: 'Marrakech, Gueliz'),
            new OA\Property(property: 'type', type: 'string', enum: ['apartment', 'house', 'villa', 'studio', 'office'], example: 'apartment'),
            new OA\Property(property: 'area', type: 'string', example: '85.00'),
            new OA\Property(property: 'price_per_month', type: 'string', example: '4500.00'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected'], example: 'approved'),
            new OA\Property(property: 'rooms', type: 'integer', example: 3),
            new OA\Property(property: 'bathrooms', type: 'integer', example: 1),
            new OA\Property(property: 'floor', type: 'integer', example: 2),
            new OA\Property(property: 'capacity', type: 'integer', example: 4),
            new OA\Property(property: 'amenities', type: 'array', items: new OA\Items(type: 'string'), example: ['wifi', 'parking']),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'owner', ref: '#/components/schemas/User'),
            new OA\Property(property: 'images', type: 'array', items: new OA\Items(ref: '#/components/schemas/PropertyImage')),
        ],
    )]
    #[OA\Schema(
        schema: 'Reservation',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 21),
            new OA\Property(property: 'property_id', type: 'integer', example: 3),
            new OA\Property(property: 'tenant_id', type: 'integer', example: 12),
            new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-11-01'),
            new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2027-05-01'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'accepted', 'rejected', 'cancelled'], example: 'pending'),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'reference', type: 'string', example: 'RES-00021'),
            new OA\Property(property: 'duration_months', type: 'integer', example: 6),
            new OA\Property(property: 'contract_id', type: 'integer', nullable: true),
            new OA\Property(property: 'paid_amount', type: 'number', format: 'float', example: 0),
            new OA\Property(property: 'remaining_balance', type: 'number', format: 'float', example: 27000),
            new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
            new OA\Property(property: 'tenant', ref: '#/components/schemas/User'),
        ],
    )]
    #[OA\Schema(
        schema: 'Payment',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 44),
            new OA\Property(property: 'reservation_id', type: 'integer', example: 21),
            new OA\Property(property: 'tenant_id', type: 'integer', example: 12),
            new OA\Property(property: 'total_amount', type: 'string', example: '27000.00'),
            new OA\Property(property: 'amount', type: 'string', example: '27000.00'),
            new OA\Property(property: 'paid_amount', type: 'string', example: '27000.00'),
            new OA\Property(property: 'remaining_balance', type: 'string', example: '0.00'),
            new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'transfer'], example: 'card'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'failed', 'refunded'], example: 'paid'),
            new OA\Property(property: 'transaction_ref', type: 'string', example: 'TXN-ABCDEF123456'),
            new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
            new OA\Property(property: 'reservation', ref: '#/components/schemas/Reservation'),
            new OA\Property(property: 'tenant', ref: '#/components/schemas/User'),
            new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
        ],
    )]
    #[OA\Schema(
        schema: 'PaymentReceipt',
        properties: [
            new OA\Property(property: 'payment_id', type: 'integer', example: 44),
            new OA\Property(property: 'reference', type: 'string', example: 'RCP-00044'),
            new OA\Property(property: 'amount', type: 'string', example: '27000.00'),
            new OA\Property(property: 'paid_amount', type: 'string', example: '27000.00'),
            new OA\Property(property: 'remaining_balance', type: 'string', example: '0.00'),
            new OA\Property(property: 'download_url', type: 'string', example: '/api/payments/44/receipt'),
        ],
    )]
    #[OA\Schema(
        schema: 'Contract',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 9),
            new OA\Property(property: 'reservation_id', type: 'integer', example: 21),
            new OA\Property(property: 'owner_id', type: 'integer', example: 7),
            new OA\Property(property: 'tenant_id', type: 'integer', example: 12),
            new OA\Property(property: 'file_url', type: 'string', nullable: true, example: 'contracts/SC-00021.pdf'),
            new OA\Property(property: 'signed_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'ended', 'completed'], example: 'active'),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'reference', type: 'string', example: 'SC-00021'),
            new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
            new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'duration_months', type: 'integer', nullable: true),
            new OA\Property(property: 'monthly_rent', type: 'string', nullable: true),
            new OA\Property(property: 'total_amount', type: 'string', nullable: true),
            new OA\Property(property: 'paid_amount', type: 'string', nullable: true),
            new OA\Property(property: 'remaining_balance', type: 'string', nullable: true),
            new OA\Property(property: 'payment_status', type: 'string', nullable: true),
            new OA\Property(property: 'tenant', ref: '#/components/schemas/User'),
            new OA\Property(property: 'owner', ref: '#/components/schemas/User'),
        ],
    )]
    #[OA\Schema(
        schema: 'Notification',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 101),
            new OA\Property(property: 'user_id', type: 'integer', example: 12),
            new OA\Property(property: 'title', type: 'string', example: 'Reservation Accepted'),
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'type', type: 'string', example: 'reservation'),
            new OA\Property(property: 'related_type', type: 'string', nullable: true),
            new OA\Property(property: 'related_id', type: 'integer', nullable: true),
            new OA\Property(property: 'is_read', type: 'boolean', example: false),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        ],
    )]
    #[OA\Schema(
        schema: 'Favorite',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 33),
            new OA\Property(property: 'user_id', type: 'integer', example: 12),
            new OA\Property(property: 'property_id', type: 'integer', example: 3),
            new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
        ],
    )]
    #[OA\Schema(
        schema: 'Conversation',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 8),
            new OA\Property(property: 'tenant_id', type: 'integer', example: 12),
            new OA\Property(property: 'owner_id', type: 'integer', example: 7),
            new OA\Property(property: 'property_id', type: 'integer', example: 3),
            new OA\Property(property: 'reservation_id', type: 'integer', nullable: true),
            new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        ],
    )]
    #[OA\Schema(
        schema: 'Message',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 500),
            new OA\Property(property: 'conversation_id', type: 'integer', example: 8),
            new OA\Property(property: 'sender_id', type: 'integer', example: 12),
            new OA\Property(property: 'receiver_id', type: 'integer', example: 7),
            new OA\Property(property: 'content', type: 'string', example: 'Is the apartment still available?'),
            new OA\Property(property: 'sent_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'is_read', type: 'boolean', example: false),
            new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        ],
    )]
    #[OA\Schema(
        schema: 'ChatbotReply',
        properties: [
            new OA\Property(property: 'reply', type: 'string', example: 'Yes, that apartment is still available.'),
            new OA\Property(property: 'conversation_id', type: 'integer', example: 8),
            new OA\Property(property: 'intent', type: 'string', example: 'property_availability'),
        ],
    )]
    #[OA\Schema(
        schema: 'MessageResponse',
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Operation completed successfully.'),
        ],
    )]
    public function schemas(): void
    {
    }
}
