# akyos/ux-native-cli

Transformez votre site Symfony en **app iOS et Android** ([Hotwire Native](https://native.hotwired.dev/)), sans quitter `bin/console`.

Le bundle génère des projets natifs prêts à compiler, puis les builde et les installe sur votre téléphone ou votre émulateur. Les pages restent votre site Symfony : les apps natives ne sont qu'une coque autour.

| Commande | À quoi elle sert |
| --- | --- |
| `native:init` | Génère les projets `native/android` et `native/ios`. |
| `native:build` | Compile avec Gradle et/ou Xcode, puis installe sur un appareil ou un simulateur. |
| `native:qr-install` | Sert l'APK (et l'IPA) sur le réseau local, avec un QR code à scanner. |
| `native:dev` | Rappelle le flux de dev et peut démarrer le serveur Symfony. |

---

## Sommaire

1. [Démarrage rapide](#démarrage-rapide)
2. [Prérequis](#prérequis)
3. [Ce qui est généré](#ce-qui-est-généré)
4. [Onglets et navigation, pilotés par Symfony](#onglets-et-navigation-pilotés-par-symfony)
5. [`native:init` et ses options](#nativeinit-et-ses-options)
   - [Scan de QR code](#scan-de-qr-code---barcode-scanner)
   - [Publicités AdMob](#publicités-admob---admob)
6. [`native:build`](#nativebuild)
7. [`native:qr-install` et `native:dev`](#nativeqr-install-et-nativedev)
8. [Signature iOS](#signature-ios)
9. [Dépannage](#dépannage)
10. [Référence de configuration](#référence-de-configuration)

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
    url: 'http://192.168.1.249:8003'     # exemple : l'IP de votre Mac sur le Wi-Fi
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
- L'extension PHP `sockets` aide `native:qr-install` à détecter votre IP. Sans elle, passez `--host=…`.

---

## Ce qui est généré

```text
native/
├── android/                       projet Gradle (Kotlin)
│   ├── app/build.gradle.kts
│   ├── app/google-services.json   à ajouter vous-même si --notification
│   └── app/src/main/java/<application_id>/
│       ├── MainActivity.kt        onglets et navigation
│       └── NativeApplication.kt   bridge components, configuration de chemins
└── ios/
    ├── NativeApp.xcodeproj        projet Xcode avec schéma partagé (requis par xcodebuild)
    └── NativeApp/
        ├── AppDelegate.swift      bridge components, configuration de chemins
        └── SceneDelegate.swift    onglets et navigation
```

- L'URL, les identifiants et le nom de l'app sont injectés depuis `native.yaml` à chaque `native:init`.
- Le package Kotlin est déplacé automatiquement pour correspondre à `application_id`.
- Les bridge components de [@joemasilotti/bridge-components](https://github.com/joemasilotti/bridge-components) (`bridge--*`) sont préinstallés.

> **Attention à `--force`.** Il **supprime** `native/android` et `native/ios` avant de les régénérer, y compris vos modifications manuelles et `google-services.json`. Sauvegardez ce fichier avant, puis remettez-le.

---

## Onglets et navigation, pilotés par Symfony

Les onglets et les règles de navigation ne sont pas codés dans l'app : l'app les lit sur votre serveur à chaque lancement, dans `/config/ios_v1.json` et `/config/android_v1.json`. On les modifie **sans rebuild** : il suffit de relancer l'app.

Ces fichiers sont produits par [Symfony UX Native](https://ux.symfony.com/native) :

```php
// src/Native/AppNativeConfiguration.php
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
                    ['label' => 'Push', 'path' => '/demo/page-3', 'icon' => 'bell'],
                ],
            ],
            rules: [new Rule(patterns: ['.*'], properties: ['context' => 'default', 'pull_to_refresh_enabled' => true])],
        );
    }
}
```

- `icon` est un nom de [SF Symbol](https://developer.apple.com/sf-symbols/) sur iOS et un nom de drawable sur Android.
- Sans `tabs`, l'app affiche une seule pile de navigation, sans barre d'onglets.

> **Les onglets ne changent pas ?** Supprimez `public/config/*.json`. Ces fichiers statiques, créés par `ux-native:dump`, masquent la route dynamique en dev. Vérifiez aussi que l'URL `http://<votre-ip>/config/ios_v1.json` renvoie bien vos onglets.

---

## `native:init` et ses options

```bash
php bin/console native:init [--force] [--offline] [--notification] [--barcode-scanner] [--admob]
```

| Option | Effet |
| --- | --- |
| `--force` | Supprime et régénère `native/android` et `native/ios` (voir l'avertissement plus haut). Sans cette option, les dossiers doivent être vides. |
| `--notification` | Ajoute les notifications push (ci-dessous). Sans cette option, l'app ne contient **aucun** code de notification. |
| `--barcode-scanner` | Ajoute le scan de QR code par le shell natif (ci-dessous). |
| `--admob` | Ajoute les publicités Google AdMob affichées par le shell natif (ci-dessous). |
| `--offline` | Mode hors ligne : installe `spomky-labs/pwa-bundle`, crée `config/packages/pwa.yaml` et une page `/offline`, ajoute `{{ pwa() }}` au layout, et autorise les service workers sur iOS. Pensez ensuite à `cache:clear`. |
| `--app-name`, `--url`, `--application-id`, `--bundle-id` | Remplacent la valeur de `native.yaml`, pour cette exécution uniquement. |

### Notifications push (`--notification`)

**Ce qui est ajouté :**

| | iOS | Android |
| --- | --- | --- |
| Récupération du token | `NotificationTokenComponent.swift` (APNs) | `NotificationTokenComponent.kt` (FCM) |
| Autorisations | Entitlements `aps-environment` | Permission `POST_NOTIFICATIONS` |
| Affichage app ouverte | Bannière au premier plan | `NativeMessagingService.kt` |
| Toucher → page `url` | `AppDelegate` + `SceneDelegate` | `MainActivity` (`singleTask`, `onNewIntent`) |
| Dépendances | — | Firebase Messaging, plugin `google-services` |

Côté web, le bundle installe `@hotwired/hotwire-native-bridge` et `@joemasilotti/bridge-components` dans l'importmap s'ils manquent, et charge les contrôleurs `bridge--*`.

**Récupérer le token dans une page :**

```twig
<div data-controller="bridge--notification-token"
     data-action="bridge--notification-token:retrieved->mon-controleur#enregistrer">
    <button data-action="bridge--notification-token#get">Activer les notifications</button>
    <p data-bridge--notification-token-target="token"></p>
</div>
```

L'événement `bridge--notification-token:retrieved` contient `event.detail.token` : un token APNs (hexadécimal) sur iOS, un token FCM sur Android.

**Envoyer les notifications :** [`akyos/native-push`](https://github.com/akyoscommunication/native-push) enregistre ce token côté serveur et envoie les notifications (`PushMessage`, APNs et FCM, ouverture de la page au toucher).

**Toucher une notification** ouvre l'app sur la page indiquée par la clé `url` du payload : la clé `url` à côté de `aps` sur APNs, `data.url` sur FCM. Un chemin est résolu sur l'URL de l'app. Sans `url`, l'app s'ouvre simplement.

**À faire de votre côté :**
- **Android :** créez une app Android dans [Firebase](https://console.firebase.google.com/), avec **le même package que `application_id`**. Déposez son `google-services.json` dans `native/android/app/`. Sans ce fichier, le build passe, mais aucun token n'est renvoyé.
- **iOS :** renseignez `ios_development_team` avec une Team **payante** (voir [Signature iOS](#signature-ios)) et testez sur un vrai iPhone. Le simulateur ne reçoit pas de token.

### Scan de QR code (`--barcode-scanner`)

Le shell ouvre la caméra et renvoie le contenu du QR. Dans un navigateur, le contrôleur ne se charge pas : gardez un repli (saisie du code).

| | iOS | Android |
| --- | --- | --- |
| Écran de scan | `BarcodeScannerComponent.swift` (AVFoundation) | Google code scanner (`play-services-code-scanner`) |
| Autorisation | `NSCameraUsageDescription` (`native.camera_usage_description`) | Aucune permission `CAMERA` : l'écran est dessiné par les services Google Play |
| Réponse | `{"code": "…"}`, ou sans code si le scan est fermé ou refusé | Idem. Sans services Google Play (certains Huawei), aucun code n'est renvoyé |

```twig
<div data-controller="bridge--barcode-scanner"
     data-bridge--barcode-scanner-auto-value="true"
     data-action="bridge--barcode-scanner:scanned->mon-controleur#utiliserLeCode">
    <button type="button" data-action="bridge--barcode-scanner#scan">Scanner</button>
</div>
```

`bridge--barcode-scanner:scanned` contient `event.detail.code`. `:cancelled` est émis si le scan est fermé. `auto` ouvre le scan dès l'apparition de l'élément.

Le contrôleur est copié dans `assets/controllers/bridge/`. Avec `--notification`, le contrôleur homonyme de `@joemasilotti/bridge-components` (contrat `barcode`, pas `code`) est retiré du chargement.

Le simulateur iOS n'a pas de caméra : le scan réel se vérifie sur un iPhone.

### Publicités AdMob (`--admob`)

Un seul composant pour les formats rewarded, interstitiel, bannière et native. L'identifiant d'**app** est écrit dans le manifeste (il change avec `native:init --force`). L'identifiant de **bloc d'annonces** est envoyé par la page à chaque demande.

| Événement | Donnée | Réponse |
| --- | --- | --- |
| `rewarded` | `{adUnitId}` | `{status: "earned", reward}` si la pub est vue jusqu'au bout, sinon `{status: "dismissed"}` |
| `interstitial` | `{adUnitId}` | `{status: "dismissed"}` à la fermeture |
| `banner` | `{adUnitId, position}` | `{status: "loaded", height}` |
| `native` | `{adUnitId, position}` | `{status: "loaded", height}` |
| `hide` | — | retire la bannière ou la pub native |

Tout format peut répondre `{status: "failed", error}`. `position` vaut `"top"` ou `"bottom"`.

```twig
<div data-controller="bridge--admob"
     data-bridge--admob-android-unit-value="ca-app-pub-…/…"
     data-bridge--admob-ios-unit-value="ca-app-pub-…/…">
    <button data-action="bridge--admob#rewarded bridge--admob:earned->mon-controleur#recompenser">Regarder une pub</button>
    <p hidden data-bridge--admob-target="error">Pas de pub disponible.</p>
</div>
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

| Option | Défaut | Effet |
| --- | --- | --- |
| `-p`, `--platform` | `android` | `android`, `ios` ou `all`. |
| `--install` / `--no-install` | installe | Après un build réussi : `adb install -r` sur Android, `devicectl` / `ios-deploy` ou `simctl install` sur iOS. |
| `--ios-target` | — | `device` (iPhones branchés seulement) ou `simulator` (simulateurs démarrés seulement). |
| `--ios-destination` | — | `device:UDID` ou `simulator:UDID` : fixe la cible sans poser de question. |

**Comment la cible iOS est choisie :** seuls les iPhones réellement branchés et les simulateurs réellement **démarrés** sont proposés. S'il y en a plusieurs, la commande vous demande lequel utiliser. En mode `-n`, précisez `--ios-destination`.

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
| L'app affiche une page blanche ou une erreur réseau | `native.url` pointe sur `127.0.0.1`, ou le serveur n'écoute pas sur le réseau. | Mettez l'IP locale, lancez `symfony serve --allow-all-ip`, puis `native:init --force`. |
| Les onglets ne se mettent pas à jour | Un `public/config/*.json` statique masque la route dynamique. | Supprimez `public/config/*.json` et relancez l'app. |
| `Unable to find a destination matching…` | L'iPhone n'est pas branché, déverrouillé ou approuvé, ou n'est pas signé. | Déverrouillez-le, acceptez « Faire confiance à cet ordinateur », et renseignez `ios_development_team`. |
| « iOS … is not installed » | Xcode n'a pas la plateforme iOS de l'iPhone. | Xcode → *Settings → Components*, installez la version demandée. |
| `No Account for Team "…"` | Le compte de cette Team n'est pas chargé dans Xcode. | Xcode → *Settings → Accounts* : ajoutez ou reconnectez le compte, et vérifiez qu'il n'y a pas de croix rouge. |
| `No profiles for '…' were found` | Pas de profil de provisioning pour ce bundle ID. | `native:build` le crée automatiquement quand la Team est valide. Vérifiez le point précédent. |
| `SDK location not found` | Gradle ne trouve pas le SDK Android. | Définissez `native.android_home`, ou `ANDROID_HOME`. |
| `Missing package product` | Les dépendances Swift ne sont pas résolues. | Xcode → *File → Packages → Reset Package Caches*. |
| « No matching client found for package name » | `google-services.json` a été créé pour un autre `application_id`. | Téléchargez le fichier de l'app Firebase qui a le bon package. |
| Plus de `google-services.json` après `--force` | `--force` supprime tout le dossier `native/android`. | Remettez votre copie dans `native/android/app/`. |

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
| `camera_usage_description` | `Scanner un QR code.` | Texte de `NSCameraUsageDescription`, écrit avec `--barcode-scanner`. |
| `admob.android_app_id` | identifiant de test Google | Identifiant d'app AdMob Android (`ca-app-pub-…~…`), écrit avec `--admob`. |
| `admob.ios_app_id` | identifiant de test Google | Identifiant d'app AdMob iOS (`ca-app-pub-…~…`), écrit avec `--admob`. |
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
