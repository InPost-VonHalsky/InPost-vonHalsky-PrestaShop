/**
 * InPost Offer Events AJAX Loader
 * Lazy loads events when Events tab is activated
 */
(function($) {
    'use strict';

    // Translations rendered by the page (views/templates/admin/_partials/js_i18n.html.twig)
    function __(text, params) {
        var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
        Object.keys(params || {}).forEach(function (key) {
            translated = translated.split('%' + key + '%').join(params[key]);
        });
        return translated;
    }

    window.InpostOfferEvents = {
        loaded: false,

        /**
         * Initialize events loader
         * @param {number} offerId - Offer database ID
         * @param {string} eventsUrl - AJAX endpoint URL
         */
        init: function(offerId, eventsUrl) {
            if (!offerId || !eventsUrl) {
                console.error('InpostOfferEvents: offerId and eventsUrl required');
                return;
            }

            this.offerId = offerId;
            this.eventsUrl = eventsUrl;

            // Attach tab activation listener
            $('a[href="#events-tab"]').on('shown.bs.tab', function() {
                if (!window.InpostOfferEvents.loaded) {
                    window.InpostOfferEvents.loadEvents();
                }
            });
        },

        /**
         * Load events from backend
         */
        loadEvents: function() {
            const self = this;

            // Show loading spinner
            $('#events-loading').show();
            $('#events-table-container').hide();

            // AJAX request
            $.ajax({
                url: self.eventsUrl,
                method: 'GET',
                data: { offer_id: self.offerId },
                dataType: 'json'
            })
            .done(function(response) {
                self.handleResponse(response);
            })
            .fail(function(xhr, status, error) {
                self.handleError(xhr, error);
            })
            .always(function() {
                // Hide loading spinner
                $('#events-loading').hide();
                $('#events-table-container').show();
            });

            // Mark as loaded (prevent reloading)
            this.loaded = true;
        },

        /**
         * Handle successful response
         * @param {object} response - AJAX response
         */
        handleResponse: function(response) {
            if (!response.success) {
                this.showError(response.error || __('Unknown error'));
                return;
            }

            const events = response.data || [];

            if (events.length === 0) {
                // Show empty state
                $('#events-table').hide();
                $('#events-empty').show();
                return;
            }

            // Populate table
            this.populateTable(events);
            $('#events-table').show();
            $('#events-empty').hide();
        },

        /**
         * Populate events table
         * @param {array} events - Events array
         */
        populateTable: function(events) {
            const $tbody = $('#events-table-body');
            $tbody.empty();

            events.forEach(function(event) {
                const row = $('<tr></tr>');

                row.append($('<td></td>').text(event.type || 'UNKNOWN'));
                row.append($('<td></td>').text(event.occurred_at || '-'));
                row.append($('<td></td>').text(event.inpost_event_id || '-'));

                $tbody.append(row);
            });
        },

        /**
         * Handle AJAX error
         * @param {object} xhr - XMLHttpRequest object
         * @param {string} error - Error message
         */
        handleError: function(xhr, error) {
            let message = __('Failed to load events');

            if (xhr.responseJSON && xhr.responseJSON.error) {
                message = xhr.responseJSON.error;
            } else if (error) {
                message += ': ' + error;
            }

            this.showError(message);
        },

        /**
         * Show error message
         * @param {string} message - Error message
         */
        showError: function(message) {
            $('#events-table').hide();
            $('#events-empty').html(
                '<div class="alert alert-danger">' +
                '<i class="material-icons">error</i> ' +
                '<strong>' + __('Error:') + '</strong> ' + $('<div>').text(message).html() +
                '</div>'
            ).show();
        }
    };

})(jQuery);
