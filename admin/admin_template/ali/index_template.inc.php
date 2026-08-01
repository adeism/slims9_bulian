<!doctype html>
<html lang="<?php echo $sysconf['default_lang'] ?? 'en'; ?>">
<head>
    <title><?php echo $page_title; ?> - <?php echo $sysconf['library_name'] ?? 'SLiMS'; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, post-check=0, pre-check=0" />
    <meta http-equiv="Expires" content="Sat, 26 Jul 1997 05:00:00 GMT" />
    <meta name="env" content="<?= isDev() ? 'dev' : 'prod' ?>"/>
    <meta name="description" content="<?php echo $sysconf['library_name'] ?? 'SLiMS Library Management System'; ?> - Admin Dashboard">

    <?php
    $imagesDisk = \SLiMS\Filesystems\Storage::images();
    $icon = SWB . 'webicon.ico';
    if (isset($sysconf['webicon']) && !empty($sysconf['webicon']) && $imagesDisk->isExists($path = 'default/' . $sysconf['webicon']))
    {
        $icon = SWB . 'lib/minigalnano/createthumb.php?filename=images/' . $path . '&width=130';
    }
    ?>

    <!-- Favicon -->
    <link rel="icon" href="<?= $icon ?>" type="image/x-icon" />
    <link rel="shortcut icon" href="<?= $icon ?>" type="image/x-icon" />

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?php echo JWB; ?>font-awesome/css/fontawesome.min.css">

    <!-- Bootstrap Core CSS (required by SLiMS) -->
    <link href="<?php echo SWB; ?>css/bootstrap.min.css?<?php echo date('this') ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo SWB; ?>css/core.css?<?php echo date('this') ?>" rel="stylesheet" type="text/css" />
    
    <!-- SLiMS Required CSS -->
    <link href="<?php echo JWB; ?>colorbox/colorbox.css?<?php echo date('this') ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo JWB; ?>chosen/chosen.css?<?php echo date('this') ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo JWB; ?>toastr/toastr.min.css?<?php echo date('this') ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo JWB; ?>jquery.imgareaselect/css/imgareaselect-default.css" rel="stylesheet" type="text/css" />
    <link href="<?php echo JWB; ?>datepicker/css/datepicker-bs4.min.css" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="<?php echo JWB; ?>/croppie/croppie.css"/>

    <!-- Ali Template CSS -->
    <link href="<?php echo $sysconf['admin_template']['css'].'?v='.date('this'); ?>" rel="stylesheet" type="text/css" />

    <!-- jQuery and Required JS -->
    <script type="text/javascript" src="<?php echo JWB; ?>jquery.js"></script>
    <script type="text/javascript" src="<?php echo AWB; ?>admin_template/<?php echo $sysconf['admin_template']['theme']?>/vendor/slimscroll/jquery.slimscroll.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>updater.js?v=<?php echo date('this') ?>"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>gui.js??v=<?php echo date('this') ?>"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>form.js?v=<?php echo date('this') ?>"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>calendar.js?v=<?php echo date('this') ?>"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>chosen/chosen.jquery.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>chosen/ajax-chosen.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>ckeditor5/ckeditor.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>tooltipsy.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>colorbox/jquery.colorbox-min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>jquery.imgareaselect/scripts/jquery.imgareaselect.pack.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>webcam.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>scanner.js"></script>
    <script type="text/javascript" src="<?php echo SWB; ?>js/popper.min.js"></script>
    <script type="text/javascript" src="<?php echo SWB; ?>js/bootstrap.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>toastr/toastr.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>datepicker/js/datepicker-full.min.js"></script>
    <script type="text/javascript" src="<?php echo JWB; ?>/croppie/croppie.js"></script>

    <?php if (file_exists(SB . 'js/datepicker/js/locales/' . substr($sysconf['default_lang'], 0,2) . '.js')): ?>
    <script type="text/javascript" src="<?php echo JWB; ?>datepicker/js/locales/<?= substr($sysconf['default_lang'], 0,2) ?>.js"></script>
    <?php endif; ?>

    <!-- Ali Template JS -->
    <script type="text/javascript" src="<?php echo $sysconf['admin_template']['dir'].'/'.$sysconf['admin_template']['theme']; ?>/js/main.js"></script>
    <script type="text/javascript" src="<?php echo $sysconf['admin_template']['dir'].'/'.$sysconf['admin_template']['theme']; ?>/js/smooth-scrollbar.js"></script>
    <script type="text/javascript" src="<?php echo $sysconf['admin_template']['dir'].'/'.$sysconf['admin_template']['theme']; ?>/js/overscroll.js"></script>

    <?php if($sysconf['chat_system']['enabled']) : ?>
    <script src="<?php echo JWB; ?>fancywebsocket.js"></script>
    <?php endif; ?>

    <!-- Theme Color Variables -->
    <style>
        :root {
            --primary: <?php echo $sysconf['admin_template']['default_color'] ?? '#3b82f6'; ?>;
            --primary-light: color-mix(in srgb, var(--primary), white 40%);
            --primary-dark: color-mix(in srgb, var(--primary), black 20%);
        }
        
        #sidepan,
        .s-user:after {
            background-color: var(--primary) !important;
        }
        
        #sidepan .s-user-frame {
            border-color: var(--primary-light);
        }
        
        #sidepan > ul > li.active > a {
            background-color: color-mix(in srgb, var(--primary), white 20%);
        }
        
        #sidepan > ul > li > a:hover {
            background-color: color-mix(in srgb, var(--primary), white 10%);
        }
        
        .btn-primary {
            background-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
    </style>
</head>
<body>
    <!-- Skip Link for Accessibility -->
    <a href="#mainContent" class="skip-link">Skip to main content</a>

    <!-- Header -->
    <header id="header">
        <button class="sidebar-toggle" aria-label="Toggle sidebar">
            <i class="fa fa-bars"></i>
        </button>
        
        <nav id="mainMenu">
            <?php echo $main_menu; ?>
        </nav>
        
        <div class="header-right">
            <!-- User Dropdown -->
            <div class="dropdown user-dropdown">
                <button class="btn btn-link dropdown-toggle" type="button" id="userDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <?php
                    if (filter_var($_SESSION['upict'], FILTER_VALIDATE_URL)) {
                        $user_image_url = $_SESSION['upict'];
                    } else {
                        $user_image = $_SESSION['upict'] && file_exists(IMGBS . 'persons/' . $_SESSION['upict']) ? $_SESSION['upict'] : 'person.png';
                        $user_image_url = '../lib/minigalnano/createthumb.php?filename=' . IMG . '/persons/' . urlencode(urlencode($user_image)) . '&width=100';
                    }
                    ?>
                    <img src="<?= $user_image_url ?>" alt="<?php echo $_SESSION['realname']; ?>" class="user-avatar">
                    <span class="user-name d-none d-md-inline"><?php echo $_SESSION['realname']; ?></span>
                    <i class="fa fa-chevron-down d-none d-md-inline"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                    <div class="dropdown-header">
                        <h5 class="mb-0"><?php echo $_SESSION['realname']; ?></h5>
                        <p class="mb-0 text-muted text-sm"><?php echo isset($_SESSION['nname']) ? $_SESSION['nname'] : __('Librarian'); ?></p>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo MWB.'system/app_user.php?changecurrent=true&action=detail'; ?>">
                        <i class="fa fa-user mr-2"></i> Profile
                    </a>
                    <a class="dropdown-item" href="<?php echo MWB.'system/app_user.php?changecurrent=true&action=edit'; ?>">
                        <i class="fa fa-cog mr-2"></i> Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="<?php echo AWB; ?>logout.php">
                        <i class="fa fa-sign-out mr-2"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" aria-hidden="true"></div>

    <!-- Sidebar -->
    <nav id="sidepan">
        <div class="s-user" id="profile">
            <div class="s-user-frame">
                <a href="<?php echo MWB.'system/app_user.php?changecurrent=true&action=detail'; ?>" class="subMenuItem" aria-label="User profile">
                    <img src="<?= $user_image_url ?>" alt="<?php echo $_SESSION['realname']; ?>">
                </a>
            </div>
            <div class="s-user-info">
                <h4 class="s-user-name"><?php echo $_SESSION['realname']; ?></h4>
                <p class="s-user-role"><?php echo isset($_SESSION['nname']) ? $_SESSION['nname'] : __('Librarian'); ?></p>
            </div>
        </div>

        <div class="sidebar-scroll">
            <?php echo $sub_menu; ?>
        </div>
    </nav>

    <!-- Loader -->
    <div class="loader hidden">
        <div class="bounce1"></div>
        <div class="bounce2"></div>
        <div class="bounce3"></div>
    </div>

    <!-- Main Content -->
    <main id="mainContent" role="main">
        <?php echo $main_content; ?>
    </main>

    <!-- Help System -->
    <div id="help">
        <a href="#" name="top" class="s-help d-none" aria-label="Help"><i class="fa fa-question-circle"></i></a>
        <a href="#" name="top" class="s-close d-none" aria-label="Close help"><i class="fa fa-times"></i></a>
        <div class="s-help-content animated fade-in d-none"></div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-left">
                <strong>SLiMS</strong> <?php echo SENAYAN_VERSION; ?>
            </div>
            <div class="footer-right">
                <?php echo $sysconf['page_footer']; ?>
            </div>
        </div>
    </footer>

    <!-- Fake submit iframe for search form -->
    <iframe name="blindSubmit" style="display: none; visibility: hidden; width: 0; height: 0;"></iframe>

    <!-- Chat System -->
    <?php include "chat.php" ?>

    <!-- Initialize Scripts -->
    <script>
        (function($) {
            'use strict';

            // Hide loader
            $('.loader').toggleClass('hidden').hide();

            // Sidebar Toggle
            const sidebar = $('#sidepan');
            const sidebarOverlay = $('.sidebar-overlay');
            const sidebarToggle = $('.sidebar-toggle');

            function toggleSidebar() {
                sidebar.toggleClass('mobile-open');
                sidebarOverlay.toggleClass('active');
                sidebarToggle.attr('aria-expanded', sidebar.hasClass('mobile-open'));
            }

            sidebarToggle.on('click', function(e) {
                e.preventDefault();
                toggleSidebar();
            });

            sidebarOverlay.on('click', function() {
                sidebar.removeClass('mobile-open');
                $(this).removeClass('active');
                sidebarToggle.attr('aria-expanded', 'false');
            });

            $(window).on('resize', function() {
                if ($(window).width() > 768) {
                    sidebar.removeClass('mobile-open');
                    sidebarOverlay.removeClass('active');
                    sidebarToggle.attr('aria-expanded', 'false');
                }
            });

            // Sidebar Collapse
            const collapseBtn = $('<button class="sidebar-collapse" aria-label="Collapse sidebar"><i class="fa fa-chevron-left"></i></button>');
            sidebar.append(collapseBtn);

            collapseBtn.on('click', function() {
                sidebar.toggleClass('collapsed');
                $(this).find('i').toggleClass('fa-chevron-left fa-chevron-right');
                $(this).attr('aria-label', sidebar.hasClass('collapsed') ? 'Expand sidebar' : 'Collapse sidebar');
            });

            // Initialize Scrollbar
            let Scrollbar = window.Scrollbar;
            if (Scrollbar) {
                Scrollbar.use(window.OverscrollPlugin);
                Scrollbar.init(document.querySelector('#sidepan .sidebar-scroll'), {
                    alwaysShowTracks: <?php echo $sysconf['admin_template']['always_show_tracks'] ?? 'false'; ?>,
                    continuousScrolling: false,
                    plugins: {
                        overscroll: { effect: 'glow' }
                    }
                });
            }

            // Help System
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
                let new_pathname = '<?php echo AWB?>help.php?url='+path_array[path_array.length-2]+'/'+clean_path[0]+'.md';
                $('.s-help').attr('href', new_pathname);
            });

            // Active menu
            const currentUrl = window.location.href;
            $('#sidepan a, #mainMenu a').each(function() {
                const href = $(this).attr('href');
                if (currentUrl.includes(href) && href !== '#') {
                    $(this).closest('li').addClass('active');
                }
            });

        })(jQuery);
    </script>
</body>
</html>
