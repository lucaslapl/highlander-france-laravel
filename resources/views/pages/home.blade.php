@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="home-layout">
<section class="home-main">

@include('partials.upcoming-matches')

<div id="questions">
    <ul>
        <li>Tu joues à Team Fortress 2 et tu parles français ?</li>
        <li>Tu t'ennuies sur le mode casual et tu as envie de plus de challenge ?</li>
        <li>Tu te demandes si tu es capable de jouer en compétitif ?</li>
    </ul>
    <p><b>Alors tu es au bon endroit, bienvenue sur Highlander France !</b></p>
</div>

<div id="about">
    <h2><b>Qui sommes-nous ?</b></h2>
    <p>Créée en Février 2026 à l'initiative de joueurs expérimentés au plus haut niveau et des joueurs membre de l'Équipe de France TF2, la communauté Highlander France a vu le jour avec l'objectif de <b>faire découvrir et de réunir</b> les joueurs et joueuses francophones pratiquant.es ou intéressé.es par le mode 9v9 et de leur offrir un lieu unique pour <b>échanger, apprendre, jouer ensemble.</b><br>
    Nous mettons un point d'honneur à faire de notre communauté un <b>lieu sûr pour tous.</b></p>
    <div class="vid-container">
        <div class="pres-video">
            <video autoplay muted loop playsinline>
                <source src="https://i.imgur.com/We4yrzC.mp4" type="video/mp4">
                Votre navigateur ne supporte pas la lecture de vidéos.
            </video>
            <p class="vid-desc">Matchs des équipes françaises streamés en direct sur Twitch!</p>
        </div>
    </div>

    <h2><b>Comment ça fonctionne ?</b></h2>
    <p>Fort de notre expérience <b>nous aidons les débutants et débutantes à appréhender le compétitif</b>, les règles, les tournois, les ligues, les méthodes pour progresser rapidement. Nous organisons régulièrement des matchs de tout niveau pour permettre à tout le monde de développer leur connaissance et mettre en action leur apprentissage ainsi que des demoreview (visionnage de match avec explications) et des maptalks (explications sur comment jouer les maps) et bien plus encore...</p>
</div>

<div id="numbers">
    <p>Highlander France, c'est :</p>
    <ul>
        <!--<li><b><span>+160</span></b> membres actifs</li>-->
        <li><b><span id="memberCount"><img class="stat-load" src="/_img/loading.gif" alt="Chargement..."></span></b> membres actifs</li>
        <li><b><span id="matchCount"><img class="stat-load" src="/_img/loading.gif" alt="Chargement..."></span></b> matchs organisés</li>
        <li><b><span>+</span><span id="hoursPlayed"><img class="stat-load" src="/_img/loading.gif" alt="Chargement..."></span></b> heures de matchs jouées au total</li>
    </ul>
</div>
<div id="join">
    <p>Alors qu'attends-tu pour nous rejoindre ? <b>Cela ne t'engage en rien</b>, tu es libre de participer ou simplement observer et lorsque tu te sens prêt, tu te lances et nous t'aiderons !</p>
    <a href="https://discord.gg/BMuj3cqUFt" class="join-btn">Rejoindre la communauté !</a>
</div>

</section>

<aside class="home-sidebar">
    @include('partials.community-news')
    @include('partials.latest-players')
</aside>

</div>

@push('scripts')
@include('partials.index-stats-script')
@include('partials.twitch-live-script')
@include('partials.twitch-header-script')
@endpush
@endsection
