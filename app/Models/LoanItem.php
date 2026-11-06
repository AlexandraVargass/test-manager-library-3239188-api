<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class LoanItem extends Model
{
    protected $table = 'library.loan_item';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'loan_id',
        'book_id',
        'book_title',
        'isbn',
        'category_name',
        'due_at',
        'returned_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id', 'id');
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    public function isOverdue(): bool
    {
        return !$this->isReturned() && $this->due_at->isPast();
    }

    public function getDaysLateAttribute(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_at->diffInDays(Carbon::now('UTC'));
    }
}
