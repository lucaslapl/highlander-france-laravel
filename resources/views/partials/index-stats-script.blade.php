<script>
$.getJSON("/api/index-stats", function(stats) {
    if (stats.data) {
        $("#matchCount").text(stats.data.matches);
        $("#hoursPlayed").text(stats.data.hours);
        if (stats.data.members) {
            $("#memberCount").text(stats.data.members);
        }
    } else {
        console.error("Structure JSON inattendue :", stats);
    }
});
</script>
