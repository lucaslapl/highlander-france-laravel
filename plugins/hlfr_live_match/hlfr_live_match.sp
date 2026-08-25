#pragma semicolon 1
#pragma newdecls required

#include <sourcemod>
#include <tf2>
#include <tf2_stocks>
#include <ripext>

#define PLUGIN_VERSION "1.1.0"

public Plugin myinfo =
{
	name        = "HLFR Live Match",
	author      = "Highlander France",
	description = "Envoie l'état d'un match en direct (map, serveur, joueurs, score, SourceTV) au site Highlander France.",
	version     = PLUGIN_VERSION,
	url         = "https://highlanderfrance.tf"
};

// --- Options du plugin ---
ConVar g_hEnabled;
ConVar g_hUrl;
ConVar g_hInterval;
ConVar g_hDebug;
ConVar g_hRequireTournament;
ConVar g_hStvUrl;
ConVar g_hStvIncludePassword;

// --- Convars du serveur / partagées avec hlfr_match_log ---
ConVar g_hMPTournament;
ConVar g_hHostname;
ConVar g_hHostIp;
ConVar g_hTvEnable;
ConVar g_hTvPort;
ConVar g_hTvPassword;
ConVar g_hToken;     // hlfr_webhook_token (créée par hlfr_match_log)
ConVar g_hServerName; // hlfr_server_name (créée par hlfr_match_log)

// --- État ---
bool   g_Live;           // un match de compétition est en cours
int    g_ScoreRed;       // manches gagnées RED
int    g_ScoreBlue;      // manches gagnées BLU
int    g_StartedAt;      // timestamp du début du match
Handle g_hHeartbeat;     // timer périodique pendant le match
bool   g_HttpPending;    // une requête HTTP est en vol (évite l'empilement)
bool   g_SendEndedPending; // un statut "ended" attend la fin de la requête en vol
char   g_LastStatus[16]; // dernier statut envoyé (diagnostics)

// --- Stats de match par joueur (scope = le match armé, reset à l'armement) ---
enum
{
	Stats_Kills,
	Stats_Deaths,
	Stats_Assists,
	Stats_Dmg,
	Stats_Heal,
	Stats_Count
};

int g_Stats[MAXPLAYERS + 1][Stats_Count];

public void OnPluginStart()
{
	CreateConVar("hlfr_live_version", PLUGIN_VERSION, "Version du plugin HLFR Live Match", FCVAR_NOTIFY);

	g_hEnabled    = CreateConVar("hlfr_live_enable", "1", "Active/désactive l'envoi du statut live.", _, true, 0.0, true, 1.0);
	g_hUrl        = CreateConVar("hlfr_live_url", "https://highlanderfrance.tf/api/server/live-status", "URL de l'endpoint live du site Highlander France.");
	g_hInterval   = CreateConVar("hlfr_live_interval", "120.0", "Intervalle (secondes) entre deux mises à jour du statut pendant un match.", _, true, 5.0);
	g_hDebug      = CreateConVar("hlfr_live_debug", "0", "Logs de debug supplémentaires dans la console du serveur.", _, true, 0.0, true, 1.0);
	g_hRequireTournament = CreateConVar("hlfr_live_require_tournament", "1", "Exige mp_tournament pour considérer le match comme en direct. Mettez 0 sur un serveur 100% match (TFTrue).", _, true, 0.0, true, 1.0);
	g_hStvUrl     = CreateConVar("hlfr_live_stv_url", "", "URL SourceTV manuelle (ex: steam://connect/185.xxx.x.x:27020). Vide = construction auto (hostip + tv_port). Utile derrière un NAT.");
	g_hStvIncludePassword = CreateConVar("hlfr_live_stv_include_password", "0", "Envoyer le mot de passe SourceTV (tv_password) au site.", _, true, 0.0, true, 1.0);

	AutoExecConfig(true, "hlfr_live_match");

	// Convars natives du serveur / du jeu.
	g_hMPTournament  = FindConVar("mp_tournament");
	g_hHostname      = FindConVar("hostname");
	g_hHostIp        = FindConVar("hostip");
	g_hTvEnable      = FindConVar("tv_enable");
	g_hTvPort        = FindConVar("tv_port");
	g_hTvPassword    = FindConVar("tv_password");

	// Les convars partagées (hlfr_webhook_token, hlfr_server_name) sont créées
	// par hlfr_match_log : elles sont résolues dans OnAllPluginsLoaded pour ne
	// pas dépendre de l'ordre alphabétique de chargement des plugins.

	HookEvent("teamplay_game_over",   Event_GameOver);
	HookEvent("tf_game_over",         Event_GameOver);
	HookEvent("teamplay_round_win",   Event_RoundWin);
	HookEvent("teamplay_round_start", Event_RoundStart);
	HookEvent("player_death",         Event_PlayerDeath);
	HookEvent("player_hurt",          Event_PlayerHurt);
	HookEvent("player_healed",        Event_PlayerHealed);

	RegAdminCmd("sm_hlfr_live",        Command_LiveSync, ADMFLAG_GENERIC, "Renvoie immédiatement le statut live actuel au site.");
	RegAdminCmd("sm_hlfr_live_status", Command_LiveStatus, ADMFLAG_GENERIC, "Affiche l'état du plugin (dépannage).");

	LogMessage("[HLFR-Live] Version %s chargée (interval=%.0fs, require_tournament=%d).", PLUGIN_VERSION, GetConVarFloat(g_hInterval), GetConVarBool(g_hRequireTournament));
	PrintToServer("[HLFR-Live] Version %s chargée.", PLUGIN_VERSION);
}

