(() => {
    'use strict';

    const root = document.documentElement;
    const isDialogElement = (value) => typeof Element !== 'undefined'
        && value instanceof Element
        && value.tagName === 'DIALOG';

    const openDialog = (dialog) => {
        if (!isDialogElement(dialog)) {
            return;
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        root.classList.add('audit-dialog-is-open');
    };

    const closeDialog = (dialog) => {
        if (!isDialogElement(dialog)) {
            return;
        }

        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
            root.classList.remove('audit-dialog-is-open');
        }
    };

    const tabButtons = Array.from(document.querySelectorAll('[data-audit-tab-target]'));
    const tabPanels = Array.from(document.querySelectorAll('.audit-tab-panel'));

    const activateTab = (tabKey, moveFocus = false) => {
        if (!tabKey) {
            return;
        }

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-audit-tab-target') === tabKey;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.tabIndex = isActive ? 0 : -1;
            if (isActive && moveFocus) {
                button.focus();
            }
        });

        tabPanels.forEach((panel) => {
            const isActive = panel.id === `audit-tab-panel-${tabKey}`;
            panel.classList.toggle('active', isActive);
            panel.hidden = !isActive;
        });
    };

    if (tabButtons.length > 0 && tabPanels.length > 0) {
        const preset = tabButtons.find((button) => button.classList.contains('active')) || tabButtons[0];
        activateTab(preset.getAttribute('data-audit-tab-target') || '', false);

        tabButtons.forEach((button, index) => {
            button.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                    return;
                }

                event.preventDefault();
                let nextIndex = index;
                if (event.key === 'ArrowRight') {
                    nextIndex = (index + 1) % tabButtons.length;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = (index - 1 + tabButtons.length) % tabButtons.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabButtons.length - 1;
                }

                const nextKey = tabButtons[nextIndex].getAttribute('data-audit-tab-target') || '';
                activateTab(nextKey, true);
            });
        });
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const tabButton = target.closest('[data-audit-tab-target]');
        if (tabButton) {
            activateTab(tabButton.getAttribute('data-audit-tab-target') || '', false);
            return;
        }

        const opener = target.closest('[data-audit-dialog]');
        if (opener) {
            const dialogId = opener.getAttribute('data-audit-dialog');
            const dialog = dialogId ? document.getElementById(dialogId) : null;
            if (isDialogElement(dialog)) {
                openDialog(dialog);
            }
            return;
        }

        const closer = target.closest('[data-audit-dialog-close]');
        if (closer) {
            closeDialog(closer.closest('dialog'));
        }
    });

    document.querySelectorAll('.audit-detail-dialog').forEach((dialog) => {
        dialog.addEventListener('close', () => {
            if (!document.querySelector('.audit-detail-dialog[open]')) {
                root.classList.remove('audit-dialog-is-open');
            }
        });

        dialog.addEventListener('cancel', () => {
            root.classList.remove('audit-dialog-is-open');
        });

        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) {
                return;
            }

            const bounds = dialog.getBoundingClientRect();
            const outside = event.clientX < bounds.left
                || event.clientX > bounds.right
                || event.clientY < bounds.top
                || event.clientY > bounds.bottom;

            if (outside) {
                closeDialog(dialog);
            }
        });
    });
})();
