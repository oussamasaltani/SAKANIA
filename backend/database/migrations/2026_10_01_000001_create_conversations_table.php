<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A conversation is the tenant <-> owner thread about one property.
        // One row is created by the first message and every later message in
        // that thread points back at it, which is what keeps the messages of a
        // pair together and lets the thread carry its property / reservation.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_message_at')->nullable();

            // The thread between a tenant and an owner about a property is
            // unique, so the "create on first message" lookup is safe.
            $table->unique(['tenant_id', 'owner_id', 'property_id'], 'conversations_pair_property_unique');
            $table->index('last_message_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->after('is_read');

            // Thread reads filter by conversation and sort by sent_at; the
            // unread badge counts a receiver's unread messages.
            $table->index(['conversation_id', 'sent_at'], 'messages_conversation_sent_at_index');
            $table->index(['receiver_id', 'is_read'], 'messages_receiver_is_read_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropIndex('messages_conversation_sent_at_index');
            $table->dropIndex('messages_receiver_is_read_index');
            $table->dropColumn(['conversation_id', 'read_at']);
        });

        Schema::dropIfExists('conversations');
    }
};