/**
 * InPost von Halsky - Productization hint picker (GOKINT-927)
 *
 * Edit mode (HintPicker): search /hints candidates in a modal, then apply a
 * chosen one to the saved offer server-side. The offer stays DRAFT; nothing is
 * sent to InPost. After apply the page reloads (attributes rendered per category).
 *
 * Create mode (HintCreate): once a PrestaShop product is selected, auto-search
 * /hints by its EAN and list matches inline. Selecting one asks the server to
 * create the PrestaShop↔InPost category mapping (when missing) and reloads the
 * create form pre-filled with the hint's product fields + attributes. The offer
 * itself is saved only when the user submits the form.
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

    var HintPicker = {
        init: function () {
            var $btn = $('#hint-search-btn');
            if ($btn.length === 0) {
                return;
            }

            this.offerId = $btn.data('offer-id');
            this.searchUrl = $btn.data('search-url');
            this.applyUrl = $btn.data('apply-url');
            this.token = new URLSearchParams(window.location.search).get('_token') || '';
            this.lastQuery = '';

            var self = this;

            $btn.on('click', function () {
                $('#hint-picker-modal').modal('show');
            });

            $('#hint-search-submit').on('click', function () {
                self.search();
            });

            $('#hint-search-input').on('keypress', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.search();
                }
            });

            $('#hint-results').on('click', '.hint-apply-btn', function () {
                self.apply($(this).data('product-id'));
            });
        },

        search: function () {
            var self = this;
            var query = $.trim($('#hint-search-input').val() || '');
            if (query === '') {
                return;
            }
            this.lastQuery = query;
            $('#hint-results').html('<div class="text-muted">' + __('Searching...') + '</div>');

            $.post(this.searchUrl, { query: query, offer_id: this.offerId, _token: this.token })
                .done(function (resp) {
                    if (!resp.success) {
                        $('#hint-results').html('<div class="alert alert-danger">' + HintPicker.escape(resp.error || __('Search failed')) + '</div>');
                        return;
                    }
                    self.renderCandidates(resp.hints || []);
                })
                .fail(function () {
                    $('#hint-results').html('<div class="alert alert-danger">' + __('Request failed') + '</div>');
                });
        },

        renderCandidates: function (hints) {
            if (hints.length === 0) {
                $('#hint-results').html('<div class="alert alert-warning">' + __('No results.') + '</div>');
                return;
            }

            var html = '<table class="table table-sm"><thead><tr>'
                + '<th>' + __('Name') + '</th><th>' + __('Brand') + '</th><th>EAN</th><th>' + __('Attrs') + '</th><th></th>'
                + '</tr></thead><tbody>';

            hints.forEach(function (h) {
                html += '<tr>'
                    + '<td>' + HintPicker.escape(h.name) + '</td>'
                    + '<td>' + HintPicker.escape(h.brand) + '</td>'
                    + '<td>' + HintPicker.escape(h.ean) + '</td>'
                    + '<td>' + (h.attribute_count || 0) + '</td>'
                    + '<td><button type="button" class="btn btn-sm btn-primary hint-apply-btn" '
                    + 'data-product-id="' + HintPicker.escape(h.product_id) + '">' + __('Apply') + '</button></td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            $('#hint-results').html(html);
        },

        apply: function (productId) {
            var self = this;
            var overwrite = $('#hint-overwrite').is(':checked') ? 1 : 0;

            $('#hint-results').append('<div class="text-muted" id="hint-applying">' + __('Applying...') + '</div>');

            $.post(this.applyUrl, {
                offer_id: this.offerId,
                query: this.lastQuery,
                product_id: productId,
                overwrite: overwrite,
                _token: this.token
            })
                .done(function (resp) {
                    if (!resp.success) {
                        $('#hint-applying').remove();
                        $('#hint-results').prepend('<div class="alert alert-danger">' + HintPicker.escape(resp.error || __('Apply failed')) + '</div>');
                        return;
                    }
                    if (resp.reload) {
                        window.location.reload();
                    }
                })
                .fail(function () {
                    $('#hint-applying').remove();
                    $('#hint-results').prepend('<div class="alert alert-danger">' + __('Request failed') + '</div>');
                });
        },

        escape: function (value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    /**
     * Create-mode inline suggestions (auto-search by EAN, select → prepare → reload).
     */
    var HintCreate = {
        init: function () {
            var $panel = $('#hint-create-panel');
            if ($panel.length === 0) {
                return;
            }

            this.searchUrl = $panel.data('search-url');
            this.prepareUrl = $panel.data('prepare-url');
            this.productId = $panel.data('product-id');
            this.productAttributeId = $panel.data('product-attribute-id') || 0;
            this.ean = $.trim(String($panel.data('ean') || ''));

            var self = this;

            $('#hint-create-refresh').on('click', function () {
                self.search();
            });

            $('#hint-create-results').on('click', '.hint-create-select-btn', function () {
                self.select($(this).data('product-id'));
            });

            if (this.ean !== '') {
                this.search();
            } else {
                $('#hint-create-results').html(
                    '<div class="text-muted">' + __('No EAN on the product — nothing to match.') + '</div>'
                );
            }
        },

        search: function () {
            var self = this;
            if (this.ean === '') {
                return;
            }
            this.lastQuery = this.ean;
            $('#hint-create-results').html('<div class="text-muted">' + __('Searching...') + '</div>');

            $.post(this.searchUrl, { query: this.ean })
                .done(function (resp) {
                    if (!resp.success) {
                        $('#hint-create-results').html(
                            '<div class="alert alert-danger">' + HintPicker.escape(resp.error || __('Search failed')) + '</div>'
                        );
                        return;
                    }
                    self.renderCandidates(resp.hints || []);
                })
                .fail(function () {
                    $('#hint-create-results').html('<div class="alert alert-danger">' + __('Request failed') + '</div>');
                });
        },

        renderCandidates: function (hints) {
            if (hints.length === 0) {
                $('#hint-create-results').html(
                    '<div class="alert alert-warning">' + __('No matching products found in the InPost catalogue.') + '</div>'
                );
                return;
            }

            var html = '<table class="table table-sm"><thead><tr>'
                + '<th>' + __('Name') + '</th><th>' + __('Brand') + '</th><th>EAN</th><th>' + __('Attrs') + '</th><th></th>'
                + '</tr></thead><tbody>';

            hints.forEach(function (h) {
                html += '<tr>'
                    + '<td>' + HintPicker.escape(h.name) + '</td>'
                    + '<td>' + HintPicker.escape(h.brand) + '</td>'
                    + '<td>' + HintPicker.escape(h.ean) + '</td>'
                    + '<td>' + (h.attribute_count || 0) + '</td>'
                    + '<td><button type="button" class="btn btn-sm btn-primary hint-create-select-btn" '
                    + 'data-product-id="' + HintPicker.escape(h.product_id) + '">' + __('Select') + '</button></td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            $('#hint-create-results').html(html);
        },

        select: function (hintProductId) {
            $('#hint-create-results').append(
                '<div class="text-muted" id="hint-create-applying">' + __('Applying...') + '</div>'
            );

            $.post(this.prepareUrl, {
                product_id: this.productId,
                product_attribute_id: this.productAttributeId,
                hint_product_id: hintProductId,
                query: this.lastQuery
            })
                .done(function (resp) {
                    if (!resp.success || !resp.redirect_url) {
                        $('#hint-create-applying').remove();
                        $('#hint-create-results').prepend(
                            '<div class="alert alert-danger">' + HintPicker.escape(resp.error || __('Failed to apply suggestion')) + '</div>'
                        );
                        return;
                    }
                    window.location = resp.redirect_url;
                })
                .fail(function (xhr) {
                    $('#hint-create-applying').remove();
                    var msg = __('Request failed');
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        msg = parsed.error || msg;
                    } catch (e) {}
                    $('#hint-create-results').prepend('<div class="alert alert-danger">' + HintPicker.escape(msg) + '</div>');
                });
        }
    };

    $(document).ready(function () {
        HintPicker.init();
        HintCreate.init();
    });
})(jQuery);
