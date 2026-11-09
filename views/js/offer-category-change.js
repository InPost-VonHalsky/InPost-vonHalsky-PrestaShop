/**
 * InPost von Halsky - "Change category" dialog of the offer edit page
 *
 * Pick an InPost category (quick picks = categories linked to the shop
 * categories of the product, or search the whole tree), see what the change
 * does (attributes kept / removed / new, other variants) and continue in the
 * offer form (?inpost_category_id=&category_change=1), where it is saved with
 * "Update Offer". Nothing is saved from here.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 */

(function ($) {
    'use strict';

    // Translations rendered by the page (offer/_category_change_i18n.html.twig)
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

    function withParams(url, params) {
        return url + (url.indexOf('?') !== -1 ? '&' : '?') + $.param(params);
    }

    var CategoryChange = {
        init: function () {
            var $modal = $('#category-change-modal');
            if ($modal.length === 0) {
                return;
            }

            this.$modal = $modal;
            this.offerId = $modal.data('offer-id');
            this.searchUrl = $modal.data('search-url');
            this.previewUrl = $modal.data('preview-url');
            this.$results = $('#cc-results');
            this.$preview = $('#cc-preview');
            this.$continue = $('#cc-continue');
            this.searchTimer = null;
            this.searchRequest = null;
            this.previewRequest = null;

            var self = this;

            $modal.on('shown.bs.modal', function () {
                $('#cc-search').trigger('focus');
            });

            $('#cc-search').on('input', function () {
                var phrase = $.trim($(this).val());
                clearTimeout(self.searchTimer);
                self.searchTimer = setTimeout(function () {
                    self.search(phrase);
                }, 300);
            }).on('keypress', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                }
            });

            $modal.on('click', '.js-cc-pick', function () {
                $modal.find('.js-cc-pick').removeClass('active');
                $(this).addClass('active');
                self.preview($(this).data('id'));
            });

            this.$continue.on('click', function (e) {
                if ($(this).hasClass('disabled')) {
                    e.preventDefault();
                }
            });
        },

        search: function (phrase) {
            var self = this;
            if (this.searchRequest) {
                this.searchRequest.abort();
            }
            if (phrase.length < 2) {
                this.$results.empty();
                return;
            }

            this.$results.html('<div class="list-group-item text-muted">' + esc(__('Searching...')) + '</div>');
            this.searchRequest = $.getJSON(withParams(this.searchUrl, {q: phrase}))
                .done(function (data) {
                    if (!data.success || !data.results.length) {
                        self.$results.html('<div class="list-group-item text-muted">' + esc(__('No results.')) + '</div>');
                        return;
                    }
                    self.$results.html(data.results.map(function (category) {
                        return '<button type="button" class="list-group-item list-group-item-action js-cc-pick" data-id="' + esc(category.id) + '">'
                            + '<strong>' + esc(category.name) + '</strong>'
                            + (category.path ? '<small>' + esc(category.path) + '</small>' : '')
                            + '</button>';
                    }).join(''));
                })
                .fail(function (xhr, status) {
                    if (status !== 'abort') {
                        self.$results.html('<div class="list-group-item text-danger">' + esc(__('Search failed')) + '</div>');
                    }
                });
        },

        preview: function (categoryId) {
            var self = this;
            if (this.previewRequest) {
                this.previewRequest.abort();
            }
            this.setContinue(null);
            this.$preview.html('<div class="text-muted">' + esc(__('Loading...')) + '</div>');

            this.previewRequest = $.getJSON(withParams(this.previewUrl, {offer_id: this.offerId, category_id: categoryId}))
                .done(function (data) {
                    if (!data.success) {
                        self.$preview.html('<div class="alert alert-danger">' + esc(data.error || __('Request failed')) + '</div>');
                        return;
                    }
                    self.render(data.preview);
                })
                .fail(function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    var error = (xhr.responseJSON && xhr.responseJSON.error) || __('Request failed');
                    self.$preview.html('<div class="alert alert-danger">' + esc(error) + '</div>');
                });
        },

        render: function (preview) {
            var html = '<div class="cc-section"><span class="text-muted">' + esc(__('New category:')) + '</span> <strong>'
                + esc(preview.target.path.join(' › ')) + '</strong></div>';

            if (preview.same_category) {
                this.$preview.html(html + '<div class="alert alert-info">' + esc(__('The offer is already in this category.')) + '</div>');
                return;
            }

            var attrs = preview.attributes;
            if (attrs.lost.length) {
                html += '<div class="cc-section alert alert-warning">' + esc(__('Removed (the new category does not have them):')) + '<ul>'
                    + attrs.lost.map(function (a) {
                        return '<li><strong>' + esc(a.label) + '</strong>: ' + esc(a.values.join(', ')) + '</li>';
                    }).join('') + '</ul></div>';
            }
            if (attrs.kept.length) {
                html += '<div class="cc-section">' + esc(__('Kept:')) + ' '
                    + attrs.kept.map(function (a) { return '<strong>' + esc(a.label) + '</strong>'; }).join(', ') + '</div>';
            }
            if (attrs.added.length) {
                var fillLabels = {ps: __('from PrestaShop'), hint: __('from the InPost catalogue')};
                html += '<div class="cc-section">' + esc(__('New attributes:')) + '<ul>'
                    + attrs.added.map(function (a) {
                        var fill = a.fill
                            ? '<span class="text-success">' + esc(fillLabels[a.fill]) + (a.values.length ? ': ' + esc(a.values.join(', ')) : '') + '</span>'
                            : '<span class="' + (a.required ? 'text-danger' : 'text-muted') + '">' + esc(__('to fill in')) + '</span>';
                        return '<li><strong>' + esc(a.label) + '</strong>'
                            + (a.required ? ' <span class="badge badge-danger">' + esc(__('required')) + '</span>' : '')
                            + ' - ' + fill + '</li>';
                    }).join('') + '</ul></div>';
            }
            if (attrs.without_source > 0) {
                html += '<div class="cc-section text-muted small">'
                    + esc(__('%count% attribute(s) of the new category have no PrestaShop source mapped - map them to fill values automatically.', {count: attrs.without_source}))
                    + '</div>';
            }
            var movable = preview.variants.filter(function (v) { return !v.blocked && !v.same_category; });
            if (movable.length) {
                html += '<div class="cc-section">'
                    + esc(__('Other variants of the product: %count% - you can move them too in the next step.', {count: movable.length}))
                    + '</div>';
            }
            if (preview.is_sent) {
                html += '<div class="cc-section alert alert-info">'
                    + esc(__('The offer is already in InPost: after saving it is sent with the new category, and InPost may review it again.'))
                    + '</div>';
            }

            this.$preview.html(html);
            this.setContinue(preview.form_url);
        },

        setContinue: function (url) {
            if (url) {
                this.$continue.attr('href', url).removeClass('disabled').attr('aria-disabled', 'false');
            } else {
                this.$continue.attr('href', '#').addClass('disabled').attr('aria-disabled', 'true');
            }
        }
    };

    $(function () {
        CategoryChange.init();
    });
})(jQuery);
