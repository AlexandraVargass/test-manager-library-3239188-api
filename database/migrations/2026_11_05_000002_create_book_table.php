<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library.book', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 250);
            $table->string('author', 200);
            $table->char('isbn', 13);
            $table->uuid('category_id');
            $table->integer('total_copies');
            $table->integer('available_copies');
            $table->string('cover_key', 512)->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('category_id')
                ->references('id')
                ->on('library.category')
                ->onDelete('restrict');
        });

        // Restricciones e índices según data-model.md §4
        DB::statement('ALTER TABLE library.book ADD CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies)');
        DB::statement('ALTER TABLE library.book ADD CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1)');
        DB::statement('CREATE UNIQUE INDEX uq_book_active_isbn ON library.book (isbn) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_book_category_title ON library.book (category_id, title) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('library.book');
    }
};
