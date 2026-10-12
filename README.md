# akyos/ux-native-cli

Transformez votre site Symfony en **app iOS et Android** ([Hotwire Native](https://native.hotwired.dev/)), sans quitter `bin/console`.

Le bundle génère des projets natifs prêts à compiler, les tient à jour depuis `native.yaml`, les builde et les installe sur votre téléphone ou votre émulateur. Les pages restent votre site Symfony : les apps natives ne sont qu'une coque autour.

| Commande | À quoi elle sert |
| --- | --- |
| `native:init` | Génère les projets `native/android` et `native/ios`. |
| `native:update:config` | Réinjecte `native.yaml` (URL, nom, icône, barre du haut) et ajoute les ponts manquants. |
| `native:build` | Met à jour la config, compile avec Gradle et/ou Xcode, puis installe sur un appareil ou un simulateur. |
| `native:qr-install` | Sert l'APK (et l'IPA) sur le réseau local, avec un QR code à scanner. |
| `native:dev` | Rappelle le flux de dev et peut démarrer le serveur Symfony. |

---

## Sommaire

1. [Démarrage rapide](#démarrage-rapide)
2. [Prérequis](#prérequis)
3. [Ce qui est généré](#ce-qui-est-généré)
4. [Onglets, navigation et barre du haut, pilotés par Symfony](#onglets-navigation-et-barre-du-haut-pilotés-par-symfony)
5. [`native:init` et ses options](#nativeinit-et-ses-options)
   - [Notifications push](#notifications-push---notification)
   - [Scan de QR code](#scan-de-qr-code---barcode-scanner)
   - [Publicités AdMob](#publicités-admob---admob)
   - [Mode hors ligne](#mode-hors-ligne---offline)
6. [`native:update:config`](#nativeupdateconfig)
7. [`native:build`](#nativebuild)
8. [`native:qr-install` et `native:dev`](#nativeqr-install-et-nativedev)
9. [Côté Twig : savoir qu'on est dans l'app](#côté-twig--savoir-quon-est-dans-lapp)
10. [Exemples d'utilisation](#exemples-dutilisation)
11. [Signature iOS](#signature-ios)
12. [Dépannage](#dépannage)
13. [Référence de configuration](#référence-de-configuration)

---

## Démarrage rapide

**1. Installer**

```bash
composer require akyos/ux-native-cli symfony/ux-native
```

Si Flex ne le fait pas, activez le bundle :

```php
// config/bundles.php
Akyos\UxNativeCliBundle\AkyosUxNativeCliBundle::class => ['all' => true],
```

**2. Configurer** l'app dans `config/packages/native.yaml` :

```yaml
native:
    app_name: MonApp
    url: 'http://192.168.1.249:8003'     # l'IP de votre machine sur le Wi-Fi
    application_id: com.akyos.monapp     # identifiant Android
    bundle_id: com.akyos.monapp          # identifiant iOS
    android_home: '%env(ANDROID_HOME)%'
    java_home: '%env(JAVA_HOME)%'
```

> **Pourquoi pas `127.0.0.1` ?** Sur un téléphone ou un émulateur, `127.0.0.1` désigne le téléphone lui-même, pas votre ordinateur. Mettez l'**IP locale** de votre machine (Réglages → Wi-Fi → Détails sur macOS), et un téléphone sur le même Wi-Fi.

**3. Générer les apps**

```bash
php bin/console native:init
```

**4. Démarrer Symfony**, accessible depuis le réseau :

```bash
symfony serve --port=8003 --allow-all-ip
```

**5. Compiler et installer**

```bash
php bin/console native:build --platform=android   # APK installé sur le téléphone ou l'émulateur branché
php bin/console native:build --platform=ios       # macOS : iPhone USB ou simulateur démarré
```

L'app s'ouvre sur votre site. C'est tout.

---

## Prérequis

**Android** (macOS, Linux ou Windows)
- [ ] Un JDK 17 ou plus récent, via `JAVA_HOME` ou `native.java_home`.
- [ ] Le SDK Android, via `ANDROID_HOME` ou `native.android_home`. Si rien n'est défini, le bundle cherche `~/Library/Android/sdk` (macOS) et `~/Android/Sdk` (Linux).
- [ ] Les *platform-tools* du SDK (`adb`), pour l'installation automatique.
- Gradle n'est pas à installer : le projet généré contient `gradlew`.

**iOS** (macOS uniquement)
- [ ] Xcode, avec la plateforme iOS de votre iPhone (Xcode → *Settings → Components*).
- [ ] `xcrun devicectl` (fourni avec Xcode récent) pour installer sur un iPhone. À défaut : `brew install ios-deploy`.
- [ ] Pour un vrai iPhone : une Team Apple (voir [Signature iOS](#signature-ios)). Le simulateur n'en a pas besoin.

**Optionnel**
- `sips` (fourni avec macOS) et l'extension PHP `gd`, pour générer les icônes depuis `native.icon`.
- L'extension PHP `sockets` aide `native:qr-install` à détecter votre IP. Sans elle, passez `--host=…`.

---

## Ce qui est généré

```text
native/
├── android/                       projet Gradle (Kotlin)
│   ├── app/build.gradle.kts
│   ├── app/google-services.json   à ajouter vous-même si --notification
│   ├── app/src/main/res/mipmap-*/ icônes, produites depuis native.icon
│   └── app/src/main/java/<application_id>/
│       ├── MainActivity.kt        onglets, navigation, barre du haut
│       ├── NativeApplication.kt   bridge components, configuration de chemins
│       └── PathConfigurationLoader.kt
└── ios/
    ├── NativeApp.xcodeproj        projet Xcode avec schéma partagé (requis par xcodebuild)
    └── NativeApp/
        ├── AppDelegate.swift      bridge components, configuration de chemins, barre du haut
        ├── SceneDelegate.swift    onglets et navigation
        ├── TabConfigurationProvider.swift
        └── Assets.xcassets/       icône, produite depuis native.icon
```

- Les valeurs de `native.yaml` sont écrites dans le code entre des marqueurs, par exemple `/*###url###*/"http://192.168.1.249:8003"/*###url###*/`. `native:update:config` et `native:build` ne remplacent que la valeur : le reste du fichier, y compris vos modifications, est conservé.
- Le package Kotlin est déplacé automatiquement pour correspondre à `application_id`.
- Les bridge components de [@joemasilotti/bridge-components](https://github.com/joemasilotti/bridge-components) (`bridge--*`) sont préinstallés côté natif.
- En debug, le cache du WebView est désactivé sur les deux plateformes : vos changements HTML et JavaScript apparaissent au prochain lancement.

> **Attention à `--force`.** Il **supprime** `native/android` et `native/ios` avant de les régénérer, y compris vos modifications manuelles et `google-services.json`. Sauvegardez ce fichier avant, puis remettez-le.

---

## Onglets, navigation et barre du haut, pilotés par Symfony

Les onglets, les règles de navigation et la barre du haut ne sont pas codés dans l'app : l'app lit votre serveur à chaque lancement, dans `/config/ios_v1.json` et `/config/android_v1.json`. On les modifie **sans rebuild** : il suffit de relancer l'app.

Ces fichiers sont produits par [Symfony UX Native](https://ux.symfony.com/native). En dev ils sont servis à la volée ; en prod, lancez `ux-native:dump` au déploiement.

```php
// src/Native/AppNativeConfiguration.php
use Symfony\UX\Native\Attribute\AsNativeConfiguration;
use Symfony\UX\Native\Attribute\AsNativeConfigurationProvider;
use Symfony\UX\Native\Configuration\Configuration;
use Symfony\UX\Native\Configuration\Rule;

#[AsNativeConfigurationProvider]
final class AppNativeConfiguration
{
    #[AsNativeConfiguration('/config/ios_v1.json')]
    public function ios(): Configuration
    {
        return new Configuration(
            settings: [
                'tabs' => [
                    ['label' => 'Accueil', 'path' => '/', 'icon' => 'house'],
                    ['label' => 'Compte', 'path' => '/compte', 'icon' => 'person'],
                ],
            ],
            rules: [
                new Rule(patterns: ['/checkout.*'], properties: ['top_bar' => false]),
                new Rule(patterns: ['.*'], properties: ['context' => 'default', 'pull_to_refresh_enabled' => true]),
            ],
        );
    }

    #[AsNativeConfiguration('/config/android_v1.json')]
    public function android(): Configuration
    {
        return new Configuration(
            settings: [
                'tabs' => [
                    ['label' => 'Accueil', 'path' => '/', 'icon' => 'ic_home'],
                    ['label' => 'Compte', 'path' => '/compte', 'icon' => 'ic_person'],
                ],
            ],
            rules: [
                new Rule(patterns: ['/checkout.*'], properties: ['top_bar' => false]),
                new Rule(patterns: ['.*'], properties: ['context' => 'default', 'pull_to_refresh_enabled' => true, 'uri' => 'hotwire://fragment/web']),
            ],
        );
    }
}
```

- **Onglets** : `icon` est un nom de [SF Symbol](https://developer.apple.com/sf-symbols/) sur iOS et un nom de drawable sur Android. Sans `tabs`, l'app affiche une seule pile de navigation, sans barre d'onglets.
- **Barre du haut** : la propriété `top_bar` d'une règle l'affiche (`true`) ou la masque (`false`) sur les pages qui correspondent au motif, sur iOS comme sur Android. Sans propriété, c'est `native.top_bar` qui s'applique. La première règle qui correspond gagne : mettez les règles précises avant `.*`.
- **Les autres propriétés** sont celles de Hotwire Native : `context` (`default` ou `modal`), `pull_to_refresh_enabled`, `presentation`, `title`, et `uri` sur Android.

> **Les onglets ne changent pas ?** Supprimez `public/config/*.json`. Ces fichiers statiques, créés par `ux-native:dump`, masquent la route dynamique en dev. Vérifiez aussi que l'URL `http://<votre-ip>/config/ios_v1.json` renvoie bien vos onglets.

---

## `native:init` et ses options

```bash
php bin/console native:init [--force] [--offline] [--notification] [--barcode-scanner] [--admob]
```

| Option | Effet |
| --- | --- |
| `--force` | Supprime et régénère `native/android` et `native/ios` (voir l'avertissement plus haut). Sans cette option, les dossiers doivent être vides. |
| `--notification` | Ajoute les notifications push. Sans cette option, l'app ne contient **aucun** code de notification. |
| `--barcode-scanner` | Ajoute le scan de QR code par le shell natif. |
| `--admob` | Ajoute les publicités Google AdMob affichées par le shell natif. |
| `--offline` | Mode hors ligne : service worker, page `/offline`, service workers autorisés sur iOS. |
| `--app-name`, `--url`, `--application-id`, `--bundle-id` | Remplacent la valeur de `native.yaml`, pour cette exécution uniquement. |

Chaque pont peut aussi être ajouté plus tard, sans régénérer, avec `native:update:config --notification|--barcode-scanner|--admob`.

**Côté web, pour tous les ponts :** les contrôleurs Stimulus `bridge--notification-token`, `bridge--barcode-scanner` et `bridge--admob` sont fournis par le bundle via Asset Mapper (`@akyos/ux-native-cli`), rien n'est copié dans `assets/`. Le bundle ajoute `@hotwired/hotwire-native-bridge` à l'importmap s'il manque. Avec `--notification`, il ajoute aussi `@joemasilotti/bridge-components` et patche `assets/stimulus_bootstrap.js` pour charger ses contrôleurs `bridge--*`, en retirant ceux que le bundle remplace. Dans un navigateur classique, ces contrôleurs ne font rien : vos autres `data-action` continuent de fonctionner.

### Notifications push (`--notification`)

**Ce qui est ajouté :**

| | iOS | Android |
| --- | --- | --- |
| Récupération du token | `NotificationTokenComponent.swift` (APNs) | `NotificationTokenComponent.kt` (FCM) |
| Autorisations | Entitlements `aps-environment` | Permission `POST_NOTIFICATIONS` |
| Affichage app ouverte | Bannière au premier plan | `NativeMessagingService.kt` |
| Toucher → page `url` | `AppDelegate` + `SceneDelegate` | `MainActivity` (`singleTask`, `onNewIntent`) |
| Dépendances | — | Firebase Messaging, plugin `google-services` |

**Dans un Live Component** (`symfony/ux-live-component`) : le trait, le `data-controller`, rien d'autre. Le bouton demande le token. `$this->notificationToken` est rempli et `onNotificationToken()` est appelée : surchargez-la pour enregistrer l'appareil. En cas de refus ou d'erreur, `$this->notificationError` contient le message.

```php
use Akyos\UxNativeCliBundle\Live\NotificationTrait;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class Devices
{
    use DefaultActionTrait;
    use NotificationTrait;

    public function __construct(private DeviceRepository $devices) {}

    protected function onNotificationToken(string $token): void
    {
        $this->devices->register($this->getUser(), $token);
    }
}
```

```twig
<div {{ attributes }}>
    <div data-controller="bridge--notification-token">
        <button type="button" data-action="bridge--notification-token#get">Activer les notifications</button>
        <p data-bridge--notification-token-target="token"></p>
    </div>
    {% if this.notificationError %}<p class="error">{{ this.notificationError }}</p>{% endif %}
</div>
```

Le `data-controller` du pont est sur un enfant, pour laisser `data-controller="live"` sur la racine. Sans Live Component, écoutez l'événement `bridge--notification-token:retrieved` : `event.detail.token` est un token APNs (hexadécimal) sur iOS, un token FCM sur Android.

**Envoyer les notifications :** [`akyos/native-push`](https://github.com/akyoscommunication/native-push) enregistre ce token côté serveur et envoie les notifications (`PushMessage`, APNs et FCM, ouverture de la page au toucher).

**Toucher une notification** ouvre l'app sur la page indiquée par la clé `url` du payload : la clé `url` à côté de `aps` sur APNs, `data.url` sur FCM. Un chemin est résolu sur l'URL de l'app. Sans `url`, l'app s'ouvre simplement.

**À faire de votre côté :**
- **Android :** créez une app Android dans [Firebase](https://console.firebase.google.com/), avec **le même package que `application_id`**. Déposez son `google-services.json` dans `native/android/app/`. Sans ce fichier, le build passe, mais aucun token n'est renvoyé.
- **iOS :** renseignez `ios_development_team` avec une Team **payante** (voir [Signature iOS](#signature-ios)) et testez sur un vrai iPhone. Le simulateur ne reçoit pas de token.

### Scan de QR code (`--barcode-scanner`)

Le shell ouvre la caméra et renvoie le contenu du QR. Dans un navigateur, le contrôleur ne fait rien : gardez un repli (saisie du code).

| | iOS | Android |
| --- | --- | --- |
| Écran de scan | `BarcodeScannerComponent.swift` (AVFoundation) | Google code scanner (`play-services-code-scanner`) |
| Autorisation | `NSCameraUsageDescription` (`native.camera_usage_description`) | Aucune permission `CAMERA` : l'écran est dessiné par les services Google Play |
| Réponse | `{"code": "…"}`, ou sans code si le scan est fermé ou refusé | Idem. Sans services Google Play (certains Huawei), aucun code n'est renvoyé |

**Dans un Live Component** : `ScanQRTrait` reçoit le code dans `$this->scannedCode` et appelle `onQrScanned()`. La valeur `auto` ouvre le scan à l'apparition, et ne le rouvre pas une fois le code reçu.

```php
use Akyos\UxNativeCliBundle\Live\ScanQRTrait;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class Join
{
    use DefaultActionTrait;
    use ScanQRTrait;

    public ?string $message = null;

    protected function onQrScanned(string $code): void
    {
        $this->message = sprintf('Billet %s validé', $code);
    }
}
```

```twig
<div {{ attributes }}>
    <div data-controller="bridge--barcode-scanner" data-bridge--barcode-scanner-auto-value="true">
        <button type="button" data-action="bridge--barcode-scanner#scan">Scanner à nouveau</button>
    </div>
    {% if this.message %}<p>{{ this.message }}</p>{% endif %}
</div>
```

Sans Live Component, écoutez `bridge--barcode-scanner:scanned` (`event.detail.code`) et `bridge--barcode-scanner:cancelled`.

Le simulateur iOS n'a pas de caméra : le scan réel se vérifie sur un iPhone.

### Publicités AdMob (`--admob`)

Un seul composant pour les formats rewarded, interstitiel, bannière et native. L'identifiant d'**app** (`native.admob.*`) est écrit dans le manifeste par `native:update:config`. L'identifiant de **bloc d'annonces** est envoyé par la page à chaque demande.

| Action | Donnée envoyée | Événement en retour |
| --- | --- | --- |
| `rewarded` | `{adUnitId}` | `bridge--admob:earned` (`detail.reward`) si la pub est vue jusqu'au bout, sinon `bridge--admob:dismissed` |
| `interstitial` | `{adUnitId}` | `bridge--admob:dismissed` à la fermeture |
| `banner` | `{adUnitId, position}` | `bridge--admob:loaded` (`detail.height`) |
| `native` | `{adUnitId, position}` | `bridge--admob:loaded` (`detail.height`) |
| `hide` | — | retire la bannière ou la pub native |

Tout format peut émettre `bridge--admob:failed` (`detail.error`) : la cible `error` est alors affichée. `position` vaut `"top"` ou `"bottom"`. Une pub plein écran interrompt les autres actions du même événement : mettez la suite sur `bridge--admob:earned` ou `:dismissed`.

```twig
<div data-controller="bridge--admob"
     data-bridge--admob-android-unit-value="ca-app-pub-3940256099942544/5224354917"
     data-bridge--admob-ios-unit-value="ca-app-pub-3940256099942544/1712485313">
    <button data-action="bridge--admob#rewarded bridge--admob:earned->bonus#credit">Regarder une pub</button>
    <p hidden data-bridge--admob-target="error">Pas de pub disponible.</p>
</div>
```

Pour une bannière ancrée en bas de page, qui laisse la place au contenu grâce à la variable CSS `--admob-anchored-height` :

```twig
<div data-controller="bridge--admob"
     data-bridge--admob-android-unit-value="ca-app-pub-3940256099942544/9214589741"
     data-bridge--admob-ios-unit-value="ca-app-pub-3940256099942544/2435281174"
     data-action="turbo:load@document->bridge--admob#banner"></div>
```

```css
body { padding-bottom: var(--admob-anchored-height, 0px); }
```

Par défaut, `native.admob.android_app_id` et `native.admob.ios_app_id` sont les identifiants d'app **de test** de Google. Les blocs de test, à mettre dans la page :

| Format | Android | iOS |
| --- | --- | --- |
| App | `ca-app-pub-3940256099942544~3347511713` | `ca-app-pub-3940256099942544~1458002511` |
| Bannière | `ca-app-pub-3940256099942544/9214589741` | `ca-app-pub-3940256099942544/2435281174` |
| Interstitiel | `ca-app-pub-3940256099942544/1033173712` | `ca-app-pub-3940256099942544/4411468910` |
| Rewarded | `ca-app-pub-3940256099942544/5224354917` | `ca-app-pub-3940256099942544/1712485313` |
| Native | `ca-app-pub-3940256099942544/2247696110` | `ca-app-pub-3940256099942544/3986624511` |

Sans identifiant d'app dans le manifeste, l'application s'arrête au lancement. En développement, restez sur les identifiants de test.

### Mode hors ligne (`--offline`)

`native:init --offline` :
- installe `spomky-labs/pwa-bundle` et l'enregistre dans `config/bundles.php` ;
- crée `config/packages/pwa.yaml`, `src/Controller/NativeOfflineController.php` (route `/offline`) et `templates/native/offline.html.twig` ;
- ajoute `{{ pwa() }}` avant `</head>` de vos `base.html.twig` ;
- autorise les service workers sur iOS (`WKAppBoundDomains` dans `Info.plist`, `limitsNavigationsToAppBoundDomains` dans `AppDelegate.swift`) ;
- embarque la configuration de chemins dans les apps et lance `pwa:compile`.

Après toute modification de `pwa.yaml`, relancez `php bin/console pwa:compile`. Les service workers exigent HTTPS, sauf sur `localhost` : utilisez `symfony serve` avec son certificat, ou ngrok, pour un vrai téléphone.

---

## `native:update:config`

```bash
php bin/console native:update:config [--notification] [--barcode-scanner] [--admob]
```

Relit `native.yaml` et met à jour le projet déjà généré. `native:init` la lance à la fin, `native:build` au début : en pratique, vous n'avez à l'appeler vous-même que pour ajouter un pont ou vérifier la configuration sans compiler.

| Ce qui change | Où |
| --- | --- |
| `url` | URL de démarrage Android et iOS, et URL du JSON de navigation |
| `app_name` | Nom sous l'icône |
| `top_bar` | Barre du haut par défaut. La propriété `top_bar` d'une règle de navigation l'emporte, page par page |
| `icon`, `icon_background` | Icône adaptative Android (`mipmap-anydpi-v26/ic_launcher.xml`, logo `ic_launcher_logo` sur un fond uni) et icône iOS 1024 px |
| `camera_usage_description` | `NSCameraUsageDescription`, si le scanner est dans le projet |
| `admob.android_app_id`, `admob.ios_app_id` | Manifeste Android et `Info.plist`, si AdMob est dans le projet |

L'icône est générée avec `sips` (macOS) et GD, depuis un PNG, un JPG ou un ICO. Elle est centrée dans un carré si elle ne l'est pas déjà. Sur Android, l'icône adaptative est indispensable : la librairie bridge-components embarque sa propre icône adaptative (un pont), qui masquerait un simple `ic_launcher.png`.

```yaml
native:
    url: 'http://192.168.1.12:8000'
    app_name: MonApp
    top_bar: false
    icon: assets/app-icon.png
    icon_background: '#1E293B'
```

Les options `--notification`, `--barcode-scanner` et `--admob` ajoutent le pont s'il manque. Elles ne retirent rien, et un second lancement ne duplique pas le code. `application_id` et `bundle_id` ne sont pas modifiés ici : ils sont posés par `native:init`.

---

## `native:build`

```bash
php bin/console native:build                                    # Android (par défaut), puis installation via adb
php bin/console native:build -p ios                             # iOS : iPhone USB ou simulateur démarré
php bin/console native:build -p ios --ios-target=simulator      # seulement les simulateurs démarrés
php bin/console native:build -p all                             # Android puis iOS
php bin/console native:build -p ios -n --ios-destination=device:00008130-000C58C40E21001C   # CI, sans question
php bin/console native:build --no-install                       # compile sans installer
```

Avant de compiler, la commande applique `native:update:config` (icône, URL, nom, barre du haut). Sur Android, l'application est désinstallée puis réinstallée, sinon le lanceur garde l'ancienne icône.

| Option | Défaut | Effet |
| --- | --- | --- |
| `-p`, `--platform` | `android` | `android`, `ios` ou `all`. |
| `--install` / `--no-install` | installe | Après un build réussi : `adb install -r` sur Android, `devicectl` / `ios-deploy` ou `simctl install` sur iOS. |
| `--ios-target` | — | `device` (iPhones branchés seulement) ou `simulator` (simulateurs démarrés seulement). |
| `--ios-destination` | — | `device:UDID` ou `simulator:UDID` : fixe la cible sans poser de question. |

**Comment la cible iOS est choisie :** seuls les iPhones réellement branchés et les simulateurs réellement **démarrés** sont proposés. S'il y en a plusieurs, la commande vous demande lequel utiliser. En mode `-n`, précisez `--ios-destination`. Les UDID s'obtiennent avec `xcrun devicectl list devices` et `xcrun simctl list devices booted`.

**Où sont les fichiers produits :**
- **Android :** `native/android/app/build/outputs/apk/debug/app-debug.apk`, ou `…/release/app-release.apk` si `android_gradle_task` contient `Release`.
- **iOS :** `native/ios/build/DerivedDataCli/Build/Products/Debug-iphoneos/` (iPhone) ou `Debug-iphonesimulator/`.

---

## `native:qr-install` et `native:dev`

### Installer via un QR code

```bash
php bin/console native:qr-install --build        # builde, puis affiche un QR à scanner
```

Le téléphone doit être sur le même Wi-Fi. Avec `--platform=all`, l'URL unique redirige vers l'APK sur Android et vers l'IPA sur iPhone.

| Option | Défaut | Effet |
| --- | --- | --- |
| `-p`, `--platform` | `all` | `all`, `android` (QR direct vers l'APK) ou `ios` (QR direct vers l'IPA, macOS). |
| `-b`, `--build` | non | Lance `native:build` sans installation avant de servir. Pour iOS, un iPhone USB est requis. |
| `--host` | détecté | IP à mettre dans l'URL. |
| `--port` | `9876` | Port d'écoute (le suivant est essayé s'il est pris). |
| `--apk` | — | Sert cet APK au lieu de la sortie Gradle. |

> **Sur iPhone, un IPA ne s'installe pas comme un APK.** iOS refuse l'installation depuis un simple lien. Utilisez `native:build -p ios` (USB), Xcode → *Appareils et simulateurs*, ou TestFlight.

La commande tourne jusqu'à ce que vous appuyiez sur Entrée.

### Rappel du flux de dev

```bash
php bin/console native:dev            # affiche l'URL, les étapes et les liens utiles
php bin/console native:dev --server   # démarre aussi symfony server:start
```

---

## Côté Twig : savoir qu'on est dans l'app

`symfony/ux-native` fournit `ux_is_native()`. Le bundle complète sa détection : une requête est aussi reconnue comme native quand elle vient d'un WebView Android (en-tête `X-Requested-With` au nom du package) ou qu'elle porte le cookie `hotwire_native=1`. Ce cookie permet de tester vos gabarits « app » depuis un navigateur de bureau.

```twig
{% if not ux_is_native() %}
    <header class="site-nav">…</header>   {# la barre native remplace ce menu dans l'app #}
{% endif %}

{% if ux_is_native() %}
    <a href="{{ path('scan') }}">Scanner un billet</a>
{% endif %}
```

---

## Exemples d'utilisation

**Changer l'URL ou le nom de l'app.** Modifiez `native.yaml`, puis compilez : la config est réinjectée avant le build.

```bash
php bin/console native:build -p android
```

**Ajouter les notifications à un projet déjà généré.** Rien à régénérer, vos modifications manuelles sont conservées.

```bash
php bin/console native:update:config --notification
cp ~/Downloads/google-services.json native/android/app/
php bin/console native:build -p all
```

**Masquer la barre du haut sur une page**, sans recompiler : une règle dans `AppNativeConfiguration`, puis relancez l'app.

```php
new Rule(patterns: ['/checkout.*', '/kiosque.*'], properties: ['top_bar' => false]),
```

**Masquer la barre partout par défaut**, puis la réafficher sur quelques pages :

```yaml
# config/packages/native.yaml
native:
    top_bar: false
```

```php
new Rule(patterns: ['/compte.*'], properties: ['top_bar' => true]),
```

**Ouvrir une page en modal** (formulaire, détail) :

```php
new Rule(patterns: ['/billets/nouveau'], properties: ['context' => 'modal', 'top_bar' => true]),
```

**Mettre votre logo en icône.** Un carré de 1024 px ou plus, PNG, JPG ou ICO, et une couleur de fond pour Android.

```yaml
native:
    icon: assets/icons/logo.png
    icon_background: '#0F172A'
```

```bash
php bin/console native:build -p android   # réinstalle l'app : le lanceur rafraîchit l'icône
```

**Faire tester l'app Android à un collègue.** Son téléphone sur le même Wi-Fi scanne le QR et télécharge l'APK.

```bash
php bin/console native:qr-install -p android --build
```

**Build Android de release** : changez la tâche Gradle, l'APK installé suit.

```yaml
native:
    android_gradle_task: assembleRelease
```

**Build iOS en CI**, sans question interactive :

```bash
xcrun simctl list devices booted      # relève l'UDID du simulateur démarré
php bin/console native:build -p ios -n --no-install --ios-destination=simulator:1A2B3C4D-…
```

**Lire le token push en JavaScript**, sans Live Component :

```js
document.addEventListener('bridge--notification-token:retrieved', (event) => {
    fetch('/api/devices', { method: 'POST', body: JSON.stringify({ token: event.detail.token }) });
});
```

**Tester les gabarits « app » dans un navigateur de bureau** : posez le cookie `hotwire_native=1` dans les outils de développement, `ux_is_native()` renvoie alors `true`.

**Tout régénérer** après une mise à jour du bundle, en gardant Firebase :

```bash
cp native/android/app/google-services.json /tmp/
php bin/console native:init --force --notification --barcode-scanner
cp /tmp/google-services.json native/android/app/
```

---

## Signature iOS

Pour installer sur un **vrai iPhone**, Xcode doit signer l'app avec une Team Apple. Renseignez-la une fois pour toutes : elle est réinjectée à chaque `native:init`, même avec `--force`.

```yaml
native:
    ios_development_team: 8WS3LS7L99   # exemple
```

| Type de Team | Installer sur iPhone | Notifications push |
| --- | --- | --- |
| Personal Team (gratuite) | Oui, 7 jours | Non |
| Apple Developer Program (payant) | Oui | Oui |

**Trouver le Team ID** (10 caractères) :
- sur [developer.apple.com/account](https://developer.apple.com/account) → *Membership details* ;
- ou dans le terminal : `defaults read com.apple.dt.Xcode IDEProvisioningTeamByIdentifier | grep -A3 teamID`.

La Team doit aussi apparaître dans Xcode → *Settings → Accounts*, **sans croix rouge** devant « Certificates, Identifiers & Profiles ». Le simulateur, lui, n'a besoin d'aucune Team.

---

## Dépannage

| Symptôme | Cause | Solution |
| --- | --- | --- |
| L'app affiche une page blanche ou une erreur réseau | `native.url` pointe sur `127.0.0.1`, ou le serveur n'écoute pas sur le réseau. | Mettez l'IP locale, lancez `symfony serve --allow-all-ip`, puis `native:build`. |
| Les onglets ou la barre du haut ne se mettent pas à jour | Un `public/config/*.json` statique masque la route dynamique, ou l'app n'a pas été relancée. | Supprimez `public/config/*.json`, fermez l'app complètement et relancez-la. |
| Aucune barre en haut de l'app | `top_bar: false` dans `native.yaml`, ou une règle `top_bar => false` qui correspond à la page. | Vérifiez les deux, puis `native:build` pour la config, un simple relancement pour les règles. |
| L'icône est un pont, pas mon logo | L'app a été compilée avant `native.icon`, ou le lanceur garde l'ancienne icône. | `native:build -p android` : l'app est désinstallée puis réinstallée. |
| `Unable to find a destination matching…` | L'iPhone n'est pas branché, déverrouillé ou approuvé, ou n'est pas signé. | Déverrouillez-le, acceptez « Faire confiance à cet ordinateur », et renseignez `ios_development_team`. |
| « iOS … is not installed » | Xcode n'a pas la plateforme iOS de l'iPhone. | Xcode → *Settings → Components*, installez la version demandée. |
| `No Account for Team "…"` | Le compte de cette Team n'est pas chargé dans Xcode. | Xcode → *Settings → Accounts* : ajoutez ou reconnectez le compte, et vérifiez qu'il n'y a pas de croix rouge. |
| `No profiles for '…' were found` | Pas de profil de provisioning pour ce bundle ID. | `native:build` le crée automatiquement quand la Team est valide. Vérifiez le point précédent. |
| `SDK location not found` | Gradle ne trouve pas le SDK Android. | Définissez `native.android_home`, ou `ANDROID_HOME`. |
| `Missing package product` | Les dépendances Swift ne sont pas résolues. | Xcode → *File → Packages → Reset Package Caches*. |
| « No matching client found for package name » | `google-services.json` a été créé pour un autre `application_id`. | Téléchargez le fichier de l'app Firebase qui a le bon package. |
| Plus de `google-services.json` après `--force` | `--force` supprime tout le dossier `native/android`. | Remettez votre copie dans `native/android/app/`. |
| `sips est introuvable` | L'icône n'est générée que sur macOS. | Lancez `native:update:config` sur un Mac, ou déposez les icônes à la main. |

---

## Référence de configuration

`config/packages/native.yaml`, sous la clé `native` :

| Clé | Défaut | Rôle |
| --- | --- | --- |
| `app_name` | `NativeApp` | Nom affiché sous l'icône. |
| `url` | `http://127.0.0.1:8000` | URL du site chargé par l'app. Utilisez l'IP locale pour un téléphone. |
| `application_id` | `com.example.nativeapp` | Identifiant Android. Le package Kotlin est déplacé en conséquence. |
| `bundle_id` | `com.example.nativeapp` | Identifiant iOS. |
| `ios_development_team` | `null` | Team ID Apple (10 caractères). Requis pour un iPhone et pour le push. |
| `top_bar` | `true` | Barre du haut native par défaut. La propriété `top_bar` d'une règle de navigation l'emporte, par page. |
| `icon` | `null` | Chemin d'une image carrée (PNG, JPG ou ICO), relatif au projet. `native:update:config` en fait les icônes Android et iOS. |
| `icon_background` | `#FFFFFF` | Fond de l'icône adaptative Android, derrière `icon`. |
| `camera_usage_description` | `Scanner un QR code.` | Texte de `NSCameraUsageDescription`, mis à jour si le scanner est présent. |
| `admob.android_app_id` | identifiant de test Google | Identifiant d'app AdMob Android (`ca-app-pub-…~…`). |
| `admob.ios_app_id` | identifiant de test Google | Identifiant d'app AdMob iOS (`ca-app-pub-…~…`). |
| `android_home` | `null` | SDK Android. Sinon `ANDROID_HOME`, puis les emplacements usuels. |
| `java_home` | `null` | JDK utilisé par Gradle. Sinon `JAVA_HOME`. |
| `android_path` | `null` | Dossier du projet Android. Par défaut `native/android`. |
| `ios_path` | `null` | Dossier du projet iOS. Par défaut `native/ios`. |
| `android_gradle_task` | `assembleDebug` | Tâche Gradle de `native:build`. Détermine aussi l'APK installé. |
| `ios_scheme` | `NativeApp` | Schéma Xcode. |
| `ios_project` | `NativeApp.xcodeproj` | Projet Xcode, dans `ios_path`. |
| `ios_product_name` | `null` | Nom du `.app`. Par défaut, celui de `ios_scheme`. |
| `ios_derived_data_path` | `null` | Dossier DerivedData. Par défaut `native/ios/build/DerivedDataCli`. |

Pour passer par les variables d'environnement, définissez `ANDROID_HOME` et `JAVA_HOME` dans `.env.local` et référencez-les avec `%env(...)%`, comme dans le démarrage rapide. Vous pouvez aussi laisser ces clés à `null` et exporter les variables dans votre shell.

---

## Ressources

- [Hotwire Native](https://native.hotwired.dev/)
- [Symfony UX Native](https://ux.symfony.com/native)
- [Bridge components de Joe Masilotti](https://github.com/joemasilotti/bridge-components)
- [`akyos/native-push`](https://github.com/akyoscommunication/native-push) : l'envoi des notifications push côté serveur

## Licence

MIT
