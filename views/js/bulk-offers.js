/**
 * InPost von Halsky - Bulk offer wizard (GOKINT-930)
 *
 * Chunked, sequential AJAX: pre-validate, then create DRAFT offers, then
 * optionally publish. Keeps going on chunk failure and reports per-product
 * results. Never runs chunks in parallel.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 */

(function ($) {
    'use strict';

    // Translations rendered by the page (views/templates/admin/_partials/js_i18n.html.twig)
    function __(text, params) {
        var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
        Object.keys(params || {}).forEach(function (key) {
            translated = translated.split('%' + key + '%').join(params[key]);
        });
        return translated;
    }

    var Bulk = {
        init: function () {
            var $root = $('.inpost-bulk-wizard');
            if ($root.length === 0) {
                return;
            }

            this.productIds = ($root.data('product-ids') || []).map(Number);
            this.chunkSize = parseInt($root.data('chunk-size'), 10) || 25;
            this.validateUrl = $root.data('validate-url');
            this.createUrl = $root.data('create-url');
            this.publishUrl = $root.data('publish-url');
            this.offerEditUrlTemplate = $root.data('offer-edit-url');
            this.token = new URLSearchParams(window.location.search).get('_token') || '';

            this.itemLabels = {};    // "id_product:id_product_attribute" => display name
            this.createdOfferIds = [];
            this.createResults = [];

            var self = this;
            $('#bulk-validate-btn').on('click', function () { self.validate(); });
            $('#bulk-create-btn').on('click', function () { self.create(); });
            $('#bulk-publish-btn').on('click', function () { self.publish(); });
            $('#bulk-copy-csv-btn').on('click', function () { self.copyCsv(); });

            // Selection of what gets a draft: single items and "all variants" of a product
            $('#bulk-validate-table')
                .on('change', '.js-bulk-item', function () {
                    self.syncGroupCheckbox($(this).data('product'));
                    self.updateSelection();
                })
                .on('change', '.js-bulk-select-group', function (e) {
                    // The back office scripts trigger synthetic "change" events on
                    // checkboxes - only a real click may (un)select all variants
                    if (!e.originalEvent) {
                        return;
                    }
                    $('#bulk-validate-table .js-bulk-item[data-product="' + $(this).data('product') + '"]:not(:disabled)')
                        .prop('checked', $(this).is(':checked'));
                    self.updateSelection();
                });

            // The list is the starting point - check the selected products right away
            if (this.productIds.length > 0) {
                this.validate();
            }
        },

        /** Items ("id_product:id_product_attribute") checked for creation */
        selectedItems: function () {
            return $('#bulk-validate-table .js-bulk-item:checked').map(function () {
                return String($(this).data('item'));
            }).get();
        },

        updateSelection: function () {
            var count = this.selectedItems().length;
            $('#bulk-selection-summary').text(__('%count% selected for creation.', { count: count }));
            $('#bulk-create-btn').prop('disabled', count === 0);
        },

        chunk: function (arr, size) {
            var out = [];
            for (var i = 0; i < arr.length; i += size) {
                out.push(arr.slice(i, i + size));
            }
            return out;
        },

        setProgress: function (sel, done, total) {
            var pct = total > 0 ? Math.round((done / total) * 100) : 100;
            $(sel).css('width', pct + '%').text(pct + '%');
        },

        // ---- Pre-validation ----
        validate: function () {
            var self = this;
            var chunks = this.chunk(this.productIds, 50);
            var total = this.productIds.length;
            var done = 0;
            var counts = { ready: 0, warning: 0, blocked: 0 };

            this.itemLabels = {};
            $('#bulk-validate-table tbody').empty();
            $('#bulk-validate-table').show();
            $('#bulk-validate-progress-wrap').show();
            $('#bulk-validate-btn').prop('disabled', true);

            var i = 0;
            function next() {
                if (i >= chunks.length) {
                    $('#bulk-validate-summary').html(
                        '<strong>' + __('%total% selected:', { total: total }) + '</strong> '
                        + __('%ready% ready, %warning% with warnings, %blocked% blocked.', { ready: counts.ready, warning: counts.warning, blocked: counts.blocked })
                    );
                    $('#bulk-validate-btn').prop('disabled', false);
                    self.updateSelection();
                    return;
                }
                var ids = chunks[i++];
                $.post(self.validateUrl, { product_ids: ids, _token: self.token })
                    .done(function (resp) {
                        if (resp.success && resp.results) {
                            resp.results.forEach(function (r) {
                                var variants = (r.status !== 'blocked' && r.combinations) ? r.combinations : [];
                                if (variants.length) {
                                    variants.forEach(function (c) { counts[c.status] = (counts[c.status] || 0) + 1; });
                                } else {
                                    counts[r.status] = (counts[r.status] || 0) + 1;
                                }
                                self.appendValidateRow(r);
                            });
                        }
                    })
                    .always(function () {
                        done += ids.length;
                        self.setProgress('#bulk-validate-progress', done, total);
                        next();
                    });
            }
            next();
        },

        appendValidateRow: function (r) {
            var self = this;
            var $tbody = $('#bulk-validate-table tbody');
            var variants = (r.status !== 'blocked' && r.combinations) ? r.combinations : [];
            var name = Bulk.escape(r.name) + ' <small class="text-muted">#' + r.id_product + '</small>';

            if (!variants.length) {
                // Product without combinations (or blocked as a whole): one item
                var item = r.id_product + ':0';
                self.itemLabels[item] = r.name;
                $tbody.append(
                    '<tr data-item-row="' + item + '">'
                    + '<td>' + self.checkbox(item, r.id_product, r.status !== 'blocked', r.status !== 'blocked') + '</td>'
                    + '<td>' + name + '</td>'
                    + '<td>' + self.badge(r.status) + '</td>'
                    + '<td>' + self.messages(r.messages) + '</td></tr>'
                );
                return;
            }

            // Product with combinations: a group row, then one row per combination
            $tbody.append(
                '<tr class="table-active">'
                + '<td><input type="checkbox" class="js-bulk-select-group" data-product="' + r.id_product + '" title="' + Bulk.escape(__('Select all variants')) + '"></td>'
                + '<td><strong>' + name + '</strong> <span class="badge badge-secondary">' + Bulk.escape(__('%count% variant(s)', { count: variants.length })) + '</span></td>'
                + '<td>' + self.badge(r.status) + '</td>'
                + '<td>' + self.messages(r.messages) + '</td></tr>'
            );
            variants.forEach(function (c) {
                var item = r.id_product + ':' + c.id_product_attribute;
                var blocked = c.status === 'blocked';
                self.itemLabels[item] = r.name + ' - ' + c.label;
                var details = [c.reference, c.ean13].filter(Boolean).map(Bulk.escape).join(' · ');
                var offerLink = c.offer_id
                    ? ' <a href="' + Bulk.escape(self.offerEditUrl(c.offer_id)) + '" target="_blank">' + Bulk.escape(__('Open offer')) + ' #' + c.offer_id + '</a>'
                    : '';
                $tbody.append(
                    '<tr data-item-row="' + item + '">'
                    + '<td class="pl-4">' + self.checkbox(item, r.id_product, !blocked, !blocked && c.selected) + '</td>'
                    + '<td class="pl-4">' + Bulk.escape(c.label)
                    + ' <small class="text-muted">' + details + (details ? ' · ' : '') + Bulk.escape(__('Stock')) + ': ' + Number(c.quantity) + '</small></td>'
                    + '<td>' + self.badge(c.status) + '</td>'
                    + '<td>' + self.messages(c.messages) + offerLink + '</td></tr>'
                );
            });
            self.syncGroupCheckbox(r.id_product);
        },

        checkbox: function (item, productId, enabled, checked) {
            return '<input type="checkbox" class="js-bulk-item" data-item="' + item + '" data-product="' + productId + '"'
                + (enabled ? '' : ' disabled') + (checked ? ' checked' : '') + '>';
        },

        syncGroupCheckbox: function (productId) {
            var $items = $('#bulk-validate-table .js-bulk-item[data-product="' + productId + '"]:not(:disabled)');
            $('#bulk-validate-table .js-bulk-select-group[data-product="' + productId + '"]')
                .prop('checked', $items.length > 0 && $items.filter(':checked').length === $items.length)
                .prop('disabled', $items.length === 0);
        },

        badge: function (status) {
            var badge = { ready: 'badge-success', warning: 'badge-warning', blocked: 'badge-danger' }[status] || 'badge-secondary';
            return '<span class="badge ' + badge + '">' + Bulk.escape(__(status)) + '</span>';
        },

        messages: function (messages) {
            var items = (messages || []).map(function (m) { return '<li>' + Bulk.escape(m) + '</li>'; }).join('');
            return items ? '<ul class="mb-0">' + items + '</ul>' : '';
        },

        offerEditUrl: function (offerId) {
            return String(this.offerEditUrlTemplate || '').replace('999999', offerId);
        },

        // ---- Creation ----
        create: function () {
            var self = this;
            var items = this.selectedItems();
            if (items.length === 0) {
                return;
            }
            var chunks = this.chunk(items, this.chunkSize);
            var total = items.length;
            var done = 0;
            var counts = { created: 0, skipped: 0, error: 0 };

            this.createdOfferIds = [];
            this.createResults = [];
            $('#bulk-create-table tbody').empty();
            $('#bulk-create-table').show();
            $('#bulk-create-progress-wrap').show();
            $('#bulk-create-btn').prop('disabled', true);
            $('#bulk-validate-table .js-bulk-item, #bulk-validate-table .js-bulk-select-group').prop('disabled', true);

            var i = 0;
            function next() {
                if (i >= chunks.length) {
                    $('#bulk-create-summary').html(
                        '<strong>' + __('Done:') + '</strong> '
                        + __('%created% created, %skipped% skipped, %errors% errors.', { created: counts.created, skipped: counts.skipped, errors: counts.error })
                    );
                    $('#bulk-copy-csv-btn').show();
                    if (self.createdOfferIds.length > 0) {
                        $('#bulk-publish-btn').show();
                    }
                    return;
                }
                var ids = chunks[i++];
                $.post(self.createUrl, { items: ids, _token: self.token })
                    .done(function (resp) {
                        if (resp.success && resp.results) {
                            resp.results.forEach(function (r) {
                                counts[r.status] = (counts[r.status] || 0) + 1;
                                if (r.status === 'created' && r.offer_id) {
                                    self.createdOfferIds.push(r.offer_id);
                                }
                                self.createResults.push(r);
                                self.appendCreateRow(r);
                            });
                        } else {
                            // Whole chunk failed: mark each item as error, keep going.
                            ids.forEach(function (item) {
                                counts.error++;
                                var r = self.failedItem(item, resp.error || __('Request failed'));
                                self.createResults.push(r);
                                self.appendCreateRow(r);
                            });
                        }
                    })
                    .fail(function () {
                        ids.forEach(function (item) {
                            counts.error++;
                            var r = self.failedItem(item, __('Request failed'));
                            self.createResults.push(r);
                            self.appendCreateRow(r);
                        });
                    })
                    .always(function () {
                        done += ids.length;
                        self.setProgress('#bulk-create-progress', done, total);
                        next();
                    });
            }
            next();
        },

        failedItem: function (item, message) {
            var parts = String(item).split(':');
            return { id_product: Number(parts[0]), id_product_attribute: Number(parts[1] || 0), status: 'error', offer_id: null, message: message };
        },

        appendCreateRow: function (r) {
            var badge = { created: 'badge-success', skipped: 'badge-secondary', error: 'badge-danger' }[r.status] || 'badge-secondary';
            var label = this.itemLabels[r.id_product + ':' + (r.id_product_attribute || 0)] || '';
            $('#bulk-create-table tbody').append(
                '<tr><td>#' + r.id_product + (label ? ' <small>' + Bulk.escape(label) + '</small>' : '') + '</td>'
                + '<td><span class="badge ' + badge + '">' + Bulk.escape(__(r.status)) + '</span></td>'
                + '<td>' + (r.offer_id || '') + '</td>'
                + '<td>' + Bulk.escape(r.message || '') + '</td></tr>'
            );
        },

        // ---- Publication ----
        publish: function () {
            var self = this;
            if (this.createdOfferIds.length === 0) {
                return;
            }
            $('#bulk-publish-btn').prop('disabled', true);
            $('#bulk-publish-summary').html(__('Publishing...'));

            var chunks = this.chunk(this.createdOfferIds, this.chunkSize);
            var i = 0;
            var created = 0, updated = 0, errors = 0;
            function next() {
                if (i >= chunks.length) {
                    $('#bulk-publish-summary').html(
                        '<strong>' + __('Publish done:') + '</strong> ' + __('%created% created, %updated% updated, %errors% errors.', { created: created, updated: updated, errors: errors })
                    );
                    $('#bulk-publish-btn').prop('disabled', false);
                    return;
                }
                var ids = chunks[i++];
                $.post(self.publishUrl, { offer_ids: ids, _token: self.token })
                    .done(function (resp) {
                        if (resp.success && resp.result && resp.result.summary) {
                            created += resp.result.summary.created || 0;
                            updated += resp.result.summary.updated || 0;
                            errors += resp.result.summary.errors || 0;
                        } else {
                            errors += ids.length;
                        }
                    })
                    .fail(function () { errors += ids.length; })
                    .always(function () { next(); });
            }
            next();
        },

        copyCsv: function () {
            var rows = [['id_product', 'id_product_attribute', 'status', 'offer_id', 'message']];
            this.createResults.forEach(function (r) {
                rows.push([r.id_product, r.id_product_attribute || 0, r.status, r.offer_id || '', (r.message || '').replace(/[\r\n,]+/g, ' ')]);
            });
            var csv = rows.map(function (r) { return r.join(','); }).join('\n');
            navigator.clipboard.writeText(csv);
        },

        escape: function (value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    };

    $(document).ready(function () {
        Bulk.init();
    });
})(jQuery);
