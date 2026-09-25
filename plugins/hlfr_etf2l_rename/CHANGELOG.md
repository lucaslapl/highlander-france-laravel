# Changelog — HLFR ETF2L Rename

## 1.0.0 (2026-09-24)

Première version.

- Renommage des joueurs avec leur vrai pseudo ETF2L à l'armement du match
  (détection identique à `hlfr_live_match` : `mp_tournament` + hors
  waiting-for-players + seuil `hlfr_etf2l_min_players`).
- Résolution des pseudos via le site (`POST /api/server/etf2l-names`, token
  partagé + IP allowlist) : cache en base + API ETF2L en secours, rate-limit
  mutualisé côté site.
- Application via `SetClientInfo` (visible par tous les clients et la
  SourceTV), SteamIDs collectés via `OnClientPostAdminCheck`.
- Late-joiners renommés (requête groupée, throttle 30 s).
- `hlfr_etf2l_enforce` : re-force le pseudo ETF2L si le joueur se renomme
  manuellement pendant le match (défaut 1).
- `hlfr_etf2l_restore` : restauration des noms Steam à `game_over`, au
  changement de carte et au déchargement du plugin (défaut 1).
- Réutilisation des convars partagées `hlfr_webhook_token` / `hlfr_server_name`
  (fournies par `hlfr_match_log`).
- Commandes admin : `sm_hlfr_etf2l` (armement manuel de test),
  `sm_hlfr_etf2l_restore`, `sm_hlfr_etf2l_status`.
- Journalisation systématique dans les logs SourceMod (`[HLFR-Rename]`).
- `.smx` fourni compilé avec SourceMod 1.12 (spcomp64).
