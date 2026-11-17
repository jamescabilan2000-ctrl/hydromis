(function () {
    'use strict';
    var key = 'hydromis-admin-color-mode';
    function normalize(mode) { return mode === 'light' ? 'light' : 'dark'; }
    window.applyAdminColorMode = function (mode, persist) {
        mode = normalize(mode);
        document.documentElement.setAttribute('data-admin-color-mode', mode);
        if (document.body) document.body.setAttribute('data-color-mode', mode);
        if (persist !== false) localStorage.setItem(key, mode);
        window.dispatchEvent(new CustomEvent('admin-theme-change', { detail:{ mode:mode } }));
        return mode;
    };
    var saved = normalize(localStorage.getItem(key));
    document.documentElement.setAttribute('data-admin-color-mode', saved);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { 
            window.applyAdminColorMode(saved, false); 
        }, { once:true });
    } else {
        window.applyAdminColorMode(saved, false);
    }
})();

(function () {
    'use strict';
    /* Versioned once so installations using the old hover sidebar start in
       the intended collapsed state. Future choices remain persistent. */
    var key = 'hydromis-admin-sidebar-v2';
    var root = document.documentElement;

    function isExpanded() {
        return root.getAttribute('data-admin-sidebar') === 'expanded';
    }

    function applySidebarState(expanded, persist) {
        root.setAttribute('data-admin-sidebar', expanded ? 'expanded' : 'collapsed');
        if (persist !== false) localStorage.setItem(key, expanded ? 'expanded' : 'collapsed');

        var button = document.querySelector('.admin-sidebar-toggle');
        var sidebar = document.querySelector('.sidebar');
        if (sidebar) sidebar.classList.remove('sidebar-profile-open');
        if (!button) return;
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
        button.title = expanded ? 'Hide full sidebar' : 'Show full sidebar';
        var icon = button.querySelector('.toggle-icon');
        var label = button.querySelector('.toggle-label');
        if (icon) icon.textContent = expanded ? '\u2039' : '\u203a';
        if (label) label.textContent = expanded ? 'Collapse sidebar' : 'Expand sidebar';
    }

    function normalizeAdminSidebar(sidebar) {
        var brand = sidebar.querySelector('.brand-logo') || sidebar.querySelector('.brand');
        if (brand) {
            brand.classList.add('brand-logo');
            var brandImage = brand.querySelector('img');
            var brandIcon = brand.querySelector('.brand-icon');
            if (!brandIcon && brandImage) {
                var imageParent = brandImage.parentElement;
                if (imageParent && imageParent !== brand && imageParent.children.length === 1) {
                    brandIcon = imageParent;
                    brandIcon.classList.add('brand-icon');
                } else {
                    brandIcon = document.createElement('div');
                    brandIcon.className = 'brand-icon';
                    brand.insertBefore(brandIcon, brand.firstChild);
                    brandIcon.appendChild(brandImage);
                }
            }
            if (brandIcon) brandIcon.classList.add('brand-icon');
        }

        var nav = sidebar.querySelector('nav');
        if (nav) {
            var page = (location.pathname.split('/').pop() || 'dashboard.php').toLowerCase();
            var sections = [
                ['Main', [
                    ['dashboard.php', 'fa-chart-pie', 'Dashboard'],
                    ['transactions.php', 'fa-exchange-alt', 'Transactions'],
                    ['reports.php', 'fa-chart-bar', 'Reports'],
                    ['feedback.php', 'fa-comments', 'Customer Feedback'],
                    ['inventory.php', 'fa-boxes-stacked', 'Inventory'],
                    ['pricing.php', 'fa-tags', 'Container Pricing'],
                    ['rewards.php', 'fa-gift', 'Rewards & Loyalty']
                ]],
                ['People', [
                    ['users.php', 'fa-users', 'Users'],
                    ['staff_account.php', 'fa-user-shield', 'Staff Account'],
                    ['manage_riders.php', 'fa-motorcycle', 'Riders']
                ]],
                ['System', [
                    ['activity_logs.php', 'fa-clock-rotate-left', 'Activity Log'],
                    ['dashboard.php?open_settings=1', 'fa-cog', 'Settings']
                ]]
            ];

            nav.innerHTML = sections.map(function (section) {
                var links = section[1].map(function (item) {
                    var targetPage = item[0].split('?')[0];
                    var active = page === targetPage && item[2] !== 'Settings' ? ' active' : '';
                    return '<a href="' + item[0] + '" class="nav-item' + active + '" title="' + item[2] + '" aria-label="' + item[2] + '"><i class="fas ' + item[1] + '" aria-hidden="true"></i><span class="nav-text">' + item[2] + '</span></a>';
                }).join('');
                return '<div class="nav-section"><div class="nav-section-label">' + section[0] + '</div><div class="nav-group">' + links + '</div></div>';
            }).join('');

            var settingsLink = nav.querySelector('a[href="dashboard.php?open_settings=1"]');
            if (settingsLink && page === 'dashboard.php' && typeof window.openSettings === 'function') {
                settingsLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    window.openSettings();
                });
            }
        }

        var footer = sidebar.querySelector('.sidebar-footer, .sidebar-foot');
        if (footer) {
            footer.classList.add('sidebar-footer');
            var card = footer.querySelector('.admin-card');
            if (!card) {
                card = document.createElement('div');
                card.className = 'admin-card';
                while (footer.firstChild) card.appendChild(footer.firstChild);
                footer.appendChild(card);
            }
            var avatar = card.querySelector('.admin-avatar, .avatar');
            if (avatar) avatar.classList.add('admin-avatar');
            var name = card.querySelector('.admin-name, strong');
            if (name) name.classList.add('admin-name');
            var role = card.querySelector('.admin-role, span');
            if (role) role.classList.add('admin-role');
            var logout = card.querySelector('.logout-link, a[href*="logout"]');
            if (logout) logout.classList.add('logout-link');
        }
    }

    applySidebarState(localStorage.getItem(key) === 'expanded', false);

    function setupSidebarToggle() {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar || sidebar.querySelector('.admin-sidebar-toggle')) return;

        normalizeAdminSidebar(sidebar);

        var avatar = sidebar.querySelector('.admin-avatar');
        var logout = sidebar.querySelector('.logout-link');
        if (avatar && logout) {
            avatar.setAttribute('role', 'button');
            avatar.setAttribute('tabindex', '0');
            avatar.setAttribute('aria-label', 'Open admin logout confirmation');
            avatar.title = 'Admin account and logout';
            if (!logout.querySelector('.logout-text')) {
                var logoutText = document.createElement('span');
                logoutText.className = 'logout-text';
                logoutText.textContent = 'Logout';
                logout.appendChild(logoutText);
            }
            avatar.addEventListener('click', function (event) {
                event.stopPropagation();
                logout.click();
            });
            avatar.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    logout.click();
                }
            });
        }

        sidebar.querySelectorAll('.nav-item').forEach(function (link) {
            var label = (link.textContent || '').replace(/\s+/g, ' ').trim();
            if (!label) return;
            if (!link.title) link.title = label;
            if (!link.getAttribute('aria-label')) link.setAttribute('aria-label', label);
        });

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-sidebar-toggle';
        button.innerHTML = '<span class="toggle-icon" aria-hidden="true">\u203a</span><span class="toggle-label">Expand sidebar</span>';
        button.addEventListener('click', function () {
            applySidebarState(!isExpanded(), true);
        });
        var footer = sidebar.querySelector('.sidebar-footer');
        (footer || sidebar).appendChild(button);
        applySidebarState(isExpanded(), false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSidebarToggle, { once: true });
    } else {
        setupSidebarToggle();
    }
})();
