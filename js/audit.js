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

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
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
