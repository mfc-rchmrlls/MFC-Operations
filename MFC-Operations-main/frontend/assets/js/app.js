window.parseMoneyValue = function (value) {
    const parsed = Number(String(value ?? '').replace(/,/g, '').trim());
    return Number.isFinite(parsed) ? parsed : 0;
};
window.formatMoneyValue = function (value) {
    return window.parseMoneyValue(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
window.formatCurrencyInput = function (input) {
    const raw = String(input.value ?? '').replace(/,/g, '').trim();
    if (raw === '') { input.value = ''; return; }
    if (!/^\d+(?:\.\d{0,2})?$/.test(raw)) return;
    input.value = window.formatMoneyValue(raw);
};
document.addEventListener('focusin', function (ev) {
    if (ev.target.matches('.currency-input')) ev.target.value = String(ev.target.value).replace(/,/g, '');
});
document.addEventListener('focusout', function (ev) {
    if (ev.target.matches('.currency-input')) window.formatCurrencyInput(ev.target);
});
document.addEventListener('input', function (ev) {
    if (!ev.target.matches('.invoice-qty,.invoice-price')) return;
    const row = ev.target.closest('tr'), q = Number(row.querySelector('.invoice-qty')?.value || 0), p = window.parseMoneyValue(row.querySelector('.invoice-price')?.value || 0);
    const amount = row.querySelector('.amount'); if (amount) amount.value = window.formatMoneyValue(q * p);
    let subtotal = 0; document.querySelectorAll('.amount').forEach(a => subtotal += window.parseMoneyValue(a.value));
    const total = document.querySelector('[data-invoice-total]'); if (total) total.textContent = window.formatMoneyValue(subtotal);
});
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.currency-input').forEach(window.formatCurrencyInput);
    const sidebar = document.getElementById('appSidebar'), toggle = document.querySelector('.mobile-nav-toggle'), backdrop = document.querySelector('.sidebar-backdrop');
    function setNavigationOpen(open) {
        sidebar?.classList.toggle('is-open', open);
        backdrop?.classList.toggle('is-visible', open);
        toggle?.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('nav-open', open);
    }
    toggle?.addEventListener('click', function () { setNavigationOpen(!sidebar?.classList.contains('is-open')); });
    backdrop?.addEventListener('click', function () { setNavigationOpen(false); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && sidebar?.classList.contains('is-open')) { setNavigationOpen(false); toggle?.focus(); } });
    document.querySelectorAll('form#withdrawalForm, form#invoiceForm').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            if (form.dataset.confirmed === 'true') { delete form.dataset.confirmed; return; }
            event.preventDefault(); const invoice = form.id === 'invoiceForm';
            const result = await Swal.fire({ title: invoice ? 'Save invoice draft?' : 'Save withdrawal?', text: invoice ? 'Your invoice draft and line details will be saved.' : 'This will save the withdrawal and its item details.', icon: 'question', showCancelButton: true, confirmButtonText: invoice ? 'Save draft' : 'Save withdrawal', cancelButtonText: 'Review details', confirmButtonColor: '#2563eb', reverseButtons: true });
            if (result.isConfirmed) { form.dataset.confirmed = 'true'; if (form.requestSubmit) form.requestSubmit(event.submitter || undefined); else form.submit(); }
        });
    });
    document.querySelectorAll('form[data-confirm-submit]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            if (form.dataset.confirmed === 'true') { delete form.dataset.confirmed; return; }
            event.preventDefault();
            const result = await Swal.fire({ title: form.dataset.confirmTitle || 'Confirm changes?', text: form.dataset.confirmSubmit, icon: 'question', showCancelButton: true, confirmButtonText: form.dataset.confirmButton || 'Continue', cancelButtonText: 'Review', confirmButtonColor: '#2563eb', reverseButtons: true });
            if (result.isConfirmed) { form.dataset.confirmed = 'true'; if (form.requestSubmit) form.requestSubmit(event.submitter || undefined); else form.submit(); }
        });
    });
    document.querySelectorAll('[data-confirm]').forEach(link => link.addEventListener('click', async function (event) {
        event.preventDefault(); const result = await Swal.fire({ title: link.dataset.confirmTitle || 'Are you sure?', text: link.dataset.confirm, icon: link.dataset.confirmIcon || 'warning', showCancelButton: true, confirmButtonText: 'Continue', cancelButtonText: 'Cancel', confirmButtonColor: '#2563eb', reverseButtons: true });
        if (result.isConfirmed) window.location.href = link.href;
    }));
    document.querySelectorAll('.table:not([data-pagination-exempt])').forEach(function (table) {
        const body = table.tBodies[0]; if (!body) return;
        const rows = Array.from(body.rows).filter(row => !row.querySelector('[colspan]')); if (!rows.length) return;
        const pageSize = 10; let page = 0; const nav = document.createElement('nav'); nav.className = 'table-pagination'; nav.setAttribute('aria-label', 'Table pagination');
        const summary = document.createElement('span'); summary.className = 'table-pagination-summary'; summary.setAttribute('aria-live', 'polite');
        const controls = document.createElement('div'); controls.className = 'table-pagination-controls';
        const buttons = [{ label: 'First', delta: -Infinity }, { label: 'Previous', delta: -1 }, { label: 'Next', delta: 1 }, { label: 'Last', delta: Infinity }].map(item => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-light btn-sm'; button.textContent = item.label;
            button.addEventListener('click', function () { const count = Math.ceil(rows.length / pageSize); page = item.delta === -Infinity ? 0 : item.delta === Infinity ? count - 1 : Math.max(0, Math.min(page + item.delta, count - 1)); render(); }); controls.appendChild(button); return button;
        });
        nav.append(summary, controls); const container = table.closest('.table-responsive'); (container || table).insertAdjacentElement('afterend', nav);
        function render() { const count = Math.ceil(rows.length / pageSize), start = page * pageSize; rows.forEach((row, index) => row.hidden = index < start || index >= start + pageSize); summary.textContent = 'Showing ' + (start + 1) + '–' + Math.min(start + pageSize, rows.length) + ' of ' + rows.length; buttons[0].disabled = buttons[1].disabled = page === 0; buttons[2].disabled = buttons[3].disabled = page === count - 1; }
        render();
    });
});
