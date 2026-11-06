'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeButton {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.listeners = {};
        this.clicks = 0;
    }

    addEventListener(name, callback) {
        this.listeners[name] = callback;
    }

    click() {
        this.clicks++;
        return this.listeners.click?.();
    }
}

class FakeErrorBox {
    constructor() {
        this.dataset = {};
    }

    set innerHTML(value) {
        this.value = value;
        this.retryButton = new FakeButton();
    }

    querySelector(selector) {
        return selector === '[data-history-retry]' ? this.retryButton : null;
    }
}

class FakeHistoryBox {
    constructor() {
        this.content = '';
        this.errorBox = null;
        this.pageButtons = null;
    }

    set innerHTML(value) {
        this.content = value;
        this.errorBox = null;
        this.pageButtons = null;
    }

    get innerHTML() {
        return this.content;
    }

    querySelector(selector) {
        return selector === '[data-history-load-error]' ? this.errorBox : null;
    }

    querySelectorAll(selector) {
        if (selector !== '[data-page]') return [];
        if (this.pageButtons === null) {
            this.pageButtons = [...this.content.matchAll(/<button[^>]*data-page="(\d+)"[^>]*>/g)]
                .map(match => new FakeButton({ page: match[1] }));
        }
        return this.pageButtons;
    }

    prepend(element) {
        this.errorBox = element;
        this.content = '<!-- retry notice -->' + this.content;
    }
}

function response(data) {
    return {
        ok: true,
        json: async () => data,
    };
}

function historyPage(page) {
    return {
        total: 20,
        page,
        pages: 2,
        items: Array.from({ length: 10 }, (_, index) => ({
            id: (page - 1) * 10 + index + 1,
            invoice_number: 'INV-' + ((page - 1) * 10 + index + 1),
            invoice_date: '2026-10-26',
            withdrawal_no: '2026-000001',
            item_count: 1,
            total_quantity: 1,
            invoice_amount: 10,
            status: 'Draft',
        })),
    };
}

function createHarness(fetchImpl) {
    const panelListeners = {};
    const box = new FakeHistoryBox();
    const accordionButton = new FakeButton();
    const panel = {
        dataset: { clientId: '7' },
        addEventListener(name, callback) {
            panelListeners[name] = callback;
        },
        querySelector(selector) {
            if (selector === '.history-content') return box;
            if (selector === '.accordion-button') return accordionButton;
            return null;
        },
    };
    const elements = new Map([
        ['invoiceDetailModal', { addEventListener() {} }],
        ['invoiceDetailTitle', { textContent: '' }],
        ['invoiceDetailBody', { innerHTML: '' }],
    ]);
    const document = {
        addEventListener(name, callback) {
            if (name === 'DOMContentLoaded') callback();
        },
        querySelectorAll() {
            return [panel];
        },
        getElementById(id) {
            return elements.get(id);
        },
        createElement() {
            return new FakeErrorBox();
        },
    };

    const source = fs.readFileSync(path.join(__dirname, '../frontend/clients.php'), 'utf8');
    const script = source.match(/<script\s*>([\s\S]*?)<\/script>/)?.[1];
    assert.ok(script, 'client-history script exists');
    vm.runInNewContext(script, { document, fetch: fetchImpl, console });

    return {
        panel,
        box,
        accordionButton,
        show: () => panelListeners['show.bs.collapse'](),
    };
}

async function testRetryDoesNotToggleAccordion() {
    let calls = 0;
    const harness = createHarness(async () => {
        calls++;
        if (calls === 1) throw new Error('temporary network failure');
        return response({ total: 0, items: [], page: 1, pages: 1 });
    });

    await harness.show();
    const retry = harness.box.errorBox.retryButton;
    await retry.click();

    assert.equal(calls, 2, 'retry performs a second history request');
    assert.equal(harness.accordionButton.clicks, 0, 'retry does not toggle the accordion');
    assert.equal(harness.panel.dataset.historyLoaded, 'true', 'successful retry marks history loaded');
}

async function testPaginationFailureKeepsCurrentPageAndCanRetry() {
    const requestedPages = [];
    let calls = 0;
    const harness = createHarness(async url => {
        const page = Number(new URL(url, 'http://localhost').searchParams.get('page'));
        requestedPages.push(page);
        calls++;
        if (calls === 2) throw new Error('temporary pagination failure');
        return response(historyPage(page));
    });

    await harness.show();
    const firstPage = harness.box.innerHTML;
    const next = harness.box.querySelectorAll('[data-page]').at(-1);
    await next.click();

    assert.match(harness.box.innerHTML, /Showing 1–10 of 20 invoices/, 'failed pagination preserves the displayed page');
    assert.equal(harness.box.errorBox !== null, true, 'failed pagination shows a retry control');
    await harness.box.errorBox.retryButton.click();

    assert.deepEqual(requestedPages, [1, 2, 2], 'retry requests the failed page again');
    assert.match(harness.box.innerHTML, /Showing 11–20 of 20 invoices/, 'successful retry renders the requested page');
    assert.notEqual(harness.box.innerHTML, firstPage, 'the current page updates after a successful retry');
}

(async () => {
    await testRetryDoesNotToggleAccordion();
    console.log('PASS: failed initial load can be retried without collapsing the accordion');
    await testPaginationFailureKeepsCurrentPageAndCanRetry();
    console.log('PASS: failed pagination preserves the prior page and retries the requested page');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
