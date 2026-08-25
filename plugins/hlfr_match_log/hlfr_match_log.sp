#pragma semicolon 1
#pragma newdecls required

#include <sourcemod>
#include <sdktools>

// REST in Pawn (ripext) est requis et auto-chargé : SourceMod définit déjà
// AUTOLOAD_EXTENSIONS/REQUIRE_EXTENSIONS par défaut, si l'extension est
// absente le plugin refuse de se charger (erreur visible dans sm plugins list).
#include <ripext>

#define PLUGIN_VERSION "1.4.0"

public Plugin myinfo =
{
	name        = "HLFR Match Log Webhook",
	author      = "Highlander France",
	description = "Notifie le site Highlander France quand un match se termine (les logs sont envoyés sur logs.tf par TFTrue)",
	version     = PLUGIN_VERSION,
	url         = "https://highlanderfrance.tf"
};

ConVar g_hEnabled;
ConVar g_hWebhookUrl;
ConVar g_hToken;
ConVar g_hServerName;
ConVar g_hDelay;
ConVar g_hMaxRetries;
ConVar g_hDebug;
ConVar g_hRequireTournament;
ConVar g_hMinPlayers;
ConVar g_hMPTournament;
ConVar g_hHostname;

bool   g_InMatch;        // un vrai match a été armé (round joué avec assez de joueurs)
bool   g_WebhookPending; // un webhook est déjà en cours (évite les doublons)
int    g_RetriesLeft;    // tentatives restantes
char   g_LastMap[PLATFORM_MAX_PATH];
Handle g_hPendingTimer;   // timer d'envoi ou de retry actuellement programmé
Handle g_hWebhookTimeout; // watchdog anti-blocage (callback ripext jamais rappelé)

public void OnPluginStart()
{
	CreateConVar("hlfr_match_log_version", PLUGIN_VERSION, "Version du plugin HLFR Match Log Webhook", FCVAR_NOTIFY);

	g_hEnabled    = CreateConVar("hlfr_enable", "1", "Active/désactive le webhook de fin de match.", _, true, 0.0, true, 1.0);
	g_hWebhookUrl = CreateConVar("hlfr_webhook_url", "https://highlanderfrance.tf/api/server/match-ended", "URL du webhook du site Highlander France.");
	g_hToken      = CreateConVar("hlfr_webhook_token", "", "Token partagé (secret) pour authentifier le webhook. Doit correspondre à SERVER_WEBHOOK_TOKEN du site.", FCVAR_PROTECTED);
	g_hServerName = CreateConVar("hlfr_server_name", "", "Nom du serveur de match envoyé au site (vide = hostname).");
	g_hDelay      = CreateConVar("hlfr_delay", "30.0", "Délai (secondes) entre la fin de match et l'envoi du webhook, pour laisser TFTrue uploader le log sur logs.tf.", _, true, 0.0);
	g_hMaxRetries = CreateConVar("hlfr_max_retries", "3", "Nombre de nouvelles tentatives si le webhook échoue.", _, true, 0.0);
	g_hDebug      = CreateConVar("hlfr_debug", "0", "Logs de debug supplémentaires dans la console du serveur.", _, true, 0.0, true, 1.0);
	g_hRequireTournament = CreateConVar("hlfr_require_tournament", "1", "Exige mp_tournament pour considérer un game_over comme une fin de match. Mettez 0 sur un serveur 100% match (TFTrue) pour déclencher sur chaque game_over.", _, true, 0.0, true, 1.0);
	g_hMinPlayers = CreateConVar("hlfr_min_players", "16", "Nombre minimum de joueurs humains en équipe pour armer un match (16 = highlander, 10 = 6v6).", _, true, 0.0, true, 64.0);

	AutoExecConfig(true, "hlfr_match_log");

	g_hMPTournament = FindConVar("mp_tournament");
	g_hHostname     = FindConVar("hostname");

	HookEvent("teamplay_game_over",   Event_GameOver);
	HookEvent("tf_game_over",         Event_GameOver);
	HookEvent("teamplay_round_win",   Event_RoundWin);
	HookEvent("teamplay_round_start", Event_RoundStart);

	RegAdminCmd("sm_hlfr_sync",   Command_Sync,   ADMFLAG_GENERIC, "Déclenche manuellement le webhook de fin de match (test).");
	RegAdminCmd("sm_hlfr_status", Command_Status, ADMFLAG_GENERIC, "Affiche l'état du plugin (dépannage).");

	LogMessage("[HLFR] Version %s chargée (hlfr_require_tournament=%d).", PLUGIN_VERSION, GetConVarBool(g_hRequireTournament));
	PrintToServer("[HLFR] Version %s chargée.", PLUGIN_VERSION);
}

