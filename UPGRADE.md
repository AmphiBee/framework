# Mise à jour vers Themosis 5.0 (Laravel 12)

Ce guide s'adresse aux projets en Themosis 3.x (Laravel 8) ou 4.x (Laravel 10).
Il a été validé sur sharingbox.com (Themosis 3.1) et polylang.pro (Themosis 3.0, WooCommerce, queue `database`).

Le détail technique de la migration du framework est dans [`UPGRADE-LARAVEL-12.md`](UPGRADE-LARAVEL-12.md).

## 1. Prérequis

- PHP **8.2** ou plus.
- Extension PHP **`intl`** : elle est désormais déclarée dans `require` (les formulaires Themosis utilisent `NumberFormatter`).
  Elle est fournie par défaut dans DDEV ; sinon, installez `php8.x-intl`.

## 2. `composer.json` du projet

| Paquet | Avant | Après |
| --- | --- | --- |
| `themosis/framework` | `^3.0` / `^4.0` | `^5.0` |
| `laravel/ui` | `^3.x` | `^4.5` (installé automatiquement par le framework) |
| `laravel/tinker` | `^2.x` | `^2.10` |
| `nesbot/carbon` | `^2.x` | `^3.8.4` |
| `jgrossi/corcel` (si utilisé) | `^5.x` | `^9.0` |
| `barryvdh/laravel-ide-helper` (si utilisé) | `^2.x` | `^3.0` |
| `fideloper/proxy` | `^4.x` | **à supprimer** (voir § 4) |
| `fruitcake/laravel-cors` | `^2.x` / `^3.x` | **à supprimer** (voir § 4) |

Dans la section `scripts`, remplacez la classe de Laravel, absente sans `laravel/framework`, par celle de Themosis :

```diff
- "Illuminate\\Foundation\\ComposerScripts::postUpdate",
+ "Themosis\\Core\\ComposerScripts::postUpdate",
```

Autres contraintes souvent à ajuster :

- `composer/installers` : un alias du type `"2.0.1 as 1.12.0"`, posé pour satisfaire Themosis 3 (`^1.9`), n'a plus lieu d'être.
  Utilisez `^2.2`.
- Paquets Symfony épinglés en 6.x (`symfony/http-client`, `symfony/mail-pace-mailer`…) : ouvrez-les à 7.x (`^6.4|^7.2`),
  car `illuminate/mail` 12 exige `symfony/mailer` 7.

Lancez ensuite `composer update -W`. Si vous l'exécutez avec `--no-scripts`, supprimez ensuite `bootstrap/cache/packages.php` et
`services.php`. Sinon, le manifeste pointe encore vers les providers des paquets retirés (`Fideloper\Proxy\TrustedProxyServiceProvider`)
et le site plante. Redémarrez PHP-FPM (ou `ddev restart`) pour vider l'opcache.

> ⚠️ `composer update` supprime les plugins WordPress installés par Composer depuis une autre branche et absents
> du `composer.lock` de la branche courante. Vérifiez `htdocs/content/plugins` après la mise à jour.

## 3. Caches à vider

Les scripts Composer du framework suppriment déjà `bootstrap/cache/services.php`, `packages.php` et `config.php`.
Il faut le faire à la main si les scripts n'ont pas tourné : plusieurs providers console sont désormais réellement différés,
et un manifeste de services ancien les chargerait de façon erronée.

Régénérez aussi le cache des routes s'il est utilisé (`php artisan route:cache`). Avant la 5.0, les routes à plusieurs
méthodes s'écrasaient dans le cache.

## 4. Middlewares

- **`fideloper/proxy`** : faites étendre `App\Http\Middleware\TrustProxies` de `Illuminate\Http\Middleware\TrustProxies`.
  Remplacez `$headers = Request::HEADER_X_FORWARDED_ALL` par
  `Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB`.
- **`fruitcake/laravel-cors`** : utilisez `Illuminate\Http\Middleware\HandleCors` dans le Kernel. Le fichier `config/cors.php` reste valable.
- `$routeMiddleware` fonctionne encore dans `App\Http\Kernel`, mais il est déprécié : renommez-le `$middlewareAliases`.
- `TrimStrings` ne retire plus les espaces de `password`, `password_confirmation` et `current_password`.
- `VerifyCsrfToken` accepte désormais le jeton `X-XSRF-TOKEN` envoyé par axios. Avant, il lisait le mauvais en-tête.
- La priorité des middlewares est alignée sur Laravel 12.

## 5. Ruptures dans les classes du framework

