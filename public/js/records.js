'use strict';

(() => {
    const form = document.querySelector('[data-records-form]');
    if (form) {
        let submitted = false;
        form.addEventListener('input', () => form.classList.add('dirty'));
        form.addEventListener('change', () => form.classList.add('dirty'));
        form.addEventListener('submit', () => submitted = true);
        window.addEventListener('beforeunload', event => {
            if (form.classList.contains('dirty') && !submitted) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }
    const table = document.getElementById('records-table');
    if (!table) return;
    const fields = JSON.parse(document.getElementById('records-fields').textContent);
    const statuses = JSON.parse(document.getElementById('records-statuses').textContent);
    const searchFields = JSON.parse(document.getElementById('records-search-fields').textContent);
    const initialFilters = JSON.parse(document.getElementById('records-initial-filters').textContent);
    const filterContainer = document.getElementById('records-filters');
    let nextFilter = 0;

    function addFilter(initial = {}) {
        const index = nextFilter++;
        const row = document.createElement('div');
        row.className = 'records-filter';
        const field = document.createElement('select');
        const match = document.createElement('select');
        field.name = `filters[${index}][field]`;
        match.name = `filters[${index}][match]`;
        for (const [name, definition] of Object.entries(searchFields)) field.add(new Option(definition[0], name));
        field.value = initial.field || 'first_name';
        const values = document.createElement('div');
        values.className = 'filter-values';
        function label(text, control) {
            const label = document.createElement('label');
            label.append(document.createTextNode(text), control);
            return label;
        }
        function configureValues(saved = {}) {
            values.replaceChildren();
            if (match.value === 'blank') return;
            const type = searchFields[field.value][1];
            function control(key, caption) {
                const select = type === 'nullable_boolean' || type === 'access_status';
                const input = document.createElement(select ? 'select' : 'input');
                input.name = `filters[${index}][${key}]`;
                if (select) {
                    input.add(new Option('Empty (null)', ''));
                    const options = type === 'access_status'
                        ? statuses.map(s => [s.id, s.status]) : [[1, 'True'], [0, 'False']];
                    for (const [value, text] of options) input.add(new Option(text, value));
                } else {
                    input.type = ['date', 'datetime'].includes(type) ? 'date' : type === 'integer' ? 'number' : 'text';
                    if (type === 'integer') { input.min = '1'; input.step = '1'; }
                    if (input.type === 'text') input.maxLength = 255;
                }
                input.required = !select;
                input.value = saved[key] ?? '';
                values.append(label(caption, input));
            }
            control('value', match.value === 'range' ? 'From' : 'Value');
            if (match.value === 'range') control('end', 'Through');
        }
        function configureField(saved = {}) {
            const type = searchFields[field.value][1];
            match.replaceChildren();
            const options = ['date', 'datetime', 'integer'].includes(type)
                ? [['exact', 'Equals'], ['range', 'Range (inclusive)'], ['blank', 'Empty (null)']]
                : ['nullable_boolean', 'access_status'].includes(type)
                    ? [['exact', 'Equals'], ['blank', 'Empty (null)']]
                    : [['contains', 'Contains'], ['exact', 'Exact'], ['blank', 'Blank / not specified']];
            for (const [value, text] of options) match.add(new Option(text, value));
            if (options.some(([value]) => value === saved.match)) match.value = saved.match;
            configureValues(saved);
        }
        field.addEventListener('change', () => configureField());
        match.addEventListener('change', () => configureValues());
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            row.remove();
            if (!filterContainer.children.length) addFilter();
            document.getElementById('add-records-filter').disabled = false;
        });
        row.append(label('Field', field), label('Match', match), values, remove);
        filterContainer.append(row);
        configureField(initial);
        document.getElementById('add-records-filter').disabled = filterContainer.children.length >= 20;
    }
    (initialFilters.length ? initialFilters : [{}]).forEach(addFilter);
    document.getElementById('add-records-filter').addEventListener('click', () => addFilter());
    const message = document.getElementById('records-message');
    let editing = null;
    let busy = false;
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    document.getElementById('select-page').addEventListener('change', event => {
        table.querySelectorAll('[data-select]').forEach(control => control.checked = event.target.checked);
    });

    function error(e) {
        message.textContent = e.message;
        message.setAttribute('role', 'alert');
    }

    async function call(url, method = 'GET', data = null) {
        const response = await fetch(url, {
            method, credentials: 'same-origin', signal: AbortSignal.timeout(10000),
            headers: {'Accept':'application/json','Content-Type':'application/json',
                'X-CSRF-TOKEN':csrf(), 'X-Student-Lock':editing?.token || ''},
            ...(data === null ? {} : {body:JSON.stringify(data)}),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message);
        return result;
    }

    function display(name, value) {
        const type = fields[name][1];
        if (type === 'access_status') return statuses.find(s => String(s.id) === String(value))?.status || '';
        if (type === 'nullable_boolean') return value === null || value === '' ? '' : (Number(value) ? 'Yes' : 'No');
        return value ?? '';
    }

    function render(row, values, editable) {
        for (const [name, field] of Object.entries(fields)) {
            const cell = row.querySelector(`[data-field="${name}"]`);
            cell.replaceChildren();
            if (field[1] === 'nullable_boolean') {
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = name;
                checkbox.value = '1';
                checkbox.checked = Boolean(Number(values[name]));
                checkbox.disabled = !editable;
                checkbox.setAttribute('aria-label', field[0]);
                if (values[name] === null) checkbox.title = 'Not specified (null)';
                cell.append(checkbox);
                continue;
            }
            if (!editable) {
                cell.textContent = display(name, values[name]);
                continue;
            }
            const type = field[1];
            const control = document.createElement(type === 'access_status' ? 'select' : 'input');
            control.name = name;
            control.setAttribute('aria-label', field[0]);
            if (control.tagName === 'SELECT') {
                const options = type === 'access_status' ? statuses.map(s => [s.id,s.status]) : [[1,'Yes'],[0,'No']];
                for (const [value,label] of [['','Not specified'], ...options]) control.add(new Option(label,value));
            } else {
                control.type = type === 'date' ? 'date' : type === 'datetime' ? 'datetime-local' : 'text';
                if (type === 'datetime') control.step = '1';
                if (field[2]) control.maxLength = field[2];
            }
            control.value = values[name] ?? '';
            cell.append(control);
        }
        row.querySelector('[data-edit]').hidden = editable;
        row.querySelector('[data-save]').hidden = !editable;
        row.querySelector('[data-save]').disabled = false;
        row.querySelector('[data-cancel]').hidden = !editable;
    }

    async function release() {
        if (!editing) return;
        await call(editing.row.dataset.base + '/release','POST',{token:editing.token});
        render(editing.row,editing.values,false);
        editing.row.querySelector('[data-lock]').textContent = '';
        editing = null;
    }

    table.addEventListener('click', async event => {
        const button = event.target.closest('button');
        if (!button || busy) return;
        const row = button.closest('tr');
        busy = true;
        try {
            if (button.matches('[data-edit]')) {
                if (editing) throw new Error('Save or cancel the current row before editing another.');
                const result = await call(row.dataset.base + '/claim','POST',{});
                if (!result.lock.owned) {
                    row.querySelector('[data-lock]').textContent = 'Locked by ' + result.lock.editor;
                    throw new Error('Read only — locked by ' + result.lock.editor);
                }
                editing = {row,token:result.lock.token,version:result.version,values:result.values};
                render(row,result.values,true);
                row.querySelector('[data-lock]').textContent = 'Editing lock held by you';
            } else if (button.matches('[data-save]')) {
                const data = {version:editing.version};
                row.querySelectorAll('[data-field] input, [data-field] select').forEach(c =>
                    data[c.name] = c.type === 'checkbox' ? (c.checked ? '1' : '0') : c.value);
                const result = await call(row.dataset.base,'PATCH',data);
                editing.values = result.values;
                editing.version = result.version;
                await release();
                message.textContent = 'Changes saved.';
                message.setAttribute('role', 'status');
            } else if (button.matches('[data-cancel]')) {
                if (!confirm('Discard this row draft and release its lock?')) return;
                await release();
            }
        } catch (e) { error(e); } finally { busy = false; }
    });

    setInterval(async () => {
        if (busy) return;
        busy = true;
        try {
            const rows = [...table.querySelectorAll('tbody tr')];
            if (rows.length) {
                const url = new URL(table.dataset.locks, location.href);
                rows.forEach(row => url.searchParams.append('ids[]',row.dataset.id));
                const result = await call(url);
                rows.filter(row => row !== editing?.row).forEach(row => {
                    const lock = result.locks.find(lock => String(lock.student_id) === row.dataset.id);
                    row.querySelector('[data-lock]').textContent = lock ? `Locked by ${lock.name} (${lock.username})` : '';
                });
            }
            if (!editing) return;
            const state = await call(editing.row.dataset.base + '/lock');
            if (!state.owned) {
                editing.row.querySelector('[data-lock]').textContent = state.editor ? 'Locked by ' + state.editor : 'Editing lock released';
                editing.row.querySelectorAll('[data-field] input, [data-field] select').forEach(c => c.disabled = true);
                editing.row.querySelector('[data-save]').disabled = true;
                throw new Error('Editing lock lost. Draft remains visible; cancel and reopen to edit. ' + (state.editor ? 'Locked by ' + state.editor : ''));
            }
        } catch (e) {
            if (editing) {
                editing.row.querySelector('[data-lock]').textContent = 'Read only — lock could not be retained';
                editing.row.querySelectorAll('[data-field] input, [data-field] select').forEach(c => c.disabled = true);
                editing.row.querySelector('[data-save]').disabled = true;
            }
            error(e);
        } finally { busy = false; }
    }, 10000);

    document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const url = new URL(table.dataset.export, location.href);
            if (button.dataset.copy !== 'all') {
                const rows = [...table.querySelectorAll('tbody tr')].filter(row => button.dataset.copy === 'page' || row.querySelector('[data-select]').checked);
                if (!rows.length) throw new Error('Select at least one row to copy.');
                rows.forEach(row => url.searchParams.append('ids[]',row.dataset.id));
            }
            const response = await fetch(url,{credentials:'same-origin',signal:AbortSignal.timeout(30000)});
            if (!response.ok || response.redirected) throw new Error('Unable to copy records. Check your session and try again.');
            const text = await response.text();
            if (!(response.headers.get('content-type') || '').includes('text/tab-separated-values')) {
                throw new Error('Unexpected export response. No data was copied.');
            }
            if (!navigator.clipboard) throw new Error('Clipboard unavailable. Use the TSV download instead.');
            await navigator.clipboard.writeText(text);
            message.textContent = 'Saved records copied with column headings. Paste into a spreadsheet.';
            message.setAttribute('role', 'status');
        } catch (e) { error(e); } finally { button.disabled = false; }
    }));
    window.addEventListener('beforeunload',event => {
        if (editing) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pagehide',() => {
        if (!editing) return;
        const data = new FormData();
        data.set('_token',csrf()); data.set('token',editing.token);
        navigator.sendBeacon(editing.row.dataset.base + '/release',data);
    });
    window.addEventListener('pageshow',event => {
        if (event.persisted && editing) {
            editing.row.querySelectorAll('input,select,button[data-save]').forEach(c => c.disabled = true);
            error(new Error('Returned to an old editing page. Reload before editing.'));
        }
    });
})();
