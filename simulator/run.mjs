// Drives one Proving Ground journey through a real browser.
//
// Reads the journey as JSON on stdin and writes one JSON line per event on
// stdout, which the PHP side reads as they arrive so the page can follow the
// run step by step. Every step ends with a screenshot, pass or fail.
//
//   { base_url, base_urls?, mail_logs?, shots_dir, step_timeout_ms, viewport, steps: [{ do, label, on?, ... }] }
//
// A journey across apps names each app's address in base_urls, and a step's
// `on` says which app it belongs to: a visit opens its path there. Steps
// without `on` use base_url, as every single-app journey does.
//
// Step kinds:
//   visit  { path }                   open a page
//   fill   { target, value }          type into a field, then leave it (wire:model.blur)
//   select { target, value | option } pick an option by value, or by its visible text
//   click  { target, confirm? }       click; confirm: true accepts the browser confirm() it opens
//   see    { text, reload_for_ms? }   wait until the text is on the page; with
//                                     reload_for_ms, reload until it shows (what
//                                     a webhook writes after the page loaded)
//   gone   { target }                 wait until the element has left the page
//   filled { target }                 wait until a field holds a value the page
//                                     put there (a challenge widget's token)
//   press  { key }                    press a key on the focused element
//   remember { target, as }           keep the element's text (or a field's
//                                     value) as {as} for later steps
//   mail   { to, pattern, as, within_ms }
//                                     keep the first match of pattern in the newest
//                                     email the step's app logged to `to` (its
//                                     mail_logs folder) as {as}
//   stripe_checkout { host, number, expiry, cvc, name, email, postal_code, within_ms }
//                                     pay on the hosted checkout page the browser
//                                     was sent to, and wait to be sent back
//
// Any string in a step may use {name} for something remembered earlier, so a
// journey can pick an existing record ("the first lead that needs a touch")
// and keep working on it.

import { chromium } from 'playwright';
import { closeSync, openSync, readFileSync, readSync, readdirSync, statSync } from 'node:fs';
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

// Livewire round trips started by the current step: how many, and the
// slowest, so the PHP side can judge whether waiting was noticeable.
const LIVEWIRE_UPDATE = /\/livewire[^/]*\/update(\?|$)/;
let livewire = { requests: 0, slowest_ms: 0 };
const livewireStarted = new Map();

// Counts visible loading feedback while a request is in flight:
// - any change to an element carrying a wire:loading directive (Livewire
//   shows, hides, disables or restyles it);
// - Flux's own loading state: data-flux-loading, or data-loading on a button
//   that carries Flux's spinner;
// - Livewire disabling a form's submit button during wire:submit, which is
//   how Flux shows its submit spinner and dims a plain button.
// Livewire's bare data-loading marker on any other element is not counted —
// it changes nothing a person can see unless the app styles it.
const watchLoadingFeedback = () => {
    window.__pgFeedback = 0;
    const hasLoadingDirective = (el) =>
        el.nodeType === 1 && Array.from(el.attributes).some((a) => a.name.startsWith('wire:loading'));
    const visible = (record) => {
        const el = record.target;
        if (el.nodeType !== 1) {
            return false;
        }
        if (hasLoadingDirective(el)) {
            return true;
        }
        if (record.attributeName === 'data-flux-loading') {
            return true;
        }
        if (record.attributeName === 'data-loading' && el.querySelector('[data-flux-loading-indicator]')) {
            return true;
        }
        return record.attributeName === 'disabled' && el.matches('button[type=submit], input[type=submit]') && el.closest('form') !== null;
    };
    new MutationObserver((records) => {
        if (records.some(visible)) {
            window.__pgFeedback++;
        }
    }).observe(document, { attributes: true, subtree: true });
};

// The feedback count, which page it belongs to (a full page load starts a new
// count), and whether the page delays any of its loading states — a delayed
// state never shows for a request faster than the delay, which is not a
// missing one.
const feedbackState = (page) =>
    page
        .evaluate(() => ({
            count: window.__pgFeedback ?? 0,
            origin: performance.timeOrigin,
            delayed: Array.from(document.querySelectorAll('*')).some((el) =>
                Array.from(el.attributes).some((a) => a.name.startsWith('wire:loading') && a.name.includes('.delay')),
            ),
        }))
        .catch(() => null);

const LOADING_DELAY_MS = 250;

// What the current step reached, in the scanner's own terms: pages loaded
// ("GET /leads/281"), Livewire actions called ("leads.show@logActivity") and
// Livewire components on screen ("leads.show"). Mapped onto the app's
// surfaces on the PHP side.
let reached = { pages: new Set(), calls: new Set(), mounts: new Set() };

