# HLFR Match Log Webhook

Plugin SourceMod qui notifie le site Highlander France quand un **match se
termine** sur un serveur de match TF2 (TFTrue), afin que le site mette à jour
immédiatement les stats joueurs et le leaderboard — sans attendre le CRON.

## Principe

1. TF2 émet `teamplay_game_over` / `tf_game_over` quand les conditions de
   victoire sont atteintes (= match terminé). Par défaut le plugin ne réagit que
   si un match est en cours (convar `mp_tournament` actif), donc pas de faux
   déclenchement en partie publique ou lors d'un simple changement de carte.
   Sur un serveur 100 % match (TFTrue), mettre `hlfr_require_tournament 0`
   déclenche sur **chaque** `game_over`.
2. Après un délai configurable (30 s par défaut), le plugin envoie un **webhook
   POST JSON** à l'endpoint du site. Ce délai laisse TFTrue terminer l'upload
   du log sur logs.tf.
3. Le site authentifie la requête (token partagé), puis exécute la chaîne de
   mise à jour des stats et du leaderboard.

## Prérequis

- Serveur **SourceMod 1.10+**.
- Extension **REST in Pawn (sm-ripext)** : <https://github.com/ErikMinekus/sm-ripext/releases>
  (à déposer dans `addons/sourcemod/extensions/`).
- **TFTrue** doit déjà uploader les logs sur logs.tf :
  `tftrue_logs_apikey "<cle logs.tf>"` (et éventuellement
  `tftrue_logs_name_prefix "Highlander France"` pour que le site retrouve le
  log via ses requêtes par titre).

## Installation

Sur chaque serveur de match :

```
addons/sourcemod/scripting/hlfr_match_log.sp   ← source (compilation)
addons/sourcemod/plugins/hlfr_match_log.smx    ← binaire (ou .sp compilé)
cfg/sourcemod/hlfr_match_log.cfg               ← configuration
```

1. Copier `hlfr_match_log.cfg` dans `cfg/sourcemod/`.
2. Renseigner les CVars :
   - `hlfr_webhook_url` → URL du site (endpoint webhook).
   - `hlfr_webhook_token` → secret partagé, identique à
     `SERVER_WEBHOOK_TOKEN` dans `config/.env` du site.
   - `hlfr_server_name` → identifiant du serveur (pour les logs du site).
3. Placer le `.smx` dans `addons/sourcemod/plugins/`. Le binaire fourni est
   compilé depuis le `.sp` courant (v1.3.0, SourceMod 1.12). Recompiler
   uniquement si vous modifiez le source : `spcomp hlfr_match_log.sp`.
4. Recharger : `sm plugins reload hlfr_match_log` (ou restart).
5. **Serveur 100 % match (TFTrue)** : mettre `hlfr_require_tournament 0` pour
   déclencher sur chaque `game_over`, sans dépendre de `mp_tournament`.

## CVars

| Convar | Défaut | Description |
|---|---|---|
| `hlfr_enable` | `1` | Active/désactive le webhook |
| `hlfr_webhook_url` | — | URL de l'endpoint webhook du site |
| `hlfr_webhook_token` | — | Token partagé (secret, `FCVAR_PROTECTED`) |
| `hlfr_server_name` | vide | Nom du serveur envoyé au site (vide = `hostname`) |
| `hlfr_delay` | `30.0` | Délai (s) après la fin du match avant l'envoi |
| `hlfr_max_retries` | `3` | Nouvelles tentatives si le webhook échoue |
| `hlfr_debug` | `0` | Logs de debug dans la console |
| `hlfr_require_tournament` | `1` | Exige `mp_tournament` pour déclencher (mettre `0` sur serveur 100 % match) |

## Dépannage

- `sm plugins list` : le plugin doit être **chargé** sur le serveur concerné.
  Vérifiez bien le serveur qui joue les matchs (le plugin peut manquer ou être
  mal configuré sur l'un d'entre eux).
- `sm exts list` : **REST in Pawn** doit être chargé (sinon le plugin refuse de
  se charger).
- `sm_hlfr_status` : affiche l'état en direct (`enable`, `require_tournament`,
  `mp_tournament`, `in_match`, `pending`, `retries`, `map`).
- Depuis la 1.3.0, **tout** est journalisé : un `game_over` ignoré est tracé
  avec sa raison dans `addons/sourcemod/logs/`, et chaque tentative d'envoi +
  statut HTTP est écrit dans le journal **et** la console. S'il ne se passe
  toujours rien, c'est que le plugin n'est pas chargé sur ce serveur.
- Les échecs webhook (HTTP 0/403/5xx…) apparaissent dans les **logs SourceMod**
  (`addons/sourcemod/logs/L*.log`) depuis la 1.3.0 (avant, seule la console les
  affichait).

## Tests

- `sm_hlfr_sync` (admin) : déclenche un webhook **immédiatement**, sans attendre
  la fin d'un match (la détection automatique n'est pas nécessaire pour tester).
- `sm_hlfr_status` : vérifier l'état avant de tester.
- Au premier `game_over` d'un match réel, le plugin affiche désormais
  `[HLFR] Fin de match détectée (map ...). Webhook dans N s.` : si cette ligne
  n'apparaît pas, le plugin n'est pas chargé ou le `game_over` a été ignoré
  (raison tracée dans le journal SourceMod).
- Messages dans la console serveur :
  - `[HLFR] Webhook de fin de match accepté (HTTP 200) - 1 nouveau log traité.`
    → tout fonctionne ; le log du match a bien été trouvé et traité par le site
    (le nombre vient de la réponse du site).
  - `[HLFR] Webhook de fin de match accepté (HTTP 200) - 0 nouveaux logs traités.`
    → webhook OK mais le log n'a pas encore été trouvé sur logs.tf : le titre du
    log ne contient pas « Highlander France » / « highlanderfrance.tf »
    (vérifier `tftrue_logs_name_prefix`), ou l'upload TFTrue est en retard.
  - `[HLFR] Webhook impossible : serveur injoignable ou erreur TLS (HTTP 0).`
    → le site est inaccessible depuis le serveur de jeu (URL, DNS, firewall).
  - `[HLFR] Webhook refusé (HTTP 403) : token incorrect ou IP non autorisée.`
  - `[HLFR] Webhook refusé (HTTP 404) : mauvaise URL hlfr_webhook_url.`
  - `[HLFR] Webhook refusé (HTTP 500...)` → erreur côté site (voir
    `_scripts/cron_debug.log`).

## Robustesse

- **Race condition logs.tf** : `hlfr_delay` couvre le temps d'upload de TFTrue ;
  en cas d'échec HTTP le plugin réessaie jusqu'à `hlfr_max_retries` fois.
- **Doublons** : un seul webhook pend à la fois (`g_WebhookPending`), et le
  plugin se désarme dès le premier `game_over`.
- **Anti-blocage** : un watchdog réarme automatiquement `g_WebhookPending` si
  la callback HTTP n'a jamais été rappelée (pire cas + marge). Un verrou ne
  peut plus bloquer silencieusement les matchs suivants.
- **Changement de carte** : les timers utilisent `TIMER_FLAG_NO_MAPCHANGE`, le
  webhook part même si la carte change avant la fin du délai.
- **Observabilité** : depuis la 1.3.0, aucun chemin n'est silencieux — tout est
  écrit dans le journal SourceMod (et la console).
