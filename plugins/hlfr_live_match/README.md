# HLFR Live Match

Plugin SourceMod qui envoie l'état d'un **match en cours** (map, serveur,
joueurs, score, lien SourceTV) au site Highlander France. Le site affiche alors
un widget « En direct » sur la page d'accueil et une page de détail avec les
joueurs présents et le score.

C'est le **compagnon** de `hlfr_match_log` : celui-ci notifie la *fin* de match,
celui-ci diffuse l'état *pendant* le match. Les deux partagent le token et le
nom de serveur.

## Principe

1. Un match est considéré « en direct » dès qu'une manche démarre/se termine
   alors que `mp_tournament` est actif (même logique que `hlfr_match_log`).
   Sur un serveur 100 % match (TFTrue), mettre `hlfr_live_require_tournament 0`
   diffuse sur **chaque** manche.
2. Pendant le match, le plugin envoie un **POST JSON** au site :
   - immédiatement à l'armement du match et à chaque manche gagnée ;
   - sur connexion/déconnexion d'un joueur ;
   - puis régulièrement (`hlfr_live_interval`, heartbeat 120 s par défaut,
     soit toutes les 2 minutes).
3. À `game_over`, un statut `ended` est envoyé pour retirer le match du site
   (si le serveur crash en plein match, une expiration côté site nettoie
   l'affichage après ~2 min).

## Payload

```json
{
  "token": "…",
  "server": "comp01",
  "map": "koth_product_final",
  "status": "live",
  "started_at": 1754370000,
  "updated_at": 1754370120,
  "scores": { "red": 2, "blue": 1 },
  "players": [
    { "name": "Pseudo", "team": "red", "class": "scout", "steamid": "STEAM_1:0:…", "score": 42, "kills": 12, "deaths": 5, "assists": 4, "dmg": 15230, "heal": 890 }
  ],
  "stv": { "connect": "steam://connect/185.xxx.x.x:27020", "ip": "185.xxx.x.x", "port": 27020 }
}
```

- `players` : uniquement les joueurs **RED/BLU** en jeu (hors spectateurs, bots
  et SourceTV). `class` est une clé correspondant aux icônes `_img/classes/`.
- `players[].kills/deaths/assists/dmg/heal` : stats comptabilisées par le plugin
  pendant le match (events `player_death`, `player_hurt`, `player_healed`),
  remises à zéro à l'armement du match. `dmg` exclut le self-damage ;
  `heal` est le soin effectué (beam du Medic).
- `stv` : présent seulement si la SourceTV est active (`tv_enable` ou client
  SourceTV détecté). Derrière un NAT, l'IP auto (`hostip`) est l'IP interne :
  renseigner `hlfr_live_stv_url` pour forcer l'URL publique.

## Prérequis

- Serveur **SourceMod 1.10+** (compilé avec SourceMod 1.12).
- Extension **REST in Pawn (sm-ripext)** : <https://github.com/ErikMinekus/sm-ripext/releases>.
- **`hlfr_match_log` installé** : le token partagé (`hlfr_webhook_token`) et le
  nom de serveur (`hlfr_server_name`) sont des CVars créées par ce plugin, que
  `hlfr_live_match` lit.

## Installation

```
addons/sourcemod/scripting/hlfr_live_match.sp   ← source (compilation)
addons/sourcemod/plugins/hlfr_live_match.smx    ← binaire (fourni, compilé)
cfg/sourcemod/hlfr_live_match.cfg               ← configuration
```

1. Copier `hlfr_live_match.cfg` dans `cfg/sourcemod/` et renseigner les CVars.
2. Vérifier que `hlfr_match_log` fournit bien `hlfr_webhook_token` et
   `hlfr_server_name` (mêmes valeurs que pour le webhook de fin de match).
3. Placer le `.smx` dans `addons/sourcemod/plugins/`.
4. Recharger : `sm plugins reload hlfr_live_match` (ou restart).
5. **Serveur 100 % match (TFTrue)** : mettre `hlfr_live_require_tournament 0`.
6. **Serveur NATé** : renseigner `hlfr_live_stv_url` (sinon le lien SourceTV
   pointera vers l'IP interne, inutilisable).

## CVars

| Convar | Défaut | Description |
|---|---|---|
| `hlfr_live_enable` | `1` | Active/désactive l'envoi du statut |
| `hlfr_live_url` | — | URL de l'endpoint live du site |
| `hlfr_live_interval` | `120.0` | Intervalle (s) du heartbeat pendant un match (2 min) |
| `hlfr_live_debug` | `0` | Logs de debug dans la console |
| `hlfr_live_require_tournament` | `1` | Exige `mp_tournament` (mettre `0` sur serveur 100 % match) |
| `hlfr_live_stv_url` | vide | URL SourceTV manuelle (override, NAT) |
| `hlfr_live_stv_include_password` | `0` | Envoyer `tv_password` au site pour les spectateurs |

Partagées (créées par `hlfr_match_log`) : `hlfr_webhook_token`,
`hlfr_server_name`.

## Dépannage

- `sm plugins list` / `sm exts list` : le plugin et **REST in Pawn** doivent être
  chargés.
- `sm_hlfr_live_status` : affiche l'état en direct (`live`, `score`, `players`,
  `pending`, `interval`).
- `sm_hlfr_live` (admin) : renvoie immédiatement le statut actuel au site
  (test sans attendre un match).
- Chaque envoi et son statut HTTP sont journalisés dans
  `addons/sourcemod/logs/` (préfixe `[HLFR-Live]`).
- Messages de la console serveur :
  - `[HLFR-Live] Match armé.` → la détection du match fonctionne.
  - `[HLFR-Live] Statut 'live' accepté (HTTP 200).` → le site a bien reçu l'état.
  - `[HLFR-Live] Site injoignable (HTTP 0).` / `Refusé (HTTP 403).` / `(HTTP 404).`
    → vérifier l'URL, le token et les IP autorisées du site.
  - `[HLFR-Live] Convar hlfr_webhook_token introuvable ...`
    → `hlfr_match_log` n'est pas chargé (l'ordre de chargement n'est plus un
    problème depuis 1.1.0 : les convars partagées sont résolues dans
    `OnAllPluginsLoaded`).

## Robustesse

- **Empilement** : une seule requête HTTP en vol ; si l'état change, le heartbeat
  suivant rattrape. Un `ended` est reporté jusqu'à la fin de la requête en vol
  pour garantir l'ordre.
- **Expiration** : si le serveur crash en plein match (pas de `game_over`), le
  site ignore l'entrée après ~2 min (TTL sur `updated_at`).
- **Changement de carte** : les timers utilisent `TIMER_FLAG_NO_MAPCHANGE` et
  l'état est réinitialisé à `OnMapStart` (un `ended` est envoyé si un match
  était en cours).
- **Score** : manches gagnées via `teamplay_round_win` (2 = RED, 3 = BLU).
  Sur les maps stopwatch (`pl_`/`plr_`), ce compteur reflète les moitiés de
  manche, pas le temps.