public void OnMapStart()
{
	// Nouvelle carte = nouveau potentiel match. Le prochain round_start re-arme le plugin.
	g_InMatch = false;
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

bool InWaitingForPlayers()
{
	return GameRules_GetProp("m_bInWaitingForPlayers") != 0;
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

public void Event_RoundStart(Event event, const char[] name, bool dontBroadcast)
{
	// Un round démarre hors échauffement avec assez de joueurs : c'est un match.
	if (ShouldArmMatch())
	{
		g_InMatch = true;
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR] Round start : match armé (%d joueurs).", CountHumansInTeams());
		}
	}
}

public void Event_RoundWin(Event event, const char[] name, bool dontBroadcast)
{
	if (ShouldArmMatch())
	{
		g_InMatch = true;
		if (GetConVarBool(g_hDebug))
		{
			PrintToServer("[HLFR] Round win : match armé (%d joueurs).", CountHumansInTeams());
		}
	}
}

public void Event_GameOver(Event event, const char[] name, bool dontBroadcast)
{
	bool requireTournament = GetConVarBool(g_hRequireTournament);

	// Déclenche si : mode "tout game_over" (serveur 100% match), OU un vrai
	// match a été armé (g_InMatch = round joué avec le seuil de joueurs requis).
	bool isMatch = !requireTournament || g_InMatch;

	if (!isMatch)
	{
		// Ne jamais rester silencieux : toujours tracer un game_over ignoré.
		LogMessage("[HLFR] Game_Over ignoré (pas un match) : hlfr_require_tournament=%d, g_InMatch=%d.", requireTournament, g_InMatch);
		return;
	}

	if (!GetConVarBool(g_hEnabled))
	{
		LogMessage("[HLFR] Fin de match détectée mais hlfr_enable=0 : webhook non envoyé.");
		PrintToServer("[HLFR] Fin de match détectée mais hlfr_enable=0 : webhook non envoyé.");
		return;
	}

	if (g_WebhookPending)
	{
		LogMessage("[HLFR] Fin de match ignorée : un webhook est déjà en cours.");
		PrintToServer("[HLFR] Fin de match ignorée : un webhook est déjà en cours.");
		return;
	}

	g_InMatch = false;

	StartWebhook(GetConVarFloat(g_hDelay));
}

public Action Command_Sync(int client, int args)
{
	if (!GetConVarBool(g_hEnabled))
	{
		ReplyToCommand(client, "[HLFR] Webhook désactivé (hlfr_enable 0).");
		return Plugin_Handled;
	}

	if (g_WebhookPending)
	{
		ReplyToCommand(client, "[HLFR] Un webhook est déjà en cours d'envoi.");
		return Plugin_Handled;
	}

	StartWebhook(0.1);
	LogMessage("[HLFR] Webhook déclenché manuellement par %L.", client);
	ReplyToCommand(client, "[HLFR] Webhook de fin de match déclenché.");
	return Plugin_Handled;
}