const pageOf = (request) => {
    const url = new URL(request.url());
    return `${request.method()} ${url.pathname}`;
};

const livewireCalls = (request) => {
    try {
        const body = JSON.parse(request.postData() ?? '{}');
        return (body.components ?? []).flatMap((component) => {
            const name = JSON.parse(component.snapshot ?? '{}')?.memo?.name;
            return name ? (component.calls ?? []).map((call) => `${name}@${call.method}`) : [];
        });
    } catch {
        return [];
    }
};

const mountedComponents = (page) =>
    page
        .evaluate(() =>
            Array.from(document.querySelectorAll('[wire\\:snapshot]'))
                .map((el) => {
                    try {
                        return JSON.parse(el.getAttribute('wire:snapshot'))?.memo?.name ?? null;
                    } catch {
                        return null;
                    }
                })
                .filter(Boolean),
        )
        .catch(() => []);

// A path on the app the step belongs to; an absolute URL is left alone.
const addressOf = (step) => {
    const base = step.on !== undefined ? journey.base_urls?.[step.on] : undefined;
    if (step.on !== undefined && base === undefined) {
        throw new Error(`No address for the app "${step.on}"`);
    }
    return base === undefined ? step.path : new URL(step.path, base).href;
};

// The newest email an app logged to `to`, searched for `pattern`. A stand-in
// keeps its mail in its log (the guard insists), one log entry per message,
// so the entries naming the address are read newest first. Only the tail of
// each file is read: a practice app's log grows for as long as it lives.
const MAIL_TAIL_BYTES = 8 * 1024 * 1024;
const LOG_ENTRY = /\n(?=\[\d{4}-\d\d-\d\d[ T][^\]]*\] )/;

const tailOf = (file) => {
    const size = statSync(file).size;
    const length = Math.min(size, MAIL_TAIL_BYTES);
    const buffer = Buffer.alloc(length);
    const fd = openSync(file, 'r');
    try {
        readSync(fd, buffer, 0, length, size - length);
    } finally {
        closeSync(fd);
    }
    return buffer.toString('utf8');
};

