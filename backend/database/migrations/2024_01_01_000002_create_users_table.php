<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 100);
            $table->string('email', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('phone', 20)->nullable();
            $table->foreignId('role_id')->constrained('roles')->onDelete('restrict');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->string('avatar', 255)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->integer('response_rate')->default(100);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
