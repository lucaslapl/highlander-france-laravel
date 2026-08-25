
<script>
    const HLFR_IS_ADMIN = {{ $isAdmin ? 'true' : 'false' }};

    $.getJSON("/api/logs", function (logs) {

        // Supprimer les 4 plus anciennes logs
        logs = logs.slice(0, logs.length - 4);

        if (HLFR_IS_ADMIN) {
            $("#logsTable thead tr").append('<th style="text-align:center;">Action</th>');
        }

        // Précalcul des chaînes une seule fois (évite de re-formater les dates à chaque rendu/filtre)
        logs = logs.map(log => {
            const d = new Date(log.date * 1000);
            const opts = {
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit"
            };
            return {
                id: log.id,
                map: log.map,
                title: log.title,
                _display: d.toLocaleString("fr-FR", opts),
                _filter: d.toLocaleString("fr-FR", opts).toLowerCase(),
                _map: String(log.map).toLowerCase(),
                _title: String(log.title).toLowerCase()
            };
        });

        const logsPerPage = 10;
        let currentPage = 1;

        let filteredLogs = [...logs];

        function applyFilters() {
            const dateFilter = $("#filter-date").val().trim().toLowerCase();
            const mapFilter = $("#filter-map").val().trim().toLowerCase();

            if (!dateFilter && !mapFilter) {
                filteredLogs = logs;
            } else {
                filteredLogs = logs.filter(log => {
                    if (dateFilter && !log._filter.includes(dateFilter)) return false;
                    if (mapFilter && !log._map.includes(mapFilter)) return false;
                    return true;
                });
            }

            currentPage = 1;
            renderTable(currentPage);
            renderPagination();
        }

        function escapeAttr(text) {
            return (text || '').toString().replace(/"/g, '&quot;');
        }

        function escapeHtml(text) {
            return (text == null ? '' : String(text))
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderTable(page) {
            const start = (page - 1) * logsPerPage;
            const end = start + logsPerPage;
            const pageLogs = filteredLogs.slice(start, end);

            let rows = "";

            pageLogs.forEach((log, index) => {
                let actionsCell = "";
                if (HLFR_IS_ADMIN) {
                    actionsCell = `
                <td style="text-align:center;">
                    <button type="button" class="btn-blacklist" data-log-id="${log.id}" data-log-title="${escapeAttr(log.title)}" title="Exclure ce log des statistiques">
                        <i class="fa-solid fa-ban"></i>
                    </button>
                </td>`;
                }

                rows += `
            <tr class="log-row" data-index="${index}">
                <td>${log._display}</td>
                <td>${escapeHtml(log.map)}</td>
                <td>
                    <div class="log-title-cell flex align-center gap-10">
                        <a class="log-link" href="/log/${log.id}">
                            ${escapeHtml(log.title)}
                        </a>
                        <a class="log-external" href="https://logs.tf/${log.id}" target="_blank" rel="noopener" title="Voir sur logs.tf">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>
                </td>
                ${actionsCell}
            </tr>
        `;
            });

            if (!pageLogs.length) {
                rows = '<tr><td colspan="' + (HLFR_IS_ADMIN ? 4 : 3) + '">Aucun log à afficher.</td></tr>';
            }

            $("#logsTable tbody").html(rows);

            $(".log-row").each(function (i) {
                setTimeout(() => $(this).addClass("visible"), i * 80);
            });
        }

        function renderPagination() {
            const totalPages = Math.max(1, Math.ceil(filteredLogs.length / logsPerPage));

            if (totalPages <= 1) {
                $("#pagination").html("");
                return;
            }

            const pageBtn = i => `<button class="page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            const prev = `<button class="page-btn nav" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button>`;
            const next = `<button class="page-btn nav" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button>`;

            // Fenêtre glissante autour de la page courante (jamais plus de 7 numéros)
            const maxVisible = 7;
            let start = Math.max(1, currentPage - 3);
            let end = Math.min(totalPages, start + maxVisible - 1);
            if (currentPage > totalPages - 4) {
                start = Math.max(1, totalPages - maxVisible + 1);
                end = totalPages;
            }

            let buttons = prev;

            if (start > 1) {
                buttons += pageBtn(1);
                if (start > 2) buttons += '<span class="page-ellipsis">…</span>';
            }

            for (let i = start; i <= end; i++) {
                buttons += pageBtn(i);
            }

            if (end < totalPages) {
                if (end < totalPages - 1) buttons += '<span class="page-ellipsis">…</span>';
                buttons += pageBtn(totalPages);
            }

            buttons += next;

            $("#pagination").html(buttons);
        }

        // Délégation : les handlers sont bindés une seule fois, quel que soit le nombre de pages
        $("#pagination").on("click", ".page-btn", function () {
            if ($(this).is("[disabled]")) return;
            const page = parseInt($(this).data("page"), 10);
            if (isNaN(page) || page === currentPage) return;
            currentPage = page;
            renderTable(currentPage);
            renderPagination();
        });

        // Délégation pour le blacklist (admin uniquement)
        $("#logsTable tbody").on("click", ".btn-blacklist", function () {
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
                    $(`.btn-blacklist[data-log-id="${logId}"]`).closest("tr").remove();
                    if ($("#logsTable tbody tr").length === 0) {
                        $("#logsTable tbody").html('<tr><td colspan="4">Aucun log à afficher.</td></tr>');
                    }
                } else {
                    alert(res.message);
                }
            }).fail(function () {
                alert("Erreur lors du blacklisting du log.");
            });
        });

        // Événements des filtres (debounce 200ms pour éviter de recalculer à chaque frappe)
        let filterTimer;
        $("#filter-date").on("input", function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(applyFilters, 200);
        });
        $("#filter-map").on("input", function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(applyFilters, 200);
        });

        // Affichage initial
        applyFilters();
    });
</script>
