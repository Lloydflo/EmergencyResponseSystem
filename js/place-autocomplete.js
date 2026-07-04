// Location autocomplete via backend geocode proxy (Nominatim + cache).
// Usage: attachPlaceAutocomplete(inputId, onSelect, options)
(function () {
    const DEFAULT_LIMIT = 6;

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

    function hasQcContext(text) {
        return /(quezon city|qc|metro manila|philippines)\b/i.test(text);
    }

    function buildProxyParams(query, options) {
        return new URLSearchParams({
            q: query,
            limit: String(options.limit || DEFAULT_LIMIT),
            strict: options.strictViewbox ? '1' : '0'
        });
    }

    function scoreSuggestion(place, query) {
        const label = String(place.display_name || '').toLowerCase();
        const q = String(query || '').toLowerCase();
        let score = toNum(place.importance) || 0;
        if (label.includes('quezon city')) score += 2;
        if (label.includes(q)) score += 1.5;
        return score;
    }

    function dedupePlaces(items) {
        const out = [];
        const seen = {};
        (items || []).forEach((item) => {
            const key = String(item.display_name || '').toLowerCase();
            if (!key || seen[key]) return;
            seen[key] = true;
            out.push(item);
        });
        return out;
    }

    async function fetchPlaces(query, options, signal) {
        const input = normalizeText(query);
        if (!input) return [];

        const localizedQuery = hasQcContext(input) ? input : `${input}, Quezon City`;
        const params = buildProxyParams(localizedQuery, options);
        const url = `api/geocode_proxy.php?${params.toString()}`;
        const res = await fetch(url, {
            signal: signal,
            headers: { Accept: 'application/json' }
        });
        if (!res.ok) {
            return [];
        }
        const payload = await res.json();
        const list = (payload && payload.ok && Array.isArray(payload.items)) ? payload.items : [];

        return dedupePlaces(list)
            .sort((a, b) => scoreSuggestion(b, input) - scoreSuggestion(a, input))
            .slice(0, options.limit || DEFAULT_LIMIT);
    }

    function createDropdown(input) {
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.position = 'absolute';
        dropdown.style.background = '#ffffff';
        dropdown.style.border = '1px solid #cbd5e1';
        dropdown.style.borderRadius = '8px';
        dropdown.style.zIndex = 2000;
        dropdown.style.width = input.offsetWidth + 'px';
        dropdown.style.maxHeight = '220px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.boxShadow = '0 10px 30px rgba(0,0,0,0.15)';
        const parent = input.parentElement;
        if (parent) {
            parent.style.position = 'relative';
            dropdown.style.left = '0px';
            dropdown.style.top = (input.offsetTop + input.offsetHeight + 4) + 'px';
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
        }
    }

    function attachPlaceAutocomplete(inputId, onSelect, options) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.dataset.placeAutocompleteAttached === '1') return;
        input.dataset.placeAutocompleteAttached = '1';

        const mergedOptions = Object.assign(
            {
                limit: DEFAULT_LIMIT,
                strictViewbox: inputId === 'search-location',
                preferViewbox: true,
                minChars: 3,
                debounceMs: 220
            },
            options || {}
        );

        let dropdown = null;
        let debounceTimer = null;
        let activeController = null;
        let requestSeq = 0;

        function removeDropdown() {
            if (dropdown) {
                dropdown.remove();
                dropdown = null;
            }
        }

        function renderMessage(text) {
            if (!dropdown) return;
            dropdown.innerHTML = `<div style="padding:12px 14px; color:#64748b; font-size:0.9rem; font-weight:500; text-align:center;">${text}</div>`;
        }

        function selectPlace(place) {
            applyPlaceToInput(input, place);
            if (typeof onSelect === 'function') {
                onSelect(place);
            }
            removeDropdown();
        }

        function renderItems(items, rawInput) {
            if (!dropdown) return;
            dropdown.innerHTML = '';

            const directCoords = parseCoordinateText(rawInput);
            if (directCoords) {
                const coordItem = document.createElement('div');
                coordItem.style.padding = '10px 14px';
                coordItem.style.cursor = 'pointer';
                coordItem.style.color = '#1f2937';
                coordItem.style.fontSize = '0.95rem';
                coordItem.style.borderBottom = '1px solid #f0f0f0';
                coordItem.style.fontWeight = '500';
                coordItem.style.transition = 'background-color 0.2s ease';
                coordItem.textContent = `Use coordinates: ${directCoords.lat.toFixed(6)}, ${directCoords.lon.toFixed(6)}`;
                coordItem.addEventListener('mouseenter', function () {
                    coordItem.style.backgroundColor = '#e0f2fe';
                });
                coordItem.addEventListener('mouseleave', function () {
                    coordItem.style.backgroundColor = 'transparent';
                });
                coordItem.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectPlace({
                        display_name: `${directCoords.lat.toFixed(6)}, ${directCoords.lon.toFixed(6)}`,
                        lat: directCoords.lat,
                        lon: directCoords.lon
                    });
                });
                dropdown.appendChild(coordItem);
            }

            if (!items.length) {
                if (!directCoords) {
                    renderMessage('No results found');
                }
                return;
            }

            items.forEach((place) => {
                const item = document.createElement('div');
                item.textContent = place.display_name || '';
                item.style.padding = '10px 14px';
                item.style.cursor = 'pointer';
                item.style.color = '#1f2937';
                item.style.fontSize = '0.95rem';
                item.style.borderBottom = '1px solid #f0f0f0';
                item.style.transition = 'background-color 0.2s ease';
                item.addEventListener('mouseenter', function () {
                    item.style.backgroundColor = '#e0f2fe';
                });
                item.addEventListener('mouseleave', function () {
                    item.style.backgroundColor = 'transparent';
                });
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectPlace(place);
                });
                dropdown.appendChild(item);
            });
        }

        async function runQuery(rawValue) {
            const value = normalizeText(rawValue);
            delete input.dataset.lat;
            delete input.dataset.lon;
            removeDropdown();

            if (value.length < mergedOptions.minChars) return;

            dropdown = createDropdown(input);
            renderMessage('Loading...');

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
                renderMessage('Suggestions unavailable. You can still type location manually.');
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
        input.addEventListener('blur', function () {
            setTimeout(removeDropdown, 160);
        });
    }

    // Expose globally
    window.attachPlaceAutocomplete = attachPlaceAutocomplete;

    // Auto-attach for known fields
    window.addEventListener('DOMContentLoaded', function () {
        attachPlaceAutocomplete('incidentLocation', null, {
            strictViewbox: false,
            preferViewbox: true
        });
        attachPlaceAutocomplete('search-location', function (place) {
            const lat = toNum(place && place.lat);
            const lon = toNum(place && place.lon);
            if (lat === null || lon === null) return;
            if (typeof window.focusMapToLocation === 'function') {
                window.focusMapToLocation(lat, lon);
            } else if (window.map) {
                window.map.setView([lat, lon], 16, { animate: true });
            }
        }, {
            strictViewbox: true,
            preferViewbox: true
        });
    });
})();
