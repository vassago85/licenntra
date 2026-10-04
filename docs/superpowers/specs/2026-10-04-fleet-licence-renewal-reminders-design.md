# Fleet licence renewal reminders

A fleet operator keeps a register of its vehicles inside Licentra. Each
vehicle records the current motor-vehicle licence expiry and keeps the
licence file on hand for download. On the first morning of each month,
every fleet with a vehicle expiring that month receives one email
listing those vehicles and linking each one to a prefilled licence
renewal.

The expiry date is read from the licence by Tesseract running on the
Licentra server. The licensing company uploads the licence, is notified
when the read finishes, and confirms the expiry, register number, and
VIN before the vehicle appears on the fleet list.

## Scope

In scope:

- A fleet vehicle register per `client_account` where `type =
  fleet_operator`.
- An uploader that accepts PDFs and photos of a motor-vehicle licence,
  runs them through Tesseract, and queues them for a licensing-company
  reviewer.
- A confirmation screen for the licensing company that shows the
  proposed expiry, register number, and VIN against the file and lets
  the reviewer accept or correct them.
- A monthly email on the first morning of each month listing vehicles
  whose licence expires that calendar month.
- A link in that email from each vehicle to a licence-renewal
  application prefilled from the fleet record. The renewal is created
  the first time the link is opened.

Out of scope:

- Roadworthy expiry reminders. The roadworthy date can be printed on the
  stored file, but it does not drive its own email.
- Operator-card expiry reminders. Same rule.
- Any OCR of application documents other than the motor-vehicle
  licence.
- Any cloud OCR service. The scan never leaves the server.
- Any change to how the existing licence-renewal application is
  reviewed, quoted, paid, or submitted to the authority.

## Why not just reuse applications?

A fleet owns vehicles Licentra never licensed. Treating the last
completed application as the "current licence" would require seeding
dummy applications for those vehicles, which crowds the real
application queue and distorts the reviewer workload metrics in
`OperationsWorkloadService`. A fleet-owned register sidesteps the
problem and leaves applications for actual licensing work.

## Users

- **Fleet user and fleet admin.** See every vehicle in their account.
  Download the stored licence file. Open the renewal link in the
  monthly email. Mark a vehicle retired.
- **Licensing-company reviewer or customer admin.** Upload licence
  files on behalf of a fleet. Review the Tesseract output and save the
  confirmed expiry, register number, and VIN.
- **Everyone else.** No access.

Visibility inside the fleet account is open to every user, matching the
existing `ScopesToClientAccount` behaviour on `Application`.

## Data model

A new `fleet_vehicles` table scoped to `client_account_id`:

```php
Schema::create('fleet_vehicles', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
    $table->string('vehicle_register_number')->nullable();
    $table->string('vin')->nullable();
    $table->string('make')->nullable();
    $table->string('model')->nullable();
    $table->string('vehicle_category'); // VehicleCategory enum
    $table->date('licence_expires_on')->nullable();
    $table->string('licence_expiry_source')->nullable(); // 'scan', 'typed'
    $table->timestamp('retired_at')->nullable();
    $table->timestamps();
    $table->index(['client_account_id', 'licence_expires_on']);
    $table->index(['client_account_id', 'retired_at']);
});
```

Two children:

- `fleet_vehicle_documents` — one row per uploaded licence file. Reuses
  the existing `document_versions` storage path and the `documents`
  disk, so retention and streaming already work. Columns:
  `fleet_vehicle_id`, `document_version_id`,
  `ocr_status` (`pending|clean|unreadable|failed`),
  `ocr_notes`, `ocr_expiry_candidate`,
  `ocr_register_candidate`, `ocr_vin_candidate`, `confirmed_at`,
  `confirmed_by_id`.
- `fleet_vehicle_events` — audit trail of create, confirm, correct,
  retire. Keeps the review history out of the main table.

A unique index on `(client_account_id, vehicle_register_number)` for
non-null register numbers prevents a fleet listing the same vehicle
twice. VIN is not unique: a VIN can appear in two fleets after a sale.

Vehicles already captured on an `Application` are not migrated. The
fleet starts empty and grows as the licensing company uploads licences.

## Flow

### Upload

A licensing-company reviewer opens the fleet's vehicle page and uploads
a licence file. Multiple files in one upload create multiple vehicles.
Each file becomes a `fleet_vehicle` row with `licence_expires_on` null
and one `fleet_vehicle_documents` row with `ocr_status = pending`.

A `ReadFleetLicence` job runs on the queue. For a PDF it renders each
page to an image with Imagick (or `pdftoppm` if Imagick is unavailable
in the image), then runs Tesseract per page. For a photo it runs
Tesseract directly. The job writes:

- `ocr_expiry_candidate`: the first `20\d{2}-\d{2}-\d{2}` found on a
  line containing `Date of expiry` or `Vervaldatum`.
- `ocr_register_candidate`: the token below `Vehicle register
  number` / `Voertuigregisternommer`.
- `ocr_vin_candidate`: the token below `Vehicle identification
  number` / `Voertuigidentifikasienommer`.
- `ocr_status`: `clean` if an expiry was found, `unreadable` otherwise,
  `failed` on exception.
- `ocr_notes`: short diagnostic when status is not `clean`.

One `fleet_vehicle` is created per page. A two-page file like the
Bellville scan creates two vehicles, each waiting for confirmation. A
single-page RC1 or licence creates one. Combining pages that belong to
the same vehicle (RC1 plus licence) is the reviewer's job on the
confirmation screen: they can attach the second document to an existing
pending vehicle instead of saving it as a new one.

### Confirm

When the job finishes, the licensing-company user who uploaded the
file receives a database notification using the existing Filament
notifications panel and an email through `NotificationDispatcher`.
Clicking the notification opens a confirmation screen that shows the
file, the three candidate fields, and the vehicle category drop-down.
The reviewer corrects or accepts the fields and saves. The save:

