/**
 * InPost von Halsky - Offer Form JavaScript
 *
 * Handles offer form interactions:
 * - Tab navigation with state preservation
 * - AJAX organization selector
 * - AJAX category selector with attribute loading
 * - Client-side validation
 * - Dynamic field updates
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
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

    /**
     * Offer Form Manager
     */
    var OfferForm = {

        /**
         * Initialize offer form
         */
        init: function() {

            this.initTabs();
            this.initValidation();
            this.initEanConditionalRequirement();
            this.initDynamicFields();
            this.initOfferActions();
        },

        /**
         * Mirror the server-side rule (GOKINT-932): EAN is required only when the
         * product is not identified by brand + manufacturer product number.
         * Progressive enhancement only; the server remains authoritative.
         */
        initEanConditionalRequirement: function() {
            var $ean = $('[name$="[product_ean]"]');
            var $brand = $('[name$="[product_brand]"]');
            var $mpn = $('[name$="[manufacturer_product_number]"]');

            if ($ean.length === 0) {
                return;
            }

            function hasAlternativeId() {
                return $.trim($brand.val() || '') !== '' && $.trim($mpn.val() || '') !== '';
            }

            function toggleRequired() {
                if (hasAlternativeId()) {
                    $ean.removeAttr('required');
                } else {
                    $ean.attr('required', 'required');
                }
            }

            $brand.on('input change', toggleRequired);
            $mpn.on('input change', toggleRequired);
            toggleRequired();

            // Format feedback on the EAN itself (empty is allowed here).
            $ean.on('blur', function() {
                var val = $.trim($ean.val() || '');
                if (val === '') {
                    $ean.removeClass('is-invalid');
                    return;
                }
                if (OfferForm.isValidEan(val)) {
                    $ean.removeClass('is-invalid');
                } else {
                    $ean.addClass('is-invalid');
                }
            });
        },

        /**
         * Modulo-10 EAN/GTIN checksum, right-to-left (matches ValidationUtils).
         * Accepts lengths 8, 12, 13.
         */
        isValidEan: function(value) {
            var ean = String(value).replace(/[\s-]/g, '');
            if (!/^\d+$/.test(ean)) {
                return false;
            }
            if ([8, 12, 13].indexOf(ean.length) === -1) {
                return false;
            }
            var digits = ean.split('').map(Number);
            var check = digits.pop();
            var sum = 0;
            var multiplier = 3;
            for (var i = digits.length - 1; i >= 0; i--) {
                sum += digits[i] * multiplier;
                multiplier = multiplier === 3 ? 1 : 3;
            }
            return check === (10 - (sum % 10)) % 10;
        },

        /**
         * Initialize tabs (stub - tabs handled by Bootstrap)
         */
        initTabs: function() {
            // Bootstrap handles tab switching automatically
        },

        /**
         * Initialize client-side validation
         * Note: Primary validation is server-side. This provides optional real-time feedback.
         */
        initValidation: function() {
            var $form = $('#offer-form');

            if ($form.length === 0) {
                return;
            }

            // Real-time validation on field blur (optional UX enhancement)
            $form.find('input[required], select[required], textarea[required]').on('blur', function() {
                OfferForm.validateField($(this));
            });
        },

        /**
         * Validate entire form with tab error badges
         */
        validateForm: function() {
            var isValid = true;
            var firstInvalidTab = null;

            // Clear all previous tab badges and error states
            $('.nav-tabs .nav-link .badge').remove();
            $('.nav-tabs .nav-link').removeClass('tab-has-errors');

            // Validate each tab and add badges for errors
            $('.tab-pane').each(function() {
                var $tab = $(this);
                var tabId = '#' + $tab.attr('id');
                var errorCount = 0;

                // Count invalid fields in tab
                $tab.find('[required]').each(function() {
                    if (!OfferForm.validateField($(this))) {
                        errorCount++;
                    }
                });

                if (errorCount > 0) {
                    isValid = false;
                    OfferForm.showTabErrorBadge(tabId, errorCount);

                    // Remember first invalid tab
                    if (!firstInvalidTab) {
                        firstInvalidTab = tabId;
                    }
                }
            });

            // Navigate to first invalid tab
            if (!isValid && firstInvalidTab) {
                $('.nav-tabs a[href="' + firstInvalidTab + '"]').tab('show');
            }

            return isValid;
        },

        /**
         * Validate tab
         */
        validateTab: function(tabSelector) {
            var $tab = $(tabSelector);
            var isValid = true;

            // Validate required fields in tab
            $tab.find('[required]').each(function() {
                if (!OfferForm.validateField($(this))) {
                    isValid = false;
                }
            });

            return isValid;
        },

        /**
         * Validate single field
         */
        validateField: function($field) {
            var value = $field.val();
            var fieldName = $field.attr('name');
            var isRequired = $field.attr('required');
            var isValid = true;

            // Clear previous error
            $field.removeClass('is-invalid');
            $field.next('.invalid-feedback').remove();

            // Required field validation
            if (isRequired && (!value || value.trim() === '')) {
                isValid = false;
                OfferForm.showFieldError($field, __('This field is required'));
            }

            // Email validation
            if ($field.attr('type') === 'email' && value) {
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    isValid = false;
                    OfferForm.showFieldError($field, __('Invalid email format'));
                }
            }

            // URL validation
            if ($field.attr('type') === 'url' && value) {
                var urlRegex = /^https?:\/\/.+/;
                if (!urlRegex.test(value)) {
                    isValid = false;
                    OfferForm.showFieldError($field, __('Invalid URL format (must start with http:// or https://)'));
                }
            }

            // Number validation
            if ($field.attr('type') === 'number' && value) {
                var min = parseFloat($field.attr('min'));
                var max = parseFloat($field.attr('max'));
                var numValue = parseFloat(value);

                if (isNaN(numValue)) {
                    isValid = false;
                    OfferForm.showFieldError($field, __('Must be a number'));
                } else if (!isNaN(min) && numValue < min) {
                    isValid = false;
                    OfferForm.showFieldError($field, __('Must be at least %min%', { min: min }));
                } else if (!isNaN(max) && numValue > max) {
                    isValid = false;
                    OfferForm.showFieldError($field, __('Must be at most %max%', { max: max }));
                }
            }

            return isValid;
        },

        /**
         * Show field validation error
         */
        showFieldError: function($field, message) {
            $field.addClass('is-invalid');
            $field.after('<div class="invalid-feedback d-block">' + message + '</div>');
        },

        /**
         * Show tab error indicator (legacy - temporary highlight)
         */
        showTabError: function(tabSelector) {
            var $tabLink = $('.nav-tabs a[href="' + tabSelector + '"]');
            $tabLink.addClass('text-danger');

            // Remove error class after 3 seconds
            setTimeout(function() {
                $tabLink.removeClass('text-danger');
            }, 3000);
        },

        /**
         * Show tab error badge with count
         */
        showTabErrorBadge: function(tabSelector, errorCount) {
            var $tabLink = $('.nav-tabs a[href="' + tabSelector + '"]');
            $tabLink.addClass('tab-has-errors');
            $tabLink.append('<span class="badge badge-danger ml-2">' + errorCount + '</span>');
        },

        /**
         * Initialize dynamic field updates
         */
        initDynamicFields: function() {
            // Apply required class to labels of required fields
            this.applyRequiredLabels();
            // GPSR conditional required fields toggle
            this.initGpsrToggle();
        },

        /**
         * Apply required class to labels of fields with required attribute
         */
        applyRequiredLabels: function() {
            $('[required]').each(function() {
                var $field = $(this);
                var $label = $field.closest('.form-group').find('label').first();
                if ($label.length && !$label.hasClass('required')) {
                    $label.addClass('required');
                }
            });
        },

        /**
         * Initialize GPSR checkbox toggle for manufacturer fields
         * When "GPSR Does Not Require Info" is unchecked, manufacturer fields become required
         */
        initGpsrToggle: function() {
            var $gpsrToggle = $('.js-gpsr-toggle');
            if (!$gpsrToggle.length) return;

            var $requiredFields = $(
                '[name*="manufacturer_name"], ' +
                '[name*="manufacturer_email"], ' +
                '[name*="manufacturer_address_country_code"]'
            );

            function updateGpsrRequired() {
                var gpsrExempt = $gpsrToggle.is(':checked');
                $requiredFields.prop('required', !gpsrExempt);
                
                // Update visual indicators via CSS class
                $requiredFields.each(function() {
                    var $field = $(this);
                    var $label = $field.closest('.form-group').find('label').first();
                    $label.toggleClass('required', !gpsrExempt);
                });
            }

            $gpsrToggle.on('change', updateGpsrRequired);
            updateGpsrRequired(); // Initialize on page load
        },

        /**
         * Initialize offer close/reopen actions
         */
        initOfferActions: function() {
            var self = this;

            // Close offer button
            $('#close-offer-btn').on('click', function() {
                if (!confirm(__('Close this offer? It will be removed from InPost marketplace.'))) {
                    return;
                }

                var offerId = $(this).data('offer-id');
                self.performOfferAction($(this), $(this).data('url'), offerId, __('Closing offer...'));
            });

            // Reopen offer button
            $('#reopen-offer-btn').on('click', function() {
                if (!confirm(__('Reopen this offer? It will be published again on InPost.'))) {
                    return;
                }

                var offerId = $(this).data('offer-id');
                self.performOfferAction($(this), $(this).data('url'), offerId, __('Reopening offer...'));
            });
        },

        /**
         * Perform offer status action (close/reopen)
         */
        performOfferAction: function($button, url, offerId, loadingMsg) {
            var self = this;

            // One request per click: the button stays disabled until the page reloads
            if ($button.prop('disabled')) {
                return;
            }
            $button.prop('disabled', true);

            self.showNotification(loadingMsg, 'info');

            $.ajax({
                url: url,
                method: 'POST',
                data: { offer_id: offerId },
                success: function(response) {
                    if (response.success) {
                        self.showNotification(response.message || __('Action completed successfully'), 'success');
                        // Reload page to show updated status
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        $button.prop('disabled', false);
                        self.showNotification(__('Error: %error%', { error: response.error || __('Unknown error') }), 'error');
                    }
                },
                error: function(xhr) {
                    $button.prop('disabled', false);
                    var errorMsg = __('Request failed');
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        errorMsg = resp.error || errorMsg;
                    } catch(e) {}
                    self.showNotification(__('Error: %error%', { error: errorMsg }), 'error');
                }
            });
        },

        /**
         * Show notification message as floating toast
         */
        showNotification: function(message, type) {
            type = type || 'info';

            var alertClass = 'alert-' + (type === 'error' ? 'danger' : type);
            var iconName = type === 'error' ? 'error' : 'info';

            // Create floating toast at top-right of page
            var $toast = $('<div class="alert ' + alertClass + ' alert-dismissible fade show position-fixed" ' +
                'style="top: 70px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);" role="alert">' +
                '<i class="material-icons mr-2" style="vertical-align: middle;">' + iconName + '</i>' +
                '<span class="js-toast-message"></span>' +
                '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                '</div>');
            // Server messages are plain text - never interpret them as HTML
            $toast.find('.js-toast-message').text(message);

            $('body').append($toast);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $toast.alert('close');
            }, 5000);
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only initialize on offer form pages, and only once (a second copy of the
        // script must not bind every handler twice)
        if (OfferForm.initialized) {
            return;
        }
        if ($('#offer-form').length || $('.offer-form-container').length) {
            OfferForm.initialized = true;
            OfferForm.init();
        }
    });

    // Export to global scope for debugging
    window.OfferForm = OfferForm;

})(jQuery);
