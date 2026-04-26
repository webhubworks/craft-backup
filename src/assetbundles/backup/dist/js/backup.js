(function($) {
    if (!$ || typeof Craft === 'undefined' || !Craft.Tabs) {
        return;
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTabs);
    } else {
        initTabs();
    }
})(window.jQuery);
