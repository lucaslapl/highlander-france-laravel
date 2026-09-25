# HLFR ETF2L Rename

Plugin SourceMod qui **renomme les joueurs avec leur vrai pseudo ETF2L** dès le
démarrage d'un match, puis restaure leurs noms Steam à la fin. Plus besoin de
deviner qui est « xXx_scout_fr » dans un match : tout le monde porte son nom
ETF2L officiel.

## Principe

1. La détection du match est **identique à `hlfr_live_match`** : armement sur
   `teamplay_round_start` / `teamplay_round_win` si `mp_tournament` est actif
   (même logique que `hlfr_match_log`), hors waiting-for-players, avec au
   moins `hlfr_etf2l_min_players` joueurs humains en équipe (16 = highlander,
   10 = 6v6). Sur un serveur 100 % match (TFTrue), mettre
   `hlfr_etf2l_require_tournament 0`.
2. À l'armement, le plugin envoie les **SteamIDs des joueurs connectés** en
   `POST JSON` au site (`POST /api/server/etf2l-names`). Le site résout les
   pseudos ETF2L (cache en base, API ETF2L en secours, rate-limit mutualisé)
   et renvoie le mapping `SteamID → pseudo ETF2L`.
3. Le plugin applique les noms via `SetClientInfo` (visible immédiatement par
   tous les clients, y compris la SourceTV).
4. **Late-joiners** : un joueur qui arrive en cours de match est renommé lui
   aussi (requête groupée, au plus une toutes les 30 s).
5. À `game_over` (ou changement de carte), les **noms Steam d'origine sont
   restaurés** (`hlfr_etf2l_restore 1`).

## Prérequis

- Serveur **SourceMod 1.10+** (compilé avec SourceMod 1.12).
- Extension **REST in Pawn (sm-ripext)** : <https://github.com/ErikMinekus/sm-ripext/releases>.
- **`hlfr_match_log` installé** : le token partagé (`hlfr_webhook_token`) et le
  nom de serveur (`hlfr_server_name`) sont des CVars créées par ce plugin, que
  `hlfr_etf2l_rename` lit.
- Côté site : l'endpoint `POST /api/server/etf2l-names` (token + IP allowlist,
  mêmes réglages que les autres webhooks).

## Installation

```
addons/sourcemod/scripting/hlfr_etf2l_rename.sp   ← source (compilation)
addons/sourcemod/plugins/hlfr_etf2l_rename.smx    ← binaire (fourni, compilé)
cfg/sourcemod/hlfr_etf2l_rename.cfg               ← configuration
```

1. Copier `hlfr_etf2l_rename.cfg` dans `cfg/sourcemod/` et renseigner les CVars.
2. Vérifier que `hlfr_match_log` fournit bien `hlfr_webhook_token` et
   `hlfr_server_name` (mêmes valeurs que pour le webhook de fin de match).
3. Placer le `.smx` dans `addons/sourcemod/plugins/`.
4. Recharger : `sm plugins reload hlfr_etf2l_rename` (ou restart).
5. **Serveur 100 % match (TFTrue)** : mettre `hlfr_etf2l_require_tournament 0`.

## CVars

| Convar | Défaut | Description |
|---|---|---|
| `hlfr_etf2l_enable` | `1` | Active/désactive le renommage |
| `hlfr_etf2l_url` | URL prod | Endpoint de résolution des pseudos du site |
| `hlfr_etf2l_require_tournament` | `1` | Exige `mp_tournament` (mettre `0` sur serveur 100 % match) |
| `hlfr_etf2l_min_players` | `16` | Seuil de joueurs humains en équipe pour armer (16 = HL, 10 = 6v6) |
| `hlfr_etf2l_enforce` | `1` | Re-force le pseudo ETF2L si le joueur se renomme pendant le match (0 = renommage unique) |
| `hlfr_etf2l_restore` | `1` | Restaure les noms Steam à la fin du match (0 = garde le pseudo ETF2L) |
| `hlfr_etf2l_debug` | `0` | Logs de debug dans la console |

Partagées (créées par `hlfr_match_log`) : `hlfr_webhook_token`,
`hlfr_server_name`.

## Dépannage

- `sm plugins list` / `sm exts list` : le plugin et **REST in Pawn** doivent
  être chargés.
- `sm_hlfr_etf2l_status` : état du plugin (`enable`, `require_tournament`,
  `enforce`, `restore`, `live`, `humains`, `resolus`, `renommes`, `pending`,
  délai depuis la dernière requête).
- `sm_hlfr_etf2l` (admin) : arme le renommage manuellement et demande les
  pseudos au site (test sans attendre un match).
- `sm_hlfr_etf2l_restore` (admin) : restaure immédiatement les noms Steam.
- Messages de la console serveur :
  - `[HLFR-Rename] Match armé : résolution des pseudos ETF2L.` → la détection
    du match fonctionne.
  - `[HLFR-Rename] Pseudos reçus : N, appliqués : M.` → le site a répondu ; un
    `M < N` signifie que certains joueurs n'ont pas de compte ETF2L (mercs) ou
    pas de pseudo valide — ils gardent leur nom d'origine.
  - `[HLFR-Rename] Site injoignable (HTTP 0).` / `Refusé (HTTP 403).` /
    `(HTTP 404).` → vérifier l'URL, le token et les IP autorisées du site.
  - `[HLFR-Rename] Convar hlfr_webhook_token introuvable ...`
    → `hlfr_match_log` n'est pas chargé.
- Chaque envoi et son statut HTTP sont journalisés dans
  `addons/sourcemod/logs/` (préfixe `[HLFR-Rename]`).

## Robustesse

- **Une seule requête en vol** : les demandes pendant une résolution en cours
  sont ignorées (le joueur sera couvert par la prochaine).
- **Throttle late-join** : au plus une requête de rattrapage toutes les 30 s
  quand de nouveaux joueurs arrivent.
- **Boucles évitées** : `ApplyName` ne fait rien si le nom est déjà conforme,
  donc la ré-application via `OnClientSettingsChanged` (`hlfr_etf2l_enforce 1`)
  ne boucle pas.
- **Fin de match** : restauration à `game_over`, au changement de carte et au
  déchargement du plugin (`OnPluginEnd`), même sans `game_over`.
- **Joueurs sans compte ETF2L** : absents de la réponse du site (cache
  négatif de 12 h côté site), ils gardent leur nom Steam.
- **Pseudos assainis côté site** : caractères de contrôle supprimés, taille
  plafonnée à 32 octets, espaces compressés — aucun débordement de buffer ni
  pseudo multi-lignes possible.
- **Changement de carte** : timers avec `TIMER_FLAG_NO_MAPCHANGE`, état
  réinitialisé à `OnMapStart`.
