// login.js
// Handles loading state for the Sign In button

document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.login-form');
    if (!form) return;

    const signInBtn = form.querySelector('.btn-signin');
    if (!signInBtn) return;

    const signInText = signInBtn.querySelector('span');
    const privacyModal = document.getElementById('privacyModal');
    const privacyAgreedInput = document.getElementById('privacyAgreed');
    const privacyCheckbox = document.getElementById('privacyConsentCheckbox');
    const privacyAgreeBtn = document.getElementById('privacyAgreeBtn');
    const privacyCancelBtn = document.getElementById('privacyCancelBtn');
    let loadingSpan = null;

    function openPrivacyModal() {
        if (!privacyModal) return;
        privacyModal.classList.add('is-open');
        privacyModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('privacy-modal-open');
        if (privacyCheckbox) {
            privacyCheckbox.checked = false;
            privacyCheckbox.focus();
        }
        if (privacyAgreeBtn) {
            privacyAgreeBtn.disabled = true;
        }
    }

    function closePrivacyModal() {
        if (!privacyModal) return;
        privacyModal.classList.remove('is-open');
        privacyModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('privacy-modal-open');
    }

    if (privacyCheckbox && privacyAgreeBtn) {
        privacyCheckbox.addEventListener('change', function() {
            privacyAgreeBtn.disabled = !privacyCheckbox.checked;
        });
    }

    if (privacyCancelBtn) {
        privacyCancelBtn.addEventListener('click', closePrivacyModal);
    }

    if (privacyAgreeBtn) {
        privacyAgreeBtn.addEventListener('click', function() {
            if (!privacyCheckbox || !privacyCheckbox.checked) return;
            if (privacyAgreedInput) {
                privacyAgreedInput.value = '1';
            }
            closePrivacyModal();
            form.requestSubmit();
        });
    }

    if (privacyModal) {
        privacyModal.addEventListener('click', function(event) {
            if (event.target === privacyModal) {
                closePrivacyModal();
            }
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && privacyModal && privacyModal.classList.contains('is-open')) {
            closePrivacyModal();
        }
    });

    form.addEventListener('submit', function(event) {
        if (event.defaultPrevented) return;

        if (!privacyAgreedInput || privacyAgreedInput.value !== '1') {
            event.preventDefault();
            openPrivacyModal();
            return;
        }

        // Prevent multiple loading states
        if (!signInBtn.classList.contains('loading')) {
            signInBtn.classList.add('loading');
            signInBtn.disabled = true;
            signInText.textContent = 'Signing in';
            // Add spinner
            loadingSpan = document.createElement('span');
            loadingSpan.className = 'spinner';
            loadingSpan.style.marginLeft = '8px';
            loadingSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            signInBtn.appendChild(loadingSpan);
        }
    });
});
