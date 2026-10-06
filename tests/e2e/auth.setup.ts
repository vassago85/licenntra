import { test as setup } from '@playwright/test';
import { signIn } from './support/fixtures';
import { type Role, storageStatePath, users } from './support/users';

/**
 * Signs each persona in once and saves the session so specs don't hit
 * Fortify's login throttle by signing in before every test.
 */
for (const role of Object.keys(users) as Role[]) {
    setup(`sign in as ${role}`, async ({ page }) => {
        await signIn(page, role);
        await page.context().storageState({ path: storageStatePath(role) });
    });
}
