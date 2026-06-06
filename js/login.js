// login.js
// Handles loading state for the Sign In button

document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.login-form');
    if (!form) return;

    const signInBtn = form.querySelector('.btn-signin');
    if (!signInBtn) return;

    const signInText = signInBtn.querySelector('span');
    let loadingSpan = null;

    form.addEventListener('submit', function(event) {
        if (event.defaultPrevented) return;

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
