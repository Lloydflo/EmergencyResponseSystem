// Location autocomplete with Barangay San Agustin Street Directory & Fast Geocoding
// Usage: attachPlaceAutocomplete(inputId, onSelect, options)
(function () {
    const DEFAULT_LIMIT = 12;

    function toNum(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
    }

    function parseCoordinateText(value) {
        const text = String(value || '').trim();
        const match = text.match(/^\s*(?:lat(?:itude)?\s*[:=]\s*)?(-?\d+(?:\.\d+)?)\s*[, ]\s*(?:lon(?:gitude)?\s*[:=]\s*)?(-?\d+(?:\.\d+)?)\s*$/i);
        if (!match) return null;
        const lat = toNum(match[1]);
        const lon = toNum(match[2]);
        if (lat === null || lon === null) return null;
        if (lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
        return { lat, lon };
    }

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function updateCoordinateFeedback(input, place) {
        if (!input) return;
        const parent = input.closest('.form-group') || input.parentElement;
        if (!parent) return;

        let badge = parent.querySelector('.location-coords-badge');
        let statusIcon = parent.querySelector('.location-status-icon');

        const lat = toNum(place && (place.lat || place.latitude));
        const lon = toNum(place && (place.lon || place.lng || place.longitude));

        if (lat !== null && lon !== null) {
            input.dataset.lat = String(lat);
            input.dataset.lon = String(lon);

            if (statusIcon) {
                statusIcon.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i>';
                statusIcon.title = `Coordinates locked: ${lat.toFixed(6)}, ${lon.toFixed(6)}`;
            }

            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'location-coords-badge';
                parent.appendChild(badge);
            }

            const areaTag = place.area ? ` <span class="badge-tag">${escapeHtml(place.area)}</span>` : '';
            badge.style.display = 'flex';
            badge.innerHTML = `
                <i class="fas fa-map-pin" style="color:#10b981; margin-right:6px;"></i>
                <span><strong>Coordinates locked:</strong> ${lat.toFixed(6)}, ${lon.toFixed(6)}${areaTag}</span>
            `;
        } else {
            delete input.dataset.lat;
            delete input.dataset.lon;

            if (statusIcon) {
                statusIcon.innerHTML = '<i class="fas fa-search-location" style="color:#94a3b8;"></i>';
                statusIcon.title = 'Searching location...';
            }

            if (badge) {
                badge.style.display = 'none';
            }
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Fast-path: query local San Agustin streets catalog first
    async function fetchSanAgustinStreets(query, signal) {
        try {
            const url = `api/san_agustin_streets.php?q=${encodeURIComponent(query)}`;
            const res = await fetch(url, { signal, headers: { Accept: 'application/json' } });
            if (!res.ok) return [];
            const data = await res.json();
            if (data && data.ok && Array.isArray(data.items)) {
                return data.items.map(item => ({
                    name: item.name,
                    area: item.area || 'San Agustin',
                    display_name: item.display_name,
                    lat: String(item.lat),
                    lon: String(item.lng),
                    importance: 2.0,
                    isSanAgustin: true
                }));
            }
        } catch (e) {
            // ignore abort or fetch errors
        }
        return [];
    }

    async function fetchPlaces(query, options, signal) {
        const input = normalizeText(query);
        if (!input) return [];

        // 1. Fetch San Agustin pre-mapped streets
        const localPromise = fetchSanAgustinStreets(input, signal);

        // 2. Fetch geocode proxy (with Nominatim/cache)
        const params = new URLSearchParams({
            q: input,
            limit: String(options.limit || DEFAULT_LIMIT)
        });
        const proxyPromise = fetch(`api/geocode_proxy.php?${params.toString()}`, {
            signal: signal,
            headers: { Accept: 'application/json' }
        }).then(r => r.ok ? r.json() : null).catch(() => null);

        const [localResults, proxyData] = await Promise.all([localPromise, proxyPromise]);
        const proxyList = (proxyData && proxyData.ok && Array.isArray(proxyData.items)) ? proxyData.items : [];

        // Merge results, prioritizing San Agustin catalog
        const combined = [];
        const seen = new Set();

        (localResults || []).forEach(item => {
            const key = (item.display_name || item.name || '').toLowerCase();
            if (key && !seen.has(key)) {
                seen.add(key);
                combined.push(item);
            }
        });

        (proxyList || []).forEach(item => {
            const key = (item.display_name || '').toLowerCase();
            if (key && !seen.has(key)) {
                seen.add(key);
                combined.push(item);
            }
        });

        return combined.slice(0, options.limit || DEFAULT_LIMIT);
    }

    function createDropdown(input) {
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown ers-street-dropdown';
        dropdown.setAttribute('role', 'listbox');

        const parent = input.closest('.location-input-wrapper') || input.parentElement;
        if (parent) {
            const currentPos = window.getComputedStyle(parent).position;
            if (!currentPos || currentPos === 'static') {
                parent.style.position = 'relative';
            }
            parent.appendChild(dropdown);
        }
        return dropdown;
    }

    function applyPlaceToInput(input, place) {
        if (!input || !place) return;
        const lat = toNum(place.lat);
        const lon = toNum(place.lon);
        if (lat !== null && lon !== null) {
            input.dataset.lat = String(lat);
            input.dataset.lon = String(lon);
        } else {
            delete input.dataset.lat;
            delete input.dataset.lon;
        }
        if (place.display_name) {
            input.value = String(place.display_name);
        } else if (place.name) {
            input.value = String(place.name);
        }
        updateCoordinateFeedback(input, place);
    }

    function attachPlaceAutocomplete(inputId, onSelect, options) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.dataset.placeAutocompleteAttached === '1') return;
        input.dataset.placeAutocompleteAttached = '1';

        const mergedOptions = Object.assign(
            {
                limit: DEFAULT_LIMIT,
                minChars: 1,
                debounceMs: 150
            },
            options || {}
        );

        let dropdown = null;
        let debounceTimer = null;
        let activeController = null;
        let requestSeq = 0;
        let selectedIndex = -1;
        let currentItems = [];

        function removeDropdown() {
            if (dropdown) {
                dropdown.remove();
                dropdown = null;
            }
            selectedIndex = -1;
            currentItems = [];
        }

        function renderMessage(text, isError = false) {
            if (!dropdown) return;
            dropdown.innerHTML = `
                <div class="ers-autocomplete-message ${isError ? 'error' : ''}">
                    <i class="fas ${isError ? 'fa-exclamation-triangle' : 'fa-spinner fa-spin'}"></i>
                    <span>${escapeHtml(text)}</span>
                </div>
            `;
        }

        function selectPlace(place) {
            applyPlaceToInput(input, place);
            if (typeof onSelect === 'function') {
                onSelect(place);
            }
            removeDropdown();
        }

        function highlightItem(index) {
            if (!dropdown) return;
            const elements = dropdown.querySelectorAll('.ers-autocomplete-item');
            elements.forEach((el, idx) => {
                if (idx === index) {
                    el.classList.add('selected');
                    el.scrollIntoView({ block: 'nearest' });
                } else {
                    el.classList.remove('selected');
                }
            });
            selectedIndex = index;
        }

        function renderItems(items, rawInput) {
            if (!dropdown) return;
            dropdown.innerHTML = '';
            currentItems = [];

            const directCoords = parseCoordinateText(rawInput);
            if (directCoords) {
                const coordItem = document.createElement('div');
                coordItem.className = 'ers-autocomplete-item ers-coord-item';
                coordItem.setAttribute('role', 'option');
                coordItem.innerHTML = `
                    <div class="item-icon"><i class="fas fa-crosshairs"></i></div>
                    <div class="item-body">
                        <div class="item-title">Use exact GPS coordinates</div>
                        <div class="item-subtitle">${directCoords.lat.toFixed(6)}, ${directCoords.lon.toFixed(6)}</div>
                    </div>
                `;
                const coordPlace = {
                    display_name: `${directCoords.lat.toFixed(6)}, ${directCoords.lon.toFixed(6)}`,
                    lat: directCoords.lat,
                    lon: directCoords.lon
                };
                coordItem.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectPlace(coordPlace);
                });
                dropdown.appendChild(coordItem);
                currentItems.push(coordPlace);
            }

            if (!items.length) {
                if (!directCoords) {
                    renderMessage('No matching streets found in San Agustin directory.');
                }
                return;
            }

            // Header for San Agustin streets
            const hasSanAgustin = items.some(i => i.isSanAgustin);
            if (hasSanAgustin) {
                const header = document.createElement('div');
                header.className = 'ers-autocomplete-header';
                header.innerHTML = '<i class="fas fa-shield-alt"></i> Barangay San Agustin Directory';
                dropdown.appendChild(header);
            }

            items.forEach((place) => {
                const item = document.createElement('div');
                item.className = `ers-autocomplete-item ${place.isSanAgustin ? 'san-agustin-item' : ''}`;
                item.setAttribute('role', 'option');

                const titleText = place.name || (place.display_name ? place.display_name.split(',')[0] : 'Unknown Location');
                const areaBadge = place.area ? `<span class="item-area-badge">${escapeHtml(place.area)}</span>` : '';
                const subText = place.display_name || '';
                const coordsText = (place.lat && place.lon) ? `${Number(place.lat).toFixed(4)}, ${Number(place.lon).toFixed(4)}` : '';

                item.innerHTML = `
                    <div class="item-icon">
                        <i class="fas ${place.isSanAgustin ? 'fa-map-pin' : 'fa-location-dot'}"></i>
                    </div>
                    <div class="item-body">
                        <div class="item-header-row">
                            <span class="item-title">${escapeHtml(titleText)}</span>
                            ${areaBadge}
                        </div>
                        <div class="item-subtitle">${escapeHtml(subText)}</div>
                    </div>
                    ${coordsText ? `<div class="item-coords" title="Coordinates"><i class="fas fa-satellite"></i> ${coordsText}</div>` : ''}
                `;

                item.addEventListener('mouseenter', function () {
                    const allItems = Array.from(dropdown.querySelectorAll('.ers-autocomplete-item'));
                    selectedIndex = allItems.indexOf(item);
                    allItems.forEach(el => el.classList.remove('selected'));
                    item.classList.add('selected');
                });

                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectPlace(place);
                });

                dropdown.appendChild(item);
                currentItems.push(place);
            });
        }

        async function runQuery(rawValue) {
            const value = normalizeText(rawValue);
            removeDropdown();

            if (value.length < mergedOptions.minChars) return;

            dropdown = createDropdown(input);
            renderMessage('Searching San Agustin streets & locations...');

            if (activeController) {
                activeController.abort();
            }
            activeController = new AbortController();
            const currentSeq = ++requestSeq;

            try {
                const items = await fetchPlaces(value, mergedOptions, activeController.signal);
                if (currentSeq !== requestSeq) return;
                renderItems(items, value);
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                if (currentSeq !== requestSeq) return;
                renderMessage('Suggestions unavailable. You can still type location manually.', true);
            }
        }

        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', function () {
            const value = input.value;
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                runQuery(value);
            }, mergedOptions.debounceMs);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= mergedOptions.minChars) {
                runQuery(input.value);
            }
        });

        input.addEventListener('keydown', function (e) {
            if (!dropdown) return;
            const items = dropdown.querySelectorAll('.ers-autocomplete-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const nextIndex = selectedIndex < items.length - 1 ? selectedIndex + 1 : 0;
                highlightItem(nextIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prevIndex = selectedIndex > 0 ? selectedIndex - 1 : items.length - 1;
                highlightItem(prevIndex);
            } else if (e.key === 'Enter') {
                if (selectedIndex >= 0 && selectedIndex < currentItems.length) {
                    e.preventDefault();
                    selectPlace(currentItems[selectedIndex]);
                }
            } else if (e.key === 'Escape') {
                removeDropdown();
            }
        });

        input.addEventListener('blur', function () {
            setTimeout(removeDropdown, 200);
        });
    }

    // Expose globally
    window.attachPlaceAutocomplete = attachPlaceAutocomplete;

    // Auto-attach for known fields
    window.addEventListener('DOMContentLoaded', function () {
        attachPlaceAutocomplete('incidentLocation');
        attachPlaceAutocomplete('modal-location-input');
        attachPlaceAutocomplete('search-location', function (place) {
            const lat = toNum(place && place.lat);
            const lon = toNum(place && (place.lon || place.lng));
            if (lat === null || lon === null) return;
            if (typeof window.focusMapToLocation === 'function') {
                window.focusMapToLocation(lat, lon);
            } else if (window.map) {
                window.map.setView([lat, lon], 16, { animate: true });
            }
        });
    });
})();
