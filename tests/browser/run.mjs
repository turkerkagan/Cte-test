import { chromium } from '@playwright/test';
import { mkdtemp, mkdir, writeFile, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';
import assert from 'node:assert/strict';

const directory = await mkdtemp(join(tmpdir(), 'cte-browser-'));
const listener = createServer();
await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
const { port } = listener.address();
await new Promise((resolve) => listener.close(resolve));
const url = `http://127.0.0.1:${port}`;
const env = { ...process.env, CTE_BROWSER_DIRECTORY: directory };
let server;
let browser;
try {
    for (const part of [
        'framework/views',
        'framework/sessions',
        'framework/cache/data',
        'logs',
        'app/private',
    ])
        await mkdir(join(directory, 'storage', part), { recursive: true });
    await writeFile(
        join(directory, 'settings.json'),
        JSON.stringify({ url, key: `base64:${randomBytes(32).toString('base64')}` }),
        { mode: 0o600 },
    );
    await writeFile(join(directory, 'database.sqlite'), '');
    const setup = spawnSync(process.env.PHP_BINARY ?? 'php', ['tests/browser/setup.php'], {
        env,
        encoding: 'utf8',
    });
    if (setup.status !== 0) throw new Error(setup.stderr || setup.stdout || 'Browser test setup failed.');
    server = spawn(
        process.env.PHP_BINARY ?? 'php',
        ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/browser/server.php'],
        { env, stdio: ['ignore', 'ignore', 'pipe'] },
    );
    let log = '';
    server.stderr.on('data', (data) => {
        log = (log + data).slice(-10000);
    });
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            const response = await fetch(`${url}/giris`);
            if (response.ok) break;
        } catch {}
        if (attempt === 99) {
            const details = await readFile(join(directory, 'storage/logs/laravel.log'), 'utf8').catch(
                () => log,
            );
            throw new Error(`Browser test server did not start. ${details.slice(-10000)}`);
        }
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    let animationRequests = 0;
    page.on('request', (request) => {
        if (request.url().includes('login-scene-')) animationRequests++;
    });
    await page.goto(`${url}/giris`);
    await page.getByLabel('Kullanıcı Adı / Sicil No').fill('synthetic-browser');
    await page.getByLabel('Şifre', { exact: true }).fill('SyntheticMaintenance!2026');
    await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith('/giris')),
        page.getByRole('button', { name: 'Giriş Yap', exact: true }).click(),
    ]);
    await page.getByTitle('Menüyü Aç/Kapat', { exact: true }).click();
    const workspaceLink = page.getByRole('link', { name: 'Çalışma merkezi', exact: true });
    if (!(await workspaceLink.isVisible()))
        await page.locator('summary').filter({ hasText: 'Kullanıcı İşlemleri' }).click();
    await workspaceLink.click();
    await page.waitForURL('**/calisma-merkezi');
    await page.getByRole('heading', { name: 'Vardiya devir özeti', exact: true }).first().waitFor();
    assert.equal(await page.getByText('Sayfa açılamadı', { exact: true }).count(), 0);
    assert.equal(animationRequests, 0, 'Reduced-motion login must not download the animation.');
    await page.evaluate(async () => {
        const cookie = document.cookie
            .split(';')
            .map((value) => value.trim())
            .find((value) => value.startsWith('XSRF-TOKEN='));
        const response = await fetch('/yonetim/telefon', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice(11) ?? ''),
            },
            body: JSON.stringify({ values: { name: 'SENTETİK TARAYICI', phone: '00123', position: 0 } }),
        });
        if (!response.ok) throw new Error('Synthetic phone creation failed.');
    });
    await page.goto(`${url}/yonetim/telefon`);
    await page.getByText('SENTETİK TARAYICI', { exact: true }).click();
    await page.getByRole('button', { name: 'Arşivle', exact: true }).click();
    await page.getByText('SENTETİK TARAYICI', { exact: true }).waitFor({ state: 'hidden' });
    await page.getByRole('link', { name: 'Arşiv', exact: true }).click();
    await page.getByText('SENTETİK TARAYICI', { exact: true }).click();
    await page.getByRole('button', { name: 'Geri al', exact: true }).click();
    await page.getByText('SENTETİK TARAYICI', { exact: true }).waitFor({ state: 'hidden' });
    await page.getByRole('link', { name: 'Aktif kayıtlar', exact: true }).click();
    await page.getByText('SENTETİK TARAYICI', { exact: true }).waitFor();
    await page.screenshot({ path: join(tmpdir(), 'portal-next-maintenance-phone.png'), fullPage: true });
    await page.route('**/calisma-merkezi?synthetic=missing', async (route) => {
        const response = await route.fetch();
        const html = (await response.text()).replace(
            '&quot;component&quot;:&quot;Workspace&quot;',
            '&quot;component&quot;:&quot;SyntheticMissingPage&quot;',
        );
        assert.ok(html.includes('SyntheticMissingPage'), 'Test must replace the real Inertia page name.');
        await route.fulfill({ response, body: html });
    });
    await page.goto(`${url}/calisma-merkezi?synthetic=missing`);
    await page.getByRole('heading', { name: 'Sayfa açılamadı' }).waitFor();
    await page.getByRole('link', { name: 'Ana sayfaya dön' }).waitFor();
    let failedChunkRequests = 0;
    await page.route('**/Workspace-*.js', (route) => {
        failedChunkRequests++;
        return route.abort('failed');
    });
    await page.goto(`${url}/calisma-merkezi?synthetic=chunk-failure`);
    await page.getByRole('heading', { name: 'Sayfa açılamadı' }).waitFor();
    assert.ok(failedChunkRequests > 0, 'Test must fail the actual page chunk download.');
    await page.unroute('**/Workspace-*.js');
    await Promise.all([
        page.waitForNavigation(),
        page.getByRole('button', { name: 'Yeniden dene', exact: true }).click(),
    ]);
    await page.getByRole('heading', { name: 'Vardiya devir özeti', exact: true }).first().waitFor();
    const mobile = await browser.newPage({
        viewport: { width: 390, height: 844 },
        reducedMotion: 'no-preference',
    });
    let mobileAnimation = 0;
    mobile.on('request', (request) => {
        if (request.url().includes('login-scene-')) mobileAnimation++;
    });
    await mobile.goto(`${url}/giris`);
    await mobile.getByRole('button', { name: 'Giriş Yap', exact: true }).waitFor();
    assert.equal(mobileAnimation, 0, 'Mobile login must not download the animation.');
    assert.deepEqual(errors, []);
    console.log(
        'BROWSER_OK: real menu Workspace, phone archive/restore, unknown page, failed chunk retry, reduced motion and mobile login.',
    );
} finally {
    await browser?.close();
    if (server && server.exitCode === null && server.signalCode === null) {
        const ended = new Promise((resolve) => server.once('exit', resolve));
        server.kill();
        await ended;
    }
    await rm(directory, { recursive: true, force: true });
}
