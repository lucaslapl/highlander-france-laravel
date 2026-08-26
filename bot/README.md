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

## 4. Déploiement production : PM2 (octave.highlanderfrance.tf)

Le bot tourne sous **PM2** et non sous Passenger : ce dernier met en veille les
apps sans trafic HTTP, ce qui faisait apparaître Octave hors ligne sur Discord
dès que personne ne visitait le domaine.

### Mise en place

1. **Plesk → Websites & Domains → Add Subdomain** : `octave.highlanderfrance.tf`
2. Uploader le contenu de `bot/` dans le dossier du sous-domaine
   (Git ou SFTP), **sans** `node_modules/` ni `.env`
3. **NPM install** (bouton Node.js dans l'UI, ou en SSH)
4. Variables d'environnement (fichier `.env`, cf. §2, ou custom environment
   variables Plesk) : `DISCORD_TOKEN`, `GUILD_ID`,
   `SITE_WEBHOOK_URL=https://highlanderfrance.tf/api/discord/member-count`,
   `SITE_WEBHOOK_TOKEN`, `SYNC_INTERVAL_MINUTES=360`,
   `MIN_PUSH_INTERVAL_SECONDS=60`
5. **Désactiver l'app Node.js Passenger** dans l'UI Plesk (sinon deux
   instances avec le même token Discord entrent en conflit de session)
6. Démarrer sous PM2 :
   - via SSH : `npm run pm2:start && pm2 save`
   - ou via l'UI Plesk (« Run script ») : `pm2:start` puis `pm2:save`
7. Vérifier : `[bot] Connecté en tant que ...` dans `pm2:logs`, puis le statut
   en ligne du bot sur Discord — il doit **y rester** sans visite du domaine

### Garder la page d'administration accessible

Passenger désactivé, le domaine ne répond plus par défaut. Pour conserver
https://octave.highlanderfrance.tf/ (page d'admin OAuth2), ajouter un reverse
proxy nginx dans **Apache & nginx Settings → Additional nginx directives** :

```nginx
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

### Persistance au reboot du serveur

`pm2 save` mémorise la liste des process, `pm2 resurrect` la restaure.
Deux options :

- Accès sudo ponctuel : `pm2 startup systemd -u <user> --hp <home>` génère un
  service qui lance automatiquement `pm2 resurrect` au boot ;
- Sinon, tâche planifiée Plesk de type **@reboot** exécutant
  `npm run pm2:resurrect` depuis le dossier du bot.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `Used disallowed intents` | SERVER MEMBERS INTENT non activée dans le portail |
| Push `HTTP 403` | `SITE_WEBHOOK_TOKEN` ≠ `DISCORD_WEBHOOK_TOKEN` côté site |
| Push `HTTP 403` avec log `guild_id inattendu` | `DISCORD_GUILD_ID` du site ne correspond pas |
| App hors ligne sur Discord | Process PM2 arrêté (`pm2 logs octave`, `npm run pm2:start`) |
| Page d'admin inaccessible | Reverse proxy nginx absent ou Passenger désactivé sans proxy (cf. §4) |
| Bot hors ligne après un reboot | `pm2 resurrect` non lancé au boot (cf. §4) |
