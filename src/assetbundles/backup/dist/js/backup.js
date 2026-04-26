(function($) {
    if (!$ || typeof Craft === 'undefined' || !Craft.Tabs) {
        return;
    }

    function moveExtensionOutsideContent() {
        var $extension = $('.cb-utility-extension');
        var $content = $('#content');
        if ($extension.length && $content.length && $.contains($content[0], $extension[0])) {
            $extension.insertAfter($content);
        }
    }

    function initTabs() {
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

    function boot() {
        moveExtensionOutsideContent();
        initTabs();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window.jQuery);
