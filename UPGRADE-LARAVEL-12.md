# Migration Themosis 4.x (Laravel 10) → Laravel 12

Fichier de suivi de la migration. Les étapes sont cochées au fur et à mesure.

- **Branche de travail :** `feature/laravel-12`, créée depuis `origin/4.0` (tag `4.12`, commit `979ff95a`)
- **Cible :** `illuminate/*` `^12.68` (dernière version au 2026-10-22 : 12.70.1), PHP `^8.2`
- **Composer :** installé en local dans `/home/lolivier/Sites/Thomson_Fork/composer.phar`
  (à lancer avec `php ../composer.phar …` depuis `framework/`)
- **Tests :** `php ../composer.phar test` (PHPUnit)

## Constats initiaux (analyse de la 4.12)

- Themosis réimplémente une grande partie de `Illuminate\Foundation` :
  - `Core/Application.php`, les Kernels HTTP et Console, `Exceptions\Handler`, `Bootstrap/*` ;
  - `helpers.php`, une cinquantaine de commandes artisan et leurs stubs.

  Ce sont les zones les plus exposées.
- Le code étend aussi `Router`, `Route`, `RouteCollection`, `FileViewFinder` et `ViewServiceProvider` d'Illuminate.
- Bugs latents déjà présents en 4.x, sous Laravel 10 :
  - `Illuminate\Queue\SerializableClosure` (dans `helpers.php` et `Bus/PendingChain.php`) n'existe plus depuis Laravel 9.
  - `Illuminate\Foundation\Routing\Precognition*` et `Illuminate\Foundation\Exceptions\ReportableHandler`
    sont référencés, alors que `laravel/framework` n'est pas requis.
  - `Application::VERSION = '8.1.0'` : la valeur renvoyée par `app()->version()` est fausse.

## Étape 0 : préparation et état de référence

- [x] Créer la branche `feature/laravel-12` depuis `origin/4.0`
- [x] Installer Composer en local (`composer.phar`)
- [x] `composer install` sur la 4.12 telle quelle (`illuminate/*` v10.49.0)
- [x] Lancer `phpunit` et noter l'état de référence (tests en échec avant migration)
  - Résultat : **202 tests, 605 assertions, 34 erreurs** (PHP 8.3.6)
  - 32 dans `RoutesTest` : `Target [Illuminate\Routing\Contracts\CallableDispatcher] is not instantiable`
    (binding absent dans l'environnement de test, bug déjà présent avant la migration)
  - 1 dans `Forms\FormCreationTest` : `Class "NumberFormatter" not found` (extension PHP `intl` absente de la machine)
  - 1 dans `Core\ExceptionHandlerTest` : un mock renvoie `null` pour `getClientMimeType(): string`
    (incompatibilité PHPUnit, déjà présente)
- [x] Brancher le projet de test local sur le fork (dépôt Composer de type `path`) : sharingbox.com, voir « Recette sharingbox.com »

## Étape 1 : passage à Laravel 11 (palier intermédiaire)

> Décision : sur ce palier, on corrige seulement ce qui casse (erreurs fatales, classes absentes).
> La resynchronisation complète des fichiers copiés de `Illuminate\Foundation` (Application, Kernels,
> Handler, commandes, Bus…) se fait directement contre la **v12** à l'étape 2, pour éviter de la faire deux fois.

- [x] `composer.json` : PHP `^8.2`, `illuminate/*` `^11.0`, mise à jour des dépendances tierces
      (`symfony/property-access` ^7, Carbon 2/3, tinker ^2.10, ui ^4.5, cron-expression ^3.4, whoops, guzzle ^7.8.2,
      twig ^3.14, `laravel/serializable-closure` ajouté, dev : `laravel-dump-server` ^2.0, `var-dumper` ^7)
  - Note : Composer installe `illuminate/mail 11.x-dev`, car toutes les 11.x stables sont visées par le
    CVE-2026-48019 (corrigé en 12.60+). Acceptable pour ce palier intermédiaire.
  - Tests : même état qu'en 4.12 (34 erreurs)
- [x] Corriger les tests déjà cassés avant la migration :
  - `RoutesTest` : enregistrement de `CallableDispatcher` dans le conteneur de test (32 tests de nouveau verts)
  - `ExceptionHandlerTest::testValidateFileMethod` : le mock `UploadedFile` renvoie des types valides
  - Reste `FormCreationTest` (`NumberFormatter`) : il faut l'extension `intl`. Le framework l'utilise
    (`NumberToLocalizedStringTransformer`) sans la déclarer → **décidé : `ext-intl` ajouté dans `require` (étape 3)**
- [x] Outils d'analyse mis en place (dans le scratchpad, non versionnés) :
  - chargement de chaque classe de `src/` dans un processus séparé, pour repérer les erreurs fatales de signature ;
  - vérification que chaque classe importée par `use` existe ;
  - PHPStan niveau 2 avec les stubs WordPress (`szepeviktor/phpstan-wordpress` ajouté en dev,
    `phpstan.neon.dist` corrigé pour PHPStan 1.x).
- [x] Corriger les cassures réelles détectées :
  - [x] `Console/ClosureCommand::execute()` renvoie `int` et gère `ManuallyFailedException` ; ajout de `purpose()` (comme en v12)
  - [x] `Auth/Console/AuthMakeCommand` : le trait `DetectsApplicationNamespace` est remplacé par `$this->laravel->getNamespace()`
  - [x] `SerializableClosure` (`helpers.php`, `Bus/PendingChain.php`) → `Laravel\SerializableClosure\SerializableClosure` ;
        imports `CallQueuedClosure` et `Gate` ajoutés dans `helpers.php`
  - [x] `helpers.php` : `dispatch()` passe par `PendingClosureDispatch` / `CallQueuedClosure::create()` ;
        `report()` accepte une chaîne ; `rescue()` accepte un callable pour `$report` ;
        nouveaux helpers : `lang_path`, `report_if`, `report_unless`, `to_route`, `to_action`, `context`, `defer`
  - [x] `FatalThrowableError` (Symfony 4) supprimé : `Http/Kernel` intercepte et transmet directement le `Throwable`
  - [x] Précognition : les dispatchers sont copiés dans `src/Core/Routing/` (`Themosis\Core\Routing\Precognition*Dispatcher`)
  - [x] `Exceptions/Handler` : les docblocks pointent vers `Themosis\Core\Exceptions\ReportableHandler` ;
        `AuthenticationException::redirectTo($request)` (argument obligatoire depuis la v11)
  - [x] `Console/VendorPublishCommand` : passage à Flysystem 3 (`LocalFilesystemAdapter`, `fileExists`/`write`), repris de la v12
  - [x] `Providers/ArtisanServiceProvider` : les `*TableCommand` d'Illuminate ne reçoivent plus que `Filesystem` ;
        suppression des 8 méthodes `registerMigrate*Command` mortes (jamais appelées, déjà cassées : les commandes
        de migration viennent de `Illuminate\Database\MigrationServiceProvider`)
  - [x] Twig 3 : l'alias de conteneur `twig` pointe vers `\Twig\Environment` (il pointait vers `\Twig_Environment`, qui n'existe plus) ; docblocks corrigés
  - [ ] *Hors périmètre, laissé tel quel :* `Field/Factory::date()` référence `Themosis\Field\Fields\DateField`,
        supprimé dans le commit `93d5c8cc` (indépendant de Laravel)
