<?php

namespace Tests\Feature;

use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Livewire\Portal\Admin\SystemSettings;
use App\Livewire\Portal\ReviewQueue;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\StageHistory;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StageWarningTimesTest extends TestCase
{
    use RefreshDatabase;

    private ClientAccount $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->dealer = ClientAccount::query()->create([
            'name' => 'Dealer A',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);

        SystemSetting::current()->update([
            'idle_timeout_minutes' => 30,
            'absolute_timeout_minutes' => 480,
            'retention_period_options' => [3, 6, 12, 24],
            'retention_max_months' => 24,
            'archive_after_days' => 90,
            'retention_wording' => 'Consent wording.',
        ]);
    }

    public function test_unconfigured_steps_default_to_48_hours_and_blank_steps_have_no_warning(): void
    {
        $settings = SystemSetting::current();
        $settings->update(['stage_warning_hours' => [
            ApplicationStage::DocumentReview->value => 8,
            ApplicationStage::SubmittedToAuthority->value => null,
        ]]);

        $this->assertSame(8, $settings->warningHoursFor(ApplicationStage::DocumentReview));
        $this->assertNull($settings->warningHoursFor(ApplicationStage::SubmittedToAuthority));
        $this->assertSame(SystemSetting::DEFAULT_WARNING_HOURS, $settings->warningHoursFor(ApplicationStage::Submitted));
        $this->assertNull($settings->warningHoursFor(ApplicationStage::Draft));
        $this->assertNull($settings->warningHoursFor(ApplicationStage::Completed));
    }

    public function test_moving_into_a_step_uses_that_steps_warning_time(): void
    {
        SystemSetting::current()->update(['stage_warning_hours' => [
            ApplicationStage::DocumentReview->value => 6,
        ]]);
        $reviewer = $this->reviewer();
        $application = $this->application(ApplicationStage::Submitted);

        $this->freezeSecond();
        $application = app(TransitionApplication::class)->handle($application, ApplicationStage::DocumentReview, $reviewer);

        $this->assertTrue($application->due_at->equalTo(now()->addHours(6)));
    }

    public function test_a_step_without_a_warning_time_never_flags_the_application(): void
    {
        SystemSetting::current()->update(['stage_warning_hours' => [
            ApplicationStage::DocumentReview->value => null,
        ]]);
        $application = $this->application(ApplicationStage::Submitted);

        $application = app(TransitionApplication::class)->handle($application, ApplicationStage::DocumentReview, $this->reviewer());

        $this->assertNull($application->due_at);

        $this->travel(30)->days();
        $this->assertFalse($application->fresh()->isPastWarningTime());
    }

    public function test_owner_sets_warning_times_and_open_applications_in_changed_steps_are_re_dated(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('owner');

        $inReview = $this->application(ApplicationStage::DocumentReview, ['due_at' => now()->addHours(40)]);
        $this->enteredStage($inReview, now()->subHours(10));
        $atAuthority = $this->application(ApplicationStage::SubmittedToAuthority, ['due_at' => now()->addHours(40)]);
        $this->enteredStage($atAuthority, now()->subHours(10));
        $untouched = $this->application(ApplicationStage::Submitted, ['due_at' => now()->addHours(40)]);
        $this->enteredStage($untouched, now()->subHours(10));
        $withoutHistory = $this->application(ApplicationStage::DocumentReview, ['due_at' => now()->addHours(40)]);
        Application::query()->whereKey($withoutHistory->id)->toBase()->update(['updated_at' => now()->subHours(2)]);
        $updatedAt = $inReview->fresh()->updated_at;

        $this->actingAs($owner);

        Livewire::test(SystemSettings::class)
            ->assertSet('warning_hours.'.ApplicationStage::DocumentReview->value, SystemSetting::DEFAULT_WARNING_HOURS)
            ->set('warning_hours.'.ApplicationStage::DocumentReview->value, '4')
            ->set('warning_hours.'.ApplicationStage::SubmittedToAuthority->value, '')
            ->call('save')
            ->assertHasNoErrors();

        $settings = SystemSetting::current();
        $this->assertSame(4, $settings->warningHoursFor(ApplicationStage::DocumentReview));
        $this->assertNull($settings->warningHoursFor(ApplicationStage::SubmittedToAuthority));
        $this->assertSame(SystemSetting::DEFAULT_WARNING_HOURS, $settings->warningHoursFor(ApplicationStage::Submitted));

        $inReview->refresh();
        $this->assertTrue($inReview->isPastWarningTime());
        $this->assertSame($updatedAt->toDateTimeString(), $inReview->updated_at->toDateTimeString());
        $this->assertTrue($withoutHistory->fresh()->due_at->between(now()->addHours(2)->subMinute(), now()->addHours(2)->addMinute()));
        $this->assertNull($atAuthority->fresh()->due_at);
        $this->assertFalse($untouched->fresh()->isPastWarningTime());

        $this->assertDatabaseHas('audit_events', ['action' => 'settings.stage_warning_hours_changed']);
    }

    public function test_warning_times_must_be_whole_positive_hours(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('owner');

        $this->actingAs($owner);

        Livewire::test(SystemSettings::class)
            ->set('warning_hours.'.ApplicationStage::DocumentReview->value, '0')
            ->call('save')
            ->assertHasErrors(['warning_hours.'.ApplicationStage::DocumentReview->value]);
    }

    public function test_review_queue_flags_and_filters_applications_past_their_warning_time(): void
    {
        $reviewer = $this->reviewer();
        $late = $this->application(ApplicationStage::DocumentReview, ['due_at' => now()->subHour()]);
        $onTime = $this->application(ApplicationStage::Submitted, ['due_at' => now()->addHours(5)]);

        $this->actingAs($reviewer);

        Livewire::test(ReviewQueue::class)
            ->assertViewHas('stats', fn (array $stats): bool => $stats['past_warning'] === 1)
            ->assertSee($late->reference)
            ->assertSee($onTime->reference)
            ->assertSee('Past warning time')
            ->assertDontSee('SLA')
            ->call('showPastWarning')
            ->assertSet('pastWarning', true)
            ->assertSee($late->reference)
            ->assertDontSee($onTime->reference);
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        return $reviewer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function application(ApplicationStage $stage, array $overrides = []): Application
    {
        static $sequence = 0;
        $sequence++;

        return Application::query()->create(array_merge([
            'reference' => sprintf('LIC-WARN-%05d', $sequence),
            'client_account_id' => $this->dealer->id,
            'stage' => $stage,
        ], $overrides));
    }

    private function enteredStage(Application $application, DateTimeInterface $at): void
    {
        $history = StageHistory::query()->create([
            'application_id' => $application->id,
            'from_stage' => ApplicationStage::Submitted,
            'to_stage' => $application->stage,
        ]);

        $history->forceFill(['created_at' => $at])->save();
    }
}
