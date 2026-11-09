/**
 * Category mapping assistant: PrestaShop category tree with InPost suggestions
 * (views/templates/admin/category_mapping/list.html.twig, "Assistant" view).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */
(function ($) {
    'use strict';

    var VIEW_KEY = 'inpost_category_mapping_view';
    var SKIP_KEY = 'inpost_category_assistant_skipped';
    var UNDO_MS = 8000;

    var t = function (text, params) {
        var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
        Object.keys(params || {}).forEach(function (key) {
            translated = translated.split('%' + key + '%').join(params[key]);
        });
        return translated;
    };
    var esc = function (value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var withParams = function (url, params) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + $.param(params);
    };

    var $root, urls, state = {
        categories: [],
        summary: {},
        filter: 'todo',
        withProducts: false,
        query: '',
        skipped: {},
        open: {} // category id => 'alt' | 'search'
    };

    function loadSkipped() {
        try {
            state.skipped = JSON.parse(localStorage.getItem(SKIP_KEY) || '{}') || {};
        } catch (e) {
            state.skipped = {};
        }
    }

    function saveSkipped() {
        localStorage.setItem(SKIP_KEY, JSON.stringify(state.skipped));
    }

    // ---- view switch -------------------------------------------------------

    function showView(view) {
        var assistant = view === 'assistant';
        $('#inpost-tree-view').toggle(!assistant);
        $root.toggle(assistant);
        $('.inpost-view-switch .btn').each(function () {
            var active = $(this).data('view') === view;
            $(this).toggleClass('btn-primary', active).toggleClass('btn-outline-primary', !active);
        });
        localStorage.setItem(VIEW_KEY, view);
        var url = new URL(window.location.href);
        if (assistant) {
            url.searchParams.set('view', 'assistant');
        } else {
            url.searchParams.delete('view');
        }
        window.history.replaceState({}, '', url.toString());
        if (assistant && !state.loaded) {
            load();
        }
    }

    // ---- data --------------------------------------------------------------

    function load() {
        state.loaded = true;
        $root.find('.ia-body').html('<div class="ia-empty"><i class="material-icons icon-spin">refresh</i>' + esc(t('Analysing categories...')) + '</div>');
        $.getJSON(urls.data).done(function (res) {
            if (!res.success) {
                return fail(res.error);
            }
            state.categories = res.categories;
            state.summary = res.summary;
            render();
        }).fail(function (xhr) {
            fail(xhr.responseJSON && xhr.responseJSON.error);
        });
    }

    function fail(message) {
        $root.find('.ia-body').html('<div class="alert alert-danger">' + esc(t('Request failed')) + (message ? ': ' + esc(message) : '') + '</div>');
    }

    function byId(id) {
        for (var i = 0; i < state.categories.length; i++) {
            if (state.categories[i].id === id) {
                return state.categories[i];
            }
        }
        return null;
    }

    function recount() {
        var s = { total: 0, mapped: 0, sure: 0, check: 0, none: 0 };
        state.categories.forEach(function (c) {
            s.total++;
            if (c.current) {
                s.mapped++;
            } else {
                var best = c.suggestions[0];
                s[best ? best.level : 'none']++;
            }
        });
        state.summary = s;
    }

    // Not mapped, not skipped, with a suggestion; onlySure keeps the green ones
    function acceptable(onlySure) {
        return state.categories.filter(function (c) {
            return !c.current && !state.skipped[c.id] && c.suggestions[0] && (!onlySure || c.suggestions[0].level === 'sure');
        });
    }

    // ---- rendering ---------------------------------------------------------

    function render() {
        recount();
        renderSummary();
        renderList();
    }

    function renderSummary() {
        var s = state.summary;
        var pct = s.total ? Math.round(100 * s.mapped / s.total) : 0;
        var sure = acceptable(true).length;
        var all = acceptable(false).length;
        $root.find('.ia-summary').html(
            '<div class="ia-progress">'
            + '<div class="d-flex justify-content-between mb-1"><strong>' + esc(t('Mapped %mapped% of %total% categories', { mapped: s.mapped, total: s.total })) + '</strong><span class="ia-muted">' + pct + '%</span></div>'
            + '<div class="progress"><div class="progress-bar bg-info" style="width:' + pct + '%"></div></div>'
            + '</div>'
            + '<span class="ia-chip" title="' + esc(t('Score %score% or more', { score: urls.sure })) + '"><span class="dot dot-sure"></span>' + esc(t('Sure')) + ': ' + s.sure + '</span>'
            + '<span class="ia-chip" title="' + esc(t('Score %from%-%to%', { from: urls.check, to: urls.sure - 1 })) + '"><span class="dot dot-check"></span>' + esc(t('To check')) + ': ' + s.check + '</span>'
            + '<span class="ia-chip"><span class="dot dot-none"></span>' + esc(t('No suggestion')) + ': ' + s.none + '</span>'
            + '<button type="button" class="btn btn-success btn-sm js-bulk-open" data-mode="sure"' + (sure ? '' : ' disabled') + '>'
            + '<i class="material-icons" style="font-size:16px;vertical-align:-3px;">done_all</i> '
            + esc(t('Accept all sure (%count%)', { count: sure })) + '</button>'
            + '<button type="button" class="btn btn-outline-success btn-sm js-bulk-open" data-mode="all"' + (all ? '' : ' disabled') + '>'
            + esc(t('Accept all (%count%)', { count: all })) + '</button>'
        );
    }

    function visible(c) {
        if (state.filter === 'todo' && c.current) {
            return false;
        }
        if (state.filter === 'mapped' && !c.current) {
            return false;
        }
        if (state.withProducts && !c.products) {
            return false;
        }
        if (state.query) {
            var hay = (c.name + ' ' + c.path).toLowerCase();
            if (hay.indexOf(state.query) === -1) {
                return false;
            }
        }
        return true;
    }

    function renderList() {
        var rows = state.categories.filter(visible).map(rowHtml);
        var empty = state.filter === 'todo' && !state.query
            ? '<div class="ia-empty"><i class="material-icons">celebration</i>' + esc(t('All categories are mapped.')) + '</div>'
            : '<div class="ia-empty"><i class="material-icons">search_off</i>' + esc(t('No categories match the filters.')) + '</div>';
        $root.find('.ia-body').html(rows.length ? '<div class="ia-list">' + rows.join('') + '</div>' : empty);
    }

    function badge(s) {
        return '<span class="ia-badge ' + esc(s.level) + '" title="' + esc(t(s.level === 'sure' ? 'Sure' : 'To check')) + '">' + s.score + '%</span>';
    }

    function optionHtml(c, s, primary, noScore) {
        return '<div class="ia-option">' + (noScore ? '' : badge(s))
            + '<span class="ia-path">' + s.path_html + '</span>'
            + '<button type="button" class="btn btn-' + (primary ? 'primary' : 'outline-primary') + ' btn-sm js-accept" data-id="' + c.id + '" data-uuid="' + esc(s.inpost_category_id) + '" data-entity="' + s.entity_id + '">'
            + esc(t(primary ? 'Accept' : 'Choose')) + '</button></div>';
    }

    function rowHtml(c) {
        var indent = 'padding-left:' + (c.depth * 18) + 'px';
        var ps = '<div class="ia-ps" style="' + indent + '">'
            + '<div class="name">' + esc(c.name) + '<span class="count">' + esc(t('%count% products', { count: c.products })) + '</span>'
            + (c.active ? '' : ' <span class="badge badge-secondary">' + esc(t('inactive')) + '</span>') + '</div>'
            + (c.path ? '<div class="path">' + esc(c.path) + '</div>' : '')
            + '</div>';

        var main = '';
        var actions = '';
        var cls = 'ia-row';
        var best = c.suggestions[0];

        if (c.current) {
            cls += ' is-mapped';
            main = '<div class="ia-mapped"><i class="material-icons">check_circle</i> ' + esc(t('Mapped to')) + '</div>'
                + '<div class="ia-path">' + (c.current.path_html || esc(c.current.path)) + '</div>'
                + (c.current.inactive ? '<span class="badge badge-warning">' + esc(t('orphaned')) + '</span>' : '');
            if (c.undo) {
                actions += '<button type="button" class="btn btn-warning btn-sm js-undo" data-id="' + c.id + '"><i class="material-icons" style="font-size:16px;vertical-align:-3px;">undo</i> ' + esc(t('Undo')) + '</button>';
            }
            actions += '<a class="btn btn-outline-secondary btn-sm" href="' + esc(withParams(urls.attributes, { category: c.current.entity_id })) + '">' + esc(t('Attributes')) + ' →</a>'
                + '<button type="button" class="btn btn-outline-secondary btn-sm js-toggle" data-panel="search" data-id="' + c.id + '">' + esc(t('Change')) + '</button>'
                + '<button type="button" class="btn btn-link btn-sm text-danger js-unmap" data-id="' + c.id + '">' + esc(t('Remove')) + '</button>';
        } else if (state.skipped[c.id]) {
            cls += ' is-skipped';
            main = '<span class="ia-muted">' + esc(t('Skipped - you will map it later.')) + '</span>';
            actions = '<button type="button" class="btn btn-link btn-sm js-unskip" data-id="' + c.id + '">' + esc(t('Restore')) + '</button>';
        } else if (best) {
            main = optionHtml(c, best, true);
            actions = (c.suggestions.length > 1
                ? '<button type="button" class="btn btn-outline-secondary btn-sm js-toggle" data-panel="alt" data-id="' + c.id + '">' + esc(t('Other (%count%)', { count: c.suggestions.length - 1 })) + '</button>'
                : '')
                + '<button type="button" class="btn btn-outline-secondary btn-sm js-toggle" data-panel="search" data-id="' + c.id + '"><i class="material-icons" style="font-size:16px;vertical-align:-3px;">search</i> ' + esc(t('Search')) + '</button>'
                + '<button type="button" class="btn btn-link btn-sm js-skip" data-id="' + c.id + '">' + esc(t('Skip')) + '</button>';
        } else {
            main = '<span class="ia-muted">' + esc(t('No confident suggestion - search the InPost tree.')) + '</span>';
            actions = '<button type="button" class="btn btn-primary btn-sm js-toggle" data-panel="search" data-id="' + c.id + '"><i class="material-icons" style="font-size:16px;vertical-align:-3px;">search</i> ' + esc(t('Search')) + '</button>'
                + '<button type="button" class="btn btn-link btn-sm js-skip" data-id="' + c.id + '">' + esc(t('Skip')) + '</button>';
        }

        var panel = '';
        if (state.open[c.id] === 'alt') {
            panel = '<div class="ia-panel">' + c.suggestions.slice(1).map(function (s) {
                return optionHtml(c, s, false);
            }).join('') + '</div>';
        } else if (state.open[c.id] === 'search') {
            panel = '<div class="ia-panel ia-search">'
                + '<input type="text" class="form-control form-control-sm js-search" data-id="' + c.id + '" value="' + esc(c.searchQuery || c.name) + '" placeholder="' + esc(t('Type an InPost category name...')) + '" autocomplete="off">'
                + '<div class="ia-help mt-1">' + esc(t('Only leaf categories can be mapped. Typos and Polish letters are tolerated.')) + '</div>'
                + '<div class="js-search-results mt-1"></div></div>';
        }

        return '<div class="' + cls + '" data-id="' + c.id + '">' + ps
            + '<div class="ia-main">' + main + panel + '</div>'
            + '<div class="ia-actions">' + actions + '</div></div>';
    }

    // ---- actions -----------------------------------------------------------

    function accept(id, uuid, entityId) {
        var c = byId(id);
        var chosen = c.suggestions.concat(c.searchResults || []).filter(function (s) {
            return s.entity_id === entityId;
        })[0];
        $.ajax({
            url: urls.store,
            method: 'POST',
            data: { prestashop_category_id: id, inpost_category_id: uuid },
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            if (!res.success) {
                return notify(res.error || t('Request failed'), 'error');
            }
            markMapped(c, chosen, true);
            refreshRow(c, true);
            renderSummary();
        }).fail(function (xhr) {
            notify((xhr.responseJSON && xhr.responseJSON.error) || t('Request failed'), 'error');
        });
    }

    function markMapped(c, suggestion, withUndo) {
        c.previous = c.current;
        c.current = {
            entity_id: suggestion.entity_id,
            inpost_category_id: suggestion.inpost_category_id,
            path: suggestion.path,
            path_html: suggestion.path_html
        };
        delete state.open[c.id];
        delete state.skipped[c.id];
        saveSkipped();
        if (withUndo) {
            c.undo = true;
            clearTimeout(c.undoTimer);
            c.undoTimer = setTimeout(function () {
                c.undo = false;
                refreshRow(c, false);
            }, UNDO_MS);
        }
    }

    function unmap(c, restorePrevious) {
        var previous = restorePrevious ? c.previous : null;
        var request = previous
            ? $.post(urls.store, { prestashop_category_id: c.id, inpost_category_id: previous.inpost_category_id })
            : $.post(urls.unmap, { prestashop_category_id: c.id });
        request.done(function () {
            c.current = previous || null;
            c.undo = false;
            clearTimeout(c.undoTimer);
            refreshRow(c, true);
            renderSummary();
        }).fail(function () {
            notify(t('Request failed'), 'error');
        });
    }

    function refreshRow(c, flash) {
        var $row = $root.find('.ia-row[data-id="' + c.id + '"]');
        if (!$row.length) {
            return;
        }
        var $new = $(rowHtml(c));
        $row.replaceWith($new);
        if (flash) {
            $new.addClass('flash');
        }
        if (state.open[c.id] === 'search') {
            runSearch($new.find('.js-search'));
        }
    }

    var searchTimer;
    function runSearch($input) {
        var id = parseInt($input.data('id'), 10);
        var c = byId(id);
        var q = $.trim($input.val());
        c.searchQuery = q;
        var $results = $input.closest('.ia-search').find('.js-search-results');
        clearTimeout(searchTimer);
        if (q.length < 2) {
            $results.empty();
            return;
        }
        searchTimer = setTimeout(function () {
            $results.html('<span class="ia-muted">' + esc(t('Searching...')) + '</span>');
            $.getJSON(withParams(urls.search, { q: q })).done(function (res) {
                c.searchResults = res.results || [];
                $results.html(c.searchResults.length
                    ? c.searchResults.map(function (s) { return optionHtml(c, s, false, true); }).join('')
                    : '<span class="ia-muted">' + esc(t('No categories match "%query%".', { query: q })) + '</span>');
            }).fail(function () {
                $results.html('<span class="text-danger">' + esc(t('Search failed')) + '</span>');
            });
        }, 300);
    }

    // List of acceptable categories with checkboxes; mode "sure" ticks only the green ones
    function openBulk(mode) {
        var items = acceptable(false).map(function (c) {
            var s = c.suggestions[0];
            var checked = mode === 'all' || s.level === 'sure';
            return '<li><label class="mb-0"><input type="checkbox" class="js-bulk-item" value="' + c.id + '" data-level="' + s.level + '"' + (checked ? ' checked' : '') + '> '
                + badge(s) + '<strong>' + esc(c.name) + '</strong> → <span class="ia-path">' + s.path_html + '</span></label></li>';
        }).join('');
        $root.find('.ia-bulk-wrap').html(
            '<div class="ia-bulk"><div class="d-flex flex-wrap align-items-center mb-1" style="gap:8px">'
            + '<strong>' + esc(t('These categories will be mapped:')) + '</strong>'
            + '<button type="button" class="btn btn-link btn-sm p-0 js-bulk-select" data-select="all">' + esc(t('Select all')) + '</button>'
            + '<button type="button" class="btn btn-link btn-sm p-0 js-bulk-select" data-select="sure">' + esc(t('Only sure')) + '</button>'
            + '<button type="button" class="btn btn-link btn-sm p-0 js-bulk-select" data-select="none">' + esc(t('Deselect all')) + '</button>'
            + '</div>'
            + '<div class="ia-help">' + esc(t('Yellow suggestions are less certain - review them before mapping.')) + '</div>'
            + '<ul>' + items + '</ul>'
            + '<button type="button" class="btn btn-success btn-sm js-bulk-run"></button> '
            + '<button type="button" class="btn btn-link btn-sm js-bulk-cancel">' + esc(t('Cancel')) + '</button></div>'
        );
        updateBulkCount();
    }

    function updateBulkCount() {
        var n = $root.find('.js-bulk-item:checked').length;
        $root.find('.js-bulk-run').prop('disabled', !n).text(t('Map selected (%count%)', { count: n }));
    }

    function runBulk() {
        var mappings = {};
        $root.find('.js-bulk-item:checked').each(function () {
            var c = byId(parseInt(this.value, 10));
            mappings[c.id] = c.suggestions[0].inpost_category_id;
        });
        if ($.isEmptyObject(mappings)) {
            return;
        }
        $root.find('.js-bulk-run').prop('disabled', true).text(t('Mapping...'));
        $.post(urls.bulk, { mappings: mappings }).done(function (res) {
            (res.mapped || []).forEach(function (id) {
                var c = byId(id);
                markMapped(c, c.suggestions[0], false);
            });
            $root.find('.ia-bulk-wrap').empty();
            render();
            var errors = Object.keys(res.errors || {}).length;
            notify(t('%mapped% categories mapped, %errors% errors.', { mapped: (res.mapped || []).length, errors: errors }), errors ? 'error' : 'success');
        }).fail(function () {
            notify(t('Request failed'), 'error');
            updateBulkCount();
        });
    }

    function notify(message, type) {
        if (window.showSuccessMessage && type === 'success') {
            window.showSuccessMessage(message);
        } else if (window.showErrorMessage && type === 'error') {
            window.showErrorMessage(message);
        } else {
            window.alert(message);
        }
    }

    // ---- wiring ------------------------------------------------------------

    $(function () {
        $root = $('#inpost-assistant-view');
        if (!$root.length) {
            return;
        }
        urls = $root.data();
        loadSkipped();

        $('.inpost-view-switch').on('click', '.btn', function () {
            showView($(this).data('view'));
        });

        $root.on('click', '.js-filter', function () {
            state.filter = $(this).data('filter');
            $root.find('.js-filter').removeClass('active');
            $(this).addClass('active');
            renderList();
        });
        $root.on('change', '.js-with-products', function () {
            state.withProducts = this.checked;
            renderList();
        });
        $root.on('input', '.js-query', function () {
            state.query = $.trim(this.value).toLowerCase();
            renderList();
        });
        $root.on('click', '.js-reload', load);

        $root.on('click', '.js-accept', function () {
            var $b = $(this);
            $b.prop('disabled', true);
            accept(parseInt($b.data('id'), 10), String($b.data('uuid')), parseInt($b.data('entity'), 10));
        });
        $root.on('click', '.js-toggle', function () {
            var id = parseInt($(this).data('id'), 10);
            var panel = $(this).data('panel');
            state.open[id] = state.open[id] === panel ? null : panel;
            refreshRow(byId(id), false);
            $root.find('.ia-row[data-id="' + id + '"] .js-search').trigger('focus');
        });
        $root.on('input', '.js-search', function () {
            runSearch($(this));
        });
        $root.on('click', '.js-skip', function () {
            var c = byId(parseInt($(this).data('id'), 10));
            state.skipped[c.id] = 1;
            saveSkipped();
            refreshRow(c, false);
            renderSummary();
        });
        $root.on('click', '.js-unskip', function () {
            var c = byId(parseInt($(this).data('id'), 10));
            delete state.skipped[c.id];
            saveSkipped();
            refreshRow(c, false);
            renderSummary();
        });
        $root.on('click', '.js-undo', function () {
            unmap(byId(parseInt($(this).data('id'), 10)), true);
        });
        $root.on('click', '.js-unmap', function () {
            var c = byId(parseInt($(this).data('id'), 10));
            if (window.confirm(t('Remove the mapping of "%name%"?', { name: c.name }))) {
                c.previous = null;
                unmap(c, false);
            }
        });
        $root.on('click', '.js-bulk-open', function () {
            openBulk($(this).data('mode'));
        });
        $root.on('click', '.js-bulk-select', function () {
            var mode = $(this).data('select');
            $root.find('.js-bulk-item').each(function () {
                this.checked = mode === 'all' || (mode === 'sure' && $(this).data('level') === 'sure');
            });
            updateBulkCount();
        });
        $root.on('change', '.js-bulk-item', updateBulkCount);
        $root.on('click', '.js-bulk-cancel', function () {
            $root.find('.ia-bulk-wrap').empty();
        });
        $root.on('click', '.js-bulk-run', runBulk);

        var requested = new URL(window.location.href).searchParams.get('view');
        showView(requested === 'assistant' || (!requested && localStorage.getItem(VIEW_KEY) === 'assistant') ? 'assistant' : 'tree');
    });
})(jQuery);
