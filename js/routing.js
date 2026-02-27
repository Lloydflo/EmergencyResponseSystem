// Routing helpers for Dispatch and GPS pages
// Requires Leaflet and Leaflet Routing Machine

let currentRoutingControl = null;

function addRouteToIncident(fromLat, fromLng, toLat, toLng) {
  try {
    if (!window.L || !window.map) {
      console.warn('Leaflet or map not initialized');
      return;
    }
    if (currentRoutingControl) {
      try { currentRoutingControl.remove(); } catch (e) {}
      currentRoutingControl = null;
    }
    currentRoutingControl = L.Routing.control({
      waypoints: [
        L.latLng(fromLat, fromLng),
        L.latLng(toLat, toLng)
      ],
      routeWhileDragging: false,
      show: false,
      addWaypoints: false,
      draggableWaypoints: false,
      fitSelectedRoutes: true
    }).addTo(map);
    showNotification('Route plotted to incident', 'success');
  } catch (e) {
    console.error('Routing error', e);
    showNotification('Unable to plot route', 'error');
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
