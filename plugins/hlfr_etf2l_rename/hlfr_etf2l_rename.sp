#pragma semicolon 1
#pragma newdecls required

#include <sourcemod>
#include <sdktools>
#include <tf2>
#include <ripext>

#define PLUGIN_VERSION "1.0.0"

#define NAME_BUF 64 // Les pseudos du site sont plafonnés à 32 octets

public Plugin myinfo =
{
	name        = "HLFR ETF2L Rename",
	author      = "Highlander France",
	description = "Renomme les joueurs avec leur vrai pseudo ETF2L au démarrage d'un match, et restaure leurs noms Steam à la fin.",
	version     = PLUGIN_VERSION,
	url         = "https://highlanderfrance.tf"
};

// --- Options du plugin ---
ConVar g_hEnabled;
ConVar g_hUrl;
ConVar g_hRequireTournament;
ConVar g_hMinPlayers;
ConVar g_hEnforce;
ConVar g_hRestore;
ConVar g_hDebug;

// --- Convars du serveur / partagées avec hlfr_match_log ---
ConVar g_hMPTournament;
ConVar g_hHostname;
ConVar g_hToken;      // hlfr_webhook_token (créée par hlfr_match_log)
ConVar g_hServerName; // hlfr_server_name (créée par hlfr_match_log)

// --- État du match ---
bool g_MatchLive;  // un match de compétition est en cours

// --- État par joueur (indices = clients 1..MaxClients) ---
char g_SteamId[MAXPLAYERS + 1][32];       // SteamID2 du joueur (authentifié)
char g_Etf2lName[MAXPLAYERS + 1][NAME_BUF]; // pseudo ETF2L résolu (vide = inconnu)
char g_OriginalName[MAXPLAYERS + 1][NAME_BUF]; // nom Steam avant le premier rename
bool g_Renamed[MAXPLAYERS + 1];           // le joueur a été renommé par le plugin

// --- Requêtes HTTP ---
bool g_HttpPending;   // une requête est en vol (une seule à la fois)
int  g_LastRequestAt; // timestamp de la dernière requête (throttle des late-join)

#define LATE_REQUEST_THROTTLE 30 // secondes entre deux requêtes late-join

public void OnPluginStart()
{
	CreateConVar("hlfr_etf2l_version", PLUGIN_VERSION, "Version du plugin HLFR ETF2L Rename", FCVAR_NOTIFY);

	g_hEnabled            = CreateConVar("hlfr_etf2l_enable", "1", "Active/désactive le renommage ETF2L.", _, true, 0.0, true, 1.0);
	g_hUrl                = CreateConVar("hlfr_etf2l_url", "https://highlanderfrance.tf/api/server/etf2l-names", "URL de l'endpoint de résolution des pseudos du site Highlander France.");
	g_hRequireTournament  = CreateConVar("hlfr_etf2l_require_tournament", "1", "Exige mp_tournament pour armer le renommage. Mettez 0 sur un serveur 100% match (TFTrue).", _, true, 0.0, true, 1.0);
	g_hMinPlayers         = CreateConVar("hlfr_etf2l_min_players", "16", "Nombre minimum de joueurs humains en équipe pour armer le renommage (16 = highlander, 10 = 6v6).", _, true, 0.0, true, 64.0);
	g_hEnforce            = CreateConVar("hlfr_etf2l_enforce", "1", "Re-forcer le pseudo ETF2L si le joueur se renomme manuellement pendant le match (0 = renommage unique au démarrage).", _, true, 0.0, true, 1.0);
	g_hRestore            = CreateConVar("hlfr_etf2l_restore", "1", "Restaurer les noms Steam d'origine à la fin du match (0 = les joueurs gardent leur pseudo ETF2L jusqu'à la déconnexion).", _, true, 0.0, true, 1.0);
	g_hDebug              = CreateConVar("hlfr_etf2l_debug", "0", "Logs de debug supplémentaires dans la console du serveur.", _, true, 0.0, true, 1.0);

	AutoExecConfig(true, "hlfr_etf2l_rename");

	g_hMPTournament = FindConVar("mp_tournament");
	g_hHostname     = FindConVar("hostname");

	HookEvent("teamplay_round_start", Event_RoundStart);
	HookEvent("teamplay_round_win",   Event_RoundWin);
	HookEvent("teamplay_game_over",   Event_GameOver);
	HookEvent("tf_game_over",         Event_GameOver);

	RegAdminCmd("sm_hlfr_etf2l",         Command_ManualRename, ADMFLAG_GENERIC, "Arme le renommage manuellement et demande les pseudos ETF2L au site (test).");
	RegAdminCmd("sm_hlfr_etf2l_restore", Command_ManualRestore, ADMFLAG_GENERIC, "Restaure immédiatement les noms Steam d'origine (test).");
	RegAdminCmd("sm_hlfr_etf2l_status",  Command_Status, ADMFLAG_GENERIC, "Affiche l'état du plugin (dépannage).");

	LogMessage("[HLFR-Rename] Version %s chargée (require_tournament=%d, enforce=%d, restore=%d).",
		PLUGIN_VERSION, GetConVarBool(g_hRequireTournament), GetConVarBool(g_hEnforce), GetConVarBool(g_hRestore));
	PrintToServer("[HLFR-Rename] Version %s chargée.", PLUGIN_VERSION);
}

