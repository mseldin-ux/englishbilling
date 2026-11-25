'use strict';

document.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox' || input.name !== 'hasenrolled') return;
    const explanation = input.checked
        ? 'Mark this learner as enrolled? They will appear in browse and financial reports. Detailed-view saves will require enrollment information.'
        : 'Mark this learner as not enrolled? They and their financial activity will be excluded from existing financial reports and from normal browse, but remain in Student Records and can be found by name or ID.';
    if (!window.confirm(explanation)) {
        input.checked = !input.checked;
        event.stopImmediatePropagation();
    }
}, true);
