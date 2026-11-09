/**
 * InPost von Halsky - "Edit attributes" dialog of the offers list (GOKINT-1102)
 *
 * Takes the offers ticked on the list, lists the attributes of their InPost
 * categories (grouped by name). Every attribute picked gets a row with its own
 * operation: set a value, clear it, restore it from PrestaShop or map it to a
 * PrestaShop source. The preview shows the result per offer (and the category
 * mappings a "map" changes); apply saves it and reloads the page - the flash
 * messages tell what happened.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 */

(function ($) {
    'use strict';

    // Translations rendered by the page (offer/_bulk_attributes_i18n.html.twig)
    function __(text, params) {
        var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
        Object.keys(params || {}).forEach(function (key) {
            translated = translated.split('%' + key + '%').join(params[key]);
        });
        return translated;
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    var TYPE_LABELS = {
        DICTIONARY: 'list',
        NUMERIC: 'whole number',
        NUMERIC_FLOAT: 'number',
        URL: 'link',
        DATE: 'date'
    };

    var OPERATIONS = [
        ['set', 'Set value'],
        ['clear', 'Clear value'],
        ['restore', 'Restore from PrestaShop'],
        ['map', 'Map from PrestaShop']
    ];

    var SOURCE_GROUPS = [
        ['features', 'feature', 'Features'],
        ['attribute_groups', 'attribute_group', 'Attributes'],
        ['product_fields', 'product_field', 'Product fields']
    ];

    // "Feature: Colour" - a feature and a product field can have the same name
    function sourceLabel(type, name) {
        var group = SOURCE_GROUPS.filter(function (g) { return g[1] === type; })[0];
        return group ? __(group[2]) + ': ' + name : name;
    }

    var BulkAttributes = {
        init: function () {
            var $modal = $('#bulk-attributes-modal');
            if ($modal.length === 0) {
                return;
            }

            this.$modal = $modal;
            this.$attributes = $('#ba-attributes');
            this.$changes = $('#ba-changes');
            this.$rows = this.$changes.find('tbody');
            this.$preview = $('#ba-preview');
            this.$error = $('#ba-error');
            this.$previewBtn = $('#ba-preview-btn');
            this.$applyBtn = $('#ba-apply-btn');
            this.attributes = {};
            this.sources = {};
            this.offerIds = [];

            var self = this;

            $modal.on('show.bs.modal', function (e) {
                if (e.target === $modal[0]) {
                    self.open();
                }
            });

            this.$attributes.on('change', function () {
                self.syncRows();
                self.changed();
            });

            this.$rows.on('change', '.js-ba-operation', function () {
                self.renderValueCell($(this).closest('tr'));
                self.changed();
            });

            this.$rows.on('input change', '.js-ba-value, .js-ba-source', function () {
                self.changed();
            });

            this.$rows.on('click', '.js-ba-remove', function () {
                var key = $(this).closest('tr').data('key');
                self.$attributes.val((self.$attributes.val() || []).filter(function (k) { return k !== key; })).trigger('change');
            });

            this.$previewBtn.on('click', function () {
                self.preview();
            });

            this.$applyBtn.on('click', function () {
                self.apply();
            });
        },

        selectedOfferIds: function () {
            return $('.offer-bulk-cb:checked').map(function () {
                return $(this).val();
            }).get();
        },

        open: function () {
            this.offerIds = this.selectedOfferIds();
            this.attributes = {};
            if (this.$attributes.hasClass('select2-hidden-accessible')) {
                this.$attributes.select2('destroy');
            }
            $('#ba-offer-count').text('(' + this.offerIds.length + ')');
            this.$attributes.empty().prop('disabled', true);
            this.$rows.empty();
            this.$changes.addClass('d-none');
            $('#ba-map-note').addClass('d-none');
            this.$preview.empty();
            this.showError(null);
            this.$previewBtn.prop('disabled', true);
            this.$applyBtn.prop('disabled', true);

            var self = this;
            this.$preview.html('<p class="text-muted">' + esc(__('Loading...')) + '</p>');
            this.post(this.$modal.data('attributes-url'), {}).done(function (response) {
                self.$preview.empty();
                self.sources = response.sources || {};
                if (!response.attributes.length) {
                    self.showError(__('The selected offers have no InPost category attributes.'));
                    return;
                }
                response.attributes.forEach(function (attribute) {
                    self.attributes[attribute.key] = attribute;
                    self.$attributes.append($('<option>').val(attribute.key).text(self.attributeLabel(attribute)));
                });
                self.$attributes.prop('disabled', false);
                if ($.fn.select2) {
                    self.$attributes.select2({ width: '100%', placeholder: __('-- select attributes --'), dropdownParent: self.$modal });
                }
            }).fail(function () {
                self.$preview.empty();
            });
        },

        attributeLabel: function (attribute) {
            var parts = [
                attribute.name,
                __(TYPE_LABELS[attribute.type] || 'text'),
                __('%offers% offer(s), %categories% categor(y/ies)', { offers: attribute.offers, categories: attribute.categories })
            ];
            if (attribute.required) {
                parts.push(__('required'));
            }
            return parts.join(' · ');
        },

        // One row per selected attribute, in the order of the selection; rows already there keep their input
        syncRows: function () {
            var self = this;
            var keys = this.$attributes.val() || [];
            this.$rows.children('tr').each(function () {
                if (keys.indexOf($(this).data('key')) === -1) {
                    $(this).remove();
                }
            });
            keys.forEach(function (key) {
                var $row = self.$rows.children('tr').filter(function () { return $(this).data('key') === key; });
                if ($row.length === 0) {
                    $row = self.buildRow(self.attributes[key]);
                }
                self.$rows.append($row);
            });
            this.$changes.toggleClass('d-none', keys.length === 0);
        },

        buildRow: function (attribute) {
            var $operation = $('<select class="form-control form-control-sm js-ba-operation">');
            OPERATIONS.forEach(function (op) {
                $operation.append($('<option>').val(op[0]).text(__(op[1])));
            });

            var $row = $('<tr>').data('key', attribute.key)
                .append($('<td>').append($('<strong>').text(attribute.name))
                    .append($('<span class="ba-meta">').text(this.attributeLabel(attribute).split(' · ').slice(1).join(' · '))))
                .append($('<td>').append($operation))
                .append($('<td class="js-ba-value-cell">'))
                .append($('<td>').append($('<button type="button" class="btn btn-link btn-sm p-0 js-ba-remove">').attr('title', __('Remove'))
                    .append('<i class="material-icons">close</i>')));
            this.renderValueCell($row);

            return $row;
        },

        renderValueCell: function ($row) {
            var attribute = this.attributes[$row.data('key')];
            var operation = $row.find('.js-ba-operation').val();
            var $cell = $row.find('.js-ba-value-cell').empty();

            if (operation === 'map') {
                $cell.append(this.sourceSelect(attribute));
            } else if (operation === 'set') {
                $cell.append(this.valueField(attribute));
            } else {
                $cell.append($('<span class="text-muted small">').text(operation === 'clear' ? __('The value is removed from the offers.') : __('The value comes from the PrestaShop mapping again.')));
            }
            if (operation === 'set' && attribute.type === 'DICTIONARY' && attribute.multiple && $.fn.select2) {
                $cell.find('select').select2({ width: '100%', placeholder: __('Select one or more...'), dropdownParent: this.$modal });
            }
            $('#ba-map-note').toggleClass('d-none', this.$rows.find('.js-ba-operation').filter(function () { return $(this).val() === 'map'; }).length === 0);
        },

        valueField: function (attribute) {
            var $field;
            switch (attribute.type) {
                case 'DICTIONARY':
                    $field = $('<select class="form-control form-control-sm">').prop('multiple', attribute.multiple);
                    if (!attribute.multiple) {
                        $field.append($('<option>').val('').text(__('-- select --')));
                    }
                    attribute.options.forEach(function (label) {
                        $field.append($('<option>').val(label).text(label));
                    });
                    break;
                case 'LONG_TEXT_VALUE':
                    $field = $('<textarea class="form-control form-control-sm" rows="2">');
                    break;
                case 'NUMERIC':
                    $field = $('<input type="text" class="form-control form-control-sm" inputmode="numeric">');
                    break;
                case 'NUMERIC_FLOAT':
                    $field = $('<input type="text" class="form-control form-control-sm" inputmode="decimal">');
                    break;
                case 'URL':
                    $field = $('<input type="url" class="form-control form-control-sm">');
                    break;
                case 'DATE':
                    $field = $('<input type="date" class="form-control form-control-sm">');
                    break;
                default:
                    $field = $('<input type="text" class="form-control form-control-sm" maxlength="1024">');
            }

            return $field.addClass('js-ba-value');
        },

        sourceSelect: function (attribute) {
            var self = this;
            var $select = $('<select class="form-control form-control-sm js-ba-source">')
                .append($('<option>').val('').text(__('-- select a source --')));
            SOURCE_GROUPS.forEach(function (group) {
                var list = self.sources[group[0]] || [];
                if (!list.length) {
                    return;
                }
                var $group = $('<optgroup>').attr('label', __(group[2]));
                list.forEach(function (source) {
                    $group.append($('<option>').val(group[1] + ':' + source.id).text(source.name));
                });
                $select.append($group);
            });
            if (attribute.source) {
                $select.val(attribute.source);
            }

            return $select;
        },

        changes: function () {
            return this.$rows.children('tr').map(function () {
                var $row = $(this);
                var operation = $row.find('.js-ba-operation').val();
                var value = $row.find('.js-ba-value').val();
                var values = operation === 'set' ? ($.isArray(value) ? value : [value]) : [];
                return {
                    key: $row.data('key'),
                    operation: operation,
                    values: values.map(function (v) { return $.trim(v || ''); }).filter(function (v) { return v !== ''; }),
                    source: operation === 'map' ? ($row.find('.js-ba-source').val() || '') : ''
                };
            }).get();
        },

        // Any change of the form invalidates the preview
        changed: function () {
            var changes = this.changes();
            var ready = changes.length > 0 && changes.every(function (change) {
                return (change.operation !== 'set' || change.values.length > 0) && (change.operation !== 'map' || change.source !== '');
            });
            this.$previewBtn.prop('disabled', !ready);
            this.$applyBtn.prop('disabled', true);
            this.$preview.empty();
            this.showError(null);
        },

        preview: function () {
            var self = this;
            this.$applyBtn.prop('disabled', true);
            this.$preview.html('<p class="text-muted">' + esc(__('Loading...')) + '</p>');
            this.post(this.$modal.data('preview-url'), { changes: this.changes() }).done(function (response) {
                self.renderPreview(response.rows, response.mappings || []);
            }).fail(function () {
                self.$preview.empty();
            });
        },

        renderPreview: function (rows, mappings) {
            var counts = { change: 0, same: 0, skip: 0 };
            var labels = { change: __('Change'), same: __('No change'), skip: __('Skipped') };
            var badges = { change: 'badge-primary', same: 'badge-secondary', skip: 'badge-warning' };
            var empty = '<span class="text-muted">' + esc(__('empty')) + '</span>';
            var html = '';

            if (mappings.length) {
                html += '<h6>' + esc(__('Category mapping')) + '</h6><div class="ba-table-wrap"><table class="table table-sm table-bordered mb-2"><thead><tr>'
                    + '<th>' + esc(__('Attribute')) + '</th>'
                    + '<th>' + esc(__('InPost category')) + '</th>'
                    + '<th>' + esc(__('Current source')) + '</th>'
                    + '<th>' + esc(__('New source')) + '</th>'
                    + '<th>' + esc(__('Result')) + '</th>'
                    + '</tr></thead><tbody>';
                mappings.forEach(function (mapping) {
                    html += '<tr><td>' + esc(mapping.attribute) + '</td><td>' + esc(mapping.category) + '</td>'
                        + '<td>' + (mapping.from !== '' ? esc(sourceLabel(mapping.from_type, mapping.from)) : empty) + '</td>'
                        + '<td>' + esc(sourceLabel(mapping.to_type, mapping.to)) + '</td>'
                        + '<td><span class="badge ' + badges[mapping.result] + '">' + esc(labels[mapping.result]) + '</span></td></tr>';
                });
                html += '</tbody></table></div>';
                this.pendingMappings = mappings.filter(function (m) { return m.result === 'change'; }).length;
            } else {
                this.pendingMappings = 0;
            }

            html += '<h6>' + esc(__('Offers')) + '</h6><div class="ba-table-wrap"><table class="table table-sm table-bordered mb-2"><thead><tr>'
                + '<th>' + esc(__('Offer')) + '</th>'
                + '<th>' + esc(__('Attribute')) + '</th>'
                + '<th>' + esc(__('InPost category')) + '</th>'
                + '<th>' + esc(__('Current value')) + '</th>'
                + '<th>' + esc(__('New value')) + '</th>'
                + '<th>' + esc(__('Result')) + '</th>'
                + '</tr></thead><tbody>';

            rows.forEach(function (row) {
                counts[row.result]++;
                var current = row.current !== '' ? esc(row.current) : empty;
                if (row.current_source === 'ps') {
                    current += ' <small class="text-muted">(' + esc(__('from PrestaShop')) + ')</small>';
                }
                var newValue = row.result === 'skip' ? '' : (row.new !== '' ? esc(row.new) : empty);

                html += '<tr class="ba-' + esc(row.result) + '">'
                    + '<td>#' + esc(row.offer_id) + ' ' + esc(row.product) + (row.sku ? ' <small class="text-muted">' + esc(row.sku) + '</small>' : '') + '</td>'
                    + '<td>' + esc(row.attribute) + '</td>'
                    + '<td>' + esc(row.category) + '</td>'
                    + '<td>' + current + '</td>'
                    + '<td>' + newValue + '</td>'
                    + '<td><span class="badge ' + badges[row.result] + '">' + esc(labels[row.result]) + '</span>'
                    + (row.reason ? '<br><small>' + esc(row.reason) + '</small>' : '') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
            html += '<p class="mb-0"><strong>' + esc(__('Changed: %changed%, no change: %same%, skipped: %skipped%', {
                changed: counts.change, same: counts.same, skipped: counts.skip
            })) + '</strong></p>';

            this.$preview.html(html);
            this.pendingChanges = counts.change;
            this.$applyBtn.prop('disabled', counts.change === 0 && this.pendingMappings === 0);
        },

        apply: function () {
            var message = __('Apply %count% change(s) to the offers? Offers already in InPost are sent there within a few minutes.', { count: this.pendingChanges });
            if (this.pendingMappings > 0) {
                message += '\n\n' + __('The PrestaShop source is also saved in %count% category mapping(s).', { count: this.pendingMappings });
            }
            if (!window.confirm(message)) {
                return;
            }
            var self = this;
            this.$applyBtn.prop('disabled', true);
            this.$previewBtn.prop('disabled', true);
            this.post(this.$modal.data('apply-url'), { changes: this.changes(), _csrf: this.$modal.data('csrf') }).done(function () {
                window.location.reload();
            }).fail(function () {
                self.$previewBtn.prop('disabled', false);
            });
        },

        post: function (url, data) {
            var self = this;
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ offer_ids: this.offerIds }, data)
            }).then(function (response) {
                if (!response || !response.success) {
                    var error = (response && response.error) || __('Request failed');
                    self.showError(error);
                    return $.Deferred().reject(error).promise();
                }
                return response;
            }, function (xhr) {
                var error = (xhr.responseJSON && xhr.responseJSON.error) || __('Request failed');
                self.showError(error);
                return $.Deferred().reject(error).promise();
            });
        },

        showError: function (message) {
            this.$error.toggleClass('d-none', !message).text(message || '');
        }
    };

    $(function () {
        BulkAttributes.init();
    });
})(jQuery);
