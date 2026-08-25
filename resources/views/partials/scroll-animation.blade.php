<script>
    window.addEventListener("load", function () {
        const content = document.querySelector("#content");
        const offset = -115;

        if (!content) return;

        setTimeout(() => {
            const target = content.getBoundingClientRect().top + window.scrollY + offset;
            const duration = 1000;
            const start = window.scrollY;
            const distance = target - start;
            const startTime = performance.now();

            function easeOutQuad(t) {
                return t * (2 - t);
            }

            function animateScroll(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = easeOutQuad(progress);

                window.scrollTo(0, start + distance * eased);

                if (progress < 1) {
                    requestAnimationFrame(animateScroll);
                }
            }

            requestAnimationFrame(animateScroll);
        }, 300);
    });
</script>
