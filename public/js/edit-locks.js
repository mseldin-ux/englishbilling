'use strict';

(() => {
    const detail = document.getElementById('student-detail');
    const sessionMessage = document.getElementById('session-state');
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    let owned = detail?.dataset.lockOwned === '1';
    const token = detail?.dataset.lockToken || '';
    let lastSent = 0;
    let pending = false;
    let ended = false;
    const controls = new Map();

    function apply() {
        if (!detail) return;
        detail.querySelectorAll('form, button[data-create-invoice], button[data-planned-amount], button[data-pay-invoice], button[data-add-payment], button[data-insert-payment], button[data-move-payment], button[data-remove-payment], button[data-clear-plan]').forEach(element => {
            const elements = element.tagName === 'FORM' ? [...element.elements] : [element];
            elements.forEach(control => {
                if (!owned) {
                    if (!controls.has(control)) controls.set(control, control.disabled);
                    if (!control.disabled) control.disabled = true;
                } else if (controls.has(control)) {
                    control.disabled = controls.get(control);
                    controls.delete(control);
                }
            });
        });
        const done = document.getElementById('done-editing');
        if (done.disabled !== !owned) done.disabled = !owned;
    }

    function lose(message) {
        owned = false;
        if (detail) {
            detail.dataset.lockOwned = '0';
            document.getElementById('edit-lock-message').textContent = message;
            apply();
        }
    }

    async function call(url, data = null) {
        const response = await fetch(url, {
            method: data === null ? 'GET' : 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(5000),
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Student-Lock': token },
            ...(data === null ? {} : { body: JSON.stringify(data) }),
        });
        if (response.status === 401 || response.status === 419 || response.redirected) {
            ended = true;
            lose('Session expired. No changes are allowed. Log in again.');
            sessionMessage.textContent = 'Your session expired. Editing locks were released. Unsaved values remain shown; log in again before reloading.';
            if (!sessionMessage.querySelector('a')) {
                const link = document.createElement('a');
                link.href = '/login';
                link.textContent = ' Log in';
                sessionMessage.append(link);
            }
            return null;
        }
        if (!response.ok) throw new Error(`Unable to verify your session/editing lock (${response.status}).`);
        return response.json();
    }

    async function poll() {
        if (ended || pending) return;
        pending = true;
        try {
            const state = await call(detail ? detail.dataset.lockStatus : '/session/status');
            if (!state) return;
            if (detail && !state.owned) lose(state.editor ? `Read only — locked by ${state.editor}. Reload to obtain a lock after release.` : 'Editing lock released. Reload to obtain a new editing lock.');
            sessionMessage.textContent = '';
        } catch (error) {
            lose('Editing disabled because lock status could not be verified. Reload after connectivity returns.');
            sessionMessage.textContent = error.message;
            sessionMessage.setAttribute('role', 'alert');
        } finally {
            pending = false;
        }
    }

    async function activity(event) {
        if (!event.isTrusted || ended || Date.now() - lastSent < 15000) return;
        lastSent = Date.now();
        try {
            await call('/session/activity', {});
        } catch (error) {
            sessionMessage.textContent = error.message;
            sessionMessage.setAttribute('role', 'alert');
        }
    }

    ['pointerdown', 'keydown', 'input', 'scroll'].forEach(type => document.addEventListener(type, activity, { passive: true }));
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!detail?.contains(form)) return;
        if (!owned) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        if (!form.querySelector('input[name="_edit_lock"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_edit_lock';
            input.value = token;
            form.append(input);
        }
    }, true);
    if (detail) {
        const observer = new MutationObserver(apply);
        observer.observe(detail, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        apply();
        document.getElementById('done-editing').addEventListener('click', async () => {
            if (detail.querySelector('form[data-saving]')) return;
            if (detail.querySelector('form.dirty') && !confirm('Release the editing lock? Unsaved values will remain, but you cannot save until reloading.')) return;
            try {
                const result = await call(detail.dataset.lockRelease, { token });
                if (result) lose('Editing lock released. Reload to edit again.');
            } catch (error) {
                sessionMessage.textContent = error.message;
                sessionMessage.setAttribute('role', 'alert');
            }
        });
        window.addEventListener('pagehide', () => {
            if (!owned || ended) return;
            const data = new FormData();
            data.set('_token', csrf());
            data.set('token', token);
            navigator.sendBeacon(detail.dataset.lockRelease, data);
        });
        window.addEventListener('pageshow', event => {
            if (event.persisted) {
                lose('Returned to an old editing page. Reload to obtain a new lock.');
                poll();
            }
        });
    }
    poll();
    setInterval(poll, 10000);
})();