const findMail = (dir, to, pattern) => {
    const files = readdirSync(dir)
        .filter((name) => name.endsWith('.log'))
        .map((name) => join(dir, name))
        .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
    const addressed = new RegExp(`^To:.*${to.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'im');
    for (const file of files) {
        const entries = tailOf(file).split(LOG_ENTRY).reverse();
        for (const entry of entries) {
            if (! addressed.test(entry)) {
                continue;
            }
            const match = entry.match(pattern);
            if (match) {
                return match[0].replace(/&amp;/g, '&');
            }
        }
    }
    return null;
};

// Text that something outside the browser puts on the page: look, and if it
// is not there yet, reload and look again until the time is up.
const seeReloading = async (page, text, within) => {
    const deadline = Date.now() + within;
    for (;;) {
        const seen = await page
            .getByText(text, { exact: false })
            .filter({ visible: true })
            .first()
            .waitFor({ state: 'visible', timeout: Math.max(500, Math.min(3000, deadline - Date.now())) })
            .then(() => true, () => false);
        if (seen) {
            return;
        }
        if (Date.now() >= deadline) {
            throw new Error(`"${text}" was not on the page after ${Math.round(within / 1000)} s of reloading`);
        }
        await page.reload();
    }
};

// A hosted Stripe Checkout page, paid with a test card. Stripe varies the
// page by account and country — an email field only when the session names
// no customer, a payment-method accordion when more than cards are on, a
// postal code where the country has one, Link's "save my info" — so each
// part is filled only when it is there. Then wait to leave Stripe: where it
// sends the payer back to is the app's own success page.
const payByCard = async (page, step) => {
    const within = step.within_ms ?? 90000;
    const onCheckout = (url) => url.hostname === step.host;
    const visible = (selector) => page.locator(selector).first().isVisible().catch(() => false);

    await page.waitForURL(onCheckout, { timeout: within });
    // Ready when any way in shows: the card form, an email to fill, or the
    // Card row's label. A subscription page shows the email as read-only
    // text and keeps the row's button hidden under its label, so neither of
    // the first three is ever visible there.
    await page.locator('#cardNumber, #email, [data-testid="card-accordion-item-button"], #payment-method-label-card').first().waitFor({ state: 'visible', timeout: within });

    if (await visible('#email')) {
        const email = page.locator('#email').first();
        if ((await email.inputValue().catch(() => '')) === '') {
            await email.fill(String(step.email));
        }
    }
    // Stripe lists payment methods as an accordion (Card, Klarna, Cash App,
    // Bank). Its radio dot is a decorative, aria-hidden span that a click
    // never lands on, so the row is chosen by what a person reads: its label.
    // Tried in turn, because Stripe changes the markup without notice.
    if (! (await visible('#cardNumber'))) {
        const choices = [
            page.locator('[data-testid="card-accordion-item-button"]'),
            page.getByRole('radio', { name: /^card$/i }),
            page.getByRole('button', { name: /^(pay with )?card$/i }),
            page.getByText('Card', { exact: true }),
        ];
        // Stripe lays a full-row AccordionButton over the label, so a click
        // on the label is refused as intercepted. A person's click lands on
        // that button; `force` clicks where the label is, and the mouse event
        // reaches the button on top exactly as theirs would.
        for (const choice of choices) {
            if (await choice.first().isVisible().catch(() => false)) {
                await choice.first().click({ force: true });
                break;
            }
        }
    }
    await page.locator('#cardNumber').first().waitFor({ state: 'visible', timeout: within });
    await page.locator('#cardNumber').first().fill(String(step.number));
    await page.locator('#cardExpiry').first().fill(String(step.expiry));
    await page.locator('#cardCvc').first().fill(String(step.cvc));
    if (await visible('#billingName')) {
        await page.locator('#billingName').first().fill(String(step.name));
    }
    if (await visible('#billingPostalCode')) {
        await page.locator('#billingPostalCode').first().fill(String(step.postal_code));
    }
    // Never sign the prospect up to Link: it would ask for a phone number.
    const link = page.locator('#enableStripePass').first();
    if ((await link.isVisible().catch(() => false)) && (await link.isChecked().catch(() => false))) {
        await link.uncheck();
    }
    await page.locator('[data-testid="hosted-payment-submit-button"], button.SubmitButton, button[type="submit"]').first().click();
    await page.waitForURL((url) => ! onCheckout(url), { timeout: within });
};

const remembered = {};
const fill = (value) =>
    typeof value === 'string' ? value.replace(/\{([a-z_]+)\}/g, (match, name) => remembered[name] ?? match) : value;

const browser = await chromium.launch({ headless: true });
let status = 'passed';

try {
    const context = await browser.newContext({
        baseURL: journey.base_url,
        viewport: journey.viewport ?? { width: 1280, height: 800 },
        ignoreHTTPSErrors: true,
    });
    context.setDefaultTimeout(timeout);
    await context.addInitScript(watchLoadingFeedback);
    const page = await context.newPage();

    page.on('request', (request) => {
        // A redirect arrives as a new request; the hop before it is done.
        const previous = request.redirectedFrom();
        if (previous) {
            inFlight.delete(previous);
        }
        // blob: and data: never touch the network, and Chromium never
        // reports one finished: Turnstile's challenge script is a blob, so
        // the first journey sat out the full step timeout on every step
        // after the website — 15 s each, across apps — and ran out of time.
        if (! /^https?:/.test(request.url())) {
            return;
        }
        inFlight.add(request);
        lastActivity = Date.now();
        if (LIVEWIRE_UPDATE.test(request.url())) {
            livewire.requests++;
            livewireStarted.set(request, Date.now());
            livewireCalls(request).forEach((call) => reached.calls.add(call));
        } else if (request.isNavigationRequest() || request.headers()['x-livewire-navigate'] !== undefined) {
            // A full page load, or wire:navigate fetching the next page.
            if (request.frame() === page.mainFrame() || request.headers()['x-livewire-navigate'] !== undefined) {
                reached.pages.add(pageOf(request));
            }
        }
    });
    const done = (request) => {
        inFlight.delete(request);
        lastActivity = Date.now();
        if (livewireStarted.has(request)) {
            livewire.slowest_ms = Math.max(livewire.slowest_ms, Date.now() - livewireStarted.get(request));
            livewireStarted.delete(request);
        }
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

    for (const [index, rawStep] of journey.steps.entries()) {
        const step = Object.fromEntries(Object.entries(rawStep).map(([key, value]) => [key, fill(value)]));
        position = index;
        emit({ event: 'step', position, status: 'running' });
        const started = Date.now();
        // The quiet window starts now, so a click whose request has not left
        // yet is not mistaken for a page at rest.
        lastActivity = started;
        livewire = { requests: 0, slowest_ms: 0 };
        reached = { pages: new Set(), calls: new Set(), mounts: new Set() };
        const feedbackBefore = step.do === 'click' ? await feedbackState(page) : null;
        let error = null;

        try {
            switch (step.do) {
                case 'visit':
                    await page.goto(addressOf(step));
                    break;
                case 'fill': {
                    const field = page.locator(step.target).first();
                    await field.fill(String(step.value ?? ''));
                    await field.blur();
                    break;
                }
                case 'select':
                    await page
                        .locator(step.target)
                        .first()
                        .selectOption(step.option !== undefined ? { label: String(step.option) } : String(step.value));
                    break;
                case 'remember': {
                    const element = page.locator(step.target).first();
                    const isField = await element.evaluate((el) => ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
                    const text = (isField ? await element.inputValue() : await element.innerText()).trim().replace(/\s+/g, ' ');
                    if (text === '') {
                        throw new Error(`Nothing to remember in ${step.target}`);
                    }
                    remembered[step.as] = text.slice(0, 200);
                    emit({ event: 'remember', position, name: step.as, value: remembered[step.as] });
                    break;
                }
                case 'click':
                    if (step.confirm) {
                        page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
                    }
                    await page.locator(step.target).first().click();
                    break;
                case 'see':
                    if (step.reload_for_ms) {
                        await seeReloading(page, step.text, step.reload_for_ms);
                        break;
                    }
                    await page.getByText(step.text, { exact: false }).filter({ visible: true }).first().waitFor({ state: 'visible' });
                    break;
                case 'mail': {
                    const dir = journey.mail_logs?.[step.on];
                    if (dir === undefined) {
                        throw new Error(`No mail log for the app "${step.on}"`);
                    }
                    const pattern = new RegExp(step.pattern);
                    const deadline = Date.now() + (step.within_ms ?? timeout);
                    let found = null;
                    for (;;) {
                        found = findMail(dir, String(step.to), pattern);
                        if (found !== null || Date.now() >= deadline) {
                            break;
                        }
                        await page.waitForTimeout(500);
                    }
                    if (found === null) {
                        throw new Error(`No email to ${step.to} matching /${step.pattern}/ in ${dir}`);
                    }
                    remembered[step.as] = found.slice(0, 2000);
                    emit({ event: 'remember', position, name: step.as, value: remembered[step.as] });
                    break;
                }
                case 'stripe_checkout':
                    await payByCard(page, step);
                    break;
                case 'gone':
                    await page.locator(step.target).first().waitFor({ state: 'detached' });
                    break;
                case 'filled': {
                    // Hidden fields count: a widget's token usually lives in one.
                    const field = page.locator(step.target).first();
                    const deadline = Date.now() + timeout;
                    for (;;) {
                        const value = await field.inputValue({ timeout: 500 }).catch(() => '');
                        if (value !== '') {
                            break;
                        }
                        if (Date.now() >= deadline) {
                            throw new Error(`${step.target} never got a value`);
                        }
                        await page.waitForTimeout(100);
                    }
                    break;
                }
                case 'press':
                    await page.keyboard.press(step.key);
                    break;
                default:
                    throw new Error(`Unknown step kind "${step.do}"`);
            }
            await settle(page);
        } catch (e) {
            // Playwright's message, without terminal colours, cut to what it
            // was waiting for: its first line and the first lines of its log.
            error = String(e?.message ?? e)
                .replace(/\u001b\[[0-9;]*m/g, '')
                .split('\n')
                .filter((line) => line.trim() !== '' && line.trim() !== 'Call log:')
                .slice(0, 3)
                .map((line) => line.trim().replace(/^- /, ''))
                .join('\n');
        }

        const ms = Date.now() - started;
        (await mountedComponents(page)).forEach((name) => reached.mounts.add(name));

        // Feedback is only judged for clicks that went to the server, and
        // only on the same page: a full page load starts a fresh count.
        let feedback = null;
        if (feedbackBefore !== null && livewire.requests > 0) {
            const after = await feedbackState(page);
            if (after !== null && after.origin === feedbackBefore.origin) {
                feedback = after.count > feedbackBefore.count;
                if (!feedback && feedbackBefore.delayed && livewire.slowest_ms < LOADING_DELAY_MS) {
                    feedback = null;
                }
            }
        }
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
            livewire_requests: livewire.requests,
            livewire_ms: livewire.requests > 0 ? livewire.slowest_ms : null,
            feedback,
            reached: {
                pages: [...reached.pages],
                calls: [...reached.calls],
                mounts: [...reached.mounts],
            },
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