public void OnAllPluginsLoaded()
{
	// Convars créées par hlfr_match_log : résolues ici, après le chargement de
	// tous les plugins, pour ne pas dépendre de l'ordre alphabétique de chargement.
	g_hToken      = FindConVar("hlfr_webhook_token");
	g_hServerName = FindConVar("hlfr_server_name");

	if (g_hToken == null)
	{
		LogError("[HLFR-Live] Convar hlfr_webhook_token introuvable : le plugin hlfr_match_log doit être installé pour fournir le token partagé.");
	}
}

public void OnMapStart()
{
	bool wasLive = g_Live;
	ResetMatchState();

	// Filet de sécurité : si une carte se termine sans teamplay_game_over,
	// on nettoie l'état côté site.
	if (wasLive)
	{
		SendStatus("ended");
	}
}

public void OnPluginEnd()
{
	ResetMatchState();
}

void ResetMatchState()
{
	g_Live = false;
	g_ScoreRed = 0;
	g_ScoreBlue = 0;
	g_StartedAt = 0;

	ResetStats();

	if (g_hHeartbeat != null && IsValidHandle(g_hHeartbeat))
	{
		KillTimer(g_hHeartbeat);
	}
	g_hHeartbeat = null;
}

void ResetStats()
{
	for (int client = 1; client <= MaxClients; client++)
	{
		for (int s = 0; s < Stats_Count; s++)
		{
			g_Stats[client][s] = 0;
		}
	}
}

public void OnClientPutInServer(int client)
{
	if (g_Live)
	{
		SendStatus("live");
	}
}

public void OnClientDisconnect(int client)
{
	if (g_Live)
	{
		SendStatus("live");
	}
}

bool ShouldArmMatch()
{
	bool requireTournament = GetConVarBool(g_hRequireTournament);
	bool tournamentActive  = (g_hMPTournament != null && GetConVarBool(g_hMPTournament));

	return !requireTournament || tournamentActive;
}

void ArmMatch()
{
	if (g_Live)
	{
		return;
	}

	g_Live = true;
	ResetStats();
	if (g_StartedAt == 0)
	{
		g_StartedAt = GetTime();
	}
	StartHeartbeat();

	LogMessage("[HLFR-Live] Match armé (map en cours).");
	if (GetConVarBool(g_hDebug))
	{
		PrintToServer("[HLFR-Live] Match armé.");
	}
}

void StartHeartbeat()
{
	if (g_hHeartbeat != null && IsValidHandle(g_hHeartbeat))
	{
		KillTimer(g_hHeartbeat);
	}
	g_hHeartbeat = CreateTimer(GetConVarFloat(g_hInterval), Timer_Heartbeat, _, TIMER_REPEAT | TIMER_FLAG_NO_MAPCHANGE);
}

public Action Timer_Heartbeat(Handle timer)
{
	if (!g_Live)
	{
		return Plugin_Stop;
	}

	SendStatus("live");
	return Plugin_Continue;
}

