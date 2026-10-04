# Open questions

These are decisions the domain model already records as assumptions, plus operational choices the portal makes until a licensing company says otherwise.

## Fees and tax

- VAT is stored as basis points. 1500 means 15.00%.
- A client account markup is basis points on the fee subtotal. 150 means 1.50%. The markup line is kept off the client-facing estimate.
- The fee snapshot is taken when an application moves to payment pending, and again when a reviewer changes the service type after submission. Later edits to the fee table do not rewrite a stored snapshot.

## Time

- Until a licensing company sets its own stage hours, each open stage is due in 48 hours.
- A stage is at risk once three quarters of that window has passed, and in breach once the due time has passed.
- The browser session goes idle after 30 minutes. The absolute sign-in lifetime defaults to 8 hours (480 minutes) and can be changed in system settings.

## Documents and scanning

- A proof of address older than 90 days is treated as stale by the document type rule.
- Uploads are limited to PDF, JPG, and PNG, checked with the file contents, at 15 MB.
- When `CLAMAV_ENABLED` is false, the scan job marks the file clean and the document awaiting review. Turn ClamAV on only after its signature database is current.
- Identity-document downloads require the `documents.identity.download` permission. Uploading an identity document does not by itself allow it to be downloaded.
- Quote expiry marks the quote expired. It does not cancel the application.

## Retention

- Retention options default to 3, 6, 12, and 24 months, with a maximum of 24.
- A daily job emails the account contact and the branding support address when a business client expires within 30 days. There is no separate "already notified" flag, so the mail repeats each day until the date passes or the consent is extended.
- Legal hold blocks secure deletion. Expired business clients without a hold are deleted, and their stored files are overwritten first.

## Logging

- Log messages redact any standalone 13-digit number, which is the shape of a South African identity number. That can also hide other 13-digit values that are not identity numbers.

## Deployment shape

- One deployment serves one licensing company. Branding, fee tables, and users live in that deployment's database. There is no switcher for a second company in the same app.
