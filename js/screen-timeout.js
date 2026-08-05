// screen-timeout.js
// Adds a screen timeout overlay and warning after 10 minutes of inactivity


console.log("Screen timeout loaded");
document.addEventListener('DOMContentLoaded', function() {
    const TIMEOUT = 10 * 60 * 1000; // 10 minutes in milliseconds
    let timeoutId;
    let overlay = null;
    let warning = null;

    function createOverlay() {
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'screen-timeout-overlay';
            overlay.style.position = 'fixed';
            overlay.style.top = 0;
            overlay.style.left = 0;
            overlay.style.width = '100vw';
            overlay.style.height = '100vh';
            overlay.style.background = 'rgba(0,0,0,0.6)';
            overlay.style.zIndex = 9999;
            overlay.style.display = 'flex';
            overlay.style.justifyContent = 'center';
            overlay.style.alignItems = 'center';
            overlay.style.transition = 'opacity 0.3s';
            overlay.style.opacity = '1';
            overlay.style.pointerEvents = 'auto';
            overlay.style.flexDirection = 'column';
            document.body.appendChild(overlay);
        }
    }

    function createWarning() {
        if (!warning) {
            warning = document.createElement('div');
            warning.id = 'screen-timeout-warning';
            warning.style.background = '#fff';
            warning.style.color = '#222';
            warning.style.padding = '2rem 3rem';
            warning.style.borderRadius = '12px';
            warning.style.boxShadow = '0 2px 16px rgba(0,0,0,0.2)';
            warning.style.fontSize = '1.3rem';
            warning.style.fontWeight = 'bold';
            warning.innerText = 'You have been inactive for 10 minutes. Move your mouse or press any key to continue.';
            overlay.appendChild(warning);
        }
    }

    function showTimeout() {
        createOverlay();
        createWarning();
        overlay.style.display = 'flex';
    }

    function hideTimeout() {
        if (overlay) overlay.style.display = 'none';
    }

    function resetTimeout() {
        hideTimeout();
        clearTimeout(timeoutId);
        timeoutId = setTimeout(showTimeout, TIMEOUT);
    }

    ['mousemove', 'keydown', 'mousedown', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, resetTimeout, true);
    });

    resetTimeout();
});
