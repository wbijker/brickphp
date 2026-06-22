// Brick runtime
var Brick = (function() {
    // --- State management ---
    var stateHandlers = {};

    function addStateHandler(name, handler) {
        if (typeof handler.load !== 'function' ||
            typeof handler.save !== 'function' ||
            typeof handler.clear !== 'function') {
            console.error('Brick: State handler must have load, save, and clear functions');
            return;
        }
        stateHandlers[name] = handler;
    }

    function getState(name, path) {
        var handler = stateHandlers[name];
        if (!handler) return {};
        var all = handler.load();
        return all[path] || {};
    }

    function saveState(name, path, state) {
        var handler = stateHandlers[name];
        if (!handler) return;
        var all = handler.load();
        all[path] = state;
        handler.save(all);
    }

    function getAll() {
        var result = {};
        var hasAny = false;
        for (var name in stateHandlers) {
            result[name] = stateHandlers[name].load();
            hasAny = true;
        }
        return hasAny ? result : null;
    }

    function setAll(state) {
        if (!state) return;
        for (var name in state) {
            if (stateHandlers[name]) {
                stateHandlers[name].save(state[name]);
            }
        }
    }

    function isStateEnabled() {
        for (var name in stateHandlers) {
            return true;
        }
        return false;
    }

    /**
     * Fire an empty request at the server, run the response through the
     * patcher. Used to pull in changes that aren't tied to a user event —
     * external data, server-side state that drifted, time-dependent UI
     * that's been marked invalidate()/invalidateAttr(). Same wire shape as
     * any other event, so hash echo, replay-on-reload, and bindings all
     * work transparently.
     */
    function refresh() {
        post({ event: '', path: '', value: null });
    }

    // Run `fn` after DOMContentLoaded — or immediately if the document
    // has already finished parsing. Used by Js::domReady() on the
    // server to defer initial-GET head-script work that depends on the
    // body being in place.
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    // Server now sends a flat array of JS statement strings (built by
    // JsExpression::toJs on the PHP side), each safe to evaluate as a
    // top-level expression. new Function() avoids leaking the enclosing
    // closure's locals and keeps eval out of strict mode.
    function executeJsDump(dump) {
        for (const stmt of dump) {
            new Function(stmt)();
        }
    }

    function findNodeByPath(path, includeTextNodes = false) {
        // Start from body's first child (the root element)
        let node = document.body.firstElementChild;
        for (let i = 0; i < path.length; i++) {
            if (!node) return null;
            const index = path[i];
            if (includeTextNodes) {
                // Use childNodes to include text nodes
                node = node.childNodes[index];
            } else {
                // Use children for elements only
                node = node.children[index];
            }
        }
        return node;
    }

    function applyPatches(patches) {
        for (const patch of patches) {
            const path = patch.path;

            switch (patch.type) {
                case 'replace_node': {
                    const node = findNodeByPath(path);
                    if (node) {
                        cleanupListeners(node);
                        const temp = document.createElement('div');
                        temp.innerHTML = patch.html;
                        node.replaceWith(temp.firstElementChild);
                    }
                    break;
                }
                case 'insert_node': {
                    const parentPath = path.slice(0, -1);
                    const index = path[path.length - 1];
                    const parent = parentPath.length === 0
                        ? document.body.firstElementChild
                        : findNodeByPath(parentPath);
                    if (parent) {
                        const temp = document.createElement('div');
                        temp.innerHTML = patch.html;
                        const newNode = temp.firstElementChild;
                        if (index >= parent.children.length) {
                            parent.appendChild(newNode);
                        } else {
                            parent.insertBefore(newNode, parent.children[index]);
                        }
                    }
                    break;
                }
                case 'delete_node': {
                    const node = findNodeByPath(path);
                    if (node) {
                        cleanupListeners(node);
                        node.remove();
                    }
                    break;
                }
                case 'set_attribute': {
                    const node = findNodeByPath(path);
                    if (node) {
                        node.setAttribute(patch.name, patch.value);
                        // For form controls, the `value` attribute only sets the default;
                        // the displayed value is the `.value` property once the user has typed.
                        if (patch.name === 'value' && 'value' in node) {
                            node.value = patch.value;
                            // Keep the localStorage draft in sync with the
                            // server-pushed value (empty string → drop entry).
                            if (node.hasAttribute && node.hasAttribute('data-bind')) {
                                captureDraft(node);
                            }
                        } else if (patch.name === 'checked' && 'checked' in node) {
                            node.checked = patch.value === 'checked' || patch.value === true;
                        }
                    }
                    break;
                }
                case 'remove_attribute': {
                    const node = findNodeByPath(path);
                    if (node) {
                        node.removeAttribute(patch.name);
                    }
                    break;
                }
                case 'replace_text': {
                    const node = findNodeByPath(path);
                    if (node) {
                        node.textContent = patch.text;
                    }
                    break;
                }
                case 'insert_at': {
                    // Insert child at specific index in a list
                    const parent = findNodeByPath(path);
                    if (parent) {
                        const temp = document.createElement('div');
                        temp.innerHTML = patch.html;
                        const newNode = temp.firstElementChild;
                        const index = patch.index;
                        if (index >= parent.children.length) {
                            parent.appendChild(newNode);
                        } else {
                            parent.insertBefore(newNode, parent.children[index]);
                        }
                    }
                    break;
                }
                case 'remove_at': {
                    // Remove child at specific index in a list
                    const parent = findNodeByPath(path);
                    if (parent && parent.children[patch.index]) {
                        cleanupListeners(parent.children[patch.index]);
                        parent.children[patch.index].remove();
                    }
                    break;
                }
                case 'update_at': {
                    // Replace child at specific index in a list
                    const parent = findNodeByPath(path);
                    if (parent && parent.children[patch.index]) {
                        cleanupListeners(parent.children[patch.index]);
                        const temp = document.createElement('div');
                        temp.innerHTML = patch.html;
                        parent.children[patch.index].replaceWith(temp.firstElementChild);
                    }
                    break;
                }
            }
        }
    }

    // --- Replay-after-reload constants (used by callback, maybeReplay, post) ---
    var BRICK_REPLAY_KEY = '__brick_replay';
    var BRICK_REPLAY_COUNT_KEY = '__brick_replay_count';
    var BRICK_MAX_REPLAYS = 2;
    var __brick_lastPayload = null;

    // The path the current DOM was last rendered for. Sent on every POST as
    // `previousPath` so the server can render OLD against the URL the user
    // actually has on screen, not whatever location.href happens to be at the
    // moment of the request (which differs after popstate). Updated after
    // every successful response so it tracks pushState navigations too.
    var __brick_renderedPath = location.pathname + location.search;

    // --- Value bindings ---
    function initBindings() {
        document.querySelectorAll('[data-bind]').forEach(function(el) {
            if (el._brickBound) return;
            el._brickBound = true;

            // Persist every keystroke to localStorage. If the tab is reloaded
            // before the user submits, restoreDrafts() will repopulate it.
            el.addEventListener('input', function () { captureDraft(el); });

            // Browsers fire `change` on Enter only when the value differs
            // from the value-at-focus. If the field was prefilled by us
            // (autofocus, draft restore, server set_attribute), pressing
            // Enter without typing would silently do nothing. Track the
            // baseline and synthesise a change event ourselves in that case.
            el.addEventListener('focus', function () {
                el._brickFocusValue = el.value;
            });
            el.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                var baseline = el._brickFocusValue !== undefined
                    ? el._brickFocusValue
                    : el.defaultValue;
                if (el.value === baseline) {
                    el.dispatchEvent(new Event('change'));
                }
            });

            // If the element is already focused at attach time (autofocus
            // fired before bootstrap), seed the baseline now.
            if (document.activeElement === el) {
                el._brickFocusValue = el.value;
            }
        });
    }

    function collectBindings() {
        var bindings = {};
        document.querySelectorAll('[data-bind]').forEach(function(el) {
            bindings[computePath(el)] = el.value;
        });
        return Object.keys(bindings).length > 0 ? bindings : null;
    }

    // --- Draft persistence for bound inputs (localStorage) ---
    // Save the value of every bound input on the `input` event, keyed by its
    // computed path. On page load, walk the bound inputs again and apply any
    // saved draft. Drafts also follow server-driven value changes so a
    // re-render that pushes value="" wipes the draft instead of resurrecting
    // it on the next reload.
    var BRICK_DRAFTS_KEY = '__brick_drafts';

    function loadDrafts() {
        try {
            var raw = localStorage.getItem(BRICK_DRAFTS_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveDrafts(drafts) {
        try {
            if (Object.keys(drafts).length === 0) {
                localStorage.removeItem(BRICK_DRAFTS_KEY);
            } else {
                localStorage.setItem(BRICK_DRAFTS_KEY, JSON.stringify(drafts));
            }
        } catch (e) { /* quota / disabled — give up silently */ }
    }

    function captureDraft(el) {
        var path = computePath(el);
        if (!path) return;
        var drafts = loadDrafts();
        if (el.value === '' || el.value == null) {
            delete drafts[path];
        } else {
            drafts[path] = el.value;
        }
        saveDrafts(drafts);
    }

    function restoreDrafts() {
        var drafts = loadDrafts();
        if (Object.keys(drafts).length === 0) return;
        document.querySelectorAll('[data-bind]').forEach(function(el) {
            var path = computePath(el);
            if (Object.prototype.hasOwnProperty.call(drafts, path)) {
                el.value = drafts[path];
                // Mirror the focus-baseline so a first Enter is recognized as
                // a no-op-since-focus and the keydown handler synthesises a
                // change event.
                el._brickFocusValue = el.value;
            }
        });
    }

    function maybeReplay() {
        var stored = sessionStorage.getItem(BRICK_REPLAY_KEY);
        if (!stored) return;
        // Clear the payload immediately so a failure here doesn't loop;
        // BRICK_REPLAY_COUNT_KEY stays until a successful response clears it.
        sessionStorage.removeItem(BRICK_REPLAY_KEY);
        var data;
        try {
            data = JSON.parse(stored);
        } catch (e) {
            return;
        }
        // Use the fresh hash from this render; the stashed one is stale.
        if (typeof window.__BRICK_HASH === 'string') {
            data.hash = window.__BRICK_HASH;
        }
        // Bypass post() so we keep the original payload's bindings/value
        // (the new page has empty inputs that would otherwise overwrite them).
        setBusy(true);
        __brick_lastPayload = data;
        var xhr = new XMLHttpRequest();
        xhr.open("POST", window.location.href, true);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) callback(null, JSON.parse(xhr.responseText));
                else callback(new Error("Request failed: " + xhr.status));
            }
        };
        xhr.send(JSON.stringify(data));
    }

    function bootstrap() {
        // Re-sync the "what URL is the current DOM rendered for" marker. The
        // IIFE set it at parse time from location.pathname, but on a page
        // served by handleNavPost (client=false form POST) the response body
        // contains an inline `history.pushState(..., "/article/...")` which
        // runs AFTER the IIFE but BEFORE bootstrap — so by now location is
        // the post-pushState URL, and that's the one the popstate-back POST
        // needs to send as `previousPath`. Without this re-sync, back posts a
        // stale path, the server diffs the same route against itself, and
        // returns 0 patches (the "URL changes, content doesn't" symptom).
        __brick_renderedPath = location.pathname + location.search;

        initBindings();
        // Restore any drafts BEFORE a possible replay, so the replayed event
        // sees the restored input value via collectBindings().
        restoreDrafts();
        maybeReplay();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }

    function callback(error, data) {
        if (error) {
            console.error("Error:", error);
            // Don't leave the queue stuck behind a failed request.
            drainQueue();
            return;
        }

        // Server signaled it cleared its state (shape mismatch / hash mismatch);
        // reload the page so we start from a fresh server render — then replay
        // the original request once the new page is up. Capped at BRICK_MAX_REPLAYS
        // to avoid an infinite loop if state keeps drifting.
        if (data && data.reload) {
            if (__brick_lastPayload) {
                var count = parseInt(sessionStorage.getItem(BRICK_REPLAY_COUNT_KEY) || '0', 10);
                if (count < BRICK_MAX_REPLAYS) {
                    try {
                        sessionStorage.setItem(BRICK_REPLAY_KEY, JSON.stringify(__brick_lastPayload));
                        sessionStorage.setItem(BRICK_REPLAY_COUNT_KEY, String(count + 1));
                    } catch (e) { /* quota / disabled storage — give up on replay */ }
                } else {
                    sessionStorage.removeItem(BRICK_REPLAY_KEY);
                    sessionStorage.removeItem(BRICK_REPLAY_COUNT_KEY);
                }
            }
            // A full reload supersedes anything queued — drop it.
            __brick_queue.length = 0;
            window.location.reload();
            return;
        }

        // A normal successful response — past any reload spiral. Clear counters.
        sessionStorage.removeItem(BRICK_REPLAY_KEY);
        sessionStorage.removeItem(BRICK_REPLAY_COUNT_KEY);

        // Refresh the state-fingerprint we echo back to the server on the next
        // request. This is set on initial page render and updated after each
        // successful event.
        if (data && typeof data.hash === 'string') {
            window.__BRICK_HASH = data.hash;
        }

        // Save state if client state management is enabled
        if (data.state) {
            Brick.setAll(data.state);
        }


        // Apply DOM patches
        if (data.patches && data.patches.length > 0) {
            // console.log("Applying patches:", data.patches);
            applyPatches(data.patches);
        }

        // Re-initialize bindings after patches (new elements may have data-bind)
        initBindings();

        // Execute JS commands from server
        if (data.js) {
            executeJsDump(data.js);
        }

        // Snapshot the URL the DOM is now rendered for. Includes any
        // pushState/replaceState issued by the server in data.js above. Sent
        // back to the server on the next POST as `previousPath` so OLD/NEW
        // diff stays correct across popstate navigations.
        __brick_renderedPath = location.pathname + location.search;

        // Signal that the DOM has been updated for this response. Used by
        // the Router script to defer scroll restoration until the new page
        // content is actually in place — patches and any pushState in data.js
        // have both run by now.
        window.dispatchEvent(new Event('brick:patched'));

        // DOM is now hydrated for this request — release the queue.
        drainQueue();
    }

    // --- Request queue + loader visibility ---
    // Only one request may be in flight at a time. Anything that comes in while
    // busy is queued and dispatched after the current response has been applied
    // (patches + bindings re-init). A `[data-brick-loader]` element on the page,
    // if present, is toggled to visible while a request is active.
    var __brick_busy = false;
    var __brick_queue = [];
    var __brick_loaderEl = null;
    var __brick_loaderTimer = null;

    function loaderEl() {
        if (__brick_loaderEl === null) {
            __brick_loaderEl = document.querySelector('[data-brick-loader]') || false;
        }
        return __brick_loaderEl || null;
    }

    function loaderDelay() {
        // Override via `window.__BRICK_LOADER_DELAY = N` (milliseconds).
        // Fast requests (< delay) complete without ever showing the loader,
        // avoiding flashes. Default: 300ms — see UX notes in README.
        var d = typeof window.__BRICK_LOADER_DELAY === 'number'
            ? window.__BRICK_LOADER_DELAY
            : 300;
        return d >= 0 ? d : 0;
    }

    function setBusy(busy) {
        __brick_busy = busy;
        var el = loaderEl();
        if (!el) return;

        if (busy) {
            // Only schedule a show on the leading edge — once a debounce is
            // pending we don't reset it for follow-up requests in the queue,
            // so a sequence of small requests still triggers the loader if
            // their total time exceeds the delay.
            if (__brick_loaderTimer === null && el.style.display === 'none') {
                __brick_loaderTimer = setTimeout(function () {
                    __brick_loaderTimer = null;
                    if (__brick_busy) el.style.display = 'block';
                }, loaderDelay());
            }
        } else {
            if (__brick_loaderTimer !== null) {
                clearTimeout(__brick_loaderTimer);
                __brick_loaderTimer = null;
            }
            el.style.display = 'none';
        }
    }

    // Console timing for one request, three nested numbers:
    //   round-trip : client-measured (request sent → response received)
    //   code       : server-reported app code execution (Brick::run → done)
    //   php        : server-reported total PHP time (since SAPI receipt)
    // round-trip ⊇ php ⊇ code, so round-trip−php ≈ network and php−code ≈ PHP
    // bootstrap. The trailing [opcache/apcu/xdebug] flags show which
    // perf-relevant extensions were active, so a number is read with its
    // config. Watch them shrink as you tune opcache / xdebug.
    function logTiming(kind, roundTripMs, codeMs, phpMs, env, sections) {
        var fmt = function (v) { return (typeof v === 'number') ? v.toFixed(1) + 'ms' : '?'; };
        console.log(
            '[Brick] ' + kind +
            '  round-trip=' + roundTripMs.toFixed(1) + 'ms' +
            '  code=' + fmt(codeMs) +
            '  php=' + fmt(phpMs) +
            envSummary(env)
        );
        logSections(kind, sections);
    }

    // Per-section breakdown of the server's code time (restore state, render
    // old, perform action, render new, diffing, output patches, saving state,
    // …), sorted slowest-first. Collapsed so it doesn't spam the console.
    function logSections(kind, sections) {
        if (!sections || typeof sections !== 'object') return;
        var rows = Object.keys(sections)
            .map(function (k) { return [k, sections[k]]; })
            .filter(function (r) { return typeof r[1] === 'number'; })
            .sort(function (a, b) { return b[1] - a[1]; });
        if (rows.length === 0) return;
        console.groupCollapsed('[Brick] ' + kind + ' sections');
        rows.forEach(function (r) {
            console.log('  ' + (r[0] + '                  ').slice(0, 18) + r[1].toFixed(1) + 'ms');
        });
        console.groupEnd();
    }

    // Compact "[opcache✓ apcu✓ xdebug✗]" suffix from the server's env flags.
    function envSummary(env) {
        if (!env) return '';
        var flag = function (name) { return name + (env[name] ? '✓' : '✗'); };
        return '  [' + flag('opcache') + ' ' + flag('apcu') + ' ' + flag('xdebug') + ']';
    }

    function post(data, headers) {
        if (__brick_busy) {
            __brick_queue.push({ kind: 'json', data: data, headers: headers });
            return;
        }
        setBusy(true);
        sendJson(data, headers);
    }

    function postMultipart(data, files) {
        if (__brick_busy) {
            __brick_queue.push({ kind: 'multipart', data: data, files: files });
            return;
        }
        setBusy(true);
        sendMultipart(data, files);
    }

    function drainQueue() {
        if (__brick_queue.length === 0) {
            setBusy(false);
            return;
        }
        var next = __brick_queue.shift();
        if (next.kind === 'multipart') {
            sendMultipart(next.data, next.files);
        } else {
            sendJson(next.data, next.headers);
        }
    }

    function sendJson(data, headers) {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", window.location.href, true);
        xhr.setRequestHeader("Content-Type", "application/json");

        for (var key in headers ?? {}) {
            if (headers.hasOwnProperty(key)) {
                xhr.setRequestHeader(key, headers[key]);
            }
        }

        __brick_lastPayload = data;

        var bindings = collectBindings();
        if (bindings) {
            data.bindings = bindings;
        }
        if (typeof window.__BRICK_HASH === 'string') {
            data.hash = window.__BRICK_HASH;
        }
        data.previousPath = __brick_renderedPath;
        if (Brick.isStateEnabled()) {
            var clientState = Brick.getAll();
            if (clientState) data.state = clientState;
        }

        var t0 = performance.now();
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                var resp = JSON.parse(xhr.responseText);
                logTiming('POST', performance.now() - t0, resp.codeMs, resp.phpMs, resp.env, resp.sections);
                callback(null, resp);
            } else {
                callback(new Error("Request failed: " + xhr.status));
            }
        };
        xhr.send(JSON.stringify(data));
    }

    function sendMultipart(data, files) {
        var bindings = collectBindings();
        if (bindings) data.bindings = bindings;
        if (typeof window.__BRICK_HASH === 'string') {
            data.hash = window.__BRICK_HASH;
        }
        data.previousPath = __brick_renderedPath;
        if (Brick.isStateEnabled()) {
            var clientState = Brick.getAll();
            if (clientState) data.state = clientState;
        }

        var formData = new FormData();
        formData.append('_brick', JSON.stringify(data));
        for (var i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        var xhr = new XMLHttpRequest();
        xhr.open("POST", window.location.href, true);
        var t0 = performance.now();
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                var resp = JSON.parse(xhr.responseText);
                logTiming('POST', performance.now() - t0, resp.codeMs, resp.phpMs, resp.env, resp.sections);
                callback(null, resp);
            } else {
                callback(new Error("Request failed: " + xhr.status));
            }
        };
        xhr.send(formData);
    }

    // client=false transport: dispatch the event as a real (non-AJAX) form
    // POST — a full-page navigation the server answers with a freshly rendered
    // HTML document. Carries the same payload sendJson builds (event / path /
    // value / bindings / hash / previousPath / state), but as urlencoded form
    // fields the server reads from $_POST (see Brick::handleNavPost). State is
    // embedded so client-side managers (localStorage) survive the navigation.
    function submitForm(data) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        form.style.display = 'none';

        function hidden(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        hidden('_brickEvent', data.event != null ? data.event : '');
        hidden('_brickPath', data.path != null ? data.path : '');
        hidden('_brickValue', JSON.stringify(data.value !== undefined ? data.value : null));
        hidden('_brickPrevPath', __brick_renderedPath);

        var bindings = collectBindings();
        if (bindings) hidden('_brickBindings', JSON.stringify(bindings));
        if (typeof window.__BRICK_HASH === 'string') hidden('_brickHash', window.__BRICK_HASH);
        if (isStateEnabled()) {
            var clientState = getAll();
            if (clientState) hidden('_brickState', JSON.stringify(clientState));
        }

        document.body.appendChild(form);
        form.submit();
    }

    function extractEventData(evt, el) {
        if (!evt) {
            // Fallback: just return element value
            return (el && el.value !== undefined) ? el.value : null;
        }

        var type = evt.type;

        // Form/input events — return element value or checked state
        if (type === 'change' || type === 'input' || type === 'select' || type === 'invalid') {
            if (el && (el.type === 'checkbox' || el.type === 'radio')) {
                return { value: el.value, checked: el.checked };
            }
            return (el && el.value !== undefined) ? el.value : null;
        }

        // Focus/blur — return relatedTarget info
        if (type === 'focus' || type === 'blur' || type === 'focusin' || type === 'focusout') {
            return null;
        }

        // Submit/reset — prevent default, return null
        if (type === 'submit' || type === 'reset') {
            evt.preventDefault();
            return null;
        }

        // Keyboard events
        if (type === 'keydown' || type === 'keyup' || type === 'keypress') {
            return {
                key: evt.key,
                code: evt.code,
                altKey: evt.altKey,
                ctrlKey: evt.ctrlKey,
                shiftKey: evt.shiftKey,
                metaKey: evt.metaKey,
                repeat: evt.repeat
            };
        }

        // Mouse events
        if (type === 'click' || type === 'dblclick' || type === 'mousedown' || type === 'mouseup' ||
            type === 'mouseover' || type === 'mouseout' || type === 'mouseenter' || type === 'mouseleave' ||
            type === 'mousemove' || type === 'contextmenu') {
            var mouseData = {
                clientX: evt.clientX,
                clientY: evt.clientY,
                offsetX: evt.offsetX,
                offsetY: evt.offsetY,
                button: evt.button,
                altKey: evt.altKey,
                ctrlKey: evt.ctrlKey,
                shiftKey: evt.shiftKey,
                metaKey: evt.metaKey
            };
            if (type === 'contextmenu') {
                evt.preventDefault();
            }
            // For click on form elements, also include value
            if (el && el.value !== undefined) {
                mouseData.value = el.value;
            }
            return mouseData;
        }

        // Pointer events
        if (type.indexOf('pointer') === 0 || type === 'gotpointercapture' || type === 'lostpointercapture') {
            return {
                clientX: evt.clientX,
                clientY: evt.clientY,
                offsetX: evt.offsetX,
                offsetY: evt.offsetY,
                button: evt.button,
                pointerId: evt.pointerId,
                pointerType: evt.pointerType,
                pressure: evt.pressure,
                width: evt.width,
                height: evt.height,
                altKey: evt.altKey,
                ctrlKey: evt.ctrlKey,
                shiftKey: evt.shiftKey,
                metaKey: evt.metaKey
            };
        }

        // Touch events
        if (type === 'touchstart' || type === 'touchend' || type === 'touchmove' || type === 'touchcancel') {
            var touches = [];
            var src = evt.touches || [];
            for (var i = 0; i < src.length; i++) {
                touches.push({ clientX: src[i].clientX, clientY: src[i].clientY, identifier: src[i].identifier });
            }
            var changedTouches = [];
            var csrc = evt.changedTouches || [];
            for (var j = 0; j < csrc.length; j++) {
                changedTouches.push({ clientX: csrc[j].clientX, clientY: csrc[j].clientY, identifier: csrc[j].identifier });
            }
            return { touches: touches, changedTouches: changedTouches };
        }

        // Wheel events
        if (type === 'wheel') {
            return {
                deltaX: evt.deltaX,
                deltaY: evt.deltaY,
                deltaZ: evt.deltaZ,
                deltaMode: evt.deltaMode,
                clientX: evt.clientX,
                clientY: evt.clientY
            };
        }

        // Scroll events
        if (type === 'scroll') {
            return {
                scrollTop: el ? el.scrollTop : 0,
                scrollLeft: el ? el.scrollLeft : 0,
                scrollHeight: el ? el.scrollHeight : 0,
                scrollWidth: el ? el.scrollWidth : 0
            };
        }

        // Drag events
        if (type === 'dragstart' || type === 'drag' || type === 'dragend' ||
            type === 'dragenter' || type === 'dragleave' || type === 'dragover' || type === 'drop') {
            if (type === 'dragover' || type === 'drop') {
                evt.preventDefault();
            }
            var dragData = {
                clientX: evt.clientX,
                clientY: evt.clientY,
                offsetX: evt.offsetX,
                offsetY: evt.offsetY
            };
            if (evt.dataTransfer) {
                try {
                    dragData.text = evt.dataTransfer.getData('text/plain');
                } catch(e) {}
                dragData.types = Array.from(evt.dataTransfer.types || []);
                dragData.dropEffect = evt.dataTransfer.dropEffect;
                dragData.effectAllowed = evt.dataTransfer.effectAllowed;
            }
            return dragData;
        }

        // Clipboard events
        if (type === 'copy' || type === 'cut' || type === 'paste') {
            var clipData = {};
            if (evt.clipboardData) {
                try {
                    clipData.text = evt.clipboardData.getData('text/plain');
                } catch(e) {}
            }
            return clipData;
        }

        // Transition/animation events
        if (type === 'transitionend' || type === 'transitionstart' || type === 'transitioncancel' ||
            type === 'transitionrun') {
            return { propertyName: evt.propertyName, elapsedTime: evt.elapsedTime, pseudoElement: evt.pseudoElement };
        }
        if (type === 'animationend' || type === 'animationstart' || type === 'animationiteration' ||
            type === 'animationcancel') {
            return { animationName: evt.animationName, elapsedTime: evt.elapsedTime, pseudoElement: evt.pseudoElement };
        }

        // Resize events
        if (type === 'resize') {
            return { width: el ? el.offsetWidth : 0, height: el ? el.offsetHeight : 0 };
        }

        // Media events
        if (type === 'play' || type === 'pause' || type === 'ended' || type === 'timeupdate' ||
            type === 'volumechange' || type === 'seeking' || type === 'seeked' ||
            type === 'loadeddata' || type === 'loadedmetadata' || type === 'canplay' || type === 'canplaythrough' ||
            type === 'waiting' || type === 'playing' || type === 'ratechange' || type === 'durationchange' ||
            type === 'progress' || type === 'stalled' || type === 'suspend' || type === 'emptied' || type === 'abort') {
            return {
                currentTime: el ? el.currentTime : 0,
                duration: el ? el.duration : 0,
                paused: el ? el.paused : true,
                volume: el ? el.volume : 1,
                muted: el ? el.muted : false,
                playbackRate: el ? el.playbackRate : 1,
                ended: el ? el.ended : false,
                readyState: el ? el.readyState : 0
            };
        }

        // Default: return element value if available
        return (el && el.value !== undefined) ? el.value : null;
    }

    function computePath(el) {
        var root = document.body.firstElementChild;
        var indices = [];
        while (el && el !== root) {
            var parent = el.parentElement;
            if (!parent) return '';
            indices.unshift(Array.prototype.indexOf.call(parent.children, el));
            el = parent;
        }
        return indices.join(',');
    }

    // Framework-extension hook: send a server event with a pre-built
    // value object, bypassing extractEventData. Used by wrappers (e.g.
    // Leaflet) that dispatch their own events with custom shapes.
    function dispatch(event, el, value) {
        post({ event: event, path: computePath(el), value: value });
    }

    // Attach a server-dispatched listener to the element at `path`. domType
    // is the real DOM event (e.g. 'change'); `event` is the logical name
    // forwarded to the server. capture picks the addEventListener phase.
    // `client` (default true) decides transport: true keeps the in-place AJAX
    // patch; false makes the listener submit a full-page form POST. Tracked on
    // the element (keyed by domType+phase) so it can be unbound and is never
    // double-bound.
    function bindEvent(path, domType, event, capture, client, keys) {
        var el = findNodeByPath(path);
        if (!el) return;
        el.__brickEvents = el.__brickEvents || {};
        var key = domType + '|' + (capture ? 'c' : 'b');
        if (el.__brickEvents[key]) return;
        // Capture the original `client` flag verbatim — handleEvent's
        // `if (client === false) submitForm(...)` check expects the original
        // value, not a derived one. A prior version derived a `serverNav` here
        // and passed it through, which inverted the semantics (client=true
        // pages went to submitForm and vice versa).
        var fn = function (evt) {
            // Client-side key filter: when a key list is given, only react to a
            // matching KeyboardEvent.key — other keys are dropped here and never
            // round-trip. Events that carry no `key` (e.g. change) are exempt.
            if (keys && keys.length && evt && typeof evt.key === 'string' && keys.indexOf(evt.key) < 0) return;
            handleEvent(evt, event, el, client);
        };
        el.addEventListener(domType, fn, capture);
        el.__brickEvents[key] = fn;
    }

    // Detach a previously bound listener from a surviving element.
    function unbindEvent(path, domType, capture) {
        var el = findNodeByPath(path);
        if (!el || !el.__brickEvents) return;
        var key = domType + '|' + (capture ? 'c' : 'b');
        var fn = el.__brickEvents[key];
        if (!fn) return;
        el.removeEventListener(domType, fn, capture);
        delete el.__brickEvents[key];
    }

    // Remove every Brick listener tracked on `el` and its descendants. Called
    // as a node is about to leave the DOM (delete/replace) so no listener is
    // left dangling on the detached subtree.
    function cleanupListeners(el) {
        if (!el || el.nodeType !== 1) return;
        if (el.__brickEvents) {
            for (var key in el.__brickEvents) {
                var capture = key.charAt(key.length - 1) === 'c';
                var domType = key.slice(0, -2);
                el.removeEventListener(domType, el.__brickEvents[key], capture);
            }
            el.__brickEvents = null;
        }
        var kids = el.children;
        for (var i = 0; i < kids.length; i++) cleanupListeners(kids[i]);
    }

    function handleEvent(evt, event, el, client) {
        // Anchor with an Brick click handler = SPA-handled link. Stop the
        // browser from following the href so the server can swap content
        // in-place. Modifier keys / middle-click fall through to the
        // browser so "open in new tab" still works.
        if (event === 'click' && el && el.tagName === 'A') {
            if (evt.ctrlKey || evt.metaKey || evt.shiftKey || evt.button !== 0) {
                return;
            }
            evt.preventDefault();
        }
        var path = computePath(el);
        var data = { event: event, path: path };
        // File upload — send as multipart form
        if (el && el.type === 'file' && el.files && el.files.length > 0) {
            data.value = null;
            postMultipart(data, el.files);
            return;
        }
        data.value = extractEventData(evt, el);
        // client === false: dispatch as a full-page form POST instead of the
        // in-place AJAX patch. extractEventData already ran any needed
        // preventDefault (submit/reset/contextmenu), so the native navigation
        // we kick off below won't double-fire.
        if (client === false) {
            submitForm(data);
            return;
        }
        post(data);
    }
    // Initial GET: the server stamps window.__BRICK_CODE_MS / __BRICK_PHP_MS
    // into the page. Pair them with the browser's navigation timing (request
    // sent → last byte) to log the same round-trip / code / php breakdown.
    if (typeof window.__BRICK_PHP_MS === 'number') {
        var logGet = function () {
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            if (!nav) return;
            logTiming('GET', nav.responseEnd - nav.requestStart, window.__BRICK_CODE_MS, window.__BRICK_PHP_MS, window.__BRICK_ENV, window.__BRICK_SECTIONS);
        };
        if (document.readyState === 'complete') logGet();
        else window.addEventListener('load', logGet);
    }

    return {
        addStateHandler: addStateHandler,
        getState: getState,
        saveState: saveState,
        getAll: getAll,
        setAll: setAll,
        isStateEnabled: isStateEnabled,
        refresh: refresh,
        tick: refresh,
        handleEvent: handleEvent,
        dispatch: dispatch,
        bindEvent: bindEvent,
        unbindEvent: unbindEvent,
        ready: ready
    };
})();