- Writes `licence_expires_on` on the vehicle.
- Sets `licence_expiry_source` to `scan` when the reviewer kept the
  Tesseract candidate, `typed` when they changed it or Tesseract
  returned nothing.
- Writes `confirmed_at` and `confirmed_by_id` on the document row.
- Logs a `fleet_vehicle_events` row.

Only then does the vehicle appear on the fleet's list.

### Fleet view

A new `/fleet/vehicles` route behind the existing portal auth
middleware, visible when `auth()->user()->clientAccount->type ===
FleetOperator`. The page lists every confirmed vehicle grouped by
expiry month, with a download link to the latest confirmed licence
file. A "retire" action sets `retired_at`; a retired vehicle is still
listed under a collapsed "Retired" section and still downloadable, but
is excluded from the renewal email.

### Monthly email

A new `SendFleetRenewalReminders` job scheduled from `routes/console.php`
runs daily at 06:00 Africa/Johannesburg. When the current date is the
first of the month, it dispatches one email per fleet.

The email lists every vehicle on that fleet where:

- `retired_at` is null, and
- `licence_expires_on` falls in the current calendar month.

Delivery reuses `NotificationDispatcher::dispatch` with its existing
contact-plus-admins resolver, the `notifications_enabled` system
toggle, the `DB::afterCommit` guard, and the branded mail-message
scaffold. One new `FleetRenewalRemindersForFleet` notification class
drives the content.

Each vehicle row in the email links to
`route('applications.create', ['prefill_fleet_vehicle' => $vehicle->id])`.
`ApplicationForm` reads that query parameter, creates a draft
`Application` with `request_type = licence_renewal`, prefills the
register number, VIN, make, and vehicle category from the fleet record,
and leaves it in `draft` so the fleet still submits it manually.
`DocumentRuleSeeder` is extended so a licence renewal of a commercial
vehicle requires a certificate of fitness, matching the existing rule
for a new commercial registration.

### Withheld licences

A licence whose disc was not issued still carries a `Date of expiry`.
The reviewer confirms that date like any other, and the vehicle lands in
the normal monthly email. The withheld status itself is not surfaced in
the reminder — the reasons live on the stored file and the fleet reads
them when they open the renewal. The commercial renewal already demands
a new certificate of fitness, which closes the loop that caused the
disc to be withheld in the first place.

## Error handling

- **Tesseract fails or finds nothing.** The vehicle stays in the review
  queue with empty candidates. The reviewer types the expiry and
  saves. The file is stored either way.
- **PDF cannot be rendered.** `ocr_status = failed`,
  `ocr_notes` records the exception message truncated to 180 chars, the
  reviewer still sees the file and types the fields.
- **Reviewer saves without an expiry.** Allowed. The vehicle appears on
  the fleet list but never generates a reminder. The fleet can see
  that the expiry is missing and ask for a corrected upload.
- **Duplicate register number inside one fleet.** The confirmation save
  fails with a validation error that names the existing vehicle, so the
  reviewer attaches the new file to that vehicle instead of creating a
  second one.
- **Notification send fails.** Caught and logged by the existing
  `NotificationDispatcher` try/catch so a bad Mailgun config does not
  break the job.
- **Fleet has no email recipients.** `NotificationDispatcher` already
  returns silently if the contact email is blank and no `client_admin`
  users exist. `SendFleetRenewalReminders` logs those fleets to
  `storage/logs` so a licensing-company admin can chase them up.

## Operational dependencies

The app Docker image gains two system packages: `tesseract-ocr` (plus
`tesseract-ocr-eng`) for OCR and `poppler-utils` for `pdftoppm`. These
are added once to `docker/Dockerfile` and inherited by the `app`,
`queue`, and `scheduler` containers that share the image. No cloud
service, no new network egress.

## Testing

- **Feature test** — a fleet admin cannot see another fleet's vehicles.
- **Feature test** — a non-fleet client account sees no `/fleet/vehicles`
  nav item and gets a 403 on the route.
- **Feature test** — uploading a licence creates a vehicle in a
  pending-review state and does not appear on the fleet list.
- **Unit test** — `ReadFleetLicence` with a fake Tesseract runner
  parses `Date of expiry 2021-06-30` into `2021-06-30` and extracts the
  register number and VIN. Also tested with no match (returns
  `unreadable`) and with the two-vehicle Bellville page shape.
- **Feature test** — confirming saves the fields, writes
  `licence_expiry_source`, and now shows the vehicle on the fleet list.
- **Feature test** — `SendFleetRenewalReminders` on the first of the
  month includes only confirmed, non-retired vehicles whose expiry
  falls in the current month. On other days, it sends nothing.
- **Feature test** — the renewal link on a fleet vehicle creates a
  draft `Application` prefilled with the fleet vehicle's details and a
  `licence_renewal` request type, and the application's document
  requirements include a certificate of fitness when the vehicle is
  commercial.
- **Feature test** — a retired vehicle is still listed and downloadable,
  but excluded from the monthly email.

## Non-goals and known trade-offs

- Tesseract on faint black-and-white Konica Minolta scans is only
  moderately accurate. The confirmation screen exists for exactly that
  reason: the reviewer is the source of truth, the OCR is a hint. We
  will not try to improve accuracy by sending files to a cloud OCR.
- The renewal email does not warn about licences already past
  expiry. Vehicles whose expiry is in the past but not in the current
  month are visible on the fleet list with an "overdue" badge, but no
  catch-up email is sent.
- The fleet cannot upload a licence themselves. If this turns out to
  slow fleets down in practice, the same confirmation flow can be
  reopened to fleet admins later without a schema change.