- [x] Lancer Rector (`rector-laravel`, set `LARAVEL_110`) → fait à l'étape 3 avec `UP_TO_LARAVEL_120`
- [x] `Core/Application.php` : la classe se charge et respecte le contrat v11 (alignement complet à l'étape 2)
- [x] Kernels HTTP et Console, `Exceptions\Handler`, `Bootstrap/*` : plus de cassure bloquante (resynchronisation à l'étape 2)
- [x] `Route/Router`, `Route`, `RouteCollection` : signatures compatibles, les 32 tests de routing passent
- [x] `ArtisanServiceProvider` : constructeurs corrigés (commandes `make:*` et stubs : étape 2)
- [x] `helpers.php`, `Bus/*` : `SerializableClosure` corrigé, helpers clés resynchronisés
- [x] Précognition et `ReportableHandler` : plus aucune dépendance à `Illuminate\Foundation`
- [x] Tests PHPUnit verts : 202 tests, 1 erreur restante (`NumberFormatter`, extension `intl` absente, environnement)
- [x] Contrôles statiques : aucune classe en erreur fatale, aucun import cassé, PHPStan passe de 136 à 102 remarques (sans nouvelle remarque)
- [x] Recette sur le projet de test → faite directement sur la v12 (sharingbox.com, voir étape 2)

## Étape 2 : passage à Laravel 12 (`^12.68`)

- [x] `composer.json` : `illuminate/*` `^12.68` (12.70.1 installée), `nesbot/carbon` `^3.8.4` ; `composer audit` sans alerte
- [x] Tests après la montée de version : 5 tests `ApplicationTest` (services différés) en échec, `Class "doe" not found`
  - Cause : `AliasLoaderTest` laissait un autoloader factice actif. Le Container 12 appelle `class_exists()`
    sur les clés qu'il résout (bindings par attributs), ce qui déclenche l'alias `foo → doe`.
  - Correction : `tearDown()` dans `AliasLoaderTest` qui retire le loader. Report de l'écriture atomique de la façade v12 dans `AliasLoader`.
- [x] Resynchronisation de `Core/Application.php` avec la v12 :
  - **`ContextServiceProvider` enregistré dans les providers de base.** Critique : sans lui, le `LogManager` v11+
    ne résout pas `ContextLogProcessor` et **tous les canaux de log basculent en silence sur l'emergency logger**.
  - `resolve()` charge aussi les providers différés, comme Laravel. Avant, seul `make()` le faisait, donc une
    dépendance différée injectée dans un constructeur n'était pas trouvée.
  - `registered()` et ses callbacks ; providers indexés par classe ; `isDeferredService()`, `removeDeferredServices()` ;
    `configurationIsCached()`, `routesAreCached()` et `eventsAreCached()` mémorisent leur résultat ;
    `LocaleUpdated` transporte `previousLocale`.
  - `version()` renvoie la version installée de `illuminate/support` (via `Composer\InstalledVersions`).
    Avant, elle renvoyait `'8.1.0'`. `VERSION` vaut la version minimale supportée, `12.68.0`.
  - Tests ajoutés : version, services Context, injection de dépendance différée, callbacks `registered`.
- [x] `helpers.php`, compléments v12 : `now()` et `today()` acceptent un enum (`enum_value`) ; ajout de `broadcast_if`,
      `broadcast_unless` et `precognitive`. `uri()` n'est pas porté (il dépend de `league/uri`, non installé).
      Nouveau `tests/Core/HelpersTest.php`.
- [x] `Bus/*` : `PendingChain`, `PendingDispatch`, `Dispatchable` et `PendingClosureDispatch` repris de la v12
      (`prepend`/`append`, `dispatchIf`, `onGroup`, jobs uniques…). Ajout de `Core/Queue/InteractsWithUniqueJobs`.
      `DispatchesJobs` garde `dispatchNow()` et gagne `dispatchSync()`.
- [x] **Compatibilité `Illuminate\Foundation` (bugs déjà présents en 4.x).** Plusieurs paquets `illuminate/*` utilisent
      directement des classes de `laravel/framework`, absentes ici :
  - `Illuminate\Queue\CallQueuedClosure` → `Foundation\Bus\Dispatchable` : `dispatch(function () {…})` plantait (erreur fatale) ;
  - `Illuminate\Bus\Dispatcher::chain()` et `ChainedBatch` → `Foundation\Bus\PendingChain` / `Dispatchable` : `Bus::chain()` plantait ;
  - `Illuminate\Broadcasting\AnonymousEvent` → `Foundation\Events\Dispatchable` ;
  - `Illuminate\Validation\ValidatesWhenResolvedTrait` → `Foundation\Precognition` (requêtes de précognition).

  Correction : un autoloader `Themosis\Core\Support\FoundationAliases`, enregistré via `src/Core/aliases.php`
  (section `autoload.files` de `composer.json`). Il crée un alias de ces classes vers leurs équivalents Themosis,
  seulement si `laravel/framework` n'est pas installé. Ajout de `Themosis\Core\Precognition`.
  - Pas couvert : `@vite` (Blade) → `Illuminate\Foundation\Vite`. Themosis utilise `mix()`. **Décidé : non porté.**
  - `laravel/tinker` teste `instanceof Illuminate\Foundation\Application`. Sans effet, à part la publication de sa config.
- [x] Resynchronisation des autres copies de `Illuminate\Foundation` (confiée à trois sous-agents, vérifiée ensuite :
      242 tests OK hors `intl`, aucune classe en erreur fatale, PHPStan 99 remarques sans nouvelle) :
  - [x] **Exceptions** (`Handler`, `ReportableHandler`, `Bootstrap/ExceptionHandler`)
    - Nouveautés : `ShouldntReport`, `RecordNotFoundException` → 404, throttling (`throttle`, `Limit`, `Lottery`),
      `dontReportDuplicates`, `dontReportWhen`, `buildContextUsing`, `respondUsing`, `shouldRenderJsonWhen`,
      `ignore`/`stopIgnoring` acceptant un tableau, callbacks `reportable` typés en union.
    - **Bug corrigé :** `handleException()` affichait l'erreur du logger à la place de l'erreur réelle.
    - Dépréciations : `logging.deprecations` accepte la forme tableau ; ajout de `forgetApp()` et `flushState()`.
    - ⚠️ **Changements de comportement :**
      - un Host invalide (`SuspiciousOperationException`) renvoie 400 au lieu de 404 ;
      - `BadRequestException` et `ConflictingHeadersException` ne sont plus reportées (400 au lieu de 500).
    - ⚠️ **Rupture :** `ignore()` et `stopIgnoring()` sont typés `array|string`. Une sous-classe qui les surcharge doit s'adapter.
  - [x] **Kernels, middlewares, `FormRequest`, `Bootstrap/*`**
    - **Bug corrigé :** le Kernel HTTP rapporte et rend les exceptions via le contrat `ExceptionHandler`. Avant, il ignorait le handler de l'application.
    - **Bug corrigé, Kernel console :** ajout de `setContainerCommandLoader()`. Sans lui, en illuminate 12, les commandes `#[AsCommand]`
      (la plupart des commandes illuminate et les closures) **n'étaient plus disponibles**. Les événements
      `CommandStarting` et `CommandFinished` sont désormais émis.
    - **Bug corrigé, `VerifyCsrfToken` :** le repli lisait `X-CSRF-TOKEN` au lieu de `X-XSRF-TOKEN`, donc le jeton envoyé par axios était refusé.
    - Middleware : ajout de `InvokeDeferredCallbacks` (exécute les `defer()`) et de l'alias `ValidateCsrfToken` ;
      `TrimStrings` gère Unicode et les jokers ; `ValidatePostSize` étend celui d'illuminate.
    - Kernel HTTP : API de gestion des middlewares de la v12 (`$middlewareAliases`, priorités, `prepend…`/`append…`) ;
      handlers `whenRequestLifecycleIsLongerThan`.
    - `FormRequest` : `validateResolved()` passe par `Themosis\Core\Precognition`. Ajouts : `after()`, `validationRules()`,
      `safe()`, `validated($key, $default)`, `$stopOnFirstFailure`.
    - Kernel console : `load()` réécrit comme en v12, `resolveConsoleSchedule()`, `scheduleCache()`.
    - `ConfigurationLoader` lie `config_loaded_from_cache` et appelle `resolveEnvironmentUsing()` (attributs `#[Bind]`).
    - ⚠️ **Changements de comportement :**
      - `TrimStrings` ne retire plus les espaces de `password`, `password_confirmation` et `current_password` ;
      - la priorité des middlewares est alignée sur la v12.
    - ⚠️ **Rupture :** `FormRequest::validated()` prend maintenant `($key = null, $default = null)`.
  - [x] **Commandes, stubs, providers, routing**
    - `ArtisanServiceProvider` : résolution comme en v12, avec 12 nouvelles commandes illuminate (`db:show`, `model:prune`,
      `queue:pause`, `schedule:interrupt`…) et `Signals::resolveAvailabilityUsing()`. Les 93 commandes se résolvent.
    - `CoreServiceProvider` :
      - binding scoped de `DeferredCallbackCollection`, avec exécution des `defer()` en fin de commande et de job ;
      - **bug corrigé :** macros `hasValidSignatureWhileIgnoring` et autres, sans lesquelles le middleware `signed` plantait ;
      - ajout de `validateWithBag` et de `Uri::setUrlGeneratorResolver()`.
    - **Bug corrigé, `RouteCollection` :** clé `allRoutes` en `GET|HEAD` comme en v12. Avant, les routes multi-méthodes s'écrasaient.
      ⚠️ Régénérer le cache de routes après la mise à jour.
    - **Bug corrigé, `Router` (admin) :** l'événement `Routing` est émis et `Illuminate\Routing\Route` est bindé.
    - `DiscoverEvents` : listeners `__invoke`, types union.
    - `EnvironmentDetector` accepte `--env staging`. `PackageManifest` gère `COMPOSER_VENDOR_DIR`.
    - **Bugs corrigés dans les commandes `make:*` et les stubs :**
      - `make:rule` : le stub manquait ;
      - `make:model --pivot` générait du PHP invalide ;
      - `make:test` : ajout de `--pest` et `--phpunit`, requis par `make:controller --test` ;
      - `test.stub` référençait des classes inexistantes ;
      - `stub:publish` utilisait de mauvais chemins.
    - **Bugs corrigés dans d'autres commandes :**
      - `key:generate` annonçait un faux succès ;
      - `salts:generate --show` écrivait quand même dans le `.env` ;
      - `listener:make` ne détectait jamais un fichier existant ;
      - `package:discover` et `route:list` provoquaient des TypeError sous PHP 8.
    - Tous les `make:*` génèrent du PHP valide (testé dans une application bac à sable).
    - Non porté : les fonctionnalités liées à l'`ApplicationBuilder` de L11 (`bootstrap/providers.php`, `withRouting`…),
      la nouvelle interface de `serve`, les nouveaux `make:*` (`make:class`, `make:enum`…).
- [x] Admin WordPress : `dispatchToAdmin()` appelle maintenant `$kernel->terminate()` au hook `shutdown`. Avant, en admin,
      les middlewares terminables et les `defer()` n'étaient jamais exécutés.
- [x] Points ouverts, signalés par les agents :
  - ~~`php-cs-fixer` : la config `.php-cs-fixer.dist.php` plante~~ : remplacé par Pint à l'étape 3 ;
  - ~~`Router::auth()` dépend du mixin `laravel/ui`~~ : vérifié sur sharingbox. `laravel/ui` est dans `require` et
    découvert automatiquement ; `Router::auth(['verify' => true])` crée les 12 routes attendues.
- [x] Appliquer le guide de migration 11 → 12 (et le 10 → 11 au passage) :
  - passage en revue du code du fork : Carbon 3 (`diffIn*` signés et flottants, seulement deux comparaisons à un seuil, sans effet),
    Doctrine DBAL, `HasUuids`, `Blueprint`, règle `image` (SVG), `mergeIfMissing`, `Str::is`, `DatabaseTokenRepository` :
    **rien d'utilisé dans le fork** ;
  - valeurs par défaut du Container : seul `Themosis\Route\Router` a une dépendance nullable (`?Container $container = null`),
    mais il est instancié explicitement (`new Router($app['events'], $app)`), donc non concerné ;
  - stockage `local` dans `storage/app/private` : ne concerne que les projets sans disque `local` dans `config/filesystems.php` (à documenter à l'étape 4).
- [x] Resynchronisation des dernières copies de `Illuminate\Foundation` (référence : sources `laravel/framework` v12.70.1) :
  - `Support/Providers/EventServiceProvider` : listeners, subscribers et nouveaux `$observers` enregistrés dans `register()`
    (via `booting`) comme en v11+. L'application n'a plus besoin d'appeler `parent::boot()` ; ajout de
    `addEventDiscoveryPaths()`, `setEventDiscoveryPaths()`, `disableEventDiscovery()` et de l'écoute automatique de
    `Registered` → `SendEmailVerificationNotification`. La découverte reste désactivée pour les providers des applications.
  - `Support/Providers/AuthServiceProvider` : les `$policies` sont enregistrées automatiquement au boot (appeler encore
    `registerPolicies()` reste sans effet de bord).
  - `Support/Providers/RouteServiceProvider` : `loadRoutesUsing()` et `loadCachedRoutesUsing()` statiques.
  - `ProviderRepository` : écriture atomique du manifeste (`Filesystem::replace`). `ComposerScripts` supprime aussi le cache de config.
  - `Mix` : `app.mix_hot_proxy_url`. `Events\Dispatchable` : arguments variadiques.
  - **Bug corrigé, providers différés :** `ConsoleCoreServiceProvider`, `ArtisanServiceProvider` et `ComposerServiceProvider`
    utilisaient `protected $defer = true`, ignoré depuis Laravel 5.8. Ils étaient donc chargés **à chaque requête web**
    (93 commandes artisan, migrator…). Ils implémentent maintenant `DeferrableProvider`. Sur sharingbox, on passe de 34 à 33 providers chargés d'office
    et le site, l'admin et artisan fonctionnent.
    ⚠️ Supprimer `bootstrap/cache/services.php` après la mise à jour (fait automatiquement par `ComposerScripts::postAutoloadDump`).
  - `MetaboxServiceProvider` et `PageServiceProvider` : propriété `$defer` morte retirée. Ils restent chargés d'office, comme depuis
    5.8 (les différer casserait l'injection de `MetaboxManagerInterface` et l'ajout du chemin de vues des pages).
  - Non resynchronisés, volontairement : `PreventRequestsDuringMaintenance` (Themosis utilise le `.maintenance` de WordPress),
    `ComposerScripts::prePackageUninstall` (dépend de `illuminate/concurrency`), `RegisterErrorViewPaths`, `TransformsRequest`
    et `EmailVerificationRequest` (écarts de forme uniquement).
  - Tests ajoutés : `tests/Core/Support/ProvidersTest.php` ; `ProviderRepositoryTest` adapté à `replace`.
- [x] Rector, set `LARAVEL_120` → fait à l'étape 3
- [x] Vérifier `laravel/ui` (4.6.3 : macros du Router, commandes `ui`), `laravel/tinker` (2.11.1, sur sharingbox) et
      `beyondcode/laravel-dump-server` (2.1.0 : provider et commande `dump-server` résolus sous illuminate 12)
- [x] Tests PHPUnit verts : 246 tests. En local, seule l'erreur `NumberFormatter` reste, l'extension `intl` manquant sur la machine ; elle est installée en CI
- [x] Recette sur le projet de test : sharingbox.com, voir ci-dessous
- [x] **Bug corrigé, `view:clear` / `view:cache` :** plantaient si le dossier de cache Twig (`view.twig`) n'existait pas.
      Bug antérieur (déjà présent en 4.12 / L8), révélé par la recette.
- [x] Décidé : non portés, la commande `about` et la signature `app:nom-en-kebab` de `make:command`
      (qui génère toujours `command:name`)

### Recette sharingbox.com (Themosis 3.1 / Laravel 8 → fork Laravel 12)

- **Mise en place :** branche `chore/themosis-5-laravel-12` du dépôt sharingbox (non commitée), snapshot DDEV
  `before-themosis5-l12` (`ddev snapshot restore before-themosis5-l12`), montage du fork dans le conteneur via
  `.ddev/docker-compose.themosis-fork.yaml`, dépôt Composer `path` (symlink).
  - ⚠️ Pour revenir sur `develop` : `git switch develop && ddev composer install`, sinon `vendor/` reste en Laravel 12.
- **Changements dans le projet :** `themosis/framework` → `dev-feature/laravel-12`, `jgrossi/corcel` ^5.1 → ^9.0,
  `barryvdh/laravel-ide-helper` ^2.10 → ^3.0, suppression de `fideloper/proxy` et `fruitcake/laravel-cors`,
  script Composer `Themosis\Core\ComposerScripts::postUpdate`. Résultat : illuminate 12.70.1, Carbon 3.15, laravel/ui 4.6.
  `app/Exceptions/Handler.php` : propriété `$ignition` déclarée (dépréciation PHP 8.2, antérieure à la migration).
  - Piège : `composer update` a supprimé le plugin `sbx-language-banner`, installé par Composer depuis une branche
    de fonctionnalité mais absent du `composer.json` de `develop`. Restauré à la main depuis le cache Composer.
- **Front :** 461 URL (échantillon des sitemaps Yoast, toutes langues), mêmes codes HTTP, titres et nombres de liens
  qu'avant la migration. La page d'accueil a exactement la même taille. Les 16 pages en 500 et 27 en 404 l'étaient déjà avant.
- **Admin :** 115 pages (tous les menus, listes, taxonomies, écrans d'édition et de création de chaque type de contenu) en 200,
  sans erreur PHP. Heartbeat `admin-ajax.php` OK. Enregistrement via l'API REST (Gutenberg) et via le formulaire classique
  `post.php` (offre avec champ ACF) OK, données intactes.
- **Formulaires :** les POST Gravity Forms traversent le Kernel Themosis (validation affichée). Soumission complète testée
  manuellement : notification et Zapier OK. Le projet désactive le contrôle CSRF (`$except = ['*']`).
- **Mail :** le hook `phpmailer_init` du projet s'exécute. En local, l'extension SolidWP Mail le surcharge (priorité 9999) vers Mandrill.
- **Artisan :** `--version` (12.70.1), `hello` (closure), `route:list`, `event:list`, `view:clear`, `view:cache`, `make:command`, `tinker` OK.
  Échecs propres au projet ou à l'environnement, déjà présents avant :
  - `schedule:list` : le cache par défaut est Redis, absent de DDEV (OK avec `CACHE_DRIVER=array`) ;
  - `config:cache` : `config/app.php` déclare des fonctions (`is_concept_archive()`…) sans `function_exists` ;
  - la commande planifiée `publish:future-posts` n'existe pas.
- **Logs :** aucune nouvelle erreur après la migration. Les erreurs `WP_Styles::add` (`block-style-variation-styles`) et
  `disable_feeds` existaient déjà.
- **Non testé :** queue et Themosis Form/Metabox (non utilisés par le projet).

## Étape 3 : outillage et qualité

- [x] PHPUnit 11 (`^11.5`, 11.5.57 installée) :
  - `phpunit.xml.dist` migré (schéma 11, `cacheDirectory`, `<source>`), options supprimées retirées ;
  - aucune annotation à convertir ;
  - 28 `setMethods()` → `onlyMethods()`. Les mocks de `stdClass` avec des méthodes inexistantes sont remplacés par de vrais types
    (`ServiceProvider`, une classe `ExceptionHandlerTestRequest`). Le mock de `Request` est supprimé : la classe a une méthode `method()`,
    refusée en PHPUnit 12 ;
  - `->will($this->returnValue(…))` → `->willReturn(…)` ;
  - les fixtures `AFilterClassForTest` et `AnActionClassForTest` sont exclues de la recherche de tests et chargées par `require_once` ;
  - résultat : 245 tests, sans avertissement ni dépréciation PHPUnit, seule l'erreur `NumberFormatter` reste (`intl`).
- [x] Rector 2 (`rector/rector` ^2.0, `driftingly/rector-laravel` ^2.0), `rector.php` au format `RectorConfig::configure()`,
      set `LaravelLevelSetList::UP_TO_LARAVEL_120` sur `src` et `tests` :
  - appliqué : `$request->get()` → `$request->input()` (`Request::get()` est déprécié depuis Symfony 7.4) dans `UserField`,
    `TaxonomyField`, `Metabox\Manager`, `Page` et les tests ;
  - `RenameClassRector` désactivé : il aliasait le `CacheManager` vers `Contracts\Cache\Repository` dans `Application`
    (ce qui casse le cache) et typait le validateur avec un contrat sans `valid()`, `passes()` ni `getData()` ;
  - `FormRequest` utilise désormais `Illuminate\Contracts\Validation\Validator`, comme Laravel 12.
    ⚠️ **Rupture :** une sous-classe qui surcharge `failedValidation(\Illuminate\Validation\Validator $v)` ou `setValidator()`
    avec le type concret doit passer au contrat.
- [x] PHPStan 2 (`phpstan/phpstan` ^2.1, `szepeviktor/phpstan-wordpress` ^2.0) : 115 remarques au niveau 2.
  Il s'agit surtout de faux positifs : méthodes de l'implémentation appelées sur des contrats, constantes WordPress/Themosis,
  `Composer\Script\Event`, code conditionné à la version de PHPUnit.
  - Corrigé : casse `\WP_post`, retours `null` implicites de `PluginsRepository::getPlugin()` et `ProviderRepository::loadManifest()`.
  - **Décidé : baseline.** `phpstan-baseline.neon` (111 remarques) est inclus dans `phpstan.neon.dist`. L'analyse passe,
    et toute nouvelle erreur fait échouer la CI.
  - Reste masqué par la baseline : `Field\Factory::date()` référence `DateField`, supprimée (hors périmètre, voir étape 1).
- [x] Formatage, **décidé : Laravel Pint** (`laravel/pint` ^1.24, preset `laravel`, `pint.json`) à la place de php-cs-fixer.
  `friendsofphp/php-cs-fixer` et `.php-cs-fixer.dist.php` sont supprimés (sa config ne correspondait plus au code : 228 fichiers
  à reformater, et une règle obsolète l'empêchait de se charger). `composer fix` lance Pint.
  - Reformatage en un seul passage : 194 fichiers. Les règles appliquées ne changent pas le comportement (imports, conditions yoda,
    `?Type` explicite qui supprime les dépréciations PHP 8.4, ordre des interfaces, docblocks). Les méthodes de test passent en snake_case.
  - Vérifié après reformatage : 246 tests, sharingbox (front, 115 pages d'admin, artisan).
  - Conseil : faire un commit dédié au reformatage, puis l'ajouter à `.git-blame-ignore-revs`.
- [x] `ext-intl` ajouté dans `require` (**décidé**). En local, sans l'extension : `composer … --ignore-platform-req=ext-intl`,
      ou installer `php8.3-intl`.
- [x] CI GitHub Actions `.github/workflows/tests.yml` :
  - job `tests` : PHP 8.2 / 8.3 / 8.4 × `prefer-lowest` / `prefer-stable`, avec `intl` ;
  - job `static-analysis` : `pint --test`, `rector --dry-run`, `phpstan` ;
  - il n'y avait pas de `.travis.yml`. Le job d'analyse statique résout les dépendances à chaque exécution (pas de `composer.lock` versionné) :
    une nouvelle version de PHPStan peut rendre la baseline obsolète, il suffira alors de la régénérer.
  - **Bug corrigé, `prefer-lowest` :** `spatie/enum` ^3.6 → ^3.11.1. Avant la 3.7, `Enum::values()` est typé `array`, et
    `Themosis\Core\Enums\PostStatus` (qui renvoie une `Closure`) provoquait une erreur fatale au chargement. Avant la 3.11.1,
    `jsonSerialize()` déclenche une dépréciation sous PHP 8.1+. Test ajouté : `tests/Core/Enums/PostStatusTest.php`.
  - **Bug corrigé, `prefer-lowest` :** `illuminate/collections`, `conditionable`, `contracts`, `macroable`, `pipeline` et `translation`
    sont utilisés directement mais n'étaient pas requis. Composer installait alors `illuminate/contracts` 12.0.0,
    incompatible avec `illuminate/log` 12.68 (`ContextLogProcessor` absent). Ils sont maintenant requis en `^12.68`.
    Testé localement en PHP 8.3 : `prefer-lowest` OK.

## Décisions prises (2026-10-22)

- `ext-intl` : déclaré dans `require`.
- Formatage : Laravel Pint, preset `laravel`.
- PHPStan : baseline + CI.
- Non portés : `Vite` (`@vite`), commande `about`, signature `app:nom-en-kebab` de `make:command`.

## Revue de sécurité (2026-10-22)

**Conclusion : la migration n'introduit aucune régression de sécurité identifiée et en corrige plusieurs.**
La revue a combiné une relecture du code (`git diff 4.12 -- src/`, comparé à laravel/framework 12.70.1) et des tests
dynamiques sur sharingbox en conditions de production (`APP_DEBUG=false`).

- **Dépendances :** `composer audit` ne signale aucune vulnérabilité, ni sur le fork, ni sur sharingbox (illuminate 12.70.1,
  donc hors du CVE-2026-48019 qui touche les 11.x).
- **Fuites d'informations :** une exception non HTTP rendue en production donne une page HTML générique ou `{"message": "Server Error"}`.
  Test avec un message contenant un faux secret et un chemin : rien n'est exposé. Les vues `errors::*` de Themosis et des deux sites
  n'affichent pas `$exception->getMessage()` (à proscrire dans les vues 5xx personnalisées).
- **En-têtes :** un `Host` mal formé renvoie 400. `X-Forwarded-Host` n'est pas repris dans les pages (aucun proxy de confiance configuré).
- **CSRF :** `VerifyCsrfToken` est désormais identique à Laravel 12 (`X-XSRF-TOKEN` déchiffré, `hash_equals`).
  Nouveau : `VerifyCsrfToken::except()` statique. Toute exclusion ajoutée par un plugin vaut pour toute l'application.
- **Précognition :** elle n'est active que sur les routes qui portent le middleware. `authorize()` s'exécute avant la validation,
  et l'action du contrôleur n'est jamais appelée. Sans le middleware, l'en-tête `Precognition: true` n'a aucun effet (vérifié).
- **API metabox** (`wp-api/themosis/v1/metabox`) : la chaîne est `throttle` → `WordPressAuthorize:edit_posts` → bindings.
  L'autorisation passe donc avant la résolution des modèles. Le middleware d'autorisation est inchangé depuis la 4.12 (404 sans droits).
- **Closures en queue :** elles sont signées avec `APP_KEY`, via `EncryptionServiceProvider`, chargé dans les deux sites.
  En 4.12, `dispatch(Closure)` était cassé.
- **Améliorations :**
  - priorité des middlewares Laravel 12 (cookies chiffrés avant la session, authentification avant les bindings) ;
  - le Kernel HTTP passe par le handler de l'application (`dontFlash`, rendus personnalisés) ;
  - `TrimStrings` ne touche plus aux mots de passe ;
  - `key:generate` échoue explicitement si la clé n'est pas écrite ;
  - `Request::get()` remplacé par `input()` (le corps prime sur la query string).
- **Bug corrigé, `salts:generate` :** le motif de remplacement n'acceptait que `KEY="…"` ou `KEY=…`. Or les deux sites stockent
  leurs sels en `KEY='…'` : la commande annonçait un succès **sans rien changer**, donc une rotation après compromission échouait
  en silence. De plus, un `$1` dans un sel était interprété comme référence arrière (sel tronqué, environ 7 % de chance par sel).
  La ligne entière est désormais remplacée par `preg_replace_callback`, la valeur est écrite entre guillemets simples (littérale
  pour phpdotenv) et la clé est ajoutée si elle manque. Tests : `tests/Core/Console/SaltsGenerateCommandTest.php`.
- **Faiblesses propres aux sites, inchangées par la migration :**
  - sharingbox et polylang.pro excluent toutes les URL du CSRF Laravel (`$except = ['*']`). C'est nécessaire, car WordPress,
    WooCommerce et Gravity Forms postent sur les URL des pages avec leurs propres nonces. Les routes Themosis des deux sites
    ne modifient aucune donnée, donc rien n'est exploitable aujourd'hui. Mais toute route Themosis qui modifierait des données
    devra vérifier un nonce ou un jeton elle-même ;
  - `SESSION_SECURE_COOKIE` n'est pas défini : les cookies `themosis_session` et `XSRF-TOKEN` partent sans `Secure`.
    À activer en production (HTTPS).

## Étape 4 : validation finale et publication

- [x] Recette complète sur deux projets réels : sharingbox.com (Themosis 3.1) et polylang.pro (Themosis 3.0, WooCommerce).
  - Second projet retenu : **polylang.pro** (Themosis 3.0, WooCommerce, AJAX, queue `database`, mail).
    Aucun projet de `~/Sites` n'utilise les formulaires, metabox ou pages Themosis : ils restent couverts par les tests unitaires.
  - Fait : snapshot DDEV `before-themosis5-l12` ; branche `chore/themosis-5-laravel-12` créée depuis `origin/develop`, sans branche amont ;
    modifications locales (`wp-config.php`, `.htaccess`, `.phpstorm.meta.php`) mises de côté dans
    `stash@{0}` ; `wp-config.php` et `.phpstorm.meta.php` réappliqués ; `.htaccess` laissé dans sa version `origin/develop` (bloc WP Rocket).
  - Dépendances de develop installées une fois l'authentification satispress configurée. Base WordPress mise à jour
    (`wp core update-db`, schéma 60717 → 61833), puisque develop embarque WordPress 7.1.3.
    ⚠️ Pour revenir sur l'ancienne branche avec l'ancienne base : `ddev snapshot restore before-themosis5-l12`.
  - **Changements dans le projet :**
    - `themosis/framework` → `dev-feature/laravel-12`, `jgrossi/corcel` ^9.0, `barryvdh/laravel-ide-helper` ^3.0 ;
    - suppression de `fideloper/proxy` et `fruitcake/laravel-cors`, script `Themosis\Core\ComposerScripts::postUpdate` ;
    - `symfony/http-client` → `^6.4|^7.0`, `symfony/mail-pace-mailer` → `^6.4|^7.2` (Symfony 7 exigé par `illuminate/mail` 12) ;
    - `composer/installers` : l'alias `"2.0.1 as 1.12.0"` existait seulement pour satisfaire Themosis 3.1 (`^1.9`). Il est remplacé par `^2.2`.
    Résultat : illuminate 12.70.1, Carbon 3.15, monolog 3.12, symfony/mailer 7.4. `composer audit` sans alerte, aucun plugin supprimé.
  - **Front :** 200 URL (sitemap complet, pages WooCommerce, 404, recherche, flux, wp-json). Mêmes codes HTTP, titres, liens
    et tailles (±2 %) qu'avant : 157 pages en 200 et 43 en 404, déjà en 404 avant la migration.
  - **Admin :** 120 pages, avec un relevé de référence cette fois. Mêmes codes (119 en 200 ; un 403 déjà présent sur
    `woo-variation-swatches-settings`) et mêmes titres.
    - Seul écart initial : l'écran d'édition d'une documentation, 6,6 % plus léger. Après réinstallation temporaire de Themosis 3,
      les deux versions sont identiques, au script de test de compression du cœur WordPress près. Le premier relevé était un état passager.
  - **AJAX :** les 5 actions `Ajax::listen` du projet (licences WooCommerce API Manager, téléchargements, nom de produit) sont
    enregistrées et refusent un nonce invalide (`-1`, 403), alors qu'une action inconnue renvoie `0`. Aucune action réelle déclenchée.
  - **Queue (`database`) :** une closure envoyée sur une file `recette` a été sérialisée (signée), stockée puis exécutée par
    `queue:work`. Les jobs `AcfOptionsPage` et `SendLicenceExpirationMail` se sérialisent et se désérialisent (non exécutés : ils
    modifient les produits ou envoient des mails clients). `queue:failed` liste les 2 échecs déjà présents (janvier 2025).
  - **Mail :** le transport personnalisé `Mail::extend('mail-pace')` se construit en `MailPaceApiTransport` (Symfony 7.4) quand
    `MAIL_DRIVER=mail-pace`. Un message de test passe par la chaîne Laravel via le mailer `array`. Aucun mail envoyé.
    - Le projet garde la clé `driver` (format Laravel ≤ 6) dans `config/mail.php`. Laravel 12 conserve ce mode de compatibilité :
      tous les mailers utilisent alors la config globale. En local, sans `MAIL_DRIVER`, tout part en SMTP vers Mailpit, comme avant.
  - **Artisan :** 97 commandes au lieu de 85. Les 6 commandes « disparues » (`queue:table`, `cache:table`…) sont renommées
    `make:*-table` en Laravel 11 et restent disponibles sous leur ancien nom. `route:list`, `event:list`, `view:clear`,
    `config:clear`, `schedule:list` et `ide-helper:meta` fonctionnent.
  - **Enregistrement :** mise à jour via l'API REST d'une documentation et d'une page (200), sans job mis en queue.
  - **Logs :** aucune erreur pendant les relevés, ni avant ni après. Les seules erreurs datent de la transition (manifeste
    `bootstrap/cache/packages.php` qui référençait encore `fideloper/proxy` avant d'être vidé).
- [x] Rédiger les instructions de mise à jour pour les projets consommateurs : [`UPGRADE.md`](UPGRADE.md)
- [ ] Commiter (conseillé : un commit dédié au reformatage Pint, ajouté à `.git-blame-ignore-revs`), pousser, vérifier la CI sur GitHub, puis taguer `5.0.0`

## Journal

- **2026-10-22** : analyse initiale, création de la branche, installation de Composer. Étape 0 terminée.
  Palier Laravel 11 : corrections bloquantes faites. Montée en 12.70.1 ; resynchronisation de `Application`,
  helpers et `Bus`, couche de compatibilité `Illuminate\Foundation`. Resynchronisation des Kernels, du Handler et des commandes.
  Recette sur sharingbox.com (interrompue par un crash machine, reprise) : front, admin, AJAX, enregistrement, artisan OK.
  Guide 11 → 12 appliqué, dernières copies de `Illuminate\Foundation` resynchronisées (providers console réellement différés).
  Étape 3 : PHPUnit 11, Rector 2 (set Laravel 12), PHPStan 2 avec baseline, Pint, `ext-intl`, CI GitHub Actions. Étape 3 terminée.
