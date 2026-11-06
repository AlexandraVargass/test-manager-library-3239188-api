<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library.loan_item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('loan_id');
            $table->uuid('book_id');
            $table->string('book_title', 250);
            $table->char('isbn', 13);
            $table->string('category_name', 120);
            $table->timestampTz('due_at');
            $table->timestampTz('returned_at')->nullable();

            $table->foreign('loan_id')
                ->references('id')
                ->on('library.loan')
                ->onDelete('cascade');

            $table->foreign('book_id')
                ->references('id')
                ->on('library.book')
                ->onDelete('restrict');

            $table->unique(['loan_id', 'book_id'], 'uq_loan_item_loan_book');
            $table->index('book_id', 'idx_loan_item_book');
        });

        // Índice parcial para consultar libros vencidos con rapidez (data-model.md §4)
        DB::statement('CREATE INDEX idx_loan_item_overdue ON library.loan_item (due_at) WHERE returned_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('library.loan_item');
    }
};
