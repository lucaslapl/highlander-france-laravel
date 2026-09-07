<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guides SEO + FAQ (Markdown étendu, éditables via le panel admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guides', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug', 128)->unique()->comment('Slug URL, ex: debuter-tf2-competitif');
            $table->string('title', 191);
            $table->string('meta_description', 300);
            $table->enum('category', ['debuter', 'highlander', 'classes', '6v6', 'config'])->comment('Pilier SEO');
            $table->text('excerpt')->nullable();
            $table->longText('content_markdown');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(1);
            $table->timestamps();
        });

        Schema::create('faq_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->enum('cluster', ['decouverte', 'highlander', 'recrutement', 'apprentissage', 'competition']);
            $table->string('question', 300);
            $table->text('answer_markdown');
            $table->string('keywords', 300)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(1);
            $table->timestamps();
        });

        $now = now();

        DB::table('guides')->insert([
            [
                'slug' => 'debuter-tf2-competitif',
                'title' => 'Débuter en TF2 compétitif : guide complet',
                'meta_description' => 'Débuter en TF2 compétitif : formats 6v6 et Highlander 9v9, ligue ETF2L, config, premiers matchs et erreurs à éviter.',
                'category' => 'debuter',
                'excerpt' => 'Le point de départ : ce qu’est le compétitif TF2, où jouer, comment configurer son jeu et rejoindre une équipe.',
                'content_markdown' => <<<'MD'
# Débuter en TF2 compétitif

Tu viens des serveurs pubs Valve (12v12, sans communication) et tu veux passer au **TF2 compétitif** ? Ce guide résume l’essentiel : formats, ligue ETF2L, configuration et premiers pas.

:::info
**Pub vs match officiel :** en compétitif on joue 6v6 ou 9v9, avec des rôles définis, une communication vocale constante et un objectif d’équipe. Les kills sont un moyen, pas une fin.
:::

## Les formats compétitifs

| Format | Joueurs | Maps | Idéal pour |
|---|---|---|---|
| Highlander 9v9 | 9v9, une de chaque classe | Payload, KOTH, CP | Découvrir tous les rôles, jouer avec 8 coéquipiers |
| 6v6 | Medic, Demo, 2 Soldiers, 2 Scouts | 5CP, KOTH | Jeu rapide et exigeant mécaniquement |
| 4v4, 7v7 Prolander, Ultiduo, MGE | Variable | KOTH, arènes | S’entraîner, progresser en visée |

Le 6v6 et le **Highlander 9v9** sont les deux formats principaux. Le 7v7 Prolander monte en popularité, le MGE (1v1) sert à travailler son aim.

## Où jouer : ETF2L et les lobbies

- **ETF2L** (European Team Fortress 2 League, gratuite, active depuis 2007) : saisons Highlander et 6v6, divisions Freshmeat (zéro prérequis) → Open → Low → Mid → Division 2 → Division 1 → Premiership.
- **TF2Center** : matchs non officiels pour s’entraîner, très utile pour débuter.
- **tf2pickup.fr** : réservé aux francophones, niveau plus élevé, meilleures conditions.
- **serveme.tf** : réserver un serveur 18 slots gratuit pour scrim.
- **Discord Highlander France** : mix francophones, maptalks, demoreviews, coaching.

:::conseil
Avant ton premier match, regarde 2 ou 3 matchs de ta division (replays ETF2L ou streams). Tu comprendras le rythme, les calls et les timings d’uber.
:::

## Configurer son jeu

1. Installe **mastercomfig** (config graphique + réseau optimisée) et choisis un HUD lisible (HP, munitions, uber).
2. Paramètre tes hitsounds et tes binds (calls rapides, medic, uber %).
3. Installe **Peach-REC** : enregistrer chaque match officiel est **obligatoire**, les ligues peuvent exiger tes démos.

## Tes premiers matchs : les 4 piliers

- **Communiquer** : calls courts et utiles (« Medic low », « Spy sur toi Heavy », « Hold le point »). Écouter avant de parler.
- **Écouter l’IGL** : le maincaller donne la direction, suis-la, ne joue pas hors script.
- **Respecter ton rôle** : tu joues un rôle, pas une classe. Tiens ta position.
- **Jouer avec discipline** : mourir utile vaut mieux que survivre inutilement, respecte les timings de push, ne tilt pas.

:::danger
Ne change pas de classe toutes les semaines. Maîtrise-en une (Heavy, Pyro ou Engineer pour débuter), trouve un mentor, regarde tes démos après chaque match.
:::

## Continuer

- [Highlander 9v9 : règles et structure d’équipe](/guides/highlander-9v9)
- [Les 9 classes en détail](/guides/classes-highlander)
- [Le 6v6 pour les amateurs](/guides/6v6-debutant)
- [FAQ compétitif TF2](/faq)
MD,
                'sort_order' => 1,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'highlander-9v9',
                'title' => 'Highlander TF2 9v9 : règles et structure d’équipe',
                'meta_description' => 'Highlander TF2 9v9 : règle one-of-each, combo, flank, pick classes, uber et objectifs d’équipe. Guide ETF2L.',
                'category' => 'highlander',
                'excerpt' => '9 joueurs, 9 classes uniques : combo, flank, uber et contrôle de carte.',
                'content_markdown' => <<<'MD'
# Highlander TF2 9v9 : règles et structure d’équipe

Le **Highlander** (« There can be only one ») oppose deux équipes de **9 joueurs avec une et une seule fois chaque classe**.

## Le combo : le cœur de l’équipe

:::combo
**Medic** (uber, survie à tout prix) · **Demoman** (DPS et zone control) · **Heavy** (tank + DPS) · **Pyro** (airblast, protection Medic) · **Engineer** (sentry, dispenser, télés) · **Sniper** (pick class à distance).
Le combo tient la position, protège le Medic et push avec l’uber.
:::

Discipline de positionnement : un Heavy seul sans Medic ou un Demo isolé fragilise tout le combo.

## Le flank : pression et espace

:::flank
**Soldier** (rocket jumps, pression) · **Scout** (vitesse x2, cap, info) · **Spy** (infiltration, backstabs).
Le flank crée du chaos, cap les objectifs (spycap, x2 scout) et synchronise ses sacs avec le combo (ex : Soldier + Spy sur le Medic adverse).
:::

Sniper et Spy sont les **pick classes** : un pick Medic inverse le tempo du match.

## L’uber, ressource centrale

Construire vite, annoncer le % et les add/disadd, pop au bon timing pour prendre une zone ou contrer l’uber adverse. **Medic mort = fight probablement perdu.** Toute l’équipe protège le Medic, pas seulement le Pyro.

## Objectifs et communication

Protéger le Medic, construire l’uber, contrôler high ground et chokes, gagner les fights coordonnés, créer des picks. Calls courts : « Medic low », « Spy check », « Hold le point ». Surveille le match HUD et les respawn waves (5 à 10 s selon classe et carte).

## Continuer

- [Les 9 classes en détail](/guides/classes-highlander)
- [Débuter en compétitif](/guides/debuter-tf2-competitif)
MD,
                'sort_order' => 2,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'classes-highlander',
                'title' => 'Les 9 classes Highlander : rôles, erreurs et quelle classe choisir',
                'meta_description' => 'Les 9 classes Highlander en détail : rôle, priorités, synergies, erreurs fréquentes et quelle classe choisir quand on débute.',
                'category' => 'classes',
                'excerpt' => 'Medic, Demo, Heavy, Pyro, Engineer, Sniper, Soldier, Scout, Spy : priorités et erreurs par classe.',
                'content_markdown' => <<<'MD'
# Les 9 classes Highlander

Chaque classe a un rôle précis au service de l’équipe. Évite la superposition de compétences : exploite ce que ta classe est la seule à faire (ex : <span class="hl-blue">Pyro Detonator</span> plutôt qu’un troisième shotgun).

## Le combo

:::combo
**Medic** — Rester en vie, construire l’uber, annoncer le %, pop au bon timing. Ordre de heal : Demo > Pyro > Scout > Sniper > Heavy. Erreur : se placer trop en avant sans annoncer.
:::

:::combo
**Demoman** — Spam stickies sur les chokes, annonce les push, reste proche du Medic. Erreur : spam aveugle puis 0 ammo, overextend puni par le Sniper.
:::

:::combo
**Heavy** — Tank entre le Medic et la menace, rev-up avant le pick, donne son sandwich. Erreur : charger solo, rester sur une ligne sniper.
:::

:::combo
**Pyro** — Reflect permanent, spycheck, Detonator pour harceler à distance. Erreur : flammes offensives pendant que le Medic meurt.
:::

:::combo
**Engineer** — Sentry anticipée (+30 s), dispenser lvl 2-3, télés pour les respawns. Erreur : coller à sa sentry sans spycheck.
:::

:::combo
**Sniper (pick class)** — Priorité Medic puis Heavy/Demo/Engineer, changer d’angle après chaque pick, annoncer « Medic down ». Erreur : rester scope sur la même ligne.
:::

## Le flank

:::flank
**Soldier** — Pression flank, jumps annoncés, abuse des packs de vie, Battalion’s Backup ou Conch sur Payload. Erreur : jump solo sur le combo sans support.
:::

:::flank
**Scout** — Info, harcèlement, cap x2, aide Demo en priorité. Erreur : solo engage sur le combo, jouer pour ses stats.
:::

:::flank
**Spy (pick class)** — Info (position sniper, % uber) puis picks Medic/Sniper/Demo dans le chaos, revolver sous-estimé. Erreur : backstab en fight ouvert où tout le monde regarde.
:::

## Quelle classe quand on débute ?

| Classe | Difficulté | Débutant ? | Conseil |
|---|---|---|---|
| Heavy | Accessible | Oui | Reste avec le combo |
| Pyro | Accessible | Oui | Focus airblast, protège le Medic |
| Engineer | Accessible | Oui | Sentry utile + dispenser/télés |
| Scout | Intermédiaire | Moyen | Joue l’objectif x2, reste en vie |
| Soldier | Intermédiaire | Moyen | Rocket jumps basiques, annonce tes sacs |
| Sniper | Avancé | Moyen | Ton impact = ta visée, entraîne-toi |
| Medic | Intermédiaire | Non* | Trop central, commence par une autre classe |
| Demoman | Intermédiaire | Non* | Apprends les maps et les stickies |
| Spy | Avancé | Non* | Maîtrise d’abord le rythme HL |

*Medic, Demo et Spy demandent une maturité de jeu qui vient en jouant d’abord Heavy/Pyro/Engineer. Choisis selon ton style **et** les besoins de l’équipe : une équipe équilibrée bat 9 solo-players.
MD,
                'sort_order' => 3,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => '6v6-debutant',
                'title' => '6v6 TF2 : guide pour les amateurs',
                'meta_description' => '6v6 TF2 : combo, flank, rollouts, mainclass et offclass, crit-heal et vocabulaire trial, scrim, sac.',
                'category' => '6v6',
                'excerpt' => 'Medic, Demo, Pocket, Roamer : le 6v6 expliqué simplement.',
                'content_markdown' => <<<'MD'
# Le 6v6 pour les amateurs

Le **6v6** est le format le plus joué : 5CP et KOTH, 30 minutes max en 5CP (gagné avec 5 manches d’avance), premier à 3 caps de 3 minutes en KOTH.

## Combo et flank

- **Combo (4)** : Medic, Demoman, Soldier Pocket, Scout Pocket. Contrôle la carte et le rythme.
- **Flank (2)** : Soldier Roamer, Scout Flank. Contrôle l’opposé du combo, passe dans le dos, offclass en last ou stalemate (Sniper, Heavy, Pyro, Engineer).

## Vocabulaire

**Mainclass** (tes classes fortes) vs **offclass** (les autres). **Trial** (période d’essai), **scrim** (entraînement), **offi** (match de ligue). **Sub** (remplaçant de l’équipe), **merc** (remplaçant extérieur). **Rollout** (trajet d’arrivée au middle le plus vite possible avec le plus de vie). **Callouts** (noms de zones), **focus** (cible à tuer ensemble), **sac/sacwave** (sacrifice coordonné sur le Medic ennemi quand le tien est mort).

:::conseil
**Crit-heal :** sans dégât pendant 10 s, un joueur est soigné à 24 HP/s, 72 HP/s après 15 s. L’overheal ne prend qu’une ou deux secondes : prépare-le avant chaque fight.
:::

## Les classes en bref

| Classe | Rôle | Armes types |
|---|---|---|
| Scout Pocket | Protège le Medic, build l’uber au Boston Basher | Scattergun, Pistolet, Boston Basher |
| Scout Flank | Contrôle sans le combo, offclass | Scattergun, Pistolet, Boston Basher |
| Soldier Pocket | Protège le Medic, crée de l’espace sans voler le heal | Roquette, Gunboats, Escape Plan |
| Soldier Roamer | Passe derrière, force l’uber adverse | Roquette, Gunboats, Escape Plan |
| Demoman | DPS de zone, traps dissuasives et cachées | Iron Bomber, Stickybomb |
| Medic | Contrôle le rythme via l’uber, ordre de heal précis au rollout | Arbalète, Medigun/Kritz, Übersaw |

## Continuer

- [Débuter en compétitif](/guides/debuter-tf2-competitif)
- [Highlander 9v9](/guides/highlander-9v9)
MD,
                'sort_order' => 4,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'config-optimiser-tf2',
                'title' => 'Config TF2 compétitif : FPS, HUD et enregistrement démo',
                'meta_description' => 'Optimiser TF2 pour le compétitif : mastercomfig, HUD, hitsounds et enregistrement obligatoire des matchs (Peach-REC).',
                'category' => 'config',
                'excerpt' => 'Un jeu fluide, un HUD lisible et des démos enregistrées : la base compète.',
                'content_markdown' => <<<'MD'
# Config TF2 compétitif

## Graphismes et réseau

Installe **mastercomfig** (préréglages FPS + réseau), choisis un HUD compétitif (HP, munitions, uber et objectif lisibles) et des hitsounds à jour. Alternative avancée : **cfg.tf** pour régler chaque paramètre ou reprendre la config d’un joueur.

## Enregistrer ses matchs (obligatoire)

:::danger
Les ligues exigent l’enregistrement de chaque match officiel. Sans démo : sanction, suspension ou bannissement en cas de contrôle ou de suspicion de cheat.
:::

Utilise l’enregistrement intégré ou **Peach-REC** (démarrage automatique en match). Garde tes STV et tes POV pour revoir tes erreurs à froid.

## Serveurs et entraînement

- **serveme.tf** : réserve un serveur 18 slots gratuit pour scrim ou MGE.
- **MGE** : 1v1 pour la visée. **DM** : visée + placement.
- Revois chaque démo : positionnement, timings d’uber, communication.

## Continuer

- [Débuter en compétitif](/guides/debuter-tf2-competitif)
- [FAQ](/faq)
MD,
                'sort_order' => 5,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $faq = [
            'decouverte' => [
                ['C’est quoi le TF2 compétitif ?', 'Le **TF2 compétitif** oppose deux équipes organisées (6v6 ou Highlander 9v9) avec rôles, communication vocale et matchs officiels, contrairement aux pubs Valve 12v12 sans structure. On y joue via des ligues comme l’ETF2L, pas via le matchmaking intégré.', 'tf2 competitif, team fortress 2 competitif'],
                ['Comment jouer à TF2 en compétitif quand on débute ?', 'Commence par lire le [guide débuter](/guides/debuter-tf2-competitif), configure ton jeu, joue des lobbies TF2Center, puis rejoins le Discord Highlander France pour des mix et du coaching. Inscris ensuite une équipe en ETF2L Freshmeat (zéro prérequis).', 'comment jouer a tf2 en competitif'],
                ['Faut-il un gros niveau pour commencer ?', 'Non. Les équipes Open et Freshmeat cherchent des joueurs fiables qui écoutent et communiquent, pas des génies de l’aim. Chaque joueur Premiership a commencé en Open.', 'competitif tf2 debutant'],
            ],
            'highlander' => [
                ['C’est quoi le Highlander TF2 9v9 ?', 'Le **Highlander 9v9** oppose 9 joueurs contre 9 avec **une et une seule fois chaque classe** (« There can be only one »). Voir le [guide Highlander](/guides/highlander-9v9).', 'tf2 highlander, highlander tf2, tf2 9v9, highlander 9v9'],
                ['Quelles sont les règles du Highlander ?', '9v9, une classe par joueur (échange possible entre deux joueurs), armes et maps fixées par la ligue, matchs Payload, KOTH, CP et 5CP. Détail dans le [guide Highlander](/guides/highlander-9v9).', 'regles highlander tf2'],
                ['Quelles sont les classes en Highlander ?', 'Medic, Demoman, Heavy, Pyro, Engineer, Sniper (combo + pick), Soldier, Scout, Spy (flank + pick). Rôles et erreurs dans le [guide des 9 classes](/guides/classes-highlander).', 'classes highlander'],
                ['C’est quoi le combo et le flank ?', 'Le **combo** (Medic, Demo, Heavy, Pyro, Engineer, Sniper) tient la position et push avec l’uber. Le **flank** (Soldier, Scout, Spy) crée de la pression sur les côtés et cap les objectifs.', 'combo flank highlander'],
            ],
            'recrutement' => [
                ['Comment trouver une équipe TF2 ?', 'Poste ton profil (classes, niveau, dispos) sur le Discord Highlander France et le forum ETF2L, joue des mix pour te faire connaître, puis passe des **trials** (périodes d’essai).', 'equipe tf2, trouver equipe tf2, recrutement tf2'],
                ['Comment rejoindre une équipe Highlander ?', 'Joue Scout/Soldier/Pyro/Heavy/Engineer en mix francophones, indique ta mainclass et tes dispos, puis trial en équipe Open ou Freshmeat. Les besoins en Demo, Medic et Sniper sont fréquents.', 'equipe highlander, jouer highlander'],
                ['C’est quoi trial, scrim, offi, sub et merc ?', '**Trial** = essai, **scrim** = entraînement entre équipes, **offi** = match officiel, **sub** = remplaçant de l’équipe, **merc** = remplaçant extérieur.', 'trial scrim offi tf2'],
            ],
            'apprentissage' => [
                ['Comment progresser en TF2 compétitif ?', 'Joue régulièrement, travaille ton aim en MGE/DM, revois tes démos, demande des demoreviews et des maptalks sur le Discord Highlander France, et garde une seule mainclass au début.', 'apprendre tf2 competitif, progresser tf2'],
                ['C’est quoi une demoreview et un maptalk ?', 'Une **demoreview** analyse ta POV pour corriger positionnement et décisions. Un **maptalk** explique les positions, timings et strats d’une carte. Les deux sont proposés par les mentors Highlander France.', 'demoreview tf2, maptalk tf2'],
                ['Quel guide Highlander lire en premier ?', 'Dans l’ordre : [Débuter](/guides/debuter-tf2-competitif), [Highlander 9v9](/guides/highlander-9v9), puis [les 9 classes](/guides/classes-highlander).', 'guide highlander'],
            ],
            'competition' => [
                ['C’est quoi l’ETF2L ?', 'L’**ETF2L** (European Team Fortress 2 League, depuis 2007, gratuite) organise les saisons 6v6 et Highlander en Europe, avec divisions Freshmeat → Open → Low → Mid → D2 → D1 → Premiership, cups et replays.', 'etf2l'],
                ['Quelles ligues et tournois pour les Français ?', 'L’ETF2L pour les saisons sérieuses, les cups ETF2L et les tournois communautaires pour le reste. Il n’existe plus de ligue française : les FR jouent en Europe, avec suivi des équipes FR sur ce site.', 'ligue tf2, tournoi tf2, competition tf2 france, tf2 france competitif'],
                ['Sur quelles maps joue-t-on en Highlander ?', 'Payload (Upward, Vigil), KOTH (Product, Ashville, Warmtic, Proot), CP (Steel). La liste exacte change chaque saison ETF2L.', 'maps highlander tf2'],
            ],
        ];

        $order = 1;
        foreach ($faq as $cluster => $items) {
            foreach ($items as [$q, $a, $kw]) {
                DB::table('faq_items')->insert([
                    'cluster' => $cluster,
                    'question' => $q,
                    'answer_markdown' => $a,
                    'keywords' => $kw,
                    'sort_order' => $order++,
                    'is_published' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('guides');
    }
};
