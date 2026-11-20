(() => {
    const search = document.getElementById('learner-search');
    if (!search) {
        return;
    }
    const results = document.getElementById('learner-results');
    const hidden = document.getElementById('learner-id');
    const selected = document.getElementById('learner-selected');
    const program = document.getElementById('program-id');
    const enrollment = document.getElementById('program-enrollment');
    let enrollments = enrollment ? JSON.parse(enrollment.dataset.options) : [];
    const showEnrollments = (preferred) => {
        if (!enrollment) return;
        const options = enrollments.filter(option => String(option.program_id) === program.value);
        enrollment.replaceChildren(new Option('Original / legacy program (no tracked enrollment)', ''));
        options.forEach(option => enrollment.add(new Option(option.label, option.id)));
        const selected = preferred !== undefined ? options.find(option => String(option.id) === String(preferred))
            : options.find(option => option.current);
        enrollment.value = selected ? String(selected.id) : '';
        enrollment.dispatchEvent(new Event('change', { bubbles: true }));
    };
    if (enrollment) {
        program.addEventListener('change', () => showEnrollments());
        showEnrollments(enrollment.dataset.selected);
    }
    let timer = null;
    let request = 0;

    const clear = () => results.replaceChildren();

    const choose = (learner) => {
        enrollments = learner.enrollments || [];
        hidden.value = learner.id;
        const strong = document.createElement('strong');
        strong.textContent = learner.name;
        selected.replaceChildren(strong, document.createTextNode(` — Cohort ${learner.cohort || '(none)'} — User ID ${learner.id}`));
        if (program && !program.value && learner.program_id !== null
            && program.querySelector(`option[value="${String(learner.program_id)}"]`)) {
            program.value = String(learner.program_id);
            program.dispatchEvent(new Event('change', { bubbles: true }));
        }
        search.value = '';
        clear();
        showEnrollments();
        document.dispatchEvent(new CustomEvent('learner-selected', { detail: learner }));
    };

    const lookup = async () => {
        const term = search.value.trim();
        if (term.length < 2 && !/^\d+$/.test(term)) {
            clear();
            return;
        }
        const current = ++request;
        try {
            const url = new URL(search.dataset.url, window.location.href);
            url.searchParams.set('q', term);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok || current !== request) {
                return;
            }
            const { learners } = await response.json();
            clear();
            if (learners.length === 0) {
                const item = document.createElement('li');
                item.textContent = 'No matching learners.';
                results.append(item);
                return;
            }
            for (const learner of learners) {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.setAttribute('role', 'option');
                button.textContent = `${learner.name} — ${learner.cohort || '(no cohort)'} — User ID ${learner.id}`;
                button.addEventListener('click', () => choose(learner));
                item.append(button);
                results.append(item);
            }
        } catch {
            // A failed lookup leaves the current selection unchanged.
        }
    };

    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(lookup, 250);
    });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(timer);
            lookup();
        }
    });
})();
