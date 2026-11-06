<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Loan extends Model
{
    protected $table = 'library.loan';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'member_id',
        'loaned_by_user_id',
        'loaned_at',
    ];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function loanedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'loan_id', 'id');
    }

    /**
     * Artículo VII de constitution.md:
     * El estado del préstamo es derivado, no se almacena en base de datos.
     * Retorna: 'closed', 'overdue', u 'open'.
     */
    public function getCalculatedStatusAttribute(): string
    {
        $now = Carbon::now('UTC');
        $items = $this->items;

        if ($items->isEmpty()) {
            return 'open';
        }

        $allReturned = $items->every(fn($item) => $item->returned_at !== null);
        if ($allReturned) {
            return 'closed';
        }

        $hasOverdue = $items->contains(fn($item) => $item->returned_at === null && $item->due_at->isPast());
        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }
}
