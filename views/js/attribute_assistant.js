/**
 * Attribute mapping assistant: attributes of mapped InPost categories grouped by
 * name, with suggested PrestaShop sources and dictionary value suggestions
 * (views/templates/admin/attribute_mapping/view.html.twig).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */
(function ($) {
    'use strict';

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
    var icon = function (name) {
        return '<i class="material-icons" style="font-size:16px;vertical-align:-3px;">' + name + '</i>';
    };

    var $root, urls, state = { attributes: [], sources: [], categories: [], open: {}, values: {} };

    function sourceLabel(type, name) {
        var labels = { feature: 'Feature: %name%', product_field: 'Product field: %name%' };
        return t(labels[type] || 'Attribute: %name%', { name: name });
    }

    function sourceName(type, id) {
        var s = state.sources.filter(function (x) { return x.type === type && x.id === id; })[0];
        return s ? s.name : '#' + id;
    }

    function byKey(key) {
        return state.attributes.filter(function (a) { return a.key === key; })[0];
    }

    function badge(score, level) {
        return '<span class="ia-badge ' + esc(level) + '">' + score + '%</span>';
    }

    function fullyMapped(a) {
        return a.current && !a.current.partial && !a.current.mixed;
    }

    // ---- data --------------------------------------------------------------

    function load() {
        var params = {};
        var category = $root.find('.js-category').val();
        if (category && category !== '0') {
            params.category = category;
        }
        if ($root.find('.js-required').is(':checked')) {
            params.required = 1;
        }
        $root.find('.ia-body').html('<div class="ia-empty"><i class="material-icons icon-spin">refresh</i>' + esc(t('Analysing attributes...')) + '</div>');
        $.getJSON(withParams(urls.data, params)).done(function (res) {
            if (!res.success) {
                return fail(res.error);
            }
            state.attributes = res.attributes;
            state.sources = res.sources;
            state.categories = res.categories;
            state.summary = res.summary;
            state.open = {};
            renderCategories();
            render();
        }).fail(function (xhr) {
            fail(xhr.responseJSON && xhr.responseJSON.error);
        });
    }

    function fail(message) {
        $root.find('.ia-body').html('<div class="alert alert-danger">' + esc(t('Request failed')) + (message ? ': ' + esc(message) : '') + '</div>');
    }

    // ---- rendering ---------------------------------------------------------

    function renderCategories() {
        var $select = $root.find('.js-category');
        var current = $select.val() || String(urls.category || 0);
        $select.html('<option value="0">' + esc(t('All mapped categories (%count%)', { count: state.categories.length })) + '</option>'
            + state.categories.map(function (c) {
                return '<option value="' + c.id + '"' + (String(c.id) === current ? ' selected' : '') + '>' + esc(c.name) + '</option>';
            }).join(''));
    }

    function render() {
        renderSummary();
        if (!state.categories.length) {
            $root.find('.ia-body').html('<div class="ia-empty"><i class="material-icons">link_off</i>'
                + esc(t('No InPost categories are mapped yet - attributes come from mapped categories.'))
                + '<div class="mt-3"><a class="btn btn-primary btn-sm" href="' + esc(urls.categories) + '">' + esc(t('Map categories')) + '</a></div></div>');
            return;
        }
        if (!state.attributes.length) {
            $root.find('.ia-body').html('<div class="ia-empty"><i class="material-icons">search_off</i>' + esc(t('No attributes match the filters.')) + '</div>');
            return;
        }
        $root.find('.ia-body').html('<div class="ia-list">' + state.attributes.map(rowHtml).join('') + '</div>');
    }

    function renderSummary() {
        var s = state.summary || {};
        var pct = s.total ? Math.round(100 * s.mapped / s.total) : 0;
        var sure = acceptable(true).length;
        var all = acceptable(false).length;
        $root.find('.ia-summary').html(
            '<div class="ia-progress">'
            + '<div class="d-flex justify-content-between mb-1"><strong>' + esc(t('Source set for %mapped% of %total% attributes', { mapped: s.mapped || 0, total: s.total || 0 })) + '</strong><span class="ia-muted">' + pct + '%</span></div>'
            + '<div class="progress"><div class="progress-bar bg-info" style="width:' + pct + '%"></div></div></div>'
            + '<a href="#" class="ia-chip js-required-chip" title="' + esc(t('Show only required')) + '"><span class="dot" style="background:#dc3545"></span>' + esc(t('Required without source')) + ': ' + (s.required_unmapped || 0) + '</a>'
            + '<span class="ia-chip"><span class="dot dot-sure"></span>' + esc(t('Sure')) + ': ' + (s.sure || 0) + '</span>'
            + '<span class="ia-chip"><span class="dot dot-check"></span>' + esc(t('To check')) + ': ' + (s.check || 0) + '</span>'
            + '<button type="button" class="btn btn-success btn-sm js-bulk-open" data-mode="sure"' + (sure ? '' : ' disabled') + '>' + icon('done_all') + ' '
            + esc(t('Accept all sure (%count%)', { count: sure })) + '</button>'
            + '<button type="button" class="btn btn-outline-success btn-sm js-bulk-open" data-mode="all"' + (all ? '' : ' disabled') + '>'
            + esc(t('Accept all (%count%)', { count: all })) + '</button>'
        );
    }

    // Attributes without a (complete) source that have a suggestion; onlySure keeps the green ones
    function acceptable(onlySure) {
        return state.attributes.filter(function (a) {
            return !fullyMapped(a) && a.suggestions[0] && (!onlySure || a.suggestions[0].level === 'sure');
        });
    }

    function openBulk(mode) {
        var items = acceptable(false).map(function (a) {
            var s = a.suggestions[0];
            var checked = mode === 'all' || s.level === 'sure';
            return '<li><label class="mb-0"><input type="checkbox" class="js-bulk-item" value="' + a.key + '" data-level="' + s.level + '"' + (checked ? ' checked' : '') + '> '
                + badge(s.score, s.level) + '<strong>' + esc(a.name) + '</strong> ← ' + esc(sourceLabel(s.type, s.name))
                + ' <span class="ia-muted">(' + esc(t('in %count% categories', { count: a.categories_count })) + ')</span></label></li>';
        }).join('');
        $root.find('.ia-bulk-wrap').html(
            '<div class="ia-bulk"><div class="d-flex flex-wrap align-items-center mb-1" style="gap:8px">'
            + '<strong>' + esc(t('These sources will be set:')) + '</strong>'
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
        $root.find('.js-bulk-run').prop('disabled', !n).text(t('Set selected (%count%)', { count: n }));
    }

    function runBulk() {
        var sources = {};
        var chosen = [];
        $root.find('.js-bulk-item:checked').each(function () {
            var a = byKey(this.value);
            sources[a.key] = a.suggestions[0].type + ':' + a.suggestions[0].id;
            chosen.push(a);
        });
        if (!chosen.length) {
            return;
        }
        $root.find('.js-bulk-run').prop('disabled', true).text(t('Saving...'));
        $.post(urls.applyBulk, { sources: sources }).done(function (res) {
            chosen.forEach(function (a) {
                var s = a.suggestions[0];
                a.current = { type: s.type, id: s.id, name: s.name, partial: false, mixed: false };
            });
            state.summary.mapped = state.attributes.filter(fullyMapped).length;
            $root.find('.ia-bulk-wrap').empty();
            render();
            notify(t('%count% attributes updated.', { count: chosen.length }), 'success');
        }).fail(function () {
            notify(t('Request failed'), 'error');
            updateBulkCount();
        });
    }

    function reasonText(s) {
        var text = t('name %pct%%', { pct: s.name_pct });
        if (s.by_values) {
            text += ' · ' + t('%matched%/%total% values match', { matched: s.matched, total: s.total });
        }
        return text;
    }

    function rowHtml(a) {
        var names = a.categories.map(function (c) { return c.name; }).join(', ');
        var left = '<div class="ia-ps">'
            + '<div class="name">' + esc(a.name) + '<span class="ia-attr-type">' + esc(a.type) + '</span>'
            + (a.required_count ? '<span class="ia-req">' + esc(t('required')) + '</span>' : '') + '</div>'
            + '<div class="path" title="' + esc(names) + '">'
            + esc(a.categories_count > 1 ? t('in %count% categories', { count: a.categories_count }) + ': ' + names : names)
            + (a.required_count && a.required_count < a.rows_count ? ' · ' + esc(t('required in %count%', { count: a.required_count })) : '')
            + '</div></div>';

        var main = '';
        if (a.current) {
            if (a.current.mixed) {
                main += '<div class="text-warning">' + icon('warning') + ' ' + esc(t('Different sources in different categories')) + '</div>';
            } else {
                main += '<div class="' + (a.current.partial ? 'text-warning' : 'ia-mapped') + '">' + icon(a.current.partial ? 'warning' : 'check_circle') + ' '
                    + esc(sourceLabel(a.current.type, a.current.name || sourceName(a.current.type, a.current.id)))
                    + (a.current.partial ? ' <span class="ia-muted">(' + esc(t('only in some categories')) + ')</span>' : '') + '</div>';
            }
        }
        var best = a.suggestions[0];
        var bestIsCurrent = best && fullyMapped(a) && a.current.type === best.type && a.current.id === best.id;
        if (best && !bestIsCurrent) {
            main += '<div class="ia-option">' + badge(best.score, best.level)
                + '<span class="ia-path"><strong>' + esc(sourceLabel(best.type, best.name)) + '</strong> <span class="ia-muted">' + esc(reasonText(best)) + '</span></span>'
                + '<button type="button" class="btn btn-' + (fullyMapped(a) ? 'outline-primary' : 'primary') + ' btn-sm js-apply" data-key="' + a.key + '" data-type="' + best.type + '" data-id="' + best.id + '">'
                + esc(t(fullyMapped(a) ? 'Use this' : 'Accept')) + '</button></div>';
        } else if (!a.current) {
            main += '<span class="ia-muted">' + esc(t('No matching feature or attribute in the shop - choose one or leave empty.')) + '</span>';
        }

        var actions = '<button type="button" class="btn btn-outline-secondary btn-sm js-toggle" data-panel="source" data-key="' + a.key + '">' + esc(t('Choose source')) + '</button>';
        if (a.dictionary) {
            actions += '<button type="button" class="btn btn-outline-secondary btn-sm js-toggle" data-panel="values" data-key="' + a.key + '">' + esc(t('Values')) + '</button>';
        }

        var panel = '';
        if (state.open[a.key] === 'source') {
            panel = sourcePanel(a);
        } else if (state.open[a.key] === 'values') {
            panel = '<div class="ia-panel ia-values" data-key="' + a.key + '"><span class="ia-muted">' + esc(t('Loading...')) + '</span></div>';
        }

        return '<div class="ia-row' + (fullyMapped(a) ? ' is-mapped' : '') + '" data-key="' + a.key + '">' + left
            + '<div class="ia-main">' + main + panel + '</div><div class="ia-actions">' + actions + '</div></div>';
    }

    function sourcePanel(a) {
        var selected = a.current && !a.current.mixed ? a.current.type + ':' + a.current.id : (a.suggestions[0] ? a.suggestions[0].type + ':' + a.suggestions[0].id : '');
        var group = function (type, label) {
            var opts = state.sources.filter(function (s) { return s.type === type; }).map(function (s) {
                var v = s.type + ':' + s.id;
                var sug = a.suggestions.filter(function (x) { return x.type === s.type && x.id === s.id; })[0];
                return '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + esc(s.name)
                    + (sug ? ' (' + sug.score + '%)' : '') + '</option>';
            }).join('');
            return opts ? '<optgroup label="' + esc(label) + '">' + opts + '</optgroup>' : '';
        };
        return '<div class="ia-panel">'
            + '<select class="form-control form-control-sm ia-source-select js-source">'
            + '<option value="">' + esc(t('-- No source (clear) --')) + '</option>'
            + group('feature', t('Features')) + group('attribute_group', t('Attributes (combinations)'))
            + group('product_field', t('Product fields (reference, MPN, EAN, weight...)'))
            + '</select> '
            + '<button type="button" class="btn btn-primary btn-sm js-apply-select" data-key="' + a.key + '">' + esc(t('Save')) + '</button>'
            + '<div class="ia-help mt-1">' + esc(t('Applies to this attribute in all %count% mapped categories.', { count: a.categories_count })) + '</div>'
            + '</div>';
    }

    function refreshRow(a, flash) {
        var $row = $root.find('.ia-row[data-key="' + a.key + '"]');
        var $new = $(rowHtml(a));
        $row.replaceWith($new);
        if (flash) {
            $new.addClass('flash');
        }
        if (state.open[a.key] === 'values') {
            loadValues(a);
        }
    }

    // ---- values ------------------------------------------------------------

    function valuesSource(a) {
        if (a.current && !a.current.mixed) {
            return { type: a.current.type, id: a.current.id };
        }
        return null;
    }

    function loadValues(a) {
        var $panel = $root.find('.ia-values[data-key="' + a.key + '"]');
        var source = valuesSource(a);
        if (!source) {
            $panel.html('<span class="ia-muted">' + esc(t('Set a source first - values are matched against it.')) + '</span>');
            return;
        }
        $.getJSON(withParams(urls.values, { key: a.key, type: source.type, source_id: source.id })).done(function (res) {
            state.values[a.key] = res.sections || [];
            $panel.html(valuesHtml(a, source, res.sections || []));
        }).fail(function () {
            $panel.html('<span class="text-danger">' + esc(t('Request failed')) + '</span>');
        });
    }

    function valuesHtml(a, source, sections) {
        if (!sections.length) {
            return '<span class="ia-muted">' + esc(t('No values.')) + '</span>';
        }
        return sections.map(function (section) {
            if (!section.options.length) {
                return '<div class="ia-muted">' + esc(t('The InPost dictionary is empty - sync attributes first.')) + '</div>';
            }
            var rows = section.rows.map(function (r) {
                var chosen = r.saved ? r.saved.value : (r.suggestion ? r.suggestion.value : '');
                var checked = !r.saved && r.suggestion && r.suggestion.level === 'sure';
                var status;
                if (r.status === 'mapped') {
                    status = '<span class="ia-mapped">' + icon('check_circle') + ' ' + esc(t('mapped')) + '</span>';
                } else if (r.status === 'auto') {
                    status = '<span class="ia-mapped">' + icon('bolt') + ' ' + esc(t('automatic')) + '</span>';
                } else if (r.status === 'suggested') {
                    status = badge(r.suggestion.score, r.suggestion.level);
                } else {
                    status = '<span class="text-danger">' + esc(t('not matched')) + '</span>';
                }
                var select = r.status === 'auto'
                    ? '<span class="ia-muted">' + esc(r.auto ? labelOf(section, r.auto) : '') + '</span>'
                    : '<select class="form-control form-control-sm js-value" data-ps="' + r.ps_value_id + '">'
                        + '<option value="">' + esc(t('-- not matched --')) + '</option>'
                        + section.options.map(function (o) {
                            return '<option value="' + esc(o.value) + '"' + (o.value === chosen ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                        }).join('') + '</select>';
                var box = r.status === 'auto' ? '' : '<input type="checkbox" class="js-value-check"' + (checked ? ' checked' : '') + '>';
                return '<tr><td>' + esc(r.ps_label) + '</td><td>' + status + '</td><td>' + select + '</td><td class="text-center">' + box + '</td></tr>';
            }).join('');
            return '<div class="mb-2" data-attribute="' + section.attribute_id + '">'
                + (sections.length > 1 ? '<div class="ia-help mb-1">' + esc(t('InPost dictionary used in: %categories%', { categories: section.categories.join(', ') })) + '</div>' : '')
                + '<table class="table table-sm"><thead><tr><th>' + esc(t('In the shop')) + '</th><th>' + esc(t('Status')) + '</th><th>' + esc(t('In InPost')) + '</th><th class="text-center">' + esc(t('Save')) + '</th></tr></thead><tbody>'
                + rows + '</tbody></table>'
                + '<button type="button" class="btn btn-primary btn-sm mt-1 js-save-values" data-key="' + a.key + '" data-attribute="' + section.attribute_id + '" data-type="' + source.type + '">'
                + esc(t('Save checked')) + '</button> <span class="ia-help">' + esc(t('"Automatic" values match by name when the offer is sent - nothing to save.')) + '</span></div>';
        }).join('');
    }

    function labelOf(section, code) {
        var o = section.options.filter(function (x) { return x.value === code; })[0];
        return o ? o.label : code;
    }

    // ---- actions -----------------------------------------------------------

    function apply(a, type, id) {
        return $.post(urls.apply, { key: a.key, type: type || '', source_id: id || 0 }).done(function (res) {
            if (!res.success) {
                notify(t('Request failed'), 'error');
                return;
            }
            a.current = type ? { type: type, id: id, name: sourceName(type, id), partial: false, mixed: false } : null;
            state.summary.mapped = state.attributes.filter(fullyMapped).length;
            if (state.open[a.key] === 'source') {
                state.open[a.key] = a.dictionary && type ? 'values' : null;
            }
            refreshRow(a, true);
            renderSummary();
        }).fail(function () {
            notify(t('Request failed'), 'error');
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

    $(function () {
        $root = $('#inpost-attribute-assistant');
        if (!$root.length) {
            return;
        }
        urls = $root.data();

        $root.on('change', '.js-category, .js-required', load);
        $root.on('click', '.js-required-chip', function (e) {
            e.preventDefault();
            $root.find('.js-required').prop('checked', true);
            load();
        });
        $root.on('click', '.js-reload', load);

        $root.on('click', '.js-apply', function () {
            $(this).prop('disabled', true);
            apply(byKey($(this).data('key')), $(this).data('type'), parseInt($(this).data('id'), 10));
        });
        $root.on('click', '.js-apply-select', function () {
            var a = byKey($(this).data('key'));
            var value = $(this).closest('.ia-panel').find('.js-source').val();
            var parts = value ? value.split(':') : [null, 0];
            apply(a, parts[0], parseInt(parts[1], 10));
        });
        $root.on('click', '.js-toggle', function () {
            var a = byKey($(this).data('key'));
            var panel = $(this).data('panel');
            state.open[a.key] = state.open[a.key] === panel ? null : panel;
            refreshRow(a, false);
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
        $root.on('click', '.js-save-values', function () {
            var $btn = $(this);
            var values = {};
            $btn.closest('[data-attribute]').find('tbody tr').each(function () {
                var $tr = $(this);
                if ($tr.find('.js-value-check').is(':checked')) {
                    var $sel = $tr.find('.js-value');
                    if ($sel.val()) {
                        values[$sel.data('ps')] = $sel.val();
                    }
                }
            });
            if ($.isEmptyObject(values)) {
                notify(t('Tick the values to save.'), 'error');
                return;
            }
            $btn.prop('disabled', true);
            $.post(urls.saveValues, { attribute_id: $btn.data('attribute'), type: $btn.data('type'), values: values }).done(function (res) {
                notify(t('%count% value mappings saved.', { count: res.saved }), 'success');
                loadValues(byKey($btn.data('key')));
            }).fail(function () {
                notify(t('Request failed'), 'error');
                $btn.prop('disabled', false);
            });
        });
        // Picking an option by hand ticks the row
        $root.on('change', '.js-value', function () {
            $(this).closest('tr').find('.js-value-check').prop('checked', !!this.value);
        });

        load();
    });
})(jQuery);
