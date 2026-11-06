<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library.user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username', 120)->unique();
            $table->string('password_hash', 512);
            $table->string('role', 40);
        });

        DB::statement("ALTER TABLE library.\"user\" ADD CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('library.user');
    }
};
