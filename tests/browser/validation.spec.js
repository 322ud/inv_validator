import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';

const validInvoicePath = process.env.KOSIT_VALID_INVOICE;

if (!validInvoicePath) {
    throw new Error('KOSIT_VALID_INVOICE must be configured');
}

test('validates invoices through the complete Nextcloud UI flow', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/index.php/login');
    await page.locator('#user').fill('admin');
    await page.locator('#password').fill('integration-test-password');
    await page.locator('button[type="submit"]').first().click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);

    await page.goto('/index.php/apps/kosit_validator/');
    await expect(page.getByRole('heading', { name: 'KoSIT Validator' })).toBeVisible();

    const fileInput = page.locator('#kosit-xml-file');
    const status = page.locator('#kosit-status');
    const selectButton = page.locator('#kosit-select-file-button');
    const reportFrame = page.locator('#kosit-report-frame');

    await fileInput.setInputFiles(validInvoicePath);
    await expect(status).toHaveText('Validierung läuft ...');
    await expect(selectButton).toBeDisabled();
    await expect(status).toHaveText('XML ist gültig.');
    await expect(reportFrame).toBeVisible();
    await expect(reportFrame).toHaveAttribute('sandbox', 'allow-same-origin allow-modals');
    await expect(page.frameLocator('#kosit-report-frame').locator('body')).toContainText('123456XX');
    await expect(page.locator('#kosit-print-button')).toBeEnabled();
    await expect(page.locator('#kosit-download-button')).toBeEnabled();

    const downloadPromise = page.waitForEvent('download');
    await page.locator('#kosit-download-button').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('kosit-pruefbericht.html');

    const validInvoice = await readFile(validInvoicePath, 'utf8');
    const invalidInvoice = validInvoice.replace(
        /\s*<cac:AccountingSupplierParty>[\s\S]*?<\/cac:AccountingSupplierParty>/,
        '',
    );
    expect(invalidInvoice).not.toBe(validInvoice);

    await fileInput.setInputFiles({
        name: 'invalid-invoice.xml',
        mimeType: 'application/xml',
        buffer: Buffer.from(invalidInvoice),
    });
    await expect(status).toHaveText('XML ist nicht gültig.');
    await expect(reportFrame).toBeVisible();
    await expect(page.frameLocator('#kosit-report-frame').locator('body')).toContainText('123456XX');

    await fileInput.setInputFiles({
        name: 'malformed.xml',
        mimeType: 'application/xml',
        buffer: Buffer.from('<Invoice>'),
    });
    await expect(status).toHaveText('Ungültiger XML-Inhalt');
    await expect(selectButton).toBeEnabled();
    await expect(pageErrors).toEqual([]);
});