public void OnAllPluginsLoaded()
{
	// Convars créées par hlfr_match_log : résolues ici, après le chargement de
	// tous les plugins, pour ne pas dépendre de l'ordre alphabétique de chargement.
	g_hToken      = FindConVar("hlfr_webhook_token");
	g_hServerName = FindConVar("hlfr_server_name");

	if (g_hToken == null)
	{
		LogError("[HLFR-Rename] Convar hlfr_webhook_token introuvable : le plugin hlfr_match_log doit être installé pour fournir le token partagé.");
	}
}

public void OnMapStart()
{
	// Restauration de sécurité si la carte change sans game_over (mid-match).
	CloseMatch();
}

public void OnPluginEnd()
{
	CloseMatch();
}

// ─── Cycle de vie des clients ────────────────────────────────────────────────

public void OnClientPostAdminCheck(int client)
{
	if (IsFakeClient(client))
	{
		return;
	}

	// SteamID garanti disponible ici (l'authentification Steam est terminée).
	if (!GetClientAuthId(client, AuthId_Steam2, g_SteamId[client], sizeof(g_SteamId[])))
	{
		g_SteamId[client][0] = '\0';

		return;
	}

	if (!g_MatchLive)
	{
		return;
	}

	// Joueur connu (déjà résolu pendant ce match) : on applique immédiatement.
	if (g_Etf2lName[client][0] != '\0')
	{
		ApplyName(client);

		return;
	}

	// Joueur inconnu : une requête groupée après 3 s (laisser le temps aux
	// autres arrivants de la même vague de rejoindre), avec throttle.
	if (!g_HttpPending && GetTime() - g_LastRequestAt >= LATE_REQUEST_THROTTLE)
	{
		CreateTimer(3.0, Timer_LateRequest, GetClientUserId(client), TIMER_FLAG_NO_MAPCHANGE);
	}
}

public Action Timer_LateRequest(Handle timer, int userid)
{
	int client = GetClientOfUserId(userid);

	if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client) || !g_MatchLive || g_HttpPending)
	{
		return Plugin_Stop;
	}

	if (g_Etf2lName[client][0] != '\0')
	{
		ApplyName(client);

		return Plugin_Stop;
	}

	if (GetTime() - g_LastRequestAt < LATE_REQUEST_THROTTLE)
	{
		return Plugin_Stop;
	}

	RequestNames();
	return Plugin_Stop;
}

public void OnClientDisconnect(int client)
{
	g_SteamId[client][0] = '\0';
	g_Etf2lName[client][0] = '\0';
	g_OriginalName[client][0] = '\0';
	g_Renamed[client] = false;
}

// ─── Détection du match (même logique que hlfr_live_match) ──────────────────

public void Event_RoundStart(Event event, const char[] name, bool dontBroadcast)
{
	if (!g_MatchLive && ShouldArmMatch())
	{
		ArmMatch();
	}
}

public void Event_RoundWin(Event event, const char[] name, bool dontBroadcast)
{
	// Le seuil de joueurs ne s'applique qu'à l'armement : une fois le match
	// armé, les manches continuent d'être comptées même si des joueurs partent.
	if (!g_MatchLive && ShouldArmMatch())
	{
		ArmMatch();
	}
}

