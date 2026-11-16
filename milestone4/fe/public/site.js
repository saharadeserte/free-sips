(() => {
    const THEME_KEY = 'fs_theme';
    const savedTheme = localStorage.getItem(THEME_KEY) || 'default';
    if (savedTheme !== 'default') {
        document.documentElement.setAttribute('data-theme', savedTheme);
    }

    window.applyTheme = (theme) => {
        if (!theme || theme === 'default') {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem(THEME_KEY, 'default');
        } else {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem(THEME_KEY, theme);
        }
    };
    window.getSavedTheme = () => localStorage.getItem(THEME_KEY) || 'default';

    function el(tag, props = {}, text = '') {
        const node = document.createElement(tag);
        Object.assign(node, props);
        if (text) node.textContent = text;
        return node;
    }

    // Reusable debounced autocomplete with AbortController + prefix cache (Spec 3 & 7)
    window.attachAutocomplete = function attachAutocomplete(inputEl, endpoint, onSelect) {
        const wrap = inputEl.parentElement;
        if (getComputedStyle(wrap).position === 'static') {
            wrap.style.position = 'relative';
        }
        const box = el('ul', { className: 'suggest-box', hidden: true });
        wrap.append(box);

        const cache = new Map();
        let timer = null;
        let controller = null;

        function hide() {
            box.hidden = true;
            box.replaceChildren();
        }

        function renderItems(items) {
            box.replaceChildren();
            if (!items || !items.length) {
                hide();
                return;
            }
            items.forEach((item) => {
                const label = typeof item === 'string' ? item : item.name;
                const li = el('li', {}, label);
                li.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    hide();
                    onSelect(item);
                });
                box.append(li);
            });
            box.hidden = false;
        }

        inputEl.addEventListener('input', () => {
            const q = inputEl.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
                if (controller) controller.abort();
                hide();
                return;
            }
            const key = q.toLowerCase();
            if (cache.has(key)) {
                renderItems(cache.get(key));
                return;
            }
            timer = setTimeout(async () => {
                if (controller) controller.abort();
                controller = new AbortController();
                try {
                    const res = await fetch(`${endpoint}?q=${encodeURIComponent(q)}`, {
                        credentials: 'same-origin',
                        signal: controller.signal
                    });
                    if (!res.ok) return hide();
                    const data = await res.json();
                    const list = data.suggestions || [];
                    cache.set(key, list);
                    renderItems(list);
                } catch (err) {
                    if (err.name !== 'AbortError') hide();
                }
            }, 250);
        });

        inputEl.addEventListener('blur', () => setTimeout(hide, 150));
        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') hide();
        });
    };

    // Build shared navbar across all pages
    function initNavbar() {
        const header = document.querySelector('header.topbar');
        if (!header) return;
        header.replaceChildren();

        const path = window.location.pathname;
        const brand = el('a', { className: 'brand', href: '/' }, '🍵 FreeSpirit Sips');

        const navLinks = el('nav', { className: 'nav-links', ariaLabel: 'Primary' });
        const browseLink = el('a', { className: 'dash-btn', href: '/index' }, 'Browse Drinks');
        if (path === '/index') browseLink.setAttribute('aria-current', 'page');

        const shareLink = el('a', { className: 'dash-btn', href: '/share' }, 'Share a drink');
        if (path === '/share') shareLink.setAttribute('aria-current', 'page');

        navLinks.append(browseLink, shareLink);

        const searchWrap = el('div', { className: 'search-wrap' });
        const searchForm = el('form', { className: 'search', role: 'search', autocomplete: 'off' });
        const searchInput = el('input', {
            type: 'search',
            name: 'q',
            placeholder: 'Search drinks...',
            ariaLabel: 'Search drinks',
            value: new URLSearchParams(window.location.search).get('q') || ''
        });
        const searchBtn = el('button', { type: 'submit' }, 'Search');
        searchForm.append(searchInput, searchBtn);
        searchWrap.append(searchForm);

        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const q = searchInput.value.trim();
            window.location.href = q ? `/search?q=${encodeURIComponent(q)}` : '/index';
        });

        window.attachAutocomplete(searchInput, '/api/suggest/drinks', (item) => {
            if (item && item.drink_id) {
                window.location.href = `/drink/${item.drink_id}`;
            } else if (typeof item === 'string') {
                window.location.href = `/search?q=${encodeURIComponent(item)}`;
            }
        });

        const authArea = el('div', { className: 'auth-area', id: 'authArea' });
        header.append(brand, navLinks, searchWrap, authArea);

        // Ensure shortened wavy footer is present on every page without "IT 490: Team 1"
        let border = document.querySelector('.sip-border');
        if (!border) {
            border = el('div', { className: 'sip-border' });
            document.body.append(border);
        }
        border.replaceChildren();
        const sipRow = el('div', { className: 'sip-row', ariaHidden: 'true' });
        ['🍵', '🫖', '🥥', '🥤', '🧋', '🧃', '🍋', '🍹', '🧊', '🍵', '🥥', '🥤'].forEach((em) => {
            sipRow.append(el('span', {}, em));
        });
        border.append(sipRow);
    }

    async function loadSharedSession() {
        initNavbar();
        let user = null;
        try {
            const res = await fetch('/api/me', { credentials: 'same-origin' });
            if (res.ok) {
                const data = await res.json();
                user = data.user || null;
            }
        } catch { }

        window.currentUser = user;
        renderSharedAuth(user);
        window.dispatchEvent(new CustomEvent('fs:auth', { detail: { user } }));
    }

    function renderSharedAuth(user) {
        const area = document.getElementById('authArea');
        if (!area) return;
        area.replaceChildren();
        const path = window.location.pathname;

        if (user) {
            const greeting = el('span', { className: 'user' }, `Hi, ${user.username}`);
            const settingsLink = el('a', {
                className: 'dash-btn gear-btn',
                href: '/settings',
                title: 'Account & Theme Settings',
                ariaLabel: 'Settings'
            }, '⚙️');
            if (path === '/settings') settingsLink.setAttribute('aria-current', 'page');

            const logoutBtn = el('button', { className: 'dash-btn', type: 'button' }, 'Log out');
            logoutBtn.addEventListener('click', async () => {
                await fetch('/api/logout', { method: 'POST', credentials: 'same-origin' });
                window.currentUser = null;
                renderSharedAuth(null);
                window.dispatchEvent(new CustomEvent('fs:auth', { detail: { user: null } }));
                if (window.location.pathname === '/settings') {
                    window.location.href = '/landing';
                }
            });
            area.append(greeting, settingsLink, logoutBtn);
        } else {
            const loginBtn = el('a', { className: 'dash-btn', href: '/login' }, 'Log in');
            const regBtn = el('a', { className: 'dash-btn', href: '/register' }, 'Register');
            if (path === '/login') loginBtn.setAttribute('aria-current', 'page');
            if (path === '/register') regBtn.setAttribute('aria-current', 'page');
            area.append(loginBtn, regBtn);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSharedSession);
    } else {
        loadSharedSession();
    }
})();