public void Event_RoundStart(Event event, const char[] name, bool dontBroadcast)
{
	if (ShouldArmMatch())
	{
		ArmMatch();
		SendStatus("live");
	}
}

public void Event_RoundWin(Event event, const char[] name, bool dontBroadcast)
{
	if (!ShouldArmMatch())
	{
		return;
	}

	if (!g_Live)
	{
		ArmMatch();
	}

	// TF2 : team 2 = RED, team 3 = BLU.
	int winner = event.GetInt("team");
	if (winner == 2)
	{
		g_ScoreRed++;
	}
	else if (winner == 3)
	{
		g_ScoreBlue++;
	}

	SendStatus("live");
}

public void Event_PlayerDeath(Event event, const char[] name, bool dontBroadcast)
{
	int victim    = GetClientOfUserId(event.GetInt("victim"));
	int attacker  = GetClientOfUserId(event.GetInt("attacker"));
	int assister  = GetClientOfUserId(event.GetInt("assister"));
	int assister2 = GetClientOfUserId(event.GetInt("assister2"));

	if (IsPlayerIndex(victim))
	{
		g_Stats[victim][Stats_Deaths]++;
	}

	if (IsPlayerIndex(attacker) && attacker != victim)
	{
		g_Stats[attacker][Stats_Kills]++;
	}

	if (IsPlayerIndex(assister) && assister != victim && assister != attacker)
	{
		g_Stats[assister][Stats_Assists]++;
	}

	if (IsPlayerIndex(assister2) && assister2 != victim && assister2 != attacker && assister2 != assister)
	{
		g_Stats[assister2][Stats_Assists]++;
	}
}

public void Event_PlayerHurt(Event event, const char[] name, bool dontBroadcast)
{
	int attacker = GetClientOfUserId(event.GetInt("attacker"));
	int victim   = GetClientOfUserId(event.GetInt("userid"));

	// On ignore le self-damage (rocket jumps...) et les dégâts du monde (attacker 0).
	if (!IsPlayerIndex(attacker) || attacker == victim)
	{
		return;
	}

	g_Stats[attacker][Stats_Dmg] += event.GetInt("damage");
}

public void Event_PlayerHealed(Event event, const char[] name, bool dontBroadcast)
{
	int healer = GetClientOfUserId(event.GetInt("healer"));

	if (IsPlayerIndex(healer))
	{
		g_Stats[healer][Stats_Heal] += event.GetInt("amount");
	}
}

bool IsPlayerIndex(int client)
{
	return client > 0 && client <= MaxClients;
}

public void Event_GameOver(Event event, const char[] name, bool dontBroadcast)
{
	bool tournamentActive = (g_hMPTournament != null && GetConVarBool(g_hMPTournament));

	if (!g_Live && !tournamentActive)
	{
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Live] Game_Over ignoré (pas un match).");
		}
		return;
	}

	LogMessage("[HLFR-Live] Fin de match (game_over). Envoi du statut 'ended'.");
	ResetMatchState();
	SendStatus("ended");
}

public Action Command_LiveSync(int client, int args)
{
	if (!GetConVarBool(g_hEnabled))
	{
		ReplyToCommand(client, "[HLFR-Live] Envoi désactivé (hlfr_live_enable 0).");
		return Plugin_Handled;
	}

	if (g_HttpPending)
	{
		ReplyToCommand(client, "[HLFR-Live] Une requête est déjà en cours d'envoi, réessayez dans quelques secondes.");
		return Plugin_Handled;
	}

	char status[8];
	if (g_Live)
	{
		status = "live";
	}
	else
	{
		status = "ended";
	}
	if (SendStatus(status))
	{
		ReplyToCommand(client, "[HLFR-Live] Statut '%s' envoyé au site.", status);
	}
	else
	{
		ReplyToCommand(client, "[HLFR-Live] Envoi impossible (url ou token manquant).");
	}

	LogMessage("[HLFR-Live] Envoi manuel déclenché par %L.", client);
	return Plugin_Handled;
}

