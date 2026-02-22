// Location autocomplete using Nominatim API with Quezon City bias.
// Usage: attachPlaceAutocomplete(inputId, onSelect, options)
(function () {
    const QC_VIEWBOX = '121.0000,14.7500,121.1000,14.6000'; // left,top,right,bottom
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

    function buildSearchParams(query, options) {
        const params = new URLSearchParams({
            format: 'jsonv2',
            addressdetails: '1',
            limit: String(options.limit || DEFAULT_LIMIT),
            countrycodes: 'ph',
            q: query
        });
        if (options.preferViewbox || options.strictViewbox) {
            params.set('viewbox', QC_VIEWBOX);
        }
        if (options.strictViewbox) {
            params.set('bounded', '1');
        }
        return params;
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

        const localizedQuery = hasQcContext(input)
            ? input
            : `${input}, Quezon City, Metro Manila, Philippines`;

        const attempts = [
            { q: localizedQuery, strict: !!options.strictViewbox },
            { q: localizedQuery, strict: false },
            { q: input, strict: false }
        ];

        let last = [];
        for (let i = 0; i < attempts.length; i += 1) {
            const attempt = attempts[i];
            const params = buildSearchParams(attempt.q, {
                limit: options.limit,
                preferViewbox: true,
                strictViewbox: attempt.strict
            });
            const url = `https://nominatim.openstreetmap.org/search?${params.toString()}`;
            const res = await fetch(url, {
                signal: signal,
                headers: { Accept: 'application/json' }
            });
            if (!res.ok) {
                continue;
            }
            const data = await res.json();
            if (!Array.isArray(data) || !data.length) {
                continue;
            }
            last = data;
            if (attempt.strict) {
                break;
            }
            if (data.some((p) => String(p.display_name || '').toLowerCase().includes('quezon city'))) {
                break;
            }
        }

        return dedupePlaces(last)
            .sort((a, b) => scoreSuggestion(b, input) - scoreSuggestion(a, input))
            .slice(0, options.limit || DEFAULT_LIMIT);
    }

    function createDropdown(input) {
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.position = 'absolute';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid #e5e7eb';
        dropdown.style.zIndex = 2000;
        dropdown.style.width = input.offsetWidth + 'px';
        dropdown.style.maxHeight = '180px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
        const parent = input.parentElement;
        if (parent) {
            parent.style.position = 'relative';
            dropdown.style.left = '0px';
            dropdown.style.top = (input.offsetTop + input.offsetHeight) + 'px';
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
            dropdown.innerHTML = `<div style="padding:8px 12px;color:#888;">${text}</div>`;
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
                coordItem.style.padding = '8px 12px';
                coordItem.style.cursor = 'pointer';
                coordItem.textContent = `Use coordinates: ${directCoords.lat.toFixed(6)}, ${directCoords.lon.toFixed(6)}`;
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
                item.style.padding = '8px 12px';
                item.style.cursor = 'pointer';
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
                renderMessage('Error loading suggestions. Try again.');
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
