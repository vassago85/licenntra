<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\LicenceFeeCategory;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Models\Concerns\ScopesToClientAccount;
use App\Services\FeatureFlags;
use Carbon\CarbonInterface;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, ScopesToClientAccount;

    protected $fillable = [
        'reference', 'client_account_id', 'request_type', 'service_type', 'vehicle_category', 'licence_category',
        'owner_type', 'business_client_id', 'is_financed', 'is_dealer_stock', 'dangerous_goods', 'title_holder_business_client_id',
        'province', 'stage', 'datafix_status', 'assigned_reviewer_id', 'due_at', 'submitted_at', 'completed_at',
        'authority_reference', 'authority_submitted_at', 'submitted_by_id',
        'authority_query_resolved_at', 'authority_query_resolution',
        'authority_returned_at', 'authority_returned_by_id', 'authority_return_notes',
        'fee_snapshot', 'cancelled_reason',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    /**
     * True when requesting payment bills the client and moves straight on
     * instead of waiting for finance to verify cash: either the client is
     * on a monthly statement, or the deployment does not track payments.
     */
    public function billsWithoutPaymentCheck(): bool
    {
        return (bool) $this->clientAccount?->isOnAccount() || ! FeatureFlags::paymentTrackingRequired();
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

    public function submissionPacks(): HasMany
    {
        return $this->hasMany(SubmissionPack::class);
    }

    public function latestSubmissionPack(): HasOne
    {
        return $this->hasOne(SubmissionPack::class)->latestOfMany();
    }

    public function authorityReturnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authority_returned_by_id');
    }

    /**
     * Documents that belong in the pack for the department: everything
     * operations accepted, in the order the client uploaded them.
     *
     * @return Collection<int, ApplicationDocument>
     */
    public function packDocuments(): Collection
    {
        return $this->documents()
            ->where('status', DocumentStatus::Accepted)
            ->whereNotNull('linked_version_id')
            ->with(['documentType', 'currentVersion'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The latest pack, but only while it still lists the current version of
     * every accepted document. A re-upload or new acceptance makes it stale.
     */
    public function currentSubmissionPack(): ?SubmissionPack
    {
        $pack = $this->latestSubmissionPack()->first();

        if ($pack === null) {
            return null;
        }

        $current = $this->packDocuments()->pluck('linked_version_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $packed = collect($pack->versionIds())->sort()->values()->all();

        return $current === $packed ? $pack : null;
    }

    /**
     * The note the department sent with its most recent query.
     */
    public function latestAuthorityQueryNote(): ?string
    {
        return $this->stageHistories()
            ->where('to_stage', ApplicationStage::AuthorityQuery)
            ->latest('id')
            ->value('reason');
    }

    public function deliverableLabel(): string
    {
        return $this->service_type === ServiceType::RegisterAndLicense
            ? 'Registration certificate and licence disc'
            : 'Registration certificate';
    }

    /**
     * True once the application has sat in its current step longer than
     * that step's warning time (see System settings).
     */
    public function isPastWarningTime(): bool
    {
        return $this->due_at !== null
            && ! $this->stage->isTerminal()
            && $this->due_at->isPast();
    }

    /**
     * When the application moved into its current step. Uses the
     * `stage_entered_at` aggregate when the query selected it, so list
     * views avoid one history lookup per row.
     */
    public function enteredStageAt(): CarbonInterface
    {
        $entered = array_key_exists('stage_entered_at', $this->getAttributes())
            ? $this->getAttribute('stage_entered_at')
            : $this->stageHistories()->max('created_at');

        $entered ??= $this->attributes['updated_at'] ?? $this->attributes['created_at'] ?? null;

        return $entered !== null ? $this->asDateTime($entered) : now();
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
            'authority_query_resolved_at' => 'datetime',
            'authority_returned_at' => 'datetime',
            'completed_at' => 'datetime',
            'fee_snapshot' => 'array',
        ];
    }
}
