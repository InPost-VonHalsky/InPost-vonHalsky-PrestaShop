/**
 * "Attachments" tab of the offer form (GOKINT-1099)
 *
 * Lists the offer's document attachments, adds files (own upload or a file of
 * the product in PrestaShop), removes them and sends rejected ones again.
 * Every endpoint answers with the fresh list; while a file is being processed
 * by InPost the list is refreshed every few seconds.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */
(function ($) {
    'use strict';

    var POLL_MS = 8000;

    // Translations rendered by the page (_attachments_i18n.html.twig)
    function __(text, params) {
        var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
        Object.keys(params || {}).forEach(function (key) {
            translated = translated.split('%' + key + '%').join(params[key]);
        });
        return translated;
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function formatSize(bytes) {
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' kB';
    }

    var STATUS = {
        PENDING: { label: 'Waiting', css: 'badge-secondary' },
        UPLOADING: { label: 'Sending...', css: 'badge-info' },
        UPLOADED: { label: 'In InPost', css: 'badge-success' },
        ERROR: { label: 'Error', css: 'badge-danger' },
        DELETING: { label: 'Removing...', css: 'badge-warning' }
    };

    var Attachments = {
        init: function ($root) {
            this.$root = $root;
            this.offerId = $root.data('offer-id');
            this.csrf = $root.data('csrf');
            this.maxSize = parseInt($root.data('max-size'), 10) || 0;
            this.loaded = false;
            this.pollTimer = null;
            this.busy = false;

            var self = this;
            $('a[href="#attachments-tab"]').on('shown.bs.tab', function () {
                self.load();
            }).on('hidden.bs.tab', function () {
                self.stopPolling();
            });
            if ($('#attachments-tab').hasClass('active')) {
                self.load();
            }

            $root.on('click', '.js-attachment-upload', function () { self.upload(); });
            $root.on('click', '.js-attachment-add-ps', function () { self.addPs(); });
            $root.on('click', '.js-attachment-remove', function () {
                self.remove($(this).data('id'), $(this).data('name'));
            });
            $root.on('click', '.js-attachment-retry', function () { self.retry($(this).data('id')); });
        },

        load: function () {
            var self = this;
            if (!self.loaded) {
                self.$root.find('.js-attachments-loading').show();
            }
            $.ajax({
                url: self.$root.data('list-url'),
                method: 'GET',
                data: { offer_id: self.offerId },
                dataType: 'json'
            }).done(function (response) {
                self.render(response);
            }).fail(function (xhr) {
                self.showError(self.errorOf(xhr));
            }).always(function () {
                self.loaded = true;
                self.$root.find('.js-attachments-loading').hide();
            });
        },

        upload: function () {
            var input = this.$root.find('.js-attachment-file')[0];
            var file = input && input.files && input.files[0];
            if (!file) {
                this.showError(__('Choose a file to add.'));
                return;
            }
            if (this.maxSize && file.size > this.maxSize) {
                this.showError(__('File %name% is too large (%size%). The limit is %max% MB.', {
                    name: file.name,
                    size: formatSize(file.size),
                    max: Math.round(this.maxSize / 1048576)
                }));
                return;
            }

            var data = new FormData();
            data.append('offer_id', this.offerId);
            data.append('attachment_type', this.$root.find('.js-attachment-type').val());
            data.append('_csrf', this.csrf);
            data.append('file', file);

            this.send('upload-url', data, true).done(function () {
                input.value = '';
            });
        },

        addPs: function () {
            var id = this.$root.find('.js-attachment-ps-file').val();
            if (!id) {
                return;
            }
            this.send('add-ps-url', {
                offer_id: this.offerId,
                id_attachment: id,
                attachment_type: this.$root.find('.js-attachment-ps-type').val(),
                _csrf: this.csrf
            });
        },

        remove: function (id, name) {
            if (!window.confirm(__('Remove attachment %name%? It is removed from the offer in InPost too.', { name: name }))) {
                return;
            }
            this.send('remove-url', { offer_id: this.offerId, attachment_id: id, _csrf: this.csrf });
        },

        retry: function (id) {
            this.send('retry-url', { offer_id: this.offerId, attachment_id: id, _csrf: this.csrf });
        },

        /**
         * POST to one of the data-*-url endpoints; the answer carries the list
         */
        send: function (urlKey, data, multipart) {
            var self = this;
            if (self.busy) {
                return $.Deferred().reject().promise();
            }
            self.busy = true;
            self.$root.find('button').prop('disabled', true);
            self.$root.find('.js-attachments-message').empty();

            var options = {
                url: self.$root.data(urlKey),
                method: 'POST',
                data: data,
                dataType: 'json'
            };
            if (multipart) {
                options.processData = false;
                options.contentType = false;
            }

            return $.ajax(options).done(function (response) {
                self.render(response);
            }).fail(function (xhr) {
                var response = xhr.responseJSON;
                if (response && response.attachments) {
                    self.render(response);
                } else {
                    self.showError(self.errorOf(xhr));
                }
            }).always(function () {
                self.busy = false;
                self.$root.find('button').prop('disabled', false);
            });
        },

        render: function (response) {
            if (!response) {
                return;
            }
            if (response.error) {
                this.showError(response.error);
            } else if (response.message) {
                this.showMessage(response.message);
            }
            if (!response.attachments) {
                return;
            }

            var rows = response.attachments;
            var $tbody = this.$root.find('.js-attachments-rows').empty();
            var processing = false;

            rows.forEach(function (row) {
                var status = STATUS[row.status] || { label: row.status, css: 'badge-secondary' };
                if (row.status === 'UPLOADING' || row.status === 'DELETING' || (row.status === 'PENDING' && response.offer_ready)) {
                    processing = true;
                }

                var statusHtml = '<span class="badge ' + status.css + '">' + escapeHtml(__(status.label)) + '</span>';
                if (row.error) {
                    statusHtml += '<div class="small text-danger mt-1">' + escapeHtml(row.error) + '</div>';
                }

                var actions = '';
                if (row.status === 'ERROR') {
                    actions += '<button type="button" class="btn btn-sm btn-outline-secondary js-attachment-retry mr-1" data-id="' + row.id + '">'
                        + escapeHtml(__('Send again')) + '</button>';
                }
                if (row.status !== 'DELETING') {
                    actions += '<button type="button" class="btn btn-sm btn-outline-danger js-attachment-remove" data-id="' + row.id
                        + '" data-name="' + escapeHtml(row.file_name) + '" title="' + escapeHtml(__('Remove')) + '">'
                        + '<i class="material-icons">delete</i></button>';
                }

                $tbody.append(
                    '<tr>'
                    + '<td>' + escapeHtml(row.file_name) + ' <span class="text-muted small">(' + formatSize(row.size) + ')</span></td>'
                    + '<td>' + escapeHtml(row.type_label) + '</td>'
                    + '<td>' + escapeHtml(row.source === 'ps_attachment' ? __('PrestaShop file') : __('Own file')) + '</td>'
                    + '<td>' + statusHtml + '</td>'
                    + '<td class="text-nowrap">' + escapeHtml(row.created_at) + '</td>'
                    + '<td class="text-right text-nowrap">' + actions + '</td>'
                    + '</tr>'
                );
            });

            this.$root.find('.js-attachments-table').toggle(rows.length > 0);
            this.$root.find('.js-attachments-empty').toggle(rows.length === 0);
            this.$root.find('.js-attachments-queued').toggle(!response.offer_ready);

            var ps = response.ps_attachments || [];
            var $select = this.$root.find('.js-attachment-ps-file').empty();
            ps.forEach(function (file) {
                var label = file.name !== file.file_name ? file.name + ' (' + file.file_name + ')' : file.file_name;
                $select.append($('<option>').val(file.id).text(label + ' - ' + formatSize(file.size)));
            });
            this.$root.find('.js-attachments-ps').toggle(ps.length > 0);
            this.$root.find('.js-attachments-ps-none').toggle(ps.length === 0);

            this.$root.closest('.card').find('a[href="#attachments-tab"] .js-attachments-count')
                .text(rows.length > 0 ? rows.length : '');

            processing ? this.startPolling() : this.stopPolling();
        },

        startPolling: function () {
            var self = this;
            if (self.pollTimer) {
                return;
            }
            self.pollTimer = window.setInterval(function () {
                if (!self.busy) {
                    self.load();
                }
            }, POLL_MS);
        },

        stopPolling: function () {
            if (this.pollTimer) {
                window.clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        errorOf: function (xhr) {
            if (xhr && xhr.status === 413) {
                return __('The file is too large for this server.');
            }
            return (xhr && xhr.responseJSON && xhr.responseJSON.error) || __('Request failed');
        },

        showError: function (text) {
            this.$root.find('.js-attachments-message').html(
                '<div class="alert alert-danger">' + escapeHtml(text) + '</div>'
            );
        },

        showMessage: function (text) {
            this.$root.find('.js-attachments-message').html(
                '<div class="alert alert-success">' + escapeHtml(text) + '</div>'
            );
        }
    };

    $(function () {
        var $root = $('#inpost-attachments');
        if ($root.length) {
            Attachments.init($root);
        }
    });
})(jQuery);