public Action Command_LiveStatus(int client, int args)
{
	int players = 0;
	for (int i = 1; i <= MaxClients; i++)
	{
		if (IsClientInGame(i) && !IsFakeClient(i) && !IsClientSourceTV(i))
		{
			int team = GetClientTeam(i);
			if (team == 2 || team == 3)
			{
				players++;
			}
		}
	}

	ReplyToCommand(client, "[HLFR-Live] Version %s | enable=%d | require_tournament=%d | mp_tournament=%d | live=%d | score=%d-%d | started=%d | players=%d | pending=%d | interval=%.0f",
		PLUGIN_VERSION,
		GetConVarBool(g_hEnabled),
		GetConVarBool(g_hRequireTournament),
		(g_hMPTournament != null && GetConVarBool(g_hMPTournament)),
		g_Live,
		g_ScoreRed,
		g_ScoreBlue,
		g_StartedAt,
		players,
		g_HttpPending,
		GetConVarFloat(g_hInterval));
	return Plugin_Handled;
}

bool SendStatus(const char[] status)
{
	if (!GetConVarBool(g_hEnabled))
	{
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Live] Envoi ignoré : hlfr_live_enable=0.");
		}
		return false;
	}

	// Une requête est en vol : on attend la suivante (le heartbeat rattrape).
	// Un "ended" est, lui, reporté jusqu'à la fin de la requête en vol.
	if (g_HttpPending)
	{
		if (StrEqual(status, "ended"))
		{
			g_SendEndedPending = true;
			return true;
		}
		return false;
	}
	g_SendEndedPending = false;

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
		LogError("[HLFR-Live] Statut '%s' non envoyé : hlfr_live_url vide ou hlfr_webhook_token absent (plugin hlfr_match_log chargé ?).", status);
		PrintToServer("[HLFR-Live] Statut non envoyé : url ou token manquant.");
		return false;
	}

	char map[PLATFORM_MAX_PATH];
	GetCurrentMap(map, sizeof(map));

	HTTPRequest request = new HTTPRequest(url);
	request.SetHeader("User-Agent", "hlfr_live_match");

	JSONObject body = new JSONObject();
	body.SetString("token", token);
	body.SetString("server", server);
	body.SetString("map", map);
	body.SetString("status", status);
	body.SetInt("started_at", g_StartedAt);
	body.SetInt("updated_at", GetTime());

	JSONObject scores = new JSONObject();
	scores.SetInt("red", g_ScoreRed);
	scores.SetInt("blue", g_ScoreBlue);
	body.Set("scores", scores);

	JSONArray players = new JSONArray();
	int playerCount = BuildPlayers(players);
	body.Set("players", players);

	BuildStv(body);

	g_HttpPending = true;
	strcopy(g_LastStatus, sizeof(g_LastStatus), status);

	LogMessage("[HLFR-Live] Statut '%s' envoyé : server=%s, map=%s, joueurs=%d, score=%d-%d.", status, server, map, playerCount, g_ScoreRed, g_ScoreBlue);
	if (GetConVarBool(g_hDebug))
	{
		PrintToServer("[HLFR-Live] Statut '%s' envoyé (%d joueurs).", status, playerCount);
	}

	// Post prend possession de `body` : ne pas faire delete.
	request.Post(body, Callback_LiveStatus);
	return true;
}

int BuildPlayers(JSONArray arr)
{
	int count = 0;

	for (int client = 1; client <= MaxClients; client++)
	{
		if (!IsClientInGame(client))
		{
			continue;
		}
		if (IsFakeClient(client))
		{
			continue;
		}
		if (IsClientSourceTV(client))
		{
			continue;
		}

		int team = GetClientTeam(client);
		if (team != 2 && team != 3)
		{
			continue;
		}

		char name[MAX_NAME_LENGTH];
		GetClientName(client, name, sizeof(name));

		char steamid[32];
		if (!GetClientAuthId(client, AuthId_Steam2, steamid, sizeof(steamid)))
		{
			steamid[0] = '\0';
		}

		char className[32];
		ClassToKey(TF2_GetPlayerClass(client), className, sizeof(className));

		char teamStr[8];
		if (team == 2)
		{
			teamStr = "red";
		}
		else
		{
			teamStr = "blue";
		}

		JSONObject player = new JSONObject();
		player.SetString("name", name);
		player.SetString("team", teamStr);
		player.SetString("class", className);
		player.SetString("steamid", steamid);
		player.SetInt("score", GetEntProp(client, Prop_Data, "m_iScore"));
		player.SetInt("kills", g_Stats[client][Stats_Kills]);
		player.SetInt("deaths", g_Stats[client][Stats_Deaths]);
		player.SetInt("assists", g_Stats[client][Stats_Assists]);
		player.SetInt("dmg", g_Stats[client][Stats_Dmg]);
		player.SetInt("heal", g_Stats[client][Stats_Heal]);
		arr.Push(player);

		count++;
	}

	return count;
}

