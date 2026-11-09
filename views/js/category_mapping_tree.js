/**
 * InPost Category Mapping Tree View
 * With lazy loading support for large trees (6000+ categories)
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

/**
 * Escape a value for safe insertion into HTML.
 */
// Translations rendered by the page (views/templates/admin/_partials/js_i18n.html.twig)
function inpostTrans(text, params) {
    var translated = (window.InpostI18n && window.InpostI18n[text]) || text;
    Object.keys(params || {}).forEach(function (key) {
        translated = translated.split('%' + key + '%').join(params[key]);
    });
    return translated;
}

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Make token function globally available
window.getAdminToken = function() {
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('_token') || '';
    return token;
};

// Get the base admin URL from current page
window.getAdminBaseUrl = function() {
    // Extract base URL up to index.php
    const currentUrl = window.location.pathname;
    const match = currentUrl.match(/(.*\/index\.php)/);
    return match ? match[1] : '/admin-dev/index.php';
};

$(document).ready(function() {


    // Save the default right-panel view (mapped categories) so we can return to it.
    window.__inpostDefaultDetailsHtml = $('#category-details-content').html();

    // Click a mapped category in the default view -> open its details.
    $('#category-details-content').on('click', '.inpost-mapped-item', function(e) {
        e.preventDefault();
        var mappingId = $(this).data('entity-id');
        var categoryId = $(this).data('category-id');
        if (mappingId && categoryId) {
            updateUrlParameter('category', categoryId);
            loadCategoryDetails(mappingId, categoryId);
        }
    });

    // "Back to mapped categories" from a detail view.
    $('#category-details-content').on('click', '.inpost-back-to-mapped', function(e) {
        e.preventDefault();
        if (window.__inpostDefaultDetailsHtml) {
            $('#category-details-content').html(window.__inpostDefaultDetailsHtml);
        }
    });

    // Initialize JSTree with lazy loading
    $('#inpost-tree-container').jstree({
        'core': {
            'data': {
                'url': function(node) {
                    // Lazy loading: send parent node ID to server with CSRF token
                    const token = window.getAdminToken();
                    const nodeId = (node.id === '#') ? '' : node.id;
                    const baseUrl = window.getAdminBaseUrl();
                    const url = baseUrl + '/modules/admin/inpost/category-mapping/tree?id=' + nodeId + '&_token=' + encodeURIComponent(token);
                    return url;
                },
                'dataType': 'json'
            },
            'themes': {
                'name': 'default',
                'responsive': true,
                'dots': true,
                'icons': true
            },
            'check_callback': false
        },
        'plugins': ['search', 'state', 'types'],
        'types': {
            'default': {
                'icon': 'material-icons icon-folder-open',
                'valid_children': ['default', 'file']
            },
            'file': {
                'icon': 'material-icons icon-description',
                'valid_children': []
            }
        },
        'state': {
            'key': 'inpost_category_tree_state',
            'preserve_loaded': true
        },
        'search': {
            'case_insensitive': true,
            'show_only_matches': true,
            'show_only_matches_children': true,
            'search_callback': function(str, node) {
                // Custom search that works with lazy-loaded nodes
                return node.text.toLowerCase().indexOf(str.toLowerCase()) !== -1;
            }
        }
    });

    // Handle tree load complete
    $('#inpost-tree-container').on('ready.jstree', function() {

        // Load category from URL parameter if present
        loadCategoryFromUrl();
    });

    // Handle tree node selection
    $('#inpost-tree-container').on('select_node.jstree', function(e, data) {
        const mappingId = data.node.li_attr['data-entity-id'];  // Fixed: using correct attribute name
        const categoryId = data.node.li_attr['data-category-id'];

        if (mappingId && categoryId) {
            // Update URL parameter for deep linking
            updateUrlParameter('category', categoryId);

            // Load category details
            loadCategoryDetails(mappingId, categoryId);
        }
    });

    // Handle tree loading errors
    $('#inpost-tree-container').on('load_node.jstree', function(e, data) {
        // Check if node loaded successfully
        if (!data.status) {
            console.error('[InPost] Failed to load tree node:', data);
        }
    });

    // Category search: server-side, across the whole tree (lazy loading means
    // client-side jsTree search would only see loaded nodes). While searching we
    // hide the tree and show a flat results list; clearing restores the tree.
    var treeSearchTimer = null;

    function showTree() {
        $('#inpost-tree-search-results').hide().empty();
        $('#inpost-tree-container').show();
    }

    function runCategorySearch(value) {
        var url = window.getAdminBaseUrl()
            + '/modules/admin/inpost/category-mapping/tree?search=' + encodeURIComponent(value)
            + '&_token=' + encodeURIComponent(window.getAdminToken());

        var $results = $('#inpost-tree-search-results');
        $('#inpost-tree-container').hide();
        $results.show().html('<div class="tree-loading"><i class="material-icons icon-spin">refresh</i></div>');

        $.getJSON(url)
            .done(function(response) {
                var items = (response && response.results) || [];
                if (items.length === 0) {
                    $results.html('<div class="alert alert-info mb-0">'
                        + escapeHtml(inpostTrans('No categories match "%query%".', { query: value })) + '</div>');
                    return;
                }

                var html = '<ul class="list-group">';
                items.forEach(function(item) {
                    var inactive = item.is_active ? '' : ' <span class="badge badge-warning">' + escapeHtml(inpostTrans('orphaned')) + '</span>';
                    var leaf = item.is_leaf
                        ? ' <i class="material-icons" style="font-size:14px;">description</i>'
                        : '';
                    var path = (item.path && item.path.length)
                        ? '<div class="text-muted" style="font-size:11px;line-height:1.2;">' + escapeHtml(item.path) + '</div>'
                        : '';
                    html += '<li class="list-group-item list-group-item-action inpost-search-result" '
                        + 'style="cursor:pointer;" '
                        + 'data-entity-id="' + escapeHtml(item.entity_id) + '" '
                        + 'data-category-id="' + escapeHtml(item.category_id) + '">'
                        + '<div>' + leaf + ' ' + escapeHtml(item.name) + inactive + '</div>'
                        + path
                        + '</li>';
                });
                html += '</ul>';
                $results.html(html);
            })
            .fail(function() {
                $results.html('<div class="alert alert-danger mb-0">' + escapeHtml(inpostTrans('Search failed')) + '</div>');
            });
    }

    $('#inpost-tree-search').on('keyup input', function() {
        var value = $.trim($(this).val() || '');
        clearTimeout(treeSearchTimer);
        treeSearchTimer = setTimeout(function() {
            if (value === '') {
                showTree();
            } else {
                runCategorySearch(value);
            }
        }, 300);
    });

    $('#inpost-tree-search-clear').on('click', function() {
        $('#inpost-tree-search').val('');
        showTree();
    });

    // Selecting a search result loads its details, same as a tree node click.
    $('#inpost-tree-search-results').on('click', '.inpost-search-result', function() {
        var mappingId = $(this).data('entity-id');
        var categoryId = $(this).data('category-id');
        if (mappingId && categoryId) {
            updateUrlParameter('category', categoryId);
            loadCategoryDetails(mappingId, categoryId);
        }
    });
});

