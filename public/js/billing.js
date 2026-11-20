'use strict';

(() => {
    const snapshots = new WeakMap();
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    const values = form => new Map(new FormData(form).entries());
    const ignored = new Set(['_token', '_method', 'version']);

    function initialize(root = document) {
        root.querySelectorAll('form[data-dirty]').forEach(form => snapshots.set(form, values(form)));
        root.querySelectorAll('[name="email_invoice"]').forEach(input => toggleInvoiceEmail(input));
        updateRemaining();
    }

    function toggleInvoiceEmail(input) {
        input.form.querySelector('[data-invoice-email-fields]').hidden = !input.checked;
        input.form.elements.email_message.required = input.checked;
        input.form.elements.email_subject.required = input.checked;
    }

    function status(form, text, error = false) {
        const element = form.querySelector('.form-status');
        if (element) {
            element.textContent = text;
            element.setAttribute('role', error ? 'alert' : 'status');
        }
    }

    function dirty(form) {
        form.classList.add('dirty');
        status(form, 'Unsaved changes');
    }

    function lockControls(controls) {
        const disabled = controls.map(control => control.disabled);
        controls.forEach(control => control.disabled = true);
        return () => controls.forEach((control, index) => control.disabled = disabled[index]);
    }

    async function request(url, data, method = 'POST') {
        const response = await fetch(url, {
            method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(),
                'X-Student-Lock': document.getElementById('student-detail')?.dataset.lockToken || '' },
            body: JSON.stringify(data),
        });
        if (response.redirected && new URL(response.url).pathname === '/login') {
            throw new Error('Your session expired. Log in again before saving. Your changes are still shown here.');
        }
        if (!(response.headers.get('content-type') || '').includes('application/json')) {
            throw new Error(`Unexpected server response (${response.status}). Changes have not been confirmed saved.`);
        }
        const result = await response.json();
        if (!response.ok) {
            const messages = result.errors ? Object.entries(result.errors).map(([field, errors]) => `${field}: ${errors.join(' ')}`) : [result.message || `Request failed (${response.status}).`];
            throw new Error(messages.join('\n'));
        }
        return result;
    }

    async function prepareInvoiceEmail(form) {
        if (form.dataset.saving) return;
        if (form.dataset.emailEdited && !confirm('Replace your edited email with a fresh preview from the template?')) return;
        const data = form.dataset.invoiceId ? { invoice_id: form.dataset.invoiceId } : Object.fromEntries(values(form));
        const restore = lockControls([...form.elements]);
        form.dataset.saving = '1';
        status(form, 'Preparing email preview...');
        try {
            const preview = await request(form.dataset.emailPreviewUrl, data);
            form.elements.email_subject.value = preview.subject;
            form.elements.email_message.value = preview.text;
            form.querySelector('[data-email-from]').textContent = preview.from || 'No email in your user profile';
            form.querySelector('[data-email-to]').textContent = preview.to || 'No learner email recorded';
            form.dataset.emailPreviewReady = '1';
            delete form.dataset.emailEdited;
            delete form.dataset.emailPreviewStale;
            status(form, 'Email preview ready. You can edit the subject and message.');
        } catch (error) {
            status(form, `Unable to prepare the email preview. ${error.message}`, true);
        } finally {
            delete form.dataset.saving;
            restore();
        }
    }

    function invoicePreviewChanged(form) {
        if (!form.dataset.emailPreviewReady) return;
        form.dataset.emailPreviewStale = '1';
        if (form.elements.email_invoice.checked) {
            status(form, 'Invoice details changed. Your email text was kept; refresh from the template or update the message to match.');
        }
    }

    async function loadSection(section, confirmChanges = true) {
        const message = section.querySelector('.section-status');
        if (section.dataset.loading || section.querySelector('form[data-saving]')) {
            message.textContent = 'Please wait for the current request to finish before reloading.';
            return false;
        }
        if (confirmChanges && section.querySelector('form.dirty') && !confirm('Discard unsaved changes in this section and reload?')) return false;
        section.dataset.loading = '1';
        const restore = lockControls([...section.querySelectorAll('input, select, textarea, button')]);
        message.textContent = 'Loading...';
        message.setAttribute('role', 'status');
        try {
            const response = await fetch(section.dataset.load, { credentials: 'same-origin' });
            if (response.redirected || !response.ok) throw new Error('Unable to load records. Check your session and try again.');
            section.querySelector('.section-content').innerHTML = await response.text();
            initialize(section);
            message.textContent = '';
            if (section.id === 'transactions') updateRemaining();
            return true;
        } catch (error) {
            message.textContent = error.message;
            message.setAttribute('role', 'alert');
            throw error;
        } finally {
            delete section.dataset.loading;
            restore();
        }
    }

    function changedData(form) {
        const current = values(form);
        const original = snapshots.get(form);
        if (!form.hasAttribute('data-partial')) return Object.fromEntries(current);
        if (!original) throw new Error('Form state is unavailable. Reload before saving.');
        return Object.fromEntries([...current].filter(([key, value]) => ignored.has(key) || value !== original.get(key)));
    }

    function cents(value) {
        const text = String(value);
        if (!/^-?\d{1,13}(?:\.\d{1,2})?$/.test(text)) return null;
        const [whole, fractional = ''] = text.replace('-', '').split('.');
        const amount = BigInt(whole) * 100n + BigInt(fractional.padEnd(2, '0'));
        return text.startsWith('-') ? -amount : amount;
    }

    function money(amount) {
        const sign = amount < 0n ? '-' : '';
        const absolute = amount < 0n ? -amount : amount;
        return `${sign}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
    }

    function updateRemaining() {
        const editor = document.querySelector('.schedule-editor');
        if (!editor) return;
        const generator = document.getElementById('schedule-generator');
        const fees = cents(document.querySelector('.ledger-summary')?.dataset.currentFees ?? editor.dataset.fees);
        const fee = generator.elements.exclude_app_fee.checked ? cents(generator.elements.app_fee.value) : 0n;
        let total = 0n;
        let valid = fees !== null && fee !== null;
        document.querySelectorAll('#schedule-rows [data-payment-field="paymentamount"]').forEach(input => {
            const amount = cents(input.value);
            if (amount === null) valid = false;
            else total += amount;
        });
        editor.querySelector('.remaining-amount').textContent = valid
            ? `Remaining amount: ${money(fees - fee - total)}`
            : 'Remaining amount: enter valid amounts with at most two decimal places.';
    }

    function renumberPayments() {
        document.querySelectorAll('#schedule-rows tr').forEach((row, index) => row.querySelector('.payment-number').textContent = index + 1);
        updateRemaining();
    }

    function addPayment(payment = {}, before = null) {
        const row = document.getElementById('payment-row-template').content.cloneNode(true);
        row.querySelector('tr').dataset.paymentId = payment.id || '';
        row.querySelectorAll('[data-payment-field]').forEach(input => input.value = payment[input.dataset.paymentField] ?? '');
        document.getElementById('schedule-rows').insertBefore(row, before);
        renumberPayments();
    }

    function scheduleData(form) {
        const payments = [...document.querySelectorAll('#schedule-rows tr')].map(row => {
            const entry = {};
            if (row.dataset.paymentId) entry.id = Number(row.dataset.paymentId);
            row.querySelectorAll('[data-payment-field]').forEach(input => entry[input.dataset.paymentField] = input.value);
            return entry;
        });
        return { ...Object.fromEntries(values(form)), payments };
    }

    async function saveSchedule(form, data) {
        const result = await request(form.action, data);
        delete form.dataset.saving;
        form.classList.remove('dirty');
        form.dataset.saved = '1';
        try {
            await loadSection(document.getElementById('schedule'), false);
        } catch (error) {
            throw new Error(`${result.message} Reload the payment plan before editing again. ${error.message}`);
        }
        document.querySelector('#schedule .section-status').textContent = result.message;
    }

    document.addEventListener('input', event => {
        const form = event.target.form;
        if (form?.dataset.emailPreviewUrl && ['email_subject', 'email_message'].includes(event.target.name)) {
            form.dataset.emailEdited = '1';
        }
        if (form?.hasAttribute('data-dirty')) dirty(form);
        updateRemaining();
    });

    document.addEventListener('change', event => {
        const input = event.target;
        if (input.name === 'email_invoice') {
            toggleInvoiceEmail(input);
            if (input.checked && !input.form.dataset.emailEdited
                && (!input.form.dataset.emailPreviewReady || input.form.dataset.emailPreviewStale)) prepareInvoiceEmail(input.form);
        }
        if (input.type === 'checkbox' && input.form) {
            const hidden = [...input.form.elements].find(other => other.type === 'hidden' && other.name === input.name);
            if (hidden) hidden.value = '0';
        }
        if (input.form?.hasAttribute('data-dirty')) dirty(input.form);
        if (input.closest('[data-invoice-fields]') || (input.name === 'email_invoice' && input.form.dataset.emailPreviewStale)) invoicePreviewChanged(input.form);
        updateRemaining();
    });

    document.addEventListener('toggle', event => {
        if (!event.target.matches('details[data-invoice-email]') || !event.target.open) return;
        const form = event.target.querySelector('form');
        if (!form.dataset.emailPreviewReady && !form.dataset.emailEdited) prepareInvoiceEmail(form);
    }, true);

    document.addEventListener('cancel', event => {
        if (event.target.querySelector('form[data-saving]')) event.preventDefault();
    }, true);

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (form.id === 'schedule-generator') {
            event.preventDefault();
            if (form.closest('section').querySelector('form[data-saving]')) return;
            if (document.querySelector('#schedule-rows tr') && !confirm('Replace the current schedule draft? It will not be saved until you click Save Payment Plan.')) return;
            const data = Object.fromEntries(values(form));
            const restore = lockControls([...form.closest('.schedule-editor').querySelectorAll('input, select, textarea, button')]);
            form.dataset.saving = '1';
            try {
                const result = await request(form.action, data);
                document.getElementById('schedule-rows').replaceChildren();
                result.payments.forEach(payment => addPayment(payment));
                document.getElementById('schedule-form').elements.paymentplandesc.value = result.description || '';
                dirty(document.getElementById('schedule-form'));
                status(form, result.message);
            } catch (error) {
                status(form, error.message, true);
            } finally {
                restore();
                delete form.dataset.saving;
            }
            return;
        }
        if (!form.hasAttribute('data-ajax') && form.id !== 'schedule-form') {
            // Native form navigation should not trigger the unsaved-change warning.
            form.classList.remove('dirty');
            return;
        }
        event.preventDefault();
        if (form.dataset.saving) return;
        if (form.dataset.saved) {
            status(form, 'This plan was saved. Reload the payment plan before making further changes.', true);
            return;
        }
        let restore = () => {};
        let savedMessage = '';
        let emailing = false;
        try {
            const old = (form.dataset.oldDates || '').split(',').some(date => date && (Date.now() - new Date(`${date}T00:00:00`).getTime()) > 30 * 86400000);
            if (old && !confirm('This record references events over 30 days old, which may have been reconciled. Really save changes?')) return;
            if (form.id === 'schedule-form') {
                const data = scheduleData(form);
                restore = lockControls([...form.closest('.schedule-editor').querySelectorAll('input, select, textarea, button')]);
                form.dataset.saving = '1';
                await saveSchedule(form, data);
                return;
            }
            const data = changedData(form);
            restore = lockControls([...form.elements]);
            form.dataset.saving = '1';
            const result = form.dataset.invoiceEmailUrl
                ? { message: form.dataset.invoiceCreatedMessage }
                : await request(form.action, data);
            savedMessage = result.message;
            if (form.id === 'new-invoices' && (data.email_invoice === '1' || form.dataset.invoiceEmailUrl)) {
                form.dataset.invoiceEmailUrl ||= result.email_url;
                form.dataset.invoiceCreatedMessage ||= result.message;
                if (result.invoice_id) form.dataset.invoiceId = String(result.invoice_id);
                if (!form.dataset.invoiceEmailed) {
                    emailing = true;
                    form.elements.email_subject.value = data.email_subject.replaceAll('{invoice_id}', form.dataset.invoiceId);
                    form.elements.email_message.value = data.email_message.replaceAll('{invoice_id}', form.dataset.invoiceId);
                    const emailed = await request(form.dataset.invoiceEmailUrl, {
                        email_previewed: data.email_previewed, email_subject: data.email_subject, email_message: data.email_message,
                    });
                    form.dataset.invoiceEmailed = '1';
                    result.message += ` ${emailed.message}`;
                    emailing = false;
                }
            }
            delete form.dataset.saving;
            restore();
            form.classList.remove('dirty');
            if (form.hasAttribute('data-invoice-email')) form.dataset.confirm = 'This invoice has already been emailed. Send it again?';
            if (!form.hasAttribute('data-partial') && form.dataset.refresh) {
                if (form.elements._method?.value === 'DELETE') {
                    form.closest('tr')?.remove();
                    if (form.dataset.refresh === 'invoices') {
                        const id = new URL(form.action).pathname.split('/').pop();
                        document.getElementById(`invoice-dialog-${id}`)?.remove();
                    }
                }
                else if (!form.dataset.invoiceEmailUrl && !form.hasAttribute('data-invoice-email')) {
                    form.reset();
                    if (form.dataset.emailPreviewUrl) {
                        delete form.dataset.emailPreviewReady;
                        delete form.dataset.emailEdited;
                        delete form.dataset.emailPreviewStale;
                        toggleInvoiceEmail(form.elements.email_invoice);
                    }
                }
            }
            snapshots.set(form, values(form));
            if (result.version) form.elements.version.value = result.version;
            if (result.enrollmentHtml) {
                const history = document.getElementById('enrollment-history');
                if (history && !history.querySelector('form.dirty')) {
                    const template = document.createElement('template');
                    template.innerHTML = result.enrollmentHtml;
                    const replacement = template.content.firstElementChild;
                    history.replaceWith(replacement);
                    initialize(replacement);
                } else if (history) {
                    let warning = history.querySelector('.enrollment-refresh-warning');
                    if (!warning) {
                        warning = document.createElement('p');
                        warning.className = 'enrollment-refresh-warning';
                        warning.setAttribute('role', 'alert');
                        history.prepend(warning);
                    }
                    warning.textContent = 'Learner details saved. Your unsaved enrollment edits were preserved, but these controls are now out of date. Copy your edits and reload before changing enrollment history.';
                }
            }
            status(form, result.message);
            form.closest('dialog')?.close();
            if (form.dataset.refresh) {
                const section = document.getElementById(form.dataset.refresh);
                if (section.querySelector('form.dirty')) {
                    section.querySelector('.section-status').textContent = `${result.message} Other unsaved changes were kept; refresh after saving them to see all updated totals.`;
                } else {
                    await loadSection(section, false);
                    section.querySelector('.section-status').textContent = result.message;
                }
            }
        } catch (error) {
            const text = savedMessage
                ? `${savedMessage} ${emailing ? 'Email not confirmed. Retry email only, or close and use Edit Invoice to email the saved PDF later.' : 'Display refresh failed. Reload the section.'} ${error.message}`
                : error.message;
            status(form, text, true);
            if (form.dataset.refresh) {
                const message = document.querySelector(`#${form.dataset.refresh} .section-status`);
                message.textContent = text;
                message.setAttribute('role', 'alert');
            }
        } finally {
            delete form.dataset.saving;
            restore();
            if (form.dataset.invoiceEmailUrl) {
                form.querySelector('[data-invoice-fields]').disabled = true;
                form.elements.email_invoice.disabled = true;
                form.querySelector('button:not([type="button"])').textContent = form.dataset.invoiceEmailed ? 'Reload invoice list' : 'Retry email only';
            }
        }
    });

    document.addEventListener('click', async event => {
        const target = event.target.closest('button, a');
        if (target?.hasAttribute('data-refresh-email-preview')) prepareInvoiceEmail(target.form);
        if (target?.dataset.open) document.getElementById(target.dataset.open).showModal();
        if (target?.hasAttribute('data-close-dialog')) target.closest('dialog').close();
        if (target?.hasAttribute('data-load-section')) {
            try { await loadSection(target.closest('section')); } catch { /* The section displays the error. */ }
        }
        if (target?.hasAttribute('data-create-invoice')) {
            try {
                const section = document.getElementById('invoices');
                if (!document.getElementById('new-invoices') && !await loadSection(section)) return;
                document.getElementById('new-invoice-dialog').showModal();
            } catch (error) {
                document.querySelector('#invoices .section-status').textContent = error.message;
            }
        }
        if (target?.hasAttribute('data-add-payment')) {
            addPayment();
            dirty(document.getElementById('schedule-form'));
        }
        if (target?.hasAttribute('data-insert-payment')) {
            addPayment({}, target.closest('tr'));
            dirty(document.getElementById('schedule-form'));
        }
        if (target?.hasAttribute('data-move-payment')) {
            const row = target.closest('tr');
            const direction = Number(target.dataset.movePayment);
            if (direction < 0 && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
            if (direction > 0 && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
            renumberPayments();
            dirty(document.getElementById('schedule-form'));
        }
        if (target?.hasAttribute('data-remove-payment') && confirm('Really delete this scheduled payment?')) {
            target.closest('tr').remove();
            renumberPayments();
            dirty(document.getElementById('schedule-form'));
        }
        if (target?.hasAttribute('data-clear-plan') && confirm('Erase this schedule draft? Save to persist the removal.')) {
            document.getElementById('schedule-rows').replaceChildren();
            document.getElementById('schedule-form').elements.paymentplandesc.value = '';
            dirty(document.getElementById('schedule-form'));
            updateRemaining();
        }
        if (target?.dataset.payInvoice) {
            const form = document.getElementById('new-transactions');
            if (form.classList.contains('dirty') && !confirm('Replace the unsaved new transaction values?')) return;
            const amount = cents(target.dataset.payAmount);
            const date = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
            form.elements.invoice_id.value = target.dataset.payInvoice;
            form.elements.processeddate.value = date;
            form.elements.transactiontype.value = 'Payment';
            form.elements.transactionamount.value = amount === null ? '' : money(amount);
            form.elements.transactiondate.focus();
            form.scrollIntoView({ block: 'center' });
            dirty(form);
        }
        if (target?.dataset.plannedAmount) {
            try {
                const section = document.getElementById('invoices');
                if (!document.getElementById('new-invoices') && !await loadSection(section)) return;
                const form = document.getElementById('new-invoices');
                if (form.classList.contains('dirty') && !confirm('Replace the unsaved new invoice values?')) return;
                form.elements.amount.value = target.dataset.plannedAmount;
                form.elements.nextdate.value = target.dataset.plannedDate;
                form.elements.notes.value = target.dataset.plannedNotes;
                document.getElementById('new-invoice-dialog').showModal();
                form.elements.invoicereference.focus();
                dirty(form);
                invoicePreviewChanged(form);
            } catch (error) {
                document.querySelector('#invoices .section-status').textContent = error.message;
            }
        }
        const row = event.target.closest('tr[data-href]');
        if (row && !event.target.closest('a, button, input, form')) location.href = row.dataset.href;
    });

    document.addEventListener('keydown', event => {
        if (!document.getElementById('student-detail') || !event.ctrlKey) return;
        const key = event.key.toLowerCase();
        const link = { p: 'previous-record', n: 'next-record', l: 'list-records' }[key];
        if (key === 'r') {
            event.preventDefault();
            document.getElementById('find-dialog').showModal();
        } else if (link && document.getElementById(link)) {
            event.preventDefault();
            document.getElementById(link).click();
        }
    });

    window.addEventListener('beforeunload', event => {
        if (document.querySelector('form.dirty')) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    initialize();
})();