void ClassToKey(TFClassType cls, char[] key, int maxlen)
{
	switch (cls)
	{
		case TFClass_Scout:        strcopy(key, maxlen, "scout");
		case TFClass_Soldier:      strcopy(key, maxlen, "soldier");
		case TFClass_Pyro:         strcopy(key, maxlen, "pyro");
		case TFClass_DemoMan:      strcopy(key, maxlen, "demoman");
		case TFClass_Heavy:        strcopy(key, maxlen, "heavyweapons");
		case TFClass_Engineer:     strcopy(key, maxlen, "engineer");
		case TFClass_Medic:        strcopy(key, maxlen, "medic");
		case TFClass_Sniper:       strcopy(key, maxlen, "sniper");
		case TFClass_Spy:          strcopy(key, maxlen, "spy");
		default:                   key[0] = '\0';
	}
}

void BuildStv(JSONObject body)
{
	char url[512];
	GetConVarString(g_hStvUrl, url, sizeof(url));

	if (url[0] == '\0')
	{
		if (!SourceTvActive())
		{
			return;
		}
		if (g_hHostIp == null || g_hTvPort == null)
		{
			return;
		}

		int ip = GetConVarInt(g_hHostIp);
		int port = GetConVarInt(g_hTvPort);

		char ipStr[16];
		FormatEx(ipStr, sizeof(ipStr), "%d.%d.%d.%d", (ip >> 24) & 0xFF, (ip >> 16) & 0xFF, (ip >> 8) & 0xFF, ip & 0xFF);
		FormatEx(url, sizeof(url), "steam://connect/%s:%d", ipStr, port);

		JSONObject stv = new JSONObject();
		stv.SetString("connect", url);
		stv.SetString("ip", ipStr);
		stv.SetInt("port", port);

		bool includePassword = GetConVarBool(g_hStvIncludePassword) && g_hTvPassword != null;
		if (includePassword)
		{
			char pw[64];
			GetConVarString(g_hTvPassword, pw, sizeof(pw));
			if (pw[0] != '\0')
			{
				stv.SetString("password", pw);
			}
		}

		body.Set("stv", stv);
		return;
	}

	// Override manuel : on envoie l'URL telle quelle.
	JSONObject stv = new JSONObject();
	stv.SetString("connect", url);
	body.Set("stv", stv);
}

bool SourceTvActive()
{
	if (g_hTvEnable != null && GetConVarBool(g_hTvEnable))
	{
		return true;
	}

	for (int client = 1; client <= MaxClients; client++)
	{
		if (IsClientConnected(client) && IsClientSourceTV(client))
		{
			return true;
		}
	}

	return false;
}

void Callback_LiveStatus(HTTPResponse response, int value)
{
	g_HttpPending = false;

	int status = view_as<int>(response.Status);

	if (status >= 200 && status <= 299)
	{
		LogMessage("[HLFR-Live] Statut '%s' accepté (HTTP %d).", g_LastStatus, status);
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR-Live] Statut '%s' accepté (HTTP %d).", g_LastStatus, status);
		}
	}
	else
	{
		LogError("[HLFR-Live] Statut '%s' refusé (HTTP %d).", g_LastStatus, status);

		if (status == 0)
		{
			PrintToServer("[HLFR-Live] Site injoignable (HTTP 0). Vérifiez hlfr_live_url.");
		}
		else if (status == 403)
		{
			PrintToServer("[HLFR-Live] Refusé (HTTP 403) : token incorrect ou IP non autorisée.");
		}
		else if (status == 404)
		{
			PrintToServer("[HLFR-Live] Refusé (HTTP 404) : mauvaise URL hlfr_live_url.");
		}
	}

	// Un "ended" a été demandé pendant que la requête précédente était en vol.
	if (g_SendEndedPending && !g_Live)
	{
		g_SendEndedPending = false;
		SendStatus("ended");
	}
}