public void Event_GameOver(Event event, const char[] name, bool dontBroadcast)
{
	if (!g_MatchLive)
	{
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Rename] Game_Over ignoré (pas un match).");
		}

		return;
	}

	LogMessage("[HLFR-Rename] Fin de match (game_over). Restauration des noms Steam.");
	CloseMatch();
}

bool InWaitingForPlayers()
{
	return GameRules_GetProp("m_bInWaitingForPlayers") != 0;
}

int CountHumansInTeams()
{
	int count = 0;

	for (int i = 1; i <= MaxClients; i++)
	{
		if (!IsClientInGame(i) || IsFakeClient(i) || IsClientSourceTV(i))
		{
			continue;
		}

		int team = GetClientTeam(i);
		if (team == 2 || team == 3)
		{
			count++;
		}
	}

	return count;
}

bool ShouldArmMatch()
{
	bool requireTournament = GetConVarBool(g_hRequireTournament);
	bool tournamentActive  = (g_hMPTournament != null && GetConVarBool(g_hMPTournament));

	if (requireTournament && !tournamentActive)
	{
		return false;
	}

	// L'échauffement (DM ou non) se déroule pendant le waiting-for-players :
	// on n'arme jamais dans cette phase.
	if (InWaitingForPlayers())
	{
		return false;
	}

	return CountHumansInTeams() >= GetConVarInt(g_hMinPlayers);
}

void ArmMatch()
{
	if (g_MatchLive)
	{
		return;
	}

	g_MatchLive = true;
	g_LastRequestAt = 0;

	LogMessage("[HLFR-Rename] Match armé : résolution des pseudos ETF2L.");
	RequestNames();
}

void CloseMatch()
{
	if (g_MatchLive)
	{
		RestoreAllNames();
	}

	g_MatchLive = false;
	g_HttpPending = false;
}

// ─── Application / restauration des noms ─────────────────────────────────────

/**
 * Renomme un client en son pseudo ETF2L (s'il est résolu et différent).
 *
 * @return true si un renommage a eu lieu
 */
bool ApplyName(int client)
{
	if (!IsClientInGame(client) || IsFakeClient(client) || g_Etf2lName[client][0] == '\0')
	{
		return false;
	}

	char current[NAME_BUF];
	GetClientName(client, current, sizeof(current));

	// Déjà conforme : rien à faire (protège aussi contre les boucles de
	// ré-application déclenchées par OnClientSettingsChanged).
	if (StrEqual(current, g_Etf2lName[client]))
	{
		return false;
	}

	if (!g_Renamed[client])
	{
		strcopy(g_OriginalName[client], sizeof(g_OriginalName[]), current);
		g_Renamed[client] = true;
	}

	// Combo éprouvé : SetClientName met à jour le nom côté gamedll
	// (m_szNetname), SetClientInfo pousse la clé "name" du userinfo auprès
	// du moteur (propagation aux autres clients à la frame suivante).
	SetClientName(client, g_Etf2lName[client]);
	SetClientInfo(client, "name", g_Etf2lName[client]);

	LogMessage("[HLFR-Rename] %N renommé en \"%s\" (ETF2L).", client, g_Etf2lName[client]);
	if (GetConVarBool(g_hDebug))
	{
		PrintToServer("[HLFR-Rename] %N renommé en \"%s\".", client, g_Etf2lName[client]);
	}

	return true;
}

/**
 * Restaure les noms Steam d'origine des joueurs renommés (si hlfr_etf2l_restore).
 */
void RestoreAllNames()
{
	bool restore = GetConVarBool(g_hRestore);

	for (int client = 1; client <= MaxClients; client++)
	{
		if (!g_Renamed[client])
		{
			continue;
		}

		if (restore && IsClientInGame(client) && !IsFakeClient(client))
		{
			SetClientName(client, g_OriginalName[client]);
			SetClientInfo(client, "name", g_OriginalName[client]);
			LogMessage("[HLFR-Rename] %N restauré en \"%s\".", client, g_OriginalName[client]);
		}

		g_Etf2lName[client][0] = '\0';
		g_OriginalName[client][0] = '\0';
		g_Renamed[client] = false;
	}
}

// ─── Ré-application quand un joueur se renomme (hlfr_etf2l_enforce) ─────────