public Action Command_Status(int client, int args)
{
	ReplyToCommand(client, "[HLFR] Version %s | enable=%d | require_tournament=%d | mp_tournament=%d | min_players=%d | players=%d | in_match=%d | pending=%d | retries=%d | map=%s",
		PLUGIN_VERSION,
		GetConVarBool(g_hEnabled),
		GetConVarBool(g_hRequireTournament),
		(g_hMPTournament != null && GetConVarBool(g_hMPTournament)),
		GetConVarInt(g_hMinPlayers),
		CountHumansInTeams(),
		g_InMatch,
		g_WebhookPending,
		g_RetriesLeft,
		g_LastMap);
	return Plugin_Handled;
}

void StartWebhook(float delay)
{
	GetCurrentMap(g_LastMap, sizeof(g_LastMap));
	g_RetriesLeft = GetConVarInt(g_hMaxRetries);
	g_WebhookPending = true;

	LogMessage("[HLFR] Fin de match détectée (map %s). Webhook dans %.0f s.", g_LastMap, delay);
	PrintToServer("[HLFR] Fin de match détectée (map %s). Webhook dans %.0f s.", g_LastMap, delay);

	StartWebhookTimeout();
	// Sans TIMER_FLAG_NO_MAPCHANGE : le webhook concerne un log déjà terminé,
	// il doit partir même si la carte change entre-temps.
	g_hPendingTimer = CreateTimer(delay, Timer_FireWebhook, _);
}

// Anti-blocage : si le callback HTTP ne revient jamais (extension ripext qui
// n'appelle pas la callback), le verrou g_WebhookPending ne doit pas rester
// bloqué pour toujours. Un watchdog réarme le plugin après le pire cas
// (envoi initial + toutes les tentatives + backoff + marge).
void StartWebhookTimeout()
{
	if (g_hWebhookTimeout != null && IsValidHandle(g_hWebhookTimeout))
	{
		KillTimer(g_hWebhookTimeout);
	}
	g_hWebhookTimeout = null;

	int maxRetries = GetConVarInt(g_hMaxRetries);
	float delay    = GetConVarFloat(g_hDelay);

	float worstCase = float(maxRetries + 1) * delay + 30.0 * float(maxRetries * (maxRetries - 1) / 2) + 120.0;
	g_hWebhookTimeout = CreateTimer(worstCase, Timer_WebhookTimeout, _);
}

public Action Timer_WebhookTimeout(Handle timer)
{
	g_hWebhookTimeout = null;

	if (g_WebhookPending)
	{
		LogError("[HLFR] Le webhook de fin de match n'a jamais abouti (callback ripext non rappelée ?). Verrou réarmé manuellement.");
		PrintToServer("[HLFR] Le webhook de fin de match n'a jamais abouti. Verrou réarmé manuellement.");
		ClearWebhookPending();
	}

	return Plugin_Stop;
}

void ClearWebhookPending()
{
	g_WebhookPending = false;

	if (g_hPendingTimer != null && IsValidHandle(g_hPendingTimer))
	{
		KillTimer(g_hPendingTimer);
	}
	g_hPendingTimer = null;

	if (g_hWebhookTimeout != null && IsValidHandle(g_hWebhookTimeout))
	{
		KillTimer(g_hWebhookTimeout);
	}
	g_hWebhookTimeout = null;
}

public Action Timer_FireWebhook(Handle timer)
{
	g_hPendingTimer = null;
	SendWebhook();
	return Plugin_Stop;
}

public Action Timer_Retry(Handle timer)
{
	g_hPendingTimer = null;
	SendWebhook();
	return Plugin_Stop;
}

