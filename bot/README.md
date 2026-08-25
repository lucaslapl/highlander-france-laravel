# Octave — Bot Discord Highlander France

Bot Node.js (discord.js v14) qui pousse le nombre de membres du serveur Discord
vers le site (`/api/discord/member-count`) à chaque arrivée/départ, plus une
sync de sécurité au démarrage et toutes les 6 h.

## Structure

```
src/
├── index.js              # Point d'entrée : bootstrap incassable + events + login
├── config.js             # Lecture/validation des variables d'environnement
├── events/               # Un fichier = un event (chargement automatique)
│   ├── ready.js          # Sync initiale + sync périodique
│   ├── guildMemberAdd.js
│   └── guildMemberRemove.js
├── services/
│   └── siteSync.js       # Push vers le site + debounce anti-raid + état /health
└── web/
    ├── server.js         # Routeur HTTP (/, /login, /callback, /admin/sync, /health)
    ├── auth.js           # OAuth2 Discord, sessions, vérification des rôles
    └── views.js          # Templates HTML de la page d'administration
tools/
└── check.js              # Diagnostic : npm run check (ou bouton Run script Plesk)
```

Ajouter une feature = ajouter un fichier dans `events/` (ou un dossier
`commands/` le moment venu) — rien d'autre à modifier.

## Page d'administration

Le bot sert sa propre page d'admin sur https://octave.highlanderfrance.tf/ :
monitoring (statut, compteur, dernier push) + bouton « Forcer une
synchronisation ». Accès réservé aux administrateurs du serveur Discord via
OAuth2 : l'utilisateur doit porter un des rôles listés dans
`DISCORD_ADMIN_ROLE_IDS`, ou être propriétaire de la guilde si la liste est
vide. La vérification se fait côté serveur via l'API Discord avec le token du
bot — elle ne peut pas être falsifiée depuis le navigateur.

Configuration :

1. Portail Discord → **OAuth2** → copier **Client ID** et **Client Secret**
2. Ajouter le **Redirect URI** : `https://octave.highlanderfrance.tf/callback`
3. Variables d'environnement (Plesk ou `.env`) :
   - `DISCORD_OAUTH_CLIENT_ID`
   - `DISCORD_OAUTH_CLIENT_SECRET`
   - `OAUTH_REDIRECT_URI=https://octave.highlanderfrance.tf/callback`
   - `DISCORD_ADMIN_ROLE_IDS=123...,456...` (clic droit sur un rôle → Copier
     l'identifiant ; vide = propriétaire uniquement)

Notes : les sessions sont en mémoire (un redémarrage du bot déconnecte les
admins) ; `/health` reste public ; les variables OAuth peuvent être ajoutées à
chaud, sans toucher au reste.

## 1. Création sur le portail Discord

1. https://discord.com/developers/applications → **New Application** → nommer (ex. Octave)
2. Onglet **Bot** :
   - **Reset Token** → copier le token (= `DISCORD_TOKEN`)
   - **Privileged Gateway Intents** → activer **SERVER MEMBERS INTENT**
     (obligatoire pour `guildMemberAdd`/`guildMemberRemove`)
3. Onglet **OAuth2 → URL Generator** :
   - Scopes : `bot`
   - Bot permissions : aucune permission spéciale n'est nécessaire
   - Ouvrir l'URL générée et inviter le bot sur le serveur

Récupérer l'ID du serveur : paramètres Discord → Avancés → Mode développeur,
puis clic droit sur l'icône du serveur → **Copier l'identifiant** (= `GUILD_ID`).

## 2. Configuration

```bash
cp .env.example .env
# Renseigner DISCORD_TOKEN, GUILD_ID, SITE_WEBHOOK_TOKEN
```

`SITE_WEBHOOK_TOKEN` doit être identique à `DISCORD_WEBHOOK_TOKEN` du
`config/.env` du site. Générer un token : `openssl rand -hex 32`.

Côté site, renseigner aussi optionnellement `DISCORD_GUILD_ID` dans
`config/.env` : le webhook refusera alors tout push d'une autre guild.

## 3. Lancement local

```bash
npm install
npm start
```

Vérifications :

- Console : `[bot] Connecté en tant que ...` puis `Compteur poussé (startup)`
- http://localhost:3000/health → statut + dernier push
- Joindre/quitter le serveur avec un compte test → push `join`/`leave`
  (débouncé : au plus un push par minute)

## 4. Déploiement Plesk (octave.highlanderfrance.tf)

1. **Plesk → Websites & Domains → Add Subdomain** : `octave.highlanderfrance.tf`
2. Uploader le contenu de `bot/` dans le dossier du sous-domaine
   (Git ou SFTP), **sans** `node_modules/` ni `.env`
3. **Node.js** (icône sur le domaine) :
   - Node.js version : la plus récente LTS disponible (>= 18)
   - Application Root : racine du sous-domaine
   - Application Startup File : `src/index.js`
   - **NPM install** (bouton dans l'UI)
   - **Custom environment variables** : `DISCORD_TOKEN`, `GUILD_ID`,
     `SITE_WEBHOOK_URL=https://highlanderfrance.tf/api/discord/member-count`,
     `SITE_WEBHOOK_TOKEN`, `SYNC_INTERVAL_MINUTES=360`,
     `MIN_PUSH_INTERVAL_SECONDS=60`
     (ne pas définir `PORT` : Passenger l'impose)
   - **Restart App**
4. Vérifier https://octave.highlanderfrance.tf/health
5. Optionnel mais recommandé : **Scheduled Tasks** (Plesk) → tâche toutes les
   5 min pour maintenir l'app chaude (Passenger recycle les apps sans trafic) :

   ```
   curl -s https://octave.highlanderfrance.tf/health > /dev/null
   ```

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `Used disallowed intents` | SERVER MEMBERS INTENT non activée dans le portail |
| Push `HTTP 403` | `SITE_WEBHOOK_TOKEN` ≠ `DISCORD_WEBHOOK_TOKEN` côté site |
| Push `HTTP 403` avec log `guild_id inattendu` | `DISCORD_GUILD_ID` du site ne correspond pas |
| App redémarre souvent | Ping cron `/health` manquant (Passenger recycle) |
