import { test as base, expect, type Page } from '@playwright/test';
import { password, storageStatePath, type Role, users } from './users';

interface Problems {
    serverErrors: string[];
    pageErrors: string[];
}

interface Fixtures {
    problems: Problems;
    /** Opens a page signed in as a persona, in its own browser context. */
    as: (role: Role) => Promise<Page>;
}

/**
 * Accepts `wire:confirm` prompts and records server 5xx answers and
 * uncaught JavaScript errors so the test fails on them.
 */
function watch(page: Page, problems: Problems): void {
    page.on('dialog', (dialog) => dialog.accept());
    page.on('response', (response) => {
        if (response.status() >= 500) {
            problems.serverErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
        }
    });
    page.on('pageerror', (error) => {
        problems.pageErrors.push(error.message);
    });
}

/**
 * Every test fails if the server answers any request with a 5xx or a page
 * throws an uncaught JavaScript error, even when the visible assertions pass.
 */
export const test = base.extend<Fixtures>({
    problems: [
        async ({ page }, use) => {
            const problems: Problems = { serverErrors: [], pageErrors: [] };
            watch(page, problems);

            await use(problems);

            expect(problems.serverErrors, 'server errors during the test').toEqual([]);
            expect(problems.pageErrors, 'uncaught page errors during the test').toEqual([]);
        },
        { auto: true },
    ],
    as: async ({ browser, baseURL, viewport, problems }, use) => {
        const contexts: Awaited<ReturnType<typeof browser.newContext>>[] = [];

        await use(async (role) => {
            const context = await browser.newContext({ baseURL, viewport, storageState: storageStatePath(role) });
            contexts.push(context);
            const page = await context.newPage();
            watch(page, problems);

            return page;
        });

        for (const context of contexts) {
            await context.close();
        }
    },
});

export { expect };

/** Use inside a describe block to run its tests signed in as a demo persona. */
export function signedInAs(role: Role): void {
    test.use({ storageState: storageStatePath(role) });
}

export async function signIn(page: Page, role: Role): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(users[role].email);
    await page.locator('#login-password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/\/login/);
}

/** The highlighted step in the stage tracker on the client's application page. */
export function currentStage(page: Page) {
    return page.locator('ol > li.font-semibold');
}
