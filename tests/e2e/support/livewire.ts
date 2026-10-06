import type { Locator, Page, Response } from '@playwright/test';
import { scan } from './files';

function isLivewireUpdate(response: Response): boolean {
    return response.request().method() === 'POST' && /\/livewire[^/]*\/update/.test(response.url());
}

/**
 * Runs an action that triggers a Livewire round trip and waits for the
 * server's answer, so the next step sees the re-rendered component.
 */
export async function livewire(page: Page, action: () => Promise<unknown>): Promise<void> {
    const update = page.waitForResponse(isLivewireUpdate);
    await action();
    await update;
}

/** Picks an option on a `wire:model.live` select and waits for the re-render. */
export async function choose(page: Page, select: Locator, value: string): Promise<void> {
    await livewire(page, () => select.selectOption(value));
}

/**
 * Attaches a scan to a Livewire file input: the browser uploads it to the
 * temporary upload endpoint first, then Livewire sets the property.
 */
export async function attachScan(page: Page, input: Locator, name: string): Promise<void> {
    const uploaded = page.waitForResponse((response) => response.url().includes('/upload-file'));
    await input.setInputFiles(scan(name));
    await uploaded;
    await page.waitForResponse(isLivewireUpdate);
}
