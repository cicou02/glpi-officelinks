/**
 * OfficeLinks — plugin GLPI.
 *
 * Aperçu du chemin saisi, copie du chemin, préremplissage du nom du document.
 *
 * La normalisation ci-dessous reproduit src/PathNormalizer.php UNIQUEMENT
 * pour l'aperçu : seule la validation côté serveur fait foi.
 *
 * Les écouteurs sont posés sur document (délégation) : l'onglet de la fiche
 * Document est chargé en AJAX, après le chargement de la page.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */
(function () {
    'use strict';

    var MAX_INPUT_BYTES = 4096;
    var MAX_DECODE_PASSES = 5;
    var FORBIDDEN_CHARS = ['\\', '/', '<', '>', ':', '"', '|', '?', '*'];
    var BLANKS = /^[ \t\r\n]+|[ \t\r\n]+$/g;

    // ------------------------------------------------------------------
    // Normalisation (miroir de PathNormalizer)
    // ------------------------------------------------------------------

    function Rejection(reason) {
        this.reason = reason;
    }

    function reject(reason) {
        throw new Rejection(reason);
    }

    function stripWrapping(input) {
        var path = input.replace(BLANKS, '');
        if (path.length >= 2 && path.charAt(0) === '"' && path.charAt(path.length - 1) === '"') {
            path = path.slice(1, -1).replace(BLANKS, '');
        }
        return path;
    }

    function isBlank(input) {
        return stripWrapping(input) === '';
    }

    /**
     * Équivalent de rawurldecode() : décode les %XX valides, laisse les
     * autres tels quels. Renvoie null si le résultat n'est pas de l'UTF-8.
     */
    function rawUrlDecode(value) {
        if (value.indexOf('%') === -1) {
            return value;
        }
        var encoder = new TextEncoder();
        var bytes = [];
        var parts = value.split(/(%[0-9A-Fa-f]{2})/);
        parts.forEach(function (part, index) {
            if (index % 2 === 1) {
                bytes.push(parseInt(part.slice(1), 16));
            } else if (part !== '') {
                encoder.encode(part).forEach(function (b) {
                    bytes.push(b);
                });
            }
        });
        try {
            return new TextDecoder('utf-8', {fatal: true}).decode(new Uint8Array(bytes));
        } catch (e) {
            return null;
        }
    }

    function foldCase(value) {
        return value.toUpperCase();
    }

    function codePointLength(value) {
        return Array.from(value).length;
    }

    function assertSafeCharacters(path) {
        if (typeof path.isWellFormed === 'function' && !path.isWellFormed()) {
            reject('invalid_encoding');
        }
        if (/[\p{Cc}\u200E\u200F\u202A-\u202E\u2066-\u2069]/u.test(path)) {
            reject('control_char');
        }
    }

    function assertValidSegment(segment) {
        var compat = segment.normalize('NFKC');

        var decoded = compat;
        for (var i = 0; i < MAX_DECODE_PASSES; i++) {
            var next = rawUrlDecode(decoded);
            if (next === null) {
                reject('dot_segment');
            }
            if (next === decoded) {
                break;
            }
            decoded = next;
        }
        if (decoded.normalize('NFKC').replace(/^[ .]+|[ .]+$/g, '') === '') {
            reject('dot_segment');
        }

        FORBIDDEN_CHARS.forEach(function (char) {
            if (compat.indexOf(char) !== -1) {
                reject('forbidden_char');
            }
        });

        if (/[. ]$/.test(segment)) {
            reject('forbidden_char');
        }
    }

    function splitSegments(path) {
        var normalized = path.replace(/\//g, '\\');
        if (normalized !== '' && normalized.charAt(normalized.length - 1) === '\\') {
            reject('folder');
        }
        var segments = [];
        normalized.split('\\').forEach(function (segment) {
            if (segment === '' || segment === '.') {
                return;
            }
            assertValidSegment(segment);
            segments.push(segment);
        });
        return segments;
    }

    function parseUnc(path) {
        var body = path.replace(/\//g, '\\').replace(/^\\+/, '');
        var host = body.split('\\')[0];
        if (host === '?' || host === '.') {
            reject('device_path');
        }
        var segments = splitSegments(body);
        if (segments.length < 2) {
            reject('not_unc');
        }
        return segments;
    }

    /** Segments d'un chemin de configuration déjà canonique (\\hôte\partage...). */
    function configSegments(canonical) {
        return String(canonical).replace(/^\\+/, '').split('\\').filter(function (s) {
            return s !== '';
        });
    }

    function startsWithSegments(segments, prefix) {
        if (segments.length < prefix.length) {
            return false;
        }
        for (var i = 0; i < prefix.length; i++) {
            if (foldCase(segments[i]) !== foldCase(prefix[i])) {
                return false;
            }
        }
        return true;
    }

    function bySegmentCountDesc(a, b) {
        return b.length - a.length;
    }

    function fromFileUri(uri) {
        var match = /^file:(\/\/[\s\S]*)$/i.exec(uri);
        if (match === null) {
            reject('not_unc');
        }
        if (/[?#]/.test(match[1])) {
            reject('forbidden_char');
        }
        var decoded = rawUrlDecode(match[1]);
        if (decoded === null) {
            reject('invalid_encoding');
        }
        var drive = /^\/+([A-Za-z]:[\\/][\s\S]*)$/.exec(decoded);
        return drive !== null ? drive[1] : decoded;
    }

    /**
     * @param {string} input
     * @param {{allowedRoots: string[], driveMappings: Object<string,string>, hostAliases: Object<string,string>, maxLength: number}} config
     * @returns {{ok: true, path: string}|{ok: false, reason: string}}
     */
    function normalize(input, config) {
        try {
            if (new TextEncoder().encode(input).length > MAX_INPUT_BYTES) {
                reject('too_long');
            }

            var path = stripWrapping(input);
            if (path === '') {
                reject('empty');
            }

            if (/^file:/i.test(path)) {
                path = fromFileUri(path);
            }
            assertSafeCharacters(path);

            var segments;
            var drive = /^([A-Za-z]):[\\/]([\s\S]*)$/.exec(path);
            if (drive !== null) {
                var letter = drive[1].toUpperCase() + ':';
                var mappings = config.driveMappings || {};
                if (!Object.prototype.hasOwnProperty.call(mappings, letter)) {
                    reject('unmapped_drive');
                }
                var rest = splitSegments(drive[2]);
                if (rest.length === 0) {
                    reject('folder');
                }
                segments = configSegments(mappings[letter]).concat(rest);
            } else if (/^[\\/]{2}/.test(path)) {
                segments = parseUnc(path);
            } else {
                reject('not_unc');
            }

            // Alias d'hôtes (le plus spécifique d'abord).
            var aliases = Object.keys(config.hostAliases || {}).map(function (from) {
                return {from: configSegments(from), to: configSegments(config.hostAliases[from])};
            }).sort(function (a, b) {
                return b.from.length - a.from.length;
            });
            for (var i = 0; i < aliases.length; i++) {
                if (startsWithSegments(segments, aliases[i].from)) {
                    segments = aliases[i].to.concat(segments.slice(aliases[i].from.length));
                    break;
                }
            }

            // Racines autorisées.
            var roots = (config.allowedRoots || []).map(configSegments).sort(bySegmentCountDesc);
            if (roots.length === 0) {
                reject('no_allowed_roots');
            }
            var matched = null;
            for (var j = 0; j < roots.length; j++) {
                if (startsWithSegments(segments, roots[j])) {
                    matched = roots[j];
                    break;
                }
            }
            if (matched === null) {
                reject('root_not_allowed');
            }
            if (segments.length === matched.length) {
                reject('folder');
            }
            segments = matched.concat(segments.slice(matched.length));

            if (!/^[\s\S]+\.[^.\s]+$/.test(segments[segments.length - 1])) {
                reject('folder');
            }

            var canonical = '\\\\' + segments.join('\\');
            if (codePointLength(canonical) > (config.maxLength || 1024)) {
                reject('too_long');
            }
            return {ok: true, path: canonical};
        } catch (e) {
            if (e instanceof Rejection) {
                return {ok: false, reason: e.reason};
            }
            throw e;
        }
    }

    /** Application Office associée au chemin, ou null. */
    function officeApplication(path, officeTypes) {
        var name = fileName(path);
        var dot = name.lastIndexOf('.');
        if (dot === -1) {
            return null;
        }
        var extension = name.slice(dot + 1).toLowerCase();
        var keys = Object.keys(officeTypes || {});
        for (var i = 0; i < keys.length; i++) {
            if (officeTypes[keys[i]].extensions.indexOf(extension) !== -1) {
                return officeTypes[keys[i]].application;
            }
        }
        return null;
    }

    function fileName(path) {
        var parts = path.split(/[\\/]/);
        return parts[parts.length - 1];
    }

    /**
     * Nom de document proposé à partir de la saisie (même invalide) :
     * nom du fichier sans extension, '' si rien d'exploitable.
     */
    function suggestedName(input) {
        var path = stripWrapping(input);
        if (/^file:/i.test(path)) {
            path = rawUrlDecode(path) || path;
        }
        var name = fileName(path).replace(BLANKS, '');
        var dot = name.lastIndexOf('.');
        if (dot > 0) {
            name = name.slice(0, dot);
        }
        return name.replace(/[. ]+$/, '');
    }

    // ------------------------------------------------------------------
    // Interface
    // ------------------------------------------------------------------

    function readConfig(input) {
        if (input._officelinksConfig === undefined) {
            try {
                input._officelinksConfig = JSON.parse(input.getAttribute('data-officelinks-config') || 'null');
            } catch (e) {
                input._officelinksConfig = null;
            }
        }
        return input._officelinksConfig;
    }

    function setPreview(element, state, parts) {
        element.classList.remove('d-none', 'text-success', 'text-danger', 'text-warning');
        element.textContent = '';
        if (state === null) {
            element.classList.add('d-none');
            return;
        }
        element.classList.add(state);
        parts.forEach(function (part) {
            if (part.code) {
                var code = document.createElement('code');
                code.textContent = part.code;
                element.appendChild(code);
            } else {
                element.appendChild(document.createTextNode(part.text));
            }
        });
    }

    function updatePreview(input) {
        var config = readConfig(input);
        var container = input.closest('.officelinks-document');
        var preview = container ? container.querySelector('[data-officelinks-preview]') : null;
        if (config === null || preview === null) {
            return;
        }
        var messages = config.messages || {};
        var value = input.value;

        if (value === input.defaultValue) {
            setPreview(preview, null, []);
            return;
        }
        if (isBlank(value)) {
            setPreview(preview, input.defaultValue !== '' ? 'text-warning' : null, [{text: messages.removal || ''}]);
            return;
        }

        var result = normalize(value, config);
        if (!result.ok) {
            var reasons = messages.reasons || {};
            setPreview(preview, 'text-danger', [
                {text: (messages.invalid || '') + ' '},
                {text: reasons[result.reason] || result.reason},
            ]);
            return;
        }

        var parts = [{text: (messages.valid || '') + ' '}, {code: result.path}];
        var application = officeApplication(result.path, config.officeTypes);
        if (application !== null && messages.office) {
            parts.push({text: ' ' + messages.office.replace('%s', application)});
        }
        setPreview(preview, 'text-success', parts);
    }

    /** Préremplit le nom du document s'il est vide (ou encore prérempli par nous). */
    function prefillName(input) {
        var form = input.form;
        if (!form) {
            return;
        }
        var nameInput = form.querySelector('input[name="name"]');
        if (nameInput === null || nameInput.readOnly || nameInput.disabled) {
            return;
        }
        var current = nameInput.value;
        var autofilled = nameInput.getAttribute('data-officelinks-autofill');
        if (current.trim() !== '' && current !== autofilled) {
            return;
        }
        var name = suggestedName(input.value);
        nameInput.value = name;
        if (name === '') {
            nameInput.removeAttribute('data-officelinks-autofill');
        } else {
            nameInput.setAttribute('data-officelinks-autofill', name);
        }
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () {
                return true;
            }, function () {
                return legacyCopy(text);
            });
        }
        return Promise.resolve(legacyCopy(text));
    }

    /** Repli pour GLPI servi en http (l'API Clipboard exige un contexte sécurisé). */
    function legacyCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.top = '-1000px';
        document.body.appendChild(textarea);
        textarea.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(textarea);
        return ok;
    }

    function onCopy(button) {
        var container = button.closest('.officelinks-actions');
        var feedback = container ? container.querySelector('[data-officelinks-feedback]') : null;
        copyText(button.getAttribute('data-officelinks-copy') || '').then(function (ok) {
            if (feedback === null) {
                return;
            }
            feedback.textContent = button.getAttribute(ok ? 'data-officelinks-copied' : 'data-officelinks-copy-failed') || '';
            feedback.classList.toggle('text-success', ok);
            feedback.classList.toggle('text-danger', !ok);
            clearTimeout(feedback._officelinksTimer);
            feedback._officelinksTimer = setTimeout(function () {
                feedback.textContent = '';
            }, 4000);
        });
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('input', function (event) {
            var input = event.target;
            if (input instanceof HTMLInputElement && input.hasAttribute('data-officelinks-input')) {
                updatePreview(input);
                prefillName(input);
            }
        });

        document.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest('[data-officelinks-copy]') : null;
            if (button !== null) {
                event.preventDefault();
                onCopy(button);
            }
        });
    }

    // Tests (Node) : node tests/js/officelinks.test.js
    if (typeof module === 'object' && module.exports) {
        module.exports = {
            normalize: normalize,
            isBlank: isBlank,
            suggestedName: suggestedName,
            officeApplication: officeApplication,
        };
    }
})();