public void OnClientSettingsChanged(int client)
{
	if (!GetConVarBool(g_hEnforce) || !g_MatchLive || !IsClientInGame(client) || IsFakeClient(client))
	{
		return;
	}

	if (g_Etf2lName[client][0] == '\0')
	{
		return;
	}

	char current[NAME_BUF];
	GetClientName(client, current, sizeof(current));

	// ApplyName ne fait rien si le nom est déjà conforme : pas de boucle.
	ApplyName(client);
}

// ─── Requête HTTP au site ────────────────────────────────────────────────────

/**
 * Envoie les SteamIDs des joueurs connectés au site et applique les pseudos
 * reçus. Une seule requête en vol à la fois.
 *
 * @return true si la requête a été émise
 */
bool RequestNames()
{
	if (!GetConVarBool(g_hEnabled))
	{
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Rename] Requête ignorée : hlfr_etf2l_enable=0.");
		}

		return false;
	}

	if (g_HttpPending)
	{
		return false;
	}

	char url[512], token[256], server[256];
	GetConVarString(g_hUrl, url, sizeof(url));

	// Filet de sécurité : un reload de hlfr_match_log en cours de partie peut
	// avoir invalidé le handle (résolu initialement dans OnAllPluginsLoaded).
	if (g_hToken == null)
	{
		g_hToken = FindConVar("hlfr_webhook_token");
	}
	if (g_hServerName == null)
	{
		g_hServerName = FindConVar("hlfr_server_name");
	}

	if (g_hToken != null)
	{
		GetConVarString(g_hToken, token, sizeof(token));
	}
	if (g_hServerName != null)
	{
		GetConVarString(g_hServerName, server, sizeof(server));
	}
	if (server[0] == '\0' && g_hHostname != null)
	{
		GetConVarString(g_hHostname, server, sizeof(server));
	}

	if (url[0] == '\0' || token[0] == '\0')
	{
		LogError("[HLFR-Rename] Requête non envoyée : hlfr_etf2l_url vide ou hlfr_webhook_token absent (plugin hlfr_match_log chargé ?).");
		PrintToServer("[HLFR-Rename] Requête non envoyée : url ou token manquant.");

		return false;
	}

	JSONArray players = new JSONArray();
	int count = 0;

	for (int client = 1; client <= MaxClients; client++)
	{
		if (!IsClientInGame(client) || IsFakeClient(client) || g_SteamId[client][0] == '\0')
		{
			continue;
		}

		players.PushString(g_SteamId[client]);
		count++;
	}

	if (count == 0)
	{
		delete players;

		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Rename] Requête ignorée : aucun joueur authentifié.");
		}

		return false;
	}

	HTTPRequest request = new HTTPRequest(url);
	request.SetHeader("User-Agent", "hlfr_etf2l_rename");

	JSONObject body = new JSONObject();
	body.SetString("token", token);
	body.SetString("server", server);
	body.Set("players", players);

	g_HttpPending = true;
	g_LastRequestAt = GetTime();

	LogMessage("[HLFR-Rename] Demande de pseudos ETF2L pour %d joueur(s).", count);
	if (GetConVarBool(g_hDebug))
	{
		PrintToServer("[HLFR-Rename] Requête envoyée (%d joueurs).", count);
	}

	// Post prend possession de `body` : ne pas faire delete.
	request.Post(body, Callback_NamesReceived);
	return true;
}

