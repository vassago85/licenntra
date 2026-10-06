import { expect, signedInAs, test } from './support/fixtures';
import type { Role } from './support/users';

interface PageCheck {
    path: string;
    heading: string | RegExp;
}

/** Every screen each persona reaches from the sidebar, with the heading it must show. */
const reachable: Record<Role, PageCheck[]> = {
    dealerAdmin: [
        { path: '/applications', heading: /Applications|Dashboard/ },
        { path: '/applications/create', heading: 'New application' },
        { path: '/business-clients', heading: 'Business clients' },
        { path: '/business-clients/create', heading: /business client/i },
        { path: '/estimate', heading: /estimate/i },
        { path: '/handovers', heading: 'Hand-overs' },
        { path: '/handovers/create?direction=collection', heading: 'New hand-over' },
        { path: '/invoices', heading: /Invoices/ },
        { path: '/team', heading: /Team/ },
        { path: '/dealership', heading: /Dealership details/ },
        { path: '/account', heading: /Account/ },
    ],
    dealerUser: [
        { path: '/applications', heading: /Applications|Dashboard/ },
        { path: '/applications/create', heading: 'New application' },
        { path: '/business-clients', heading: 'Business clients' },
        { path: '/estimate', heading: /estimate/i },
        { path: '/handovers', heading: 'Hand-overs' },
        { path: '/invoices', heading: /Invoices/ },
        { path: '/account', heading: /Account/ },
    ],
    operations: [
        { path: '/admin', heading: /Overview/ },
        { path: '/review', heading: /Review/ },
        { path: '/tasks/outstanding', heading: /Outstanding tasks/ },
        { path: '/dealerships/board', heading: /Dealership/ },
        { path: '/fleet-vehicles/review', heading: /Fleet/ },
        { path: '/handovers', heading: 'Hand-overs' },
        { path: '/handovers/create?direction=delivery', heading: 'New hand-over' },
        { path: '/account', heading: /Account/ },
    ],
    finance: [
        { path: '/admin', heading: /Overview/ },
        { path: '/review', heading: /Review/ },
        { path: '/finance/invoices', heading: /Invoices/ },
        { path: '/account', heading: /Account/ },
    ],
    owner: [
        { path: '/admin', heading: /Overview/ },
        { path: '/review', heading: /Review/ },
        { path: '/tasks/outstanding', heading: /Outstanding tasks/ },
        { path: '/dealerships/board', heading: /Dealership/ },
        { path: '/finance/invoices', heading: /Invoices/ },
        { path: '/fleet-vehicles/review', heading: /Fleet/ },
        { path: '/handovers', heading: 'Hand-overs' },
        { path: '/admin/users', heading: /Users/ },
        { path: '/admin/client-accounts', heading: /Client accounts/ },
        { path: '/admin/fee-table-versions', heading: /Fee/ },
        { path: '/admin/fee-tables', heading: /Fee/ },
        { path: '/admin/fee-lines', heading: /Fee/ },
        { path: '/admin/document-rules', heading: /Document/ },
        { path: '/admin/document-types', heading: /Document/ },
        { path: '/settings/branding', heading: /Branding/ },
        { path: '/settings/system', heading: /System settings/ },
        { path: '/audit', heading: /Audit/ },
        { path: '/platform/billing', heading: /billing/i },
        { path: '/account', heading: /Account/ },
    ],
};

/** Screens each persona must be refused, straight from the address bar. */
const forbidden: Record<Role, string[]> = {
    dealerAdmin: ['/admin', '/review', '/admin/users', '/settings/branding', '/audit', '/finance/invoices', '/tasks/outstanding'],
    dealerUser: ['/admin', '/review', '/team', '/dealership', '/admin/users', '/audit'],
    operations: ['/admin/users', '/settings/branding', '/settings/system', '/audit', '/platform/billing', '/finance/invoices', '/dealership'],
    finance: ['/admin/users', '/settings/branding', '/audit', '/platform/billing', '/dealership'],
    owner: ['/dealership'],
};

for (const role of Object.keys(reachable) as Role[]) {
    test.describe(`${role} navigation`, () => {
        signedInAs(role);

        for (const check of reachable[role]) {
            test(`opens ${check.path}`, async ({ page }) => {
                const response = await page.goto(check.path);

                expect(response?.status()).toBeLessThan(400);
                await expect(page).not.toHaveURL(/\/login/);
                await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(check.heading);
            });
        }

        for (const path of forbidden[role]) {
            test(`is refused ${path}`, async ({ page }) => {
                const response = await page.goto(path);

                expect(response?.status()).toBe(403);
            });
        }
    });
}
