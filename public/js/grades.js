(() => {
    const form = document.getElementById('grades-form');
    if (!form) {
        return;
    }
    const program = document.getElementById('program-id');
    const notice = document.getElementById('existing-sheets');
    const duplicate = document.getElementById('duplicate-sheet');
    const prompt = document.getElementById('choose-program');
    const buttons = form.querySelectorAll('button[type="submit"]');
    let sheets = [];
    let request = 0;

    const sameProgram = () => (program ? sheets.find((sheet) => String(sheet.program_id) === program.value) : null);

    const refreshDuplicate = () => {
        if (!duplicate) {
            return;
        }
        const match = sameProgram();
        duplicate.hidden = !match;
        duplicate.replaceChildren();
        if (match) {
            const link = document.createElement('a');
            link.href = match.url;
            link.textContent = `open sheet ${match.id}`;
            duplicate.append(`This learner already has a ${match.status === 'submitted' ? 'submitted' : 'draft'} grade sheet for this program — `, link, ' instead.');
        }
        buttons.forEach((button) => { button.disabled = Boolean(match); });
    };

    const showProgram = () => {
        if (!program) {
            return;
        }
        form.querySelectorAll('.grade-items').forEach((group) => {
            const shown = group.dataset.program === program.value;
            group.hidden = !shown;
            group.querySelectorAll('input').forEach((input) => { input.disabled = !shown; });
        });
        if (prompt) {
            prompt.hidden = program.value !== '';
        }
        refreshDuplicate();
    };

    const render = () => {
        notice.replaceChildren();
        notice.hidden = sheets.length === 0;
        if (sheets.length === 0) {
            return;
        }
        const heading = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = 'This learner already has grade sheets. Open an existing sheet rather than starting over:';
        heading.append(strong);
        const list = document.createElement('ul');
        sheets.forEach((sheet, index) => {
            const item = document.createElement('li');
            item.dataset.program = sheet.program_id;
            const link = document.createElement('a');
            link.href = sheet.url;
            link.textContent = `Sheet ${sheet.id} — ${sheet.program || 'Unknown program'} — ${sheet.status === 'submitted' ? 'Submitted' : 'Draft'}, updated ${sheet.updated_at}`;
            item.append(link);
            if (index === 0) {
                item.append(' (most recent)');
            }
            list.append(item);
        });
        notice.append(heading, list);
    };

    const load = async (studentId) => {
        const current = ++request;
        sheets = [];
        render();
        refreshDuplicate();
        if (!studentId) {
            return;
        }
        try {
            const url = new URL(notice.dataset.url, window.location.href);
            url.searchParams.set('student', studentId);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok || current !== request) {
                return;
            }
            sheets = (await response.json()).sheets.filter((sheet) => String(sheet.id) !== notice.dataset.current);
            render();
            refreshDuplicate();
        } catch {
            // The server still rejects duplicate sheets if this lookup fails.
        }
    };

    if (program) {
        program.addEventListener('change', showProgram);
        document.addEventListener('learner-selected', (event) => load(event.detail.id));
        const learner = document.getElementById('learner-id');
        showProgram();
        if (learner && learner.value) {
            load(learner.value);
        }
    }
})();
