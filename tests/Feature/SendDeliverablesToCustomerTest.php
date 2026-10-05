<?php

use App\Actions\SendDeliverablesToCustomer;
use App\Enums\ApplicationStage;
use App\Enums\DeliverableKind;
use App\Enums\OwnerType;
use App\Livewire\Portal\ApplicationShow;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\DeliverableDocument;
use App\Models\User;
use App\Notifications\DeliverablesForCustomer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Dealers forward the finished NaTIS cert and licence disc straight to
 * the vehicle owner's email from inside the application screen so they
 * don't have to download + re-attach in Outlook. The dealership name
 * drives the email, each deliverable is attached inline, and the
 * recipient address is masked in the audit log.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');
    Notification::fake();

    $this->account = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->otherAccount = ClientAccount::query()->create([
        'name' => 'Lowveld Vehicle Group',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->dealer = User::factory()->create([
        'client_account_id' => $this->account->id,
        'is_active' => true,
    ]);
    $this->dealer->assignRole('customer_user');

    $this->application = Application::query()->create([
        'reference' => 'EXL-SEND-00001',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::Completed,
    ]);
});

function fakeDeliverableBytes(): string
{
    return "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

function storedDeliverable(Application $application, DeliverableKind $kind, string $filename): DeliverableDocument
{
    $path = 'deliverables/'.$application->id.'/'.str_replace('.pdf', '', $filename).'.pdf';
    Storage::disk('documents')->put($path, fakeDeliverableBytes());

    return DeliverableDocument::query()->create([
        'application_id' => $application->id,
        'kind' => $kind,
        'storage_path' => $path,
        'original_filename' => $filename,
        'mime' => 'application/pdf',
        'size_bytes' => strlen(fakeDeliverableBytes()),
        'uploaded_at' => now(),
    ]);
}

it('sends every selected deliverable to the customer email with the dealership name on the subject + body', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');
    $disc = storedDeliverable($this->application, DeliverableKind::LicenceDisc, 'disc.pdf');

    $count = app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'customer@example.com',
        deliverableIds: [$natis->id, $disc->id],
        dealerMessage: 'Please keep the NaTIS in the glove box.',
    );

    expect($count)->toBe(2);

    Notification::assertSentOnDemand(
        DeliverablesForCustomer::class,
        function (DeliverablesForCustomer $notification, array $channels, object $notifiable): bool {
            $routed = $notifiable->routes['mail'] ?? null;
            $toCustomer = is_array($routed)
                ? in_array('customer@example.com', $routed, true)
                : $routed === 'customer@example.com';

            return $toCustomer
                && $notification->deliverables->count() === 2
                && $notification->dealershipName === 'Highveld Commercial Centurion'
                && $notification->dealerMessage === 'Please keep the NaTIS in the glove box.';
        },
    );
});

it('rejects an invalid email with a validation error on recipient_email', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    expect(fn () => app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'not-an-email',
        deliverableIds: [$natis->id],
    ))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
});

it('rejects a send with no deliverable ids', function (): void {
    expect(fn () => app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'customer@example.com',
        deliverableIds: [],
    ))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
});

it('refuses to attach a deliverable that belongs to a different application', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    $foreignApp = Application::query()->create([
        'reference' => 'EXL-OTHER-9999',
        'client_account_id' => $this->otherAccount->id,
        'stage' => ApplicationStage::Completed,
    ]);
    $foreignDeliverable = storedDeliverable($foreignApp, DeliverableKind::LicenceDisc, 'foreign.pdf');

    expect(fn () => app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'customer@example.com',
        deliverableIds: [$natis->id, $foreignDeliverable->id],
    ))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
});

it('writes a deliverable.sent_to_customer audit event with the recipient email masked', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'paul.c@example.com',
        deliverableIds: [$natis->id],
    );

    $this->assertDatabaseHas('audit_events', [
        'action' => 'deliverable.sent_to_customer',
        'subject_type' => Application::class,
        'subject_id' => $this->application->id,
        'actor_user_id' => $this->dealer->id,
    ]);

    $event = AuditEvent::query()
        ->where('action', 'deliverable.sent_to_customer')
        ->firstOrFail();

    // Masked local part should not leak the full address.
    expect($event->after['recipient_email_masked'] ?? null)->toBe('p...c@example.com')
        ->and($event->summary)->not->toContain('paul.c@example.com');
});

it('policy: dealer on another dealership cannot send deliverables', function (): void {
    $stranger = User::factory()->create([
        'client_account_id' => $this->otherAccount->id,
        'is_active' => true,
    ]);
    $stranger->assignRole('customer_user');

    storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    // Mount() on ApplicationShow already blocks the stranger via the
    // ApplicationPolicy@view check, so cross-dealer users never even
    // reach a state where they could call openSendForm or
    // sendDeliverablesToCustomer. That's the strongest posture.
    Livewire::actingAs($stranger)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('the Livewire form pre-selects all deliverables and pre-fills the email from the owner business clients proxy_contact when it looks like an email', function (): void {
    storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');
    storedDeliverable($this->application, DeliverableKind::LicenceDisc, 'disc.pdf');

    $fleet = BusinessClient::query()->create([
        'client_account_id' => $this->account->id,
        'business_name' => 'Pauls Transport',
        'proxy_contact' => 'paul@paulstransport.co.za',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    $this->application->update([
        'owner_type' => OwnerType::Business,
        'business_client_id' => $fleet->id,
    ]);

    Livewire::actingAs($this->dealer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('openSendForm')
        ->assertSet('showSendForm', true)
        ->assertSet('sendRecipientEmail', 'paul@paulstransport.co.za')
        ->assertCount('sendDeliverableIds', 2);
});

it('the Livewire form flashes a success message with the count after sending', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');
    $disc = storedDeliverable($this->application, DeliverableKind::LicenceDisc, 'disc.pdf');

    Livewire::actingAs($this->dealer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('sendDeliverableIds', [$natis->id, $disc->id])
        ->set('sendRecipientEmail', 'customer@example.com')
        ->set('sendMessage', '')
        ->call('sendDeliverablesToCustomer')
        ->assertHasNoErrors()
        ->assertSet('showSendForm', false)
        ->assertSee('2 documents emailed');

    Notification::assertSentOnDemand(DeliverablesForCustomer::class);
});

it('the Livewire form surfaces validation errors from the action', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    Livewire::actingAs($this->dealer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('sendDeliverableIds', [$natis->id])
        ->set('sendRecipientEmail', 'not-valid')
        ->call('sendDeliverablesToCustomer')
        ->assertHasErrors(['recipient_email']);

    Notification::assertNothingSent();
});

it('attaches the physical files on the notification mail message', function (): void {
    $natis = storedDeliverable($this->application, DeliverableKind::NatisCertificate, 'natis.pdf');

    app(SendDeliverablesToCustomer::class)->handle(
        application: $this->application,
        actor: $this->dealer,
        recipientEmail: 'customer@example.com',
        deliverableIds: [$natis->id],
    );

    Notification::assertSentOnDemand(
        DeliverablesForCustomer::class,
        function (DeliverablesForCustomer $notification): bool {
            $mail = $notification->toMail((object) []);

            $attachments = $mail->attachments ?? [];
            if ($attachments === []) {
                return false;
            }

            $first = $attachments[0];
            $hasRightFilename = ($first['options']['as'] ?? '') === 'NaTIS registration certificate.pdf';
            $hasRightMime = ($first['options']['mime'] ?? '') === 'application/pdf';
            $hasFile = is_string($first['file'] ?? null) && is_file($first['file']);

            return $hasRightFilename && $hasRightMime && $hasFile;
        },
    );
});
