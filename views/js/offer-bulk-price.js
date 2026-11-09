/**
 * InPost von Halsky - "Set price" dialog of the offers list (GOKINT-1098)
 *
 * Takes the offers ticked on the list and sets their InPost price: one manual
 * price for all, a manual price = shop price +/- %, or back to the automatic
 * price. The preview shows the price per offer; apply saves it and reloads the
 * page - the flash messages tell what happened.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 */

(function ($) {
    'use strict';

    // Translations rendered by the page (offer/_bulk_price_modal.html.twig)
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

    function money(value, currency) {
        return value == null ? '' : Number(value).toFixed(2) + ' ' + (currency || 'PLN');
    }

    var BulkPrice = {
        init: function () {
            var $modal = $('#bulk-price-modal');
            if ($modal.length === 0) {
                return;
            }

            this.$modal = $modal;
            this.$preview = $('#bp-preview');
            this.$error = $('#bp-error');
            this.$applyBtn = $('#bp-apply-btn');
            this.offerIds = [];

            var self = this;

            $modal.on('show.bs.modal', function (e) {
                if (e.target === $modal[0]) {
                    self.open();
                }
            });

            $modal.on('change', '.js-bp-operation', function () {
                self.toggleFields();
                self.changed();
            });

            $modal.on('input change', '#bp-amount, #bp-percent, #bp-rounding', function () {
                self.changed();
            });

            $('#bp-preview-btn').on('click', function () {
                self.preview();
            });

            this.$applyBtn.on('click', function () {
                self.apply();
            });
        },

        open: function () {
            this.offerIds = $('.offer-bulk-cb:checked').map(function () {
                return $(this).val();
            }).get();
            $('#bp-offer-count').text('(' + this.offerIds.length + ')');
            this.toggleFields();
            this.changed();
        },

        operation: function () {
            return this.$modal.find('.js-bp-operation:checked').val();
        },

        toggleFields: function () {
            var operation = this.operation();
            this.$modal.find('.js-bp-fields').each(function () {
                $(this).toggleClass('d-none', $(this).data('operation') !== operation);
            });
        },

        // Any change invalidates the preview
        changed: function () {
            this.$applyBtn.prop('disabled', true);
            this.$preview.empty();
            this.showError(null);
        },

        params: function () {
            return {
                operation: this.operation(),
                amount: $('#bp-amount').val(),
                percent: $('#bp-percent').val(),
                rounding: $('#bp-rounding').val()
            };
        },

        preview: function () {
            var self = this;
            this.$applyBtn.prop('disabled', true);
            this.$preview.html('<p class="text-muted">' + esc(__('Loading...')) + '</p>');
            this.post(this.$modal.data('preview-url'), this.params()).done(function (response) {
                self.renderPreview(response.rows);
            }).fail(function () {
                self.$preview.empty();
            });
        },

        renderPreview: function (rows) {
            var counts = { change: 0, same: 0, skip: 0 };
            var labels = { change: __('Change'), same: __('No change'), skip: __('Skipped') };
            var badges = { change: 'badge-primary', same: 'badge-secondary', skip: 'badge-warning' };
            var mode = function (value) {
                return value ? ' <small class="text-muted">(' + esc(__(value === 'manual' ? 'manual' : 'automatic')) + ')</small>' : '';
            };

            var html = '<div class="bp-table-wrap"><table class="table table-sm table-bordered mb-2"><thead><tr>'
                + '<th>' + esc(__('Offer')) + '</th>'
                + '<th>' + esc(__('Current price')) + '</th>'
                + '<th>' + esc(__('New price')) + '</th>'
                + '<th>' + esc(__('Result')) + '</th>'
                + '</tr></thead><tbody>';

            rows.forEach(function (row) {
                counts[row.result]++;
                html += '<tr class="bp-' + esc(row.result) + '">'
                    + '<td>#' + esc(row.offer_id) + ' ' + esc(row.product) + (row.sku ? ' <small class="text-muted">' + esc(row.sku) + '</small>' : '') + '</td>'
                    + '<td>' + esc(money(row.current, row.currency)) + mode(row.current_mode) + '</td>'
                    + '<td>' + (row.result === 'skip' ? '' : esc(money(row.new, row.currency)) + mode(row.new_mode)) + '</td>'
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
            this.$applyBtn.prop('disabled', counts.change === 0);
        },

        apply: function () {
            if (!window.confirm(__('Change the price of %count% offer(s)? Offers already in InPost are sent there within a few minutes.', { count: this.pendingChanges }))) {
                return;
            }
            var self = this;
            this.$applyBtn.prop('disabled', true);
            this.post(this.$modal.data('apply-url'), $.extend(this.params(), { _csrf: this.$modal.data('csrf') })).done(function () {
                window.location.reload();
            }).fail(function () {
                self.$applyBtn.prop('disabled', false);
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
        BulkPrice.init();
    });
})(jQuery);
