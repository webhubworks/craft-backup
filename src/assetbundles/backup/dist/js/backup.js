(function($) {
    if (typeof Craft === 'undefined') {
        return;
    }

    var CARD_ACTIONS = {
        health: 'backup/backup/health-card',
        checks: 'backup/backup/checks-card',
        backups: 'backup/backup/backups-card',
        notifications: 'backup/backup/notifications-card',
        sanitize: 'backup/backup/sanitize-card',
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

    function initTestNotification(scope) {
        var links = scope.querySelectorAll('[data-cb-test-notification]');
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
                    body.append('channel', link.getAttribute('data-cb-channel'));
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
                                    console.warn('Test notification failed:', data.error);
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
                                console.warn('Test notification failed:', err);
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
        if (!action) return Promise.resolve();

        return fetch(Craft.getActionUrl(action), {
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
                    initTestNotification(placeholder);
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

    function notify(message, isError) {
        if (Craft.cp && isError && Craft.cp.displayError) {
            Craft.cp.displayError(message);
        } else if (Craft.cp && Craft.cp.displayNotice) {
            Craft.cp.displayNotice(message);
        }
    }

    function postJson(url) {
        var body = new URLSearchParams();
        if (Craft.csrfTokenName && Craft.csrfTokenValue) {
            body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        }).then(function(response) { return response.json(); });
    }

    function getJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        }).then(function(response) { return response.json(); });
    }

    // Once the job is done: refresh the list, then download the new copy if "Download backup" is ticked.
    function finishSanitizedCopy(button, startedAt) {
        var placeholder = document.querySelector('[data-cb-card="backups"]');
        var reload = placeholder ? loadCard(placeholder) : Promise.resolve();

        reload.then(function() {
            var checkbox = document.getElementById('download-backup');
            if (!checkbox || !checkbox.checked) {
                notify(button.getAttribute('data-cb-label-done'), false);
                return;
            }

            // An SFTP server's clock can differ a little from the web server's.
            var isNew = function(el) { return Number(el.getAttribute('data-cb-modified')) >= startedAt - 5; };
            var form = Array.prototype.filter.call(document.querySelectorAll('[data-cb-sanitized-download]'), isNew)[0];
            if (form) {
                form.submit();
                return;
            }
            var tooLarge = Array.prototype.filter.call(document.querySelectorAll('[data-cb-sanitized-too-large]'), isNew)[0];
            notify(button.getAttribute(tooLarge ? 'data-cb-label-too-large' : 'data-cb-label-no-download'), true);
        });
    }

    function pollSanitizedCopy(button, jobId, startedAt, reset) {
        var url = Craft.getActionUrl('backup/backup/sanitize-status', {jobId: jobId});

        var tick = function() {
            getJson(url)
                .then(function(data) {
                    if (data.status === 'done') {
                        reset();
                        finishSanitizedCopy(button, startedAt);
                    } else if (data.status === 'failed') {
                        reset();
                        notify(data.error, true);
                    } else {
                        window.setTimeout(tick, 3000);
                    }
                })
                .catch(function(err) {
                    reset();
                    notify(String(err), true);
                });
        };
        window.setTimeout(tick, 3000);
    }

    function initSanitizeButton() {
        var button = document.querySelector('[data-cb-sanitize]');
        if (!button) return;

        button.addEventListener('click', function() {
            if (button.disabled) return;
            var label = button.textContent;
            var reset = function() {
                button.disabled = false;
                button.textContent = label;
            };
            button.disabled = true;
            button.textContent = button.getAttribute('data-cb-label-busy');

            postJson(button.getAttribute('data-cb-action-url'))
                .then(function(data) {
                    if (!data || !data.success) {
                        throw new Error(data && data.error || 'Request failed');
                    }
                    notify(data.message, false);
                    if (Craft.cp && Craft.cp.runQueue) {
                        Craft.cp.runQueue();
                    }
                    if (!data.jobId) {
                        throw new Error('The queue returned no job ID.');
                    }
                    pollSanitizedCopy(button, data.jobId, data.queuedAt, reset);
                })
                .catch(function(err) {
                    reset();
                    notify(err.message || String(err), true);
                });
        });
    }

    function boot() {
        moveExtensionOutsideContent();
        initSanitizeButton();
        loadAllCards();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window.jQuery);
