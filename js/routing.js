// Routing helpers for Dispatch and GPS pages
// Requires Leaflet and Leaflet Routing Machine

let currentRoutingControl = null;
window.currentRoutingControl = null;

function addRouteToIncident(fromLat, fromLng, toLat, toLng, options) {
  const silent = !!(options && options.silent);
  try {
    if (!window.L || !window.map) {
      console.warn('Leaflet or map not initialized');
      return;
    }
    const startLat = Number(fromLat);
    const startLng = Number(fromLng);
    const endLat = Number(toLat);
    const endLng = Number(toLng);
    if (![startLat, startLng, endLat, endLng].every(Number.isFinite)) {
      if (!silent) {
        showNotification('Unable to plot route: invalid coordinates', 'error');
      }
      return;
    }
    if (currentRoutingControl) {
      try { currentRoutingControl.remove(); } catch (e) {}
      currentRoutingControl = null;
      window.currentRoutingControl = null;
    }
    currentRoutingControl = L.Routing.control({
      waypoints: [
        L.latLng(startLat, startLng),
        L.latLng(endLat, endLng)
      ],
      routeWhileDragging: false,
      show: false,
      addWaypoints: false,
      draggableWaypoints: false,
      showAlternatives: false,
      fitSelectedRoutes: true,
      lineOptions: {
        styles: [
          { color: '#0f172a', opacity: 0.25, weight: 8 },
          { color: '#2563eb', opacity: 0.95, weight: 5 }
        ]
      }
    }).addTo(window.map);
    currentRoutingControl.on('routingerror', () => {
      if (!silent) {
        showNotification('Unable to plot road route', 'error');
      }
    });
    window.currentRoutingControl = currentRoutingControl;
    if (!silent) {
      showNotification('Route plotted to incident', 'success');
    }
  } catch (e) {
    console.error('Routing error', e);
    if (!silent) {
      showNotification('Unable to plot route', 'error');
    }
  }
}

function showNotification(message, type) {
  // Minimal toast-style notification
  const colors = {
    success: '#28a745',
    error: '#dc3545',
    warning: '#ffc107',
    info: '#007bff'
  };
  const toast = document.createElement('div');
  toast.textContent = message;
  toast.style.position = 'fixed';
  toast.style.bottom = '20px';
  toast.style.right = '20px';
  toast.style.padding = '10px 14px';
  toast.style.borderRadius = '6px';
  toast.style.background = colors[type] || '#333';
  toast.style.color = '#fff';
  toast.style.fontSize = '14px';
  toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
  toast.style.zIndex = '10000';
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity .3s';
    setTimeout(() => toast.remove(), 300);
  }, 2000);
}
