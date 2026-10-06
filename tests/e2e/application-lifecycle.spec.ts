import { currentStage, expect, test } from './support/fixtures';
import { attachScan, choose, livewire } from './support/livewire';

/**
 * The whole journey of one application through the UI: the dealer
 * captures and submits it, operations reviews it, checks the RLV, prints
 * the pack, lodges it, records the department's return and hands the
 * documents back, and the dealer sees it completed.
 */
test('an application travels from the dealer to completed', async ({ as }) => {
    test.setTimeout(180_000);

    const dealer = await as('dealerAdmin');
    const operations = await as('operations');
    let applicationId = '';

    await test.step('dealer captures the application and uploads every document', async () => {
        await dealer.goto('/applications/create');
        await choose(dealer, dealer.getByLabel('Request'), 'new_registration');
        await choose(dealer, dealer.getByLabel('Province'), 'gauteng');
        await choose(dealer, dealer.getByLabel('Vehicle category'), 'passenger');
        await choose(dealer, dealer.getByLabel('Service'), 'register_and_license');
        await choose(dealer, dealer.getByLabel('Owner type'), 'individual');

        await dealer.getByLabel('VIN', { exact: true }).fill('AAHH1234567890123');
        await dealer.getByLabel('NaTIS Vehicle Number', { exact: true }).fill('TLX900G');
        await dealer.getByLabel('Make', { exact: true }).fill('Toyota');
        await dealer.getByLabel('Model', { exact: true }).fill('Corolla');
        await dealer.getByLabel('Year', { exact: true }).fill('2026');
        await dealer.getByLabel('Name', { exact: true }).fill('Sipho Ndlovu');
        await dealer.getByLabel('ID number', { exact: true }).fill('8001015009087');
        await dealer.getByLabel('Address', { exact: true }).fill('12 Oak Street, Lyttelton, Centurion, 0157');

        await expect(dealer.getByText('Save the draft to build the checklist.')).toBeVisible();
        await dealer.getByRole('button', { name: 'Save draft' }).click();
        await dealer.waitForURL(/\/applications\/\d+\/edit$/);
        applicationId = dealer.url().match(/applications\/(\d+)/)?.[1] ?? '';
        await expect(dealer.getByText('Draft saved.')).toBeVisible();

        const checklist = dealer.locator('aside li').filter({ has: dealer.locator('input[type=file]') });
        await expect(checklist).toHaveCount(3);

        for (let index = 0; index < 3; index++) {
            const item = checklist.nth(index);
            await attachScan(dealer, item.locator('input[type=file]'), `document-${index}`);
            await livewire(dealer, () => item.getByRole('button', { name: 'Upload' }).click());
            await expect(item.getByText('Uploaded')).toBeVisible();
        }

        await dealer.getByRole('button', { name: 'Submit' }).click();
        await dealer.waitForURL(new RegExp(`/applications/${applicationId}$`));
        await expect(dealer.getByRole('heading', { level: 1 })).toHaveText('Toyota Corolla');
    });

    await test.step('operations accepts the documents and bills the account', async () => {
        await operations.goto(`/review/${applicationId}`);
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('Document review');
        await livewire(operations, () => operations.getByRole('button', { name: 'Assign to me' }).click());

        for (let index = 0; index < 3; index++) {
            await livewire(operations, () => operations.getByRole('button', { name: 'Accept', exact: true }).nth(index).click());
        }
        await expect(operations.locator('li').filter({ hasText: 'Accepted' })).toHaveCount(3);

        await livewire(operations, () => operations.getByRole('button', { name: 'Payment pending' }).click());
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('Payment verified');
        await expect(operations.getByText('All documents are accepted and originals are in hand.')).toBeVisible();
        await expect(operations.getByText('The RLV prints with the pack but has not been checked.')).toBeVisible();
    });

    await test.step('operations checks and completes the RLV', async () => {
        await operations.getByRole('link', { name: 'Check the RLV' }).click();
        await operations.waitForURL(/natis-form$/);
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('Check the RLV(5)');
        await expect(operations.getByLabel('Surname / name of organisation', { exact: true }).first()).toHaveValue('Ndlovu');

        await operations.getByLabel('Tare (kg)', { exact: true }).fill('1280');
        await operations.getByLabel('Engine number', { exact: true }).fill('2ZR1234567');
        await livewire(operations, () => operations.getByRole('button', { name: 'Save', exact: true }).click());

        await expect(operations.getByText('Every field the department needs is filled in.')).toBeVisible();
        await expect(operations.getByText(/Checked .* by Operations\./)).toBeVisible();

        await operations.getByRole('link', { name: 'Back to review' }).click();
        await expect(operations.getByText(/Checked .* by Operations\. It prints with the pack\./)).toBeVisible();
    });

    await test.step('operations prints the submission pack', async () => {
        await operations.getByRole('button', { name: 'Prepare and print pack' }).click();
        await operations.waitForURL(/\/review\/packs\/print/);
        await expect(operations.getByText('RLV(5): checked by Operations')).toBeVisible();
        await expect(operations.getByText(/Register no\. TLX900G/)).toBeVisible();
        await expect(operations.getByRole('row', { name: 'Surname / name of organisation Ndlovu' }).first()).toBeVisible();
        await expect(operations.getByRole('row', { name: 'Tare (kg) 1280' })).toBeVisible();
    });

    await test.step('operations records the department lodgement, approval and return', async () => {
        await operations.goto(`/review/${applicationId}`);
        await operations.getByPlaceholder('Department reference').fill('GP-LODGE-0001');
        await livewire(operations, () => operations.getByRole('button', { name: 'Record submission' }).click());
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('At the authority');
        await expect(operations.getByText('ref GP-LODGE-0001')).toBeVisible();

        await livewire(operations, () => operations.getByRole('button', { name: 'Approved', exact: true }).click());
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('Approved');

        await operations.getByPlaceholder('Notes (e.g. what came back)').fill('Registration certificate and licence disc');
        await livewire(operations, () => operations.getByRole('button', { name: 'Record physical receipt' }).click());
        await expect(operations.getByRole('heading', { level: 1 })).toHaveText('Ready for collection');
        await expect(operations.getByText('Receipt recorded. Ready for handover to the customer.')).toBeVisible();
    });

    await test.step('operations hands the documents back to the dealership', async () => {
        await operations.getByRole('link', { name: 'Record hand-over to customer' }).click();
        await operations.waitForURL(/\/handovers\/create/);

        await expect(operations.getByLabel('Direction')).toHaveValue('delivery');
        await expect(operations.getByLabel('Licensing company', { exact: true })).toHaveValue('Example Licensing');
        await expect(operations.getByLabel('Licensing company staff member')).toHaveValue('Operations');
        await expect(operations.locator(`input[type=checkbox][value="${applicationId}"]`)).toBeChecked();

        await operations.getByLabel('Dealership person on the counter').fill('Thandi Mokoena');
        await operations.getByLabel('Items summary (printed on the POD/POC)').fill('1 x registration certificate, 1 x licence disc');
        await operations.getByRole('button', { name: 'Save', exact: true }).click();
        await operations.waitForURL(/\/handovers\/\d+\/edit$/);

        await livewire(operations, () => operations.getByRole('button', { name: /Confirm digitally/ }).click());
        await expect(operations.getByText(/Hand-over confirmed digitally/)).toBeVisible();
    });

    await test.step('the dealer sees the application completed', async () => {
        await dealer.goto(`/applications/${applicationId}`);
        await expect(currentStage(dealer)).toHaveText('Completed');
    });
});
