document.addEventListener('DOMContentLoaded', () => {
    const burgerToggle = document.getElementById('burgerToggle');
    const navMenu = document.getElementById('nav-menu');
    const MOBILE_BREAKPOINT = 870;

    if (!burgerToggle || !navMenu) {
        return;
    }

    const isOpen = () => navMenu.classList.contains('open');

    const setOpen = (open) => {
        navMenu.classList.toggle('open', open);
        burgerToggle.classList.toggle('open', open);
        burgerToggle.setAttribute('aria-expanded', String(open));
        burgerToggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    };

    burgerToggle.addEventListener('click', () => {
        setOpen(!isOpen());
    });

    document.addEventListener('click', (event) => {
        if (isOpen() && !navMenu.contains(event.target) && !burgerToggle.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
            burgerToggle.focus();
        }
    });

    navMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    const dropdowns = document.querySelectorAll('.nav-dropdown');
    dropdowns.forEach((dd) => {
        const toggle = dd.querySelector('.nav-dropdown-toggle');
        const menu = dd.querySelector('.nav-dropdown-menu');
        if (!toggle || !menu) return;
        const setDdOpen = (open) => {
            dd.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', String(open));
        };
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !dd.classList.contains('open');
            document.querySelectorAll('.nav-dropdown.open').forEach((other) => {
                if (other !== dd) {
                    other.classList.remove('open');
                    const t = other.querySelector('.nav-dropdown-toggle');
                    if (t) t.setAttribute('aria-expanded', 'false');
                }
            });
            setDdOpen(willOpen);
        });
        toggle.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                setDdOpen(false);
                toggle.focus();
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.nav-dropdown')) {
            document.querySelectorAll('.nav-dropdown.open').forEach((dd) => {
                dd.classList.remove('open');
                const t = dd.querySelector('.nav-dropdown-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > MOBILE_BREAKPOINT && isOpen()) {
            setOpen(false);
        }
    });
});
