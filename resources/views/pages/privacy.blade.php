@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<h2>Politique de Confidentialité</h2>
<p><em>Dernière mise à jour : 4 août 2026</em></p>
<h3>1. Introduction</h3>
<p>Chez Highlander France, nous nous engageons à protéger votre vie privée. Cette politique de confidentialité explique comment nous collectons, utilisons et protégeons vos informations personnelles lorsque vous utilisez notre site web.</p>
<h3>2. Responsable de traitement et hébergeur</h3>
<p>Les responsables du traitement des données sont <b>Félix et Shamp00</b>, de la plateforme reconnexion.tf. Le site est hébergé chez <b>Pulseheberg</b>. Pour toute question relative à vos données personnelles, vous pouvez nous contacter à : <a style="color: #007bff; text-decoration: underline;" href="mailto:contact@reconnexion.tf">contact@reconnexion.tf</a></p>
<h3>3. Données collectées</h3>
<p>Le site ne comporte pas de compte à proprement parler : la connexion s'effectue via votre identifiant Steam et la majorité des données affichées proviennent de logs.tf. Les données que nous traitons sont les suivantes :</p>
<ul>
    <li><b>Lors de la connexion via Steam (OpenID) :</b> votre SteamID64 (identifiant unique public), votre nom d'utilisateur Steam et votre avatar Steam, récupérés via l'API Steam. Nous ne collectons aucune autre information personnelle. Nous n'avons pas accès à votre adresse e-mail, à votre mot de passe Steam, à vos données de jeu ou à votre historique de jeu sur Steam.</li>
    <li><b>Données que vous fournissez volontairement :</b> un nom d'affichage (modifiable une seule fois) et un pays, visibles sur votre profil public.</li>
    <li><b>Données issues de logs.tf :</b> lorsque des logs de matchs « Highlander France » sont publiés sur logs.tf, nous en récupérons les statistiques de jeu (dégâts, kills, morts, assists, classes jouées, etc.). Ces statistiques sont associées au SteamID des joueurs présents dans ces logs, <b>y compris pour les joueurs qui ne se sont jamais connectés au site</b>.</li>
    <li><b>Données techniques :</b> un cookie de session (<b>HLFR_SESSION</b>, durée 30 jours) est déposé pour maintenir votre connexion. Votre adresse IP peut être enregistrée dans les journaux d'audit internes lors de certaines actions d'administration.</li>
</ul>
<h3>4. Utilisation des données</h3>
<p>Les données que nous collectons sont utilisées uniquement pour :</p>
<ul>
    <li>Affichage de votre profil public sur le site (pseudo, avatar, pays, date d'inscription).</li>
    <li>Classement des joueurs sur le leaderboard (Hall of Fame).</li>
    <li>Statistiques de jeu liées à votre SteamID grâce aux données de l'API logs.tf.</li>
    <li>Affichage public de l'équipe (staff) : pseudo, avatar, pays et rôle des membres.</li>
</ul>
<h3>5. Cookies</h3>
<p><b>Cookie de session :</b> le site utilise un cookie de session (<b>HLFR_SESSION</b>) strictement nécessaire au bon fonctionnement de la connexion. Il est déposé uniquement lorsque vous vous connectez via Steam.</p>
<p><b>Google Analytics :</b> nous utilisons Google Analytics pour comprendre la manière dont les visiteurs utilisent le site et améliorer l'expérience utilisateur. Google Analytics utilise des cookies pour suivre les interactions des utilisateurs avec le site, et les données collectées peuvent être transmises et traitées par Google, y compris en dehors de l'Union européenne. Vous pouvez désactiver les cookies de Google Analytics en installant le <a href="https://tools.google.com/dlpage/gaoptout" style="color: #007bff; text-decoration: underline;">module complémentaire de navigateur pour la désactivation de Google Analytics</a>.</p>
<h3>6. Services tiers</h3>
<p>Le site s'appuie sur les services tiers suivants, qui peuvent traiter certaines données (notamment votre adresse IP) selon leurs propres politiques de confidentialité :</p>
<ul>
    <li><b>Steam</b> (Valve) : authentification OpenID et récupération du profil (pseudo, avatar).</li>
    <li><b>logs.tf</b> : données de statistiques de matchs.</li>
    <li><b>ETF2L</b> : agenda des prochains matchs des équipes françaises.</li>
    <li><b>Font Awesome</b>, <b>jQuery</b> (cdnjs), <b>Chart.js</b> (jsdelivr) : bibliothèques et icônes chargées depuis des CDN.</li>
    <li><b>Discord</b> et <b>imgur</b> : liens externes et contenus embarqués.</li>
</ul>
<h3>7. Stockage, sécurité et conservation</h3>
<p>Nous prenons la sécurité de vos données au sérieux. Les informations que nous collectons sont stockées sur des serveurs sécurisés et ne sont accessibles qu'à un nombre limité de personnes ayant des droits d'accès spéciaux à ces systèmes. Nous ne partageons pas vos données avec des tiers, sauf si cela est nécessaire pour se conformer à la loi ou pour protéger nos droits.</p>
<p>Le cookie de session est conservé 30 jours. Les données de statistiques sont conservées tant qu'elles sont nécessaires au fonctionnement du site, et nous ne conservons aucune donnée vous concernant au-delà de ce qui est nécessaire.</p>
<h3>8. Vos droits</h3>
<p>Conformément au RGPD, vous disposez de droits sur vos données personnelles : droit d'accès, de rectification, d'effacement, de limitation du traitement, de portabilité, d'opposition et de retrait du consentement. Le site ne comportant pas de compte au sens classique du terme, toutes les demandes peuvent être effectuées par e-mail. Pour exercer ces droits, veuillez nous contacter à l'adresse suivante : <a style="color: #007bff; text-decoration: underline;" href="mailto:contact@reconnexion.tf">contact@reconnexion.tf</a>. Si vous estimez que vos droits ne sont pas respectés, vous pouvez également introduire une réclamation auprès de la CNIL.</p>
@endsection
