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

    window.addEventListener('resize', () => {
        if (window.innerWidth > MOBILE_BREAKPOINT && isOpen()) {
            setOpen(false);
        }
    });
});