void SendWebhook()
{
	char url[512], token[256], server[256];
	GetConVarString(g_hWebhookUrl, url, sizeof(url));
	GetConVarString(g_hToken, token, sizeof(token));
	GetConVarString(g_hServerName, server, sizeof(server));

	if (server[0] == '\0' && g_hHostname != null)
	{
		GetConVarString(g_hHostname, server, sizeof(server));
	}

	if (url[0] == '\0' || token[0] == '\0')
	{
		LogError("[HLFR] Webhook non envoyé : hlfr_webhook_url ou hlfr_webhook_token vide.");
		PrintToServer("[HLFR] Webhook non envoyé : hlfr_webhook_url ou hlfr_webhook_token vide.");
		ClearWebhookPending();
		return;
	}

	int attempt = GetConVarInt(g_hMaxRetries) - g_RetriesLeft + 1;
	LogMessage("[HLFR] Envoi du webhook (tentative %d) vers %s (server=%s, map=%s).", attempt, url, server, g_LastMap);
	PrintToServer("[HLFR] Envoi du webhook vers %s (server=%s, map=%s).", url, server, g_LastMap);

	HTTPRequest request = new HTTPRequest(url);
	request.SetHeader("User-Agent", "hlfr_match_log");

	JSONObject body = new JSONObject();
	body.SetString("token", token);
	body.SetString("server", server);
	body.SetString("map", g_LastMap);
	body.SetString("source", "hlfr_match_log");

	// Post prend possession de `body` : ne pas faire delete.
	request.Post(body, Callback_Webhook);
}

void Callback_Webhook(HTTPResponse response, int value)
{
	int status = view_as<int>(response.Status);

	if (status >= 200 && status <= 299)
	{
		int processed = -1;
		char contentType[64];
		if (response.GetHeader("Content-Type", contentType, sizeof(contentType))
			&& StrContains(contentType, "json") != -1)
		{
			JSON data = response.Data;
			if (data != null)
			{
				JSONObject obj = view_as<JSONObject>(data);
				if (obj.HasKey("processed_logs"))
				{
					processed = obj.GetInt("processed_logs");
				}
			}
		}

		if (processed == 1)
		{
			PrintToServer("[HLFR] Webhook de fin de match accepté (HTTP %d) - 1 nouveau log traité.", status);
			LogMessage("Webhook fin de match envoyé avec succès (HTTP %d) - 1 nouveau log traité.", status);
		}
		else if (processed >= 0)
		{
			PrintToServer("[HLFR] Webhook de fin de match accepté (HTTP %d) - %d nouveaux logs traités.", status, processed);
			LogMessage("Webhook fin de match envoyé avec succès (HTTP %d) - %d nouveaux logs traités.", status, processed);
		}
		else
		{
			PrintToServer("[HLFR] Webhook de fin de match accepté (HTTP %d).", status);
			LogMessage("Webhook fin de match envoyé avec succès (HTTP %d).", status);
		}

		ClearWebhookPending();
		return;
	}

	// Hints de diagnostic pour les codes fréquents.
	if (status == 0)
	{
		PrintToServer("[HLFR] Webhook impossible : serveur injoignable ou erreur TLS (HTTP 0). Vérifiez que le site est accessible depuis ce serveur.");
	}
	else if (status == 403)
	{
		PrintToServer("[HLFR] Webhook refusé (HTTP 403) : token incorrect ou IP non autorisée.");
	}
	else if (status == 404)
	{
		PrintToServer("[HLFR] Webhook refusé (HTTP 404) : mauvaise URL hlfr_webhook_url.");
	}
	else if (status >= 500)
	{
		PrintToServer("[HLFR] Webhook refusé (HTTP %d) : erreur côté site, sera réessayé.", status);
	}
	else
	{
		PrintToServer("[HLFR] Webhook refusé (HTTP %d).", status);
	}

	LogMessage("[HLFR] Webhook HTTP %d, tentatives restantes : %d.", status, g_RetriesLeft);

	g_RetriesLeft--;

	if (g_RetriesLeft > 0)
	{
		float backoff = GetConVarFloat(g_hDelay) + float(GetConVarInt(g_hMaxRetries) - g_RetriesLeft) * 30.0;
		PrintToServer("[HLFR] Nouvel essai dans %.0f s (%d restant%s).", backoff, g_RetriesLeft, g_RetriesLeft > 1 ? "s" : "");
		g_hPendingTimer = CreateTimer(backoff, Timer_Retry, _);
		return;
	}

	LogError("[HLFR] Webhook de fin de match définitivement refusé (HTTP %d).", status);
	PrintToServer("[HLFR] Webhook de fin de match définitivement refusé (HTTP %d).", status);
	ClearWebhookPending();
}
