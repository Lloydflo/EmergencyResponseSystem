// Simple place autocomplete using Nominatim API
// Usage: attachPlaceAutocomplete(inputId, onSelect)
function attachPlaceAutocomplete(inputId, onSelect) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    let dropdown;
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', async function() {
        const val = input.value.trim();
        delete input.dataset.lat;
        delete input.dataset.lon;
        if (dropdown) dropdown.remove();
        if (val.length < 3) {
            return;
        }
        dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.position = 'absolute';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid #e5e7eb';
        dropdown.style.zIndex = 2000;
        dropdown.style.width = input.offsetWidth + 'px';
        dropdown.style.maxHeight = '180px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
        dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">Loading...</div>';
        const parent = input.parentElement;
        parent.style.position = 'relative';
        dropdown.style.left = 0;
        dropdown.style.top = (input.offsetTop + input.offsetHeight) + 'px';
        parent.appendChild(dropdown);

        try {
            const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=PH&q=${encodeURIComponent(val)}`;
            try {
                const res = await fetch(url);
                const data = await res.json();
                dropdown.innerHTML = '';
                if (data.length === 0) {
                    const noRes = document.createElement('div');
                    noRes.textContent = 'No results found';
                    noRes.style.color = '#888';
                    noRes.style.padding = '8px 12px';
                    dropdown.appendChild(noRes);
                } else {
                    data.slice(0, 6).forEach(place => {
                        const item = document.createElement('div');
                        item.textContent = place.display_name;
                        item.style.padding = '8px 12px';
                        item.style.cursor = 'pointer';
                        item.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            input.value = place.display_name;
                            if (place.lat && place.lon) {
                                input.dataset.lat = String(place.lat);
                                input.dataset.lon = String(place.lon);
                            }
                            if (onSelect) onSelect(place);
                            dropdown.remove();
                        });
                        dropdown.appendChild(item);
                    });
                }
            } catch (e) {
                console.error('Suggestion fetch error:', e);
                dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">Error loading suggestions. Please check your internet connection or try again later.</div>';
            }
        } catch (e) {
            console.error('Autocomplete error:', e);
            dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">Error initializing suggestions</div>';
        }
    });
    input.addEventListener('blur', function() {
        setTimeout(() => { if (dropdown) dropdown.remove(); }, 150);
    });
}

// Attach to incidentLocation on DOMContentLoaded
window.addEventListener('DOMContentLoaded', function() {
    attachPlaceAutocomplete('incidentLocation');
    // Attach to search-location for GPS map
    if (document.getElementById('search-location')) {
        attachPlaceAutocomplete('search-location', function(place) {
            // If GPS map exists and place has lat/lon, zoom to it
            if (window.map && place && place.lat && place.lon) {
                window.map.setView([parseFloat(place.lat), parseFloat(place.lon)], 16, { animate: true });
            }
        });
    }
});
