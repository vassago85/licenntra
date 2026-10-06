import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const port = Number(process.env.E2E_PORT ?? 8010);
const baseURL = `http://127.0.0.1:${port}`;
const database = path.resolve(import.meta.dirname, 'database', 'e2e.sqlite');

/**
 * The suite runs against its own PHP server and SQLite database, rebuilt
 * from migrations plus DemoSeeder on every run, so it never touches the
 * local development database. Specs share that database and mutate it,
 * so they run serially in one worker.
 */
const serverEnv = {
    APP_URL: baseURL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
};

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        viewport: { width: 1440, height: 900 },
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
            dependencies: ['setup'],
        },
    ],
    webServer: {
        command: [
            `php -r "touch('database/e2e.sqlite');"`,
            'php artisan migrate:fresh --seed --seeder=DemoSeeder --force --no-interaction',
            'cd public',
            `php -S 127.0.0.1:${port} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        ].join(' && '),
        url: `${baseURL}/login`,
        env: serverEnv,
        reuseExistingServer: false,
        timeout: 180_000,
        stdout: 'ignore',
        stderr: 'ignore',
    },
});
