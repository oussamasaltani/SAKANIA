<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('type', 50)->nullable();
            $table->decimal('area', 10, 2)->nullable();
            $table->decimal('price_per_month', 10, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('rooms')->default(0);
            $table->integer('bathrooms')->default(0);
            $table->integer('floor')->default(0);
            $table->integer('capacity')->default(0);
            $table->json('amenities')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
