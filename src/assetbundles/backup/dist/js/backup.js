(function($) {
    if (typeof Craft === 'undefined') {
        return;
    }

    var CARD_ACTIONS = {
        health: 'backup/backup/health-card',
        checks: 'backup/backup/checks-card',
        backups: 'backup/backup/backups-card',
        notifications: 'backup/backup/notifications-card',
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

    function initTestSlack(scope) {
        var links = scope.querySelectorAll('[data-cb-test-slack]');
        for (var i = 0; i < links.length; i++) {
            (function(link) {
                link.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    if (link.getAttribute('data-cb-busy') === '1') return;

                    var actionUrl = link.getAttribute('data-cb-action-url');
                    var labelSending = link.getAttribute('data-cb-label-sending');
                    var labelSent = link.getAttribute('data-cb-label-sent');
                    var labelFailed = link.getAttribute('data-cb-label-failed');
                    var labelDefault = link.getAttribute('data-cb-label-default');

                    link.setAttribute('data-cb-busy', '1');
                    link.textContent = labelSending;

                    var body = new URLSearchParams();
                    if (typeof Craft !== 'undefined' && Craft.csrfTokenName && Craft.csrfTokenValue) {
                        body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
                    }

                    fetch(actionUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: body.toString(),
                    })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data && data.success) {
                                link.textContent = labelSent;
                            } else {
                                link.textContent = labelFailed;
                                if (data && data.error && typeof console !== 'undefined') {
                                    console.warn('Slack test failed:', data.error);
                                }
                            }
                            window.setTimeout(function() {
                                link.textContent = labelDefault;
                                link.removeAttribute('data-cb-busy');
                            }, 3000);
                        })
                        .catch(function(err) {
                            link.textContent = labelFailed;
                            if (typeof console !== 'undefined') {
                                console.warn('Slack test failed:', err);
                            }
                            window.setTimeout(function() {
                                link.textContent = labelDefault;
                                link.removeAttribute('data-cb-busy');
                            }, 3000);
                        });
                });
            })(links[i]);
        }
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
                } else if (card === 'notifications') {
                    initTestSlack(placeholder);
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
