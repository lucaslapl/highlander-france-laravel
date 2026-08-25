# Changelog — HLFR Live Match

## 1.1.0 (2026-08-10)

- **Stats de match par joueur** : kills, deaths, assists, dégâts (dmg) et soins
  (heal), comptabilisés en temps réel via les events TF2 (`player_death`,
  `player_hurt`, `player_healed`) et remis à zéro à l'armement du match
  (le warmup n'est pas compté). Chaque joueur du payload expose désormais
  `kills`, `deaths`, `assists`, `dmg`, `heal`.
- **Correctif ordre de chargement** : les convars partagées
  (`hlfr_webhook_token`, `hlfr_server_name`) sont désormais résolues dans
  `OnAllPluginsLoaded` (et re-résolues avant chaque envoi), pour ne plus
  dépendre de l'ordre alphabétique de chargement face à `hlfr_match_log`.
- **Cadence** : intervalle par défaut passé à 120 s (toutes les 2 minutes).
  Les envois immédiats (manche gagnée, connexion/déconnexion) sont conservés.

## 1.0.0 (2026-08-10)

Première version.

- Diffusion de l'état d'un match en direct au site (endpoint
  `POST /api/server/live-status`).
- Contenu du payload : serveur, map, statut (`live`/`ended`), `started_at` /
  `updated_at`, score RED-BLU (manches gagnées), joueurs RED/BLU (nom, équipe,
  classe, steamid, score individuel).
- Détection du match par `mp_tournament` (convar `hlfr_live_require_tournament`,
  défaut 1 ; 0 sur serveur 100 % match).
- Envois : armement du match, chaque manche gagnée, connexion/déconnexion d'un
  joueur, heartbeat `hlfr_live_interval` (30 s), `ended` à `game_over`.
- SourceTV : bloc `stv` avec lien `steam://connect` construit depuis
  `hostip` + `tv_port`, override manuel `hlfr_live_stv_url` (NAT), mot de passe
  optionnel (`hlfr_live_stv_include_password`).
- Réutilisation des convars partagées `hlfr_webhook_token` / `hlfr_server_name`
  (fournies par `hlfr_match_log`).
- Commandes admin : `sm_hlfr_live` (envoi manuel), `sm_hlfr_live_status`.
- Journalisation systématique dans les logs SourceMod (`[HLFR-Live]`).
- `.smx` fourni compilé avec SourceMod 1.12 (spcomp64).