- **`App\Exceptions\Handler`**
  - Si vous surchargez `ignore()` ou `stopIgnoring()`, leur paramètre est maintenant typé `array|string`.
  - Déclarez les propriétés que vous assignez dans `register()` (par exemple `protected Ignition $ignition;`).
    Sinon, PHP 8.2 émet une dépréciation « dynamic property » à chaque requête.
- **`FormRequest`**
  - `validated()` a pour signature `validated($key = null, $default = null)`.
  - `failedValidation()` et `setValidator()` reçoivent `Illuminate\Contracts\Validation\Validator`, et non plus la classe concrète.
- **`EventServiceProvider`** : les listeners sont enregistrés dans `register()`. L'appel à `parent::boot()` n'est plus nécessaire,
  mais il reste sans effet de bord.
  Nouveau : la propriété `$observers`.
- **`AuthServiceProvider`** : les `$policies` sont enregistrées automatiquement. L'appel à `$this->registerPolicies()` peut être retiré.

- **Mail** : si `config/mail.php` contient encore la clé `driver` (format Laravel ≤ 6), Laravel 12 garde ce mode de compatibilité,
  et tous les mailers utilisent la config globale. Les transports personnalisés (`Mail::extend()`) continuent de fonctionner via `MAIL_DRIVER`.
- **Artisan** : `queue:table`, `cache:table`, `session:table`… s'appellent maintenant `make:queue-table`, etc. Les anciens noms restent des alias.

## 6. Changements de comportement

- Un en-tête `Host` invalide renvoie 400 (au lieu de 404).
- `BadRequestException` et `ConflictingHeadersException` renvoient 400 et ne sont plus journalisées.
- En console, les événements `CommandStarting` et `CommandFinished` sont émis : des listeners existants peuvent se déclencher.
- `defer()` fonctionne, y compris dans l'admin WordPress : le Kernel est terminé au hook `shutdown`.
- Les logs fonctionnent avec le `LogManager` de Laravel 11+. Avant le correctif, tous les canaux basculaient
  en silence sur le logger d'urgence.

## 7. Code du projet : points du guide Laravel 11 / 12 à vérifier

- **Carbon 3** : `diffIn*()` renvoie un nombre signé à virgule (`$a->diffInDays($b)` peut valoir `-2.5`).
- **Container** : une dépendance de classe nullable avec valeur par défaut (`?Foo $foo = null`) reçoit maintenant `null`,
  au lieu d'une instance résolue.
- **`Request::get()`** est déprécié depuis Symfony 7.4 : utilisez `$request->input()`.
- **Stockage** : sans disque `local` déclaré dans `config/filesystems.php`, sa racine devient `storage/app/private`.
- **Validation** : la règle `image` n'accepte plus le SVG, sauf `image:allow_svg`.

Guides officiels : [Laravel 11](https://laravel.com/docs/11.x/upgrade) et [Laravel 12](https://laravel.com/docs/12.x/upgrade).

## 8. Sécurité

- En production, définissez `SESSION_SECURE_COOKIE=true`, sinon les cookies de session et `XSRF-TOKEN` partent sans `Secure`.
- Les squelettes Themosis excluent souvent toutes les URL du CSRF Laravel (`$except = ['*']`), parce que WordPress et ses plugins
  postent sur les URL des pages. Une route Themosis qui modifie des données doit alors vérifier un nonce WordPress elle-même.
- `VerifyCsrfToken::except()` (statique) exclut des URI pour toute l'application : surveillez son usage par les plugins.
- Ne jamais afficher `$exception->getMessage()` dans une vue d'erreur 5xx personnalisée.
- `php artisan salts:generate` réécrit désormais réellement les sels, quel que soit leur format dans le `.env`.
  Avant la 5.0, la commande annonçait un succès sans rien changer si les sels étaient entre guillemets simples.

## 9. Non disponible dans Themosis

- `@vite` / `Illuminate\Foundation\Vite` : continuez à utiliser `mix()`.
- La commande `php artisan about`, l'`ApplicationBuilder` (`bootstrap/providers.php`, `withRouting()`…) et les nouveaux
  `make:class`, `make:enum`, etc.

## 10. Recette conseillée

1. Avant la mise à jour : snapshot de la base (`ddev snapshot`) et relevé des URL du sitemap (code HTTP, titre, taille).
2. Après : comparez ce relevé, parcourez l'admin (menus, listes, écrans d'édition), enregistrez un contenu et
   testez un formulaire, l'AJAX et les commandes artisan utilisées par le projet.
3. Comparez les logs (`storage/logs`) d'avant et d'après la mise à jour.