/**
 * Load category details via AJAX
 *
 * @param {number} mappingId Mapping entity ID
 * @param {string} categoryId InPost category ID
 * @param {{type: string, message: string}} [notice] Optional alert shown on top of the reloaded panel
 */
function loadCategoryDetails(mappingId, categoryId, notice) {
    const $content = $('#category-details-content');

    // Show loading state
    $content.html(`
        <div class="text-center py-5">
            <i class="material-icons icon-spin" style="font-size:48px;">refresh</i>
            <p class="mt-3 text-muted">${escapeHtml(inpostTrans('Loading category details...'))}</p>
        </div>
    `);

    // AJAX call to get category details with CSRF token
    $.ajax({
        url: window.getAdminBaseUrl() + '/modules/admin/inpost/category-mapping/details',
        method: 'GET',
        data: {
            mapping_id: mappingId,
            category_id: categoryId,
            _token: window.getAdminToken()
        },
        success: function(response) {
            var backBar = '<div class="mb-3">'
                + '<a href="#" class="inpost-back-to-mapped btn btn-sm btn-outline-secondary">'
                + '<i class="material-icons align-middle" style="font-size:16px;">arrow_back</i> '
                + escapeHtml(inpostTrans('Mapped categories')) + '</a></div>';
            $content.html(backBar + response);
            if (notice && notice.message) {
                var alertClass = notice.type === 'success' ? 'alert-success' : 'alert-danger';
                var icon = notice.type === 'success' ? 'check_circle' : 'error';
                $('<div class="alert ' + alertClass + ' alert-dismissible fade show" role="alert">')
                    .append('<i class="material-icons align-middle">' + icon + '</i> ')
                    .append($('<span>').text(notice.message))
                    .append('<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>')
                    .insertAfter($content.children().first());
            }
        },
        error: function(xhr, status, error) {
            console.error('[InPost] Error loading category details:', error);
            $content.html(`
                <div class="alert alert-danger">
                    <i class="material-icons">error</i>
                    <strong>${escapeHtml(inpostTrans('Error loading category details:'))}</strong> ${escapeHtml(error)}
                    <br><small>${escapeHtml(inpostTrans('Status: %status%', { status: xhr.status }))}</small>
                </div>
            `);
        }
    });
}

/**
 * Update URL parameter without page reload
 *
 * @param {string} param Parameter name
 * @param {string} value Parameter value
 */
function updateUrlParameter(param, value) {
    if (typeof URL !== 'undefined' && URL) {
        const url = new URL(window.location);
        url.searchParams.set(param, value);
        window.history.pushState({}, '', url);
    }
}

/**
 * Load category from URL parameter on page load
 */
function loadCategoryFromUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    const categoryId = urlParams.get('category');

    if (categoryId) {

        // Wait for tree to fully load, then select node
        setTimeout(function() {
            const tree = $('#inpost-tree-container').jstree(true);
            const nodeSelector = '[data-category-id="' + categoryId + '"]';
            const $node = $(nodeSelector);

            if ($node.length > 0) {
                const nodeId = $node.parent().attr('id');
                tree.select_node(nodeId);
            } else {
                console.warn('[InPost] Category node not found in tree:', categoryId);
            }
        }, 1000);
    }
}
