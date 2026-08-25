
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
    $(document).on("click", ".matchlog-admin .btn-blacklist", function () {
        const logId = $(this).data("log-id");
        const logTitle = $(this).data("log-title");

        if (!confirm(`Blacklister le log #${logId} (« ${logTitle} ») ?\nIl sera exclu des Match Stats et des statistiques.`)) {
            return;
        }

        $.ajax({
            type: "POST",
            url: "/api/admin/blacklist",
            data: {
                action: "add",
                log_id: logId
            },
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": "{{ csrf_token() }}"
            },
            dataType: "json"
        }).done(function (res) {
            if (res.success) {
                alert("Log blacklisté. Il a été retiré des statistiques.");
                window.location.href = "/match-logs";
            } else {
                alert(res.message);
            }
        }).fail(function () {
            alert("Erreur lors du blacklisting du log.");
        });
    });
    @endif
</script>
