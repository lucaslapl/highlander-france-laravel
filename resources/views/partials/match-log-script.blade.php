
<script>
    (function () {
        const getIndex = cell => Array.prototype.indexOf.call(cell.parentElement.children, cell);

        document.querySelectorAll(".matchlog-table").forEach(function (table) {
            const tbody = table.querySelector("tbody");
            if (!tbody) return;

            table.querySelectorAll("thead th").forEach(function (th) {
                const type = th.dataset.sort;
                if (!type) return;

                th.addEventListener("click", function () {
                    const idx = getIndex(th);
                    const rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));

                    rows.sort(function (a, b) {
                        const ca = a.cells[idx];
                        const cb = b.cells[idx];
                        if (type === "num") {
                            const va = parseFloat(ca.dataset.sortVal || "0");
                            const vb = parseFloat(cb.dataset.sortVal || "0");
                            return vb - va;
                        }
                        return ca.textContent.trim().localeCompare(cb.textContent.trim(), "fr");
                    });

                    rows.forEach(function (row, pos) {
                        row.cells[0].textContent = pos + 1;
                    });

                    rows.forEach(function (row) {
                        tbody.appendChild(row);
                    });

                    table.querySelectorAll("thead th").forEach(function (h) {
                        h.classList.remove("sorted-asc", "sorted-desc");
                    });
                    th.classList.add("sorted-desc");
                });
            });
        });
    })();

    @if ($isAdmin)
    document.addEventListener("click", function (event) {
        const btn = event.target.closest(".matchlog-admin .btn-blacklist");
        if (!btn) return;
        const logId = btn.getAttribute("data-log-id");
        const logTitle = btn.getAttribute("data-log-title");

        if (!confirm(`Blacklister le log #${logId} (« ${logTitle} ») ?\nIl sera exclu des Match Stats et des statistiques.`)) {
            return;
        }

        fetch("/api/admin/blacklist", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": "{{ csrf_token() }}"
            },
            body: new URLSearchParams({ action: "add", log_id: String(logId) })
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (res) {
            if (res && res.success) {
                alert("Log blacklisté. Il a été retiré des statistiques.");
                window.location.href = "/match-logs";
            } else {
                alert(res && res.message ? res.message : "Erreur lors du blacklisting du log.");
            }
        }).catch(function () {
            alert("Erreur lors du blacklisting du log.");
        });
    });
    @endif
</script>
