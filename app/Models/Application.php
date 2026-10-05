<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\LicenceFeeCategory;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Models\Concerns\ScopesToClientAccount;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, ScopesToClientAccount;

    protected $fillable = [
        'reference', 'client_account_id', 'request_type', 'service_type', 'vehicle_category', 'licence_category',
        'owner_type', 'business_client_id', 'is_financed', 'is_dealer_stock', 'dangerous_goods', 'title_holder_business_client_id',
        'province', 'stage', 'datafix_status', 'assigned_reviewer_id', 'due_at', 'submitted_at',
        'authority_reference', 'authority_submitted_at', 'submitted_by_id',
        'fee_snapshot', 'cancelled_reason',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function businessClient(): BelongsTo
    {
        return $this->belongsTo(BusinessClient::class);
    }

    public function titleHolder(): BelongsTo
    {
        return $this->belongsTo(BusinessClient::class, 'title_holder_business_client_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    /**
     * The customer user who clicked "Submit" on this application.
     * Null for records migrated from before this field was introduced,
     * rendered as "Unknown" in the UI.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(DeliverableDocument::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function datafix(): HasOne
    {
        return $this->hasOne(DatafixRecord::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function exportSteps(): HasMany
    {
        return $this->hasMany(ExportStep::class);
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(StageHistory::class);
    }

    public function deliverableLabel(): string
    {
        return $this->service_type === ServiceType::RegisterAndLicense
            ? 'Registration certificate and licence disc'
            : 'Registration certificate';
    }

    public function slaFlag(): ?string
    {
        if ($this->due_at === null || $this->stage->isTerminal()) {
            return null;
        }

        $start = $this->stageHistories()->latest('id')->first()?->created_at ?? $this->updated_at;
        $total = $start->diffInSeconds($this->due_at, false);

        if ($total <= 0) {
            return 'breach';
        }

        $elapsed = $start->diffInSeconds(now(), false);
        $ratio = $elapsed / $total;

        if ($ratio >= 1) {
            return 'breach';
        }

        if ($ratio >= 0.75) {
            return 'risk';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => RequestType::class,
            'service_type' => ServiceType::class,
            'vehicle_category' => VehicleCategory::class,
            'licence_category' => LicenceFeeCategory::class,
            'owner_type' => OwnerType::class,
            'province' => Province::class,
            'stage' => ApplicationStage::class,
            'datafix_status' => DatafixStatus::class,
            'is_financed' => 'boolean',
            'is_dealer_stock' => 'boolean',
            'dangerous_goods' => 'boolean',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'authority_submitted_at' => 'datetime',
            'fee_snapshot' => 'array',
        ];
    }
}
