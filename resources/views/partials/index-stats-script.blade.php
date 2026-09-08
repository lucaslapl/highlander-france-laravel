<script>
fetch("/api/index-stats").then(function (response) {
    return response.ok ? response.json() : null;
}).then(function (stats) {
    if (stats && stats.data) {
        var matchCount = document.getElementById("matchCount");
        var hoursPlayed = document.getElementById("hoursPlayed");
        var memberCount = document.getElementById("memberCount");
        if (matchCount) matchCount.textContent = stats.data.matches;
        if (hoursPlayed) hoursPlayed.textContent = stats.data.hours;
        if (memberCount && stats.data.members) memberCount.textContent = stats.data.members;
    } else {
        console.error("Structure JSON inattendue :", stats);
    }
}).catch(function (err) {
    console.error("Impossible de charger les stats :", err);
});
</script>
