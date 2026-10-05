<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property string $invoice_number
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $sha256
 * @property int|null $uploaded_by_id
 * @property Carbon $uploaded_at
 * @property Carbon|null $paid_at
 * @property int|null $paid_by_user_id
 * @property string|null $paid_reference
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id',
        'invoice_number',
        'storage_path',
        'original_filename',
        'mime',
        'size_bytes',
        'sha256',
        'uploaded_by_id',
        'recipient_user_id',
        'uploaded_at',
        'paid_at',
        'paid_by_user_id',
        'paid_reference',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * The dealership-side user the invoice is addressed to. Defaults to
     * the dealership's stock controller when the licensing company
     * uploads an invoice without picking a specific recipient.
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function statusLabel(): string
    {
        return $this->isPaid() ? 'Paid' : 'Outstanding';
    }

    public function statusTone(): string
    {
        return $this->isPaid() ? 'success' : 'warning';
    }

    /**
     * The amount the invoice is for, read from the parent application's
     * fee snapshot. Invoices do not store their own total.
     */
    public function amountCents(): int
    {
        return (int) ($this->application?->fee_snapshot['total_cents'] ?? 0);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('paid_at');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('paid_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
