// Drives one Proving Ground journey through a real browser.
//
// Reads the journey as JSON on stdin and writes one JSON line per event on
// stdout, which the PHP side reads as they arrive so the page can follow the
// run step by step. Every step ends with a screenshot, pass or fail.
//
//   { base_url, shots_dir, step_timeout_ms, viewport, steps: [{ do, label, ... }] }
//
// Step kinds:
//   visit  { path }                   open a page
//   fill   { target, value }          type into a field, then leave it (wire:model.blur)
//   select { target, value }          pick an option by value
//   click  { target, confirm? }       click; confirm: true accepts the browser confirm() it opens
//   see    { text }                   wait until the text is on the page
//   gone   { target }                 wait until the element has left the page
//   press  { key }                    press a key on the focused element

import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const emit = (event) => process.stdout.write(JSON.stringify(event) + '\n');

const journey = JSON.parse(readFileSync(0, 'utf8'));
const timeout = journey.step_timeout_ms ?? 15000;

// Requests in flight, counted from the browser's side, so a step can wait
// for the Livewire round trip (and any navigation it triggers) to finish
// rather than guessing with a sleep. Settled means nothing in flight for a
// short quiet window; long-lived streams would never settle, so the wait is
// capped by the step timeout and carries on.
const inFlight = new Set();
let lastActivity = Date.now();
const QUIET_MS = 250;

const settle = async (page) => {
    await page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {});
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (inFlight.size === 0 && Date.now() - lastActivity >= QUIET_MS) {
            return;
        }
        await page.waitForTimeout(50);
    }
};

const browser = await chromium.launch({ headless: true });
let status = 'passed';

try {
    const context = await browser.newContext({
        baseURL: journey.base_url,
        viewport: journey.viewport ?? { width: 1280, height: 800 },
        ignoreHTTPSErrors: true,
    });
    context.setDefaultTimeout(timeout);
    const page = await context.newPage();

    page.on('request', (request) => {
        // A redirect arrives as a new request; the hop before it is done.
        const previous = request.redirectedFrom();
        if (previous) {
            inFlight.delete(previous);
        }
        inFlight.add(request);
        lastActivity = Date.now();
    });
    const done = (request) => {
        inFlight.delete(request);
        lastActivity = Date.now();
    };
    page.on('requestfinished', done);
    page.on('requestfailed', done);
    let position = 0;

    page.on('console', (msg) => {
        if (msg.type() === 'error' || msg.type() === 'warning') {
            emit({ event: 'console', position, level: msg.type(), text: msg.text().slice(0, 2000) });
        }
    });
    page.on('pageerror', (error) => emit({ event: 'console', position, level: 'exception', text: String(error).slice(0, 2000) }));
    page.on('response', (response) => {
        if (response.status() >= 500) {
            emit({ event: 'console', position, level: 'http', text: `${response.status()} ${response.request().method()} ${response.url()}` });
        }
    });

    emit({ event: 'started' });

    for (const [index, step] of journey.steps.entries()) {
        position = index;
        emit({ event: 'step', position, status: 'running' });
        const started = Date.now();
        // The quiet window starts now, so a click whose request has not left
        // yet is not mistaken for a page at rest.
        lastActivity = started;
        let error = null;

        try {
            switch (step.do) {
                case 'visit':
                    await page.goto(step.path);
                    break;
                case 'fill': {
                    const field = page.locator(step.target).first();
                    await field.fill(String(step.value ?? ''));
                    await field.blur();
                    break;
                }
                case 'select':
                    await page.locator(step.target).first().selectOption(String(step.value));
                    break;
                case 'click':
                    if (step.confirm) {
                        page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
                    }
                    await page.locator(step.target).first().click();
                    break;
                case 'see':
                    await page.getByText(step.text, { exact: false }).filter({ visible: true }).first().waitFor({ state: 'visible' });
                    break;
                case 'gone':
                    await page.locator(step.target).first().waitFor({ state: 'detached' });
                    break;
                case 'press':
                    await page.keyboard.press(step.key);
                    break;
                default:
                    throw new Error(`Unknown step kind "${step.do}"`);
            }
            await settle(page);
        } catch (e) {
            error = String(e?.message ?? e).split('\n').slice(0, 6).join('\n');
        }

        const ms = Date.now() - started;
        const shot = `${String(index).padStart(3, '0')}.jpg`;
        const shotTaken = await page
            .screenshot({ path: join(journey.shots_dir, shot), type: 'jpeg', quality: 60 })
            .then(() => true, () => false);

        emit({
            event: 'step',
            position,
            status: error ? 'failed' : 'passed',
            ms,
            url: page.url(),
            screenshot: shotTaken ? shot : null,
            error,
        });

        if (error) {
            status = 'failed';
            break;
        }
    }
} catch (e) {
    status = 'failed';
    emit({ event: 'error', text: String(e?.message ?? e).slice(0, 2000) });
} finally {
    await browser.close().catch(() => {});
    emit({ event: 'finished', status });
}