void Callback_NamesReceived(HTTPResponse response, any value)
{
	g_HttpPending = false;

	int status = view_as<int>(response.Status);

	if (status < 200 || status > 299)
	{
		LogError("[HLFR-Rename] Réponse du site refusée (HTTP %d).", status);

		if (status == 0)
		{
			PrintToServer("[HLFR-Rename] Site injoignable (HTTP 0). Vérifiez hlfr_etf2l_url.");
		}
		else if (status == 403)
		{
			PrintToServer("[HLFR-Rename] Refusé (HTTP 403) : token incorrect ou IP non autorisée.");
		}
		else if (status == 404)
		{
			PrintToServer("[HLFR-Rename] Refusé (HTTP 404) : mauvaise URL hlfr_etf2l_url.");
		}

		return;
	}

	JSONObject root = view_as<JSONObject>(response.Data);
	if (root == null)
	{
		LogError("[HLFR-Rename] Réponse du site illisible (JSON invalide).");

		return;
	}

	JSONObject players = view_as<JSONObject>(root.Get("players"));
	if (players == null)
	{
		delete root;
		LogError("[HLFR-Rename] Réponse du site sans bloc players.");

		return;
	}

	int applied = 0;
	int resolved = 0;

	// Le match peut avoir été fermé pendant la requête (game_over, changement
	// de carte) : on ne stocke ni n'applique quoi que ce soit.
	if (!g_MatchLive)
	{
		delete root;

		return;
	}

	JSONObjectKeys keys = players.Keys();
	char steamid[32];
	char name[NAME_BUF];

	while (keys.ReadKey(steamid, sizeof(steamid)))
	{
		JSONObject entry = view_as<JSONObject>(players.Get(steamid));
		if (entry == null)
		{
			continue;
		}

		resolved++;

		if (!entry.GetString("name", name, sizeof(name)) || name[0] == '\0')
		{
			delete entry;

			continue;
		}

		// On associe le pseudo au(x) client(s) portant ce SteamID.
		for (int client = 1; client <= MaxClients; client++)
		{
			if (g_SteamId[client][0] == '\0' || !StrEqual(g_SteamId[client], steamid))
			{
				continue;
			}

			strcopy(g_Etf2lName[client], sizeof(g_Etf2lName[]), name);

			if (ApplyName(client))
			{
				applied++;
			}
		}

		delete entry;
	}

	delete keys;
	delete players;
	delete root;

	LogMessage("[HLFR-Rename] Pseudos reçus : %d, appliqués : %d.", resolved, applied);
	if (GetConVarBool(g_hDebug))
	{
		PrintToServer("[HLFR-Rename] Pseudos reçus : %d, appliqués : %d.", resolved, applied);
	}
}

// ─── Commandes admin (tests / dépannage) ─────────────────────────────────────

public Action Command_ManualRename(int client, int args)
{
	if (!GetConVarBool(g_hEnabled))
	{
		ReplyToCommand(client, "[HLFR-Rename] Renommage désactivé (hlfr_etf2l_enable 0).");

		return Plugin_Handled;
	}

	if (g_HttpPending)
	{
		ReplyToCommand(client, "[HLFR-Rename] Une requête est déjà en cours, réessayez dans quelques secondes.");

		return Plugin_Handled;
	}

	// Armer manuellement permet de tester sans serveur de match configuré
	// (mp_tournament, nombre de joueurs...).
	if (!g_MatchLive)
	{
		g_MatchLive = true;
	}

	if (RequestNames())
	{
		ReplyToCommand(client, "[HLFR-Rename] Demande de pseudos envoyée au site.");
	}
	else
	{
		ReplyToCommand(client, "[HLFR-Rename] Envoi impossible (aucun joueur authentifié, url ou token manquant).");
	}

	return Plugin_Handled;
}

public Action Command_ManualRestore(int client, int args)
{
	RestoreAllNames();
	g_MatchLive = false;
	g_HttpPending = false;

	ReplyToCommand(client, "[HLFR-Rename] Noms Steam restaurés.");
	LogMessage("[HLFR-Rename] Restauration manuelle déclenchée par %N.", client);

	return Plugin_Handled;
}

public Action Command_Status(int client, int args)
{
	int humans = 0, renamed = 0, resolved = 0;

	for (int i = 1; i <= MaxClients; i++)
	{
		if (!IsClientInGame(i) || IsFakeClient(i) || IsClientSourceTV(i))
		{
			continue;
		}

		humans++;

		if (g_Renamed[i])
		{
			renamed++;
		}

		if (g_Etf2lName[i][0] != '\0')
		{
			resolved++;
		}
	}

	ReplyToCommand(client, "[HLFR-Rename] Version %s | enable=%d | require_tournament=%d | mp_tournament=%d | enforce=%d | restore=%d | live=%d | humains=%d | resolus=%d | renommes=%d | pending=%d | derniere_req=%ds",
		PLUGIN_VERSION,
		GetConVarBool(g_hEnabled),
		GetConVarBool(g_hRequireTournament),
		(g_hMPTournament != null && GetConVarBool(g_hMPTournament)),
		GetConVarBool(g_hEnforce),
		GetConVarBool(g_hRestore),
		g_MatchLive,
		humans,
		resolved,
		renamed,
		g_HttpPending,
		(g_LastRequestAt > 0) ? (GetTime() - g_LastRequestAt) : -1);

	return Plugin_Handled;
}
