import { defineConfig } from '@playwright/test';

const nextcloudRoot = process.env.NEXTCLOUD_ROOT;

if (!nextcloudRoot) {
    throw new Error('NEXTCLOUD_ROOT must be configured');
}

export default defineConfig({
    testDir: 'tests/browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',
    timeout: 180_000,
    expect: {
        timeout: 120_000,
    },
    use: {
        baseURL: 'http://127.0.0.1:8080',
        acceptDownloads: true,
        actionTimeout: 30_000,
        navigationTimeout: 60_000,
        trace: 'retain-on-failure',
    },
    webServer: {
        command: `php -S 127.0.0.1:8080 -t ${JSON.stringify(nextcloudRoot)}`,
        url: 'http://127.0.0.1:8080/index.php/login',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
