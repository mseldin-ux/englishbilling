// Opens and closes the stage guide dialogs on a workflow's page.
document.addEventListener('click', event => {
    const target = event.target.closest('button');
    if (target?.dataset.stageInfo) document.getElementById(target.dataset.stageInfo)?.showModal();
    if (target?.hasAttribute('data-stage-info-close')) target.closest('dialog').close();
    // A click on the backdrop closes the dialog.
    const dialog = event.target;
    if (dialog instanceof HTMLDialogElement && dialog.classList.contains('stage-guide')) {
        const box = dialog.getBoundingClientRect();
        const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
        if (outside) dialog.close();
    }
});