// Dev-only HMR: long-poll /hmr.php; on a detected .php change, reload.
// Gated on window.__BRICK_DEV, which the server sets from config.php
// (development => true|false). When false, no polling happens at all.
//
// Polling is CHAINED, not interval-driven: the next poll is issued only when
// the previous one resolves. A fixed setInterval would race the server's ~60s
// hold and abort the in-flight request mid-response — if that response was the
// `{changed:true}` for a save, the reload is lost AND the next poll re-baselines
// against the already-changed source, so the edit is never seen again (HMR
// silently sticks). Chaining keeps exactly one poll in flight with no gap, so
// every change is observed and the only abort is on navigation.
(function () {
    if (!window.__BRICK_DEV) return;
    var ctl;
    var retry;
    function poll() {
        ctl = new AbortController();
        fetch('/hmr.php', { signal: ctl.signal, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.changed) { location.reload(); return; }
                poll(); // no change after the server's hold — re-poll immediately
            })
            .catch(function () {
                // Navigation abort, server restart, or network blip. Back off
                // briefly and retry so HMR self-heals once the server returns.
                retry = setTimeout(poll, 1000);
            });
    }
    window.addEventListener('beforeunload', function () {
        if (ctl) ctl.abort();
        if (retry) clearTimeout(retry);
    });
    poll();
})();
