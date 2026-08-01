/**
 * Ali Admin Template - Main JavaScript
 * Clean, Responsive, and Modern Admin Template for SLiMS
 */

(function($) {
    'use strict';

    if (typeof $ === 'undefined') {
        console.error('Ali Template: jQuery is required');
        return;
    }

    const AliTemplate = {
        init: function() {
            this.initSidebar();
            this.initDropdowns();
            this.initMenu();
            this.initAccessibility();
        },
        
        initSidebar: function() {
            const sidebar = $('#sidepan');
            const sidebarOverlay = $('.sidebar-overlay');
            const sidebarToggle = $('.sidebar-toggle');
            const mainContent = $('#mainContent');

            if (!sidebar.length) return;

            // Mobile toggle
            sidebarToggle.on('click', function(e) {
                e.preventDefault();
                sidebar.toggleClass('mobile-open');
                sidebarOverlay.toggleClass('active');
                sidebarToggle.attr('aria-expanded', sidebar.hasClass('mobile-open'));
            });

            // Close on overlay click
            sidebarOverlay.on('click', function() {
                sidebar.removeClass('mobile-open');
                sidebarOverlay.removeClass('active');
                sidebarToggle.attr('aria-expanded', 'false');
            });

            // Close on resize
            $(window).on('resize', function() {
                if ($(window).width() > 768) {
                    sidebar.removeClass('mobile-open');
                    sidebarOverlay.removeClass('active');
                    sidebarToggle.attr('aria-expanded', 'false');
                }
            });

            // Collapse button
            const collapseBtn = $('<button class="sidebar-collapse" aria-label="Collapse sidebar"><i class="fa fa-chevron-left"></i></button>');
            sidebar.append(collapseBtn);

            collapseBtn.on('click', function() {
                sidebar.toggleClass('collapsed');
                $(this).find('i').toggleClass('fa-chevron-left fa-chevron-right');
                $(this).attr('aria-label', sidebar.hasClass('collapsed') ? 'Expand sidebar' : 'Collapse sidebar');
                localStorage.setItem('ali_sidebar_collapsed', sidebar.hasClass('collapsed'));
            });

            // Restore collapsed state
            if (localStorage.getItem('ali_sidebar_collapsed') === 'true' && window.innerWidth > 768) {
                sidebar.addClass('collapsed');
                collapseBtn.find('i').addClass('fa-chevron-right').removeClass('fa-chevron-left');
                collapseBtn.attr('aria-label', 'Expand sidebar');
            }
        },
        
        initDropdowns: function() {
            // Close dropdowns when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.dropdown').length) {
                    $('.dropdown-menu').removeClass('show');
                }
            });

            // Keyboard navigation
            $('.dropdown-menu [role="menuitem"]').on('keydown', function(e) {
                const $items = $('.dropdown-menu [role="menuitem"]');
                const currentIndex = $items.index(this);
                
                switch(e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        $items.eq((currentIndex + 1) % $items.length).focus();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        $items.eq((currentIndex - 1 + $items.length) % $items.length).focus();
                        break;
                    case 'Escape':
                        e.preventDefault();
                        $('.dropdown-menu').removeClass('show');
                        break;
                }
            });
        },
        
        initMenu: function() {
            // Highlight active menu
            const currentUrl = window.location.href;
            $('#sidepan a, #mainMenu a').each(function() {
                const href = $(this).attr('href');
                if (href && href !== '#' && currentUrl.includes(href)) {
                    $(this).closest('li').addClass('active');
                }
            });

            // Help system integration
            $('.s-close').click(function(e){
                e.preventDefault();
                $('.s-help').removeClass('d-none');
                $('.s-close').addClass('d-none');
                $('.s-help-content').html('').addClass('d-none');
            });

            $('.s-help').click(function(e){
                e.preventDefault();
                if($(this).attr('href') != '#') {
                    $('.s-help-content').addClass('d-none');
                    $('.s-close').toggleClass('d-none');
                    $.ajax({
                        type: 'GET',
                        url: $(this).attr('href')
                    }).done(function( data ) {
                        $('.s-help-content').html(data).removeClass('d-none');
                        $('.s-close').toggleClass('d-none');
                    });
                }
            });

            $('.subMenuItem').click(function(){
                $('.s-help').removeClass('d-none');
                $('.s-close, .s-help-content').addClass('d-none');
                let get_url = $(this).attr('href');
                let path_array = get_url.split('/');
                let clean_path = path_array[path_array.length-1].split('.');
                let new_pathname = window.location.origin + window.location.pathname.replace(/\/admin\/.*$/, '/admin/help.php?url=') + path_array[path_array.length-2]+'/'+clean_path[0]+'.md';
                $('.s-help').attr('href', new_pathname);
            });
        },
        
        initAccessibility: function() {
            // Skip link
            $('.skip-link').on('click', function(e) {
                e.preventDefault();
                const mainContent = $('#mainContent');
                if (mainContent.length) {
                    mainContent.attr('tabindex', '-1');
                    mainContent.focus();
                    $(window).scrollTop(0);
                }
            });

            // Focus trap for mobile sidebar
            $(document).on('keydown', function(e) {
                if (e.key === 'Tab' && $('#sidepan').hasClass('mobile-open')) {
                    const focusableElements = $('#sidepan').find('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
                    const firstElement = focusableElements.first();
                    const lastElement = focusableElements.last();
                    
                    if (e.target === lastElement[0] && !e.shiftKey) {
                        e.preventDefault();
                        firstElement.focus();
                    } else if (e.target === firstElement[0] && e.shiftKey) {
                        e.preventDefault();
                        lastElement.focus();
                    }
                }
            });
        }
    };

    // Initialize
    $(document).ready(function() {
        AliTemplate.init();
    });

    // Expose globally
    window.AliTemplate = AliTemplate;

})(jQuery);
