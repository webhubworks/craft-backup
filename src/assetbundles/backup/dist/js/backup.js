(function($) {
    if (typeof Craft === 'undefined') {
        return;
    }

    var CARD_ACTIONS = {
        health: 'backup/backup/health-card',
        checks: 'backup/backup/checks-card',
        backups: 'backup/backup/backups-card',
    };

    function moveExtensionOutsideContent() {
        if (!$) return;
        var $extension = $('.cb-utility-extension');
        var $content = $('#content');
        if ($extension.length && $content.length && $.contains($content[0], $extension[0])) {
            $extension.insertAfter($content);
        }
    }

    function initTabs() {
        if (!$ || !Craft.Tabs) return;
        var $container = $('#cb-target-tabs');
        if (!$container.length || $container.data('tabs')) {
            return;
        }

        var tabManager = new Craft.Tabs($container);

        tabManager.on('selectTab', function(ev) {
            var href = ev.$tab.attr('href');
            if (href && href.charAt(0) === '#') {
                $(href).removeClass('hidden');
            }
        });

        tabManager.on('deselectTab', function(ev) {
            var href = ev.$tab.attr('href');
            if (href && href.charAt(0) === '#') {
                $(href).addClass('hidden');
            }
        });
    }

    function renderError(placeholder, message) {
        placeholder.innerHTML =
            '<div class="cb-card"><div class="cb-error">' +
            Craft.escapeHtml(message) +
            '</div></div>';
    }

    function loadCard(placeholder) {
        var card = placeholder.getAttribute('data-cb-card');
        var action = CARD_ACTIONS[card];
        if (!action) return;

        fetch(Craft.getActionUrl(action), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                var html = (data && data.html) || '';
                placeholder.innerHTML = html;
                if (html === '') {
                    placeholder.parentNode && placeholder.parentNode.removeChild(placeholder);
                } else if (card === 'backups') {
                    initTabs();
                }
            })
            .catch(function(err) {
                renderError(placeholder, 'Could not load this section. (' + err.message + ')');
            });
    }

    function loadAllCards() {
        var placeholders = document.querySelectorAll('[data-cb-card]');
        for (var i = 0; i < placeholders.length; i++) {
            loadCard(placeholders[i]);
        }
    }

    function boot() {
        moveExtensionOutsideContent();
        loadAllCards();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window.jQuery);
