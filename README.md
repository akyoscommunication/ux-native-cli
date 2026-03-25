# akyos/ux-native-cli

Bundle Symfony qui expose des commandes console pour générer des coques **Hotwire Native** minimales (Android et iOS) à partir de modèles, puis lancer les outils natifs (**Gradle**, **xcodebuild**) depuis la racine du projet PHP.

## Installation

```bash
composer require akyos/ux-native-cli
```

Enregistrez le bundle si Flex ne le fait pas automatiquement :

```php
// config/bundles.php
return [
    // ...
    Akyos\UxNativeCliBundle\AkyosUxNativeCliBundle::class => ['all' => true],
];
```

### Dépendance suggérée

Pour la détection côté serveur et la configuration des chemins JSON Hotwire Native dans Symfony, le package officiel est recommandé :

```bash
composer require symfony/ux-native
```

## Configuration

Créez `config/packages/native.yaml` (l’alias d’extension est `native`).

| Clé | Défaut | Rôle |
|-----|--------|------|
| `app_name` | `NativeApp` | Nom affiché de l’application native. |
| `url` | `http://127.0.0.1:8000` | URL de base du backend (remplacée dans les modèles). À adapter pour un téléphone ou un émulateur (IP LAN, tunnel, etc.). |
| `application_id` | `com.example.nativeapp` | Identifiant Android (`applicationId`). Le code Kotlin est déplacé vers le chemin de package correspondant. |
| `bundle_id` | `com.example.nativeapp` | Identifiant iOS (bundle identifier). |
| `android_path` | `null` | Répertoire du projet Android. Si `null` : `{project_dir}/native/android`. |
| `ios_path` | `null` | Répertoire du projet iOS. Si `null` : `{project_dir}/native/ios`. |
| `android_gradle_task` | `assembleDebug` | Tâche Gradle exécutée par `native:build` pour Android. Influence aussi le chemin de l’APK utilisé pour `adb install` (debug vs release). |
| `ios_scheme` | `NativeApp` | Schéma Xcode passé à `xcodebuild`. |
| `ios_project` | `NativeApp.xcodeproj` | Nom du projet Xcode (fichier `.xcodeproj` dans `ios_path`). |
| `ios_product_name` | `null` | Nom du produit (dossier `.app`, ex. `NativeApp`). Si `null` : identique à `ios_scheme`. |
| `ios_derived_data_path` | `null` | Dossier DerivedData pour les builds CLI. Si `null` : `{ios_path}/build/DerivedDataCli`. Peut être un chemin absolu. |
| `android_home` | `null` | SDK Android explicite pour Gradle et pour localiser `adb`. Sinon : variable d’environnement `ANDROID_HOME`, puis emplacements usuels (voir ci-dessous). |
| `java_home` | `null` | JDK pour Gradle. Sinon : `JAVA_HOME` dans l’environnement. |

### Exemple avec variables d’environnement

Déclarez les clés dans `.env` / `.env.local`, puis référencez-les :

```dotenv
ANDROID_HOME=/chemin/vers/sdk
JAVA_HOME=/chemin/vers/jdk
```

```yaml
native:
    app_name: MonApp
    url: 'http://192.168.1.10:8000'
    application_id: com.monsite.app
    bundle_id: com.monsite.app
    android_home: '%env(ANDROID_HOME)%'
    java_home: '%env(JAVA_HOME)%'
```

Si vous préférez ne pas passer par l’injecteur d’environnement, laissez `android_home` et `java_home` à `null` et exportez `ANDROID_HOME` / `JAVA_HOME` dans le shell qui lance `bin/console`.

## Prérequis outillage

- **Android** : JDK (`JAVA_HOME`), Android SDK (`ANDROID_HOME` ou `native.android_home`), outils en ligne de commande Gradle via le `gradlew` fourni dans le modèle. Pour l’installation automatique de l’APK : **platform-tools** (`adb`) dans le SDK.
- **`native:qr-install`** : dépendance **endroid/qr-code** (installée avec le bundle). L’extension PHP **sockets** facilite la détection d’IP LAN ; sans elle, utilisez `--host=…`.
- **iOS** : **macOS**, Xcode, `xcodebuild`. Installation USB sur appareil : **`xcrun devicectl`** (Xcode récent) ou **`ios-deploy`** en secours (`brew install ios-deploy`). La signature code (équipe de développement) doit être valide pour un build **iphoneos**.
- Si xcodebuild affiche **« iOS … is not installed »** ou **« Any iOS Device »** avec **`generic/platform=iOS`** : branchez l’iPhone (le CLI cible alors `platform=iOS,id=UDID`) ou installez la plateforme iOS demandée dans **Xcode → Settings → Components**.

Si `ANDROID_HOME` n’est pas défini, la recherche de `adb` tente notamment :

- macOS : `$HOME/Library/Android/sdk`
- Linux (souvent) : `$HOME/Android/Sdk`

## Commandes

### `native:init`

Copie les modèles Android et iOS vers `native/android` et `native/ios` (ou les chemins configurés), remplace les jetons (`%NATIVE_APP_NAME%`, `%NATIVE_BASE_URL%`, etc.) et réorganise le package Kotlin si `application_id` diffère du modèle par défaut. Le projet iOS inclut un **schéma partagé** `NativeApp.xcodeproj/xcshareddata/xcschemes/NativeApp.xcscheme` pour que `xcodebuild` en CLI résolve correctement les destinations (sans quoi : *Supported platforms for the buildables in the current scheme is empty*).

| Option | Description |
|--------|-------------|
| `--force` | Supprime et recrée les répertoires cibles s’ils ne sont pas vides. |
| `--app-name`, `--url`, `--application-id`, `--bundle-id` | Surchargent la configuration YAML pour cette exécution uniquement. |

Les répertoires de sortie **ne doivent pas être vides** sans `--force`.

### `native:build`

Lance le build natif selon la plateforme.

| Option | Défaut | Description |
|--------|--------|-------------|
| `-p`, `--platform` | `android` | `android`, `ios` ou `all` (Android puis iOS). |
| `--install` / `--no-install` | install activé | Après un build **réussi** : Android → `adb install -r` ; iOS → appareil USB (`devicectl` / `ios-deploy`) ou simulateur (`xcrun simctl install` sur l’UDID choisi). |
| `--ios-target` | — | Filtre macOS : `device` (uniquement si au moins un appareil USB est détecté) ou `simulator` (uniquement si au moins un simulateur est **démarré**). Sans filtre, la liste proposée mélange **uniquement** les appareils réellement branchés et les simulateurs réellement bootés. |
| `--ios-destination` | — | macOS : `device:UDID` ou `simulator:UDID` pour fixer la destination sans question (recommandé avec `-n` / CI). |

Comportement Android :

- Exécution de `./gradlew {android_gradle_task}` dans le répertoire Android, avec `ANDROID_HOME` et `JAVA_HOME` injectés quand ils sont résolus.
- Si l’installation est activée : APK attendu selon la tâche :
  - tâche contenant `Release` : `app/build/outputs/apk/release/app-release.apk` ;
  - sinon (ex. `assembleDebug`) : `app/build/outputs/apk/debug/app-debug.apk`.

Comportement iOS :

- `xcodebuild` avec `-derivedDataPath` sous `{ios_path}/build/DerivedDataCli` (ou `ios_derived_data_path`).
- La destination est toujours résolue en **`device:UDID`** ou **`simulator:UDID`** (détection via `devicectl` / `ios-deploy` pour le matériel, `xcrun simctl list devices booted -j` pour les simulateurs démarrés). **Simulateur** n’est proposé que s’il y a au moins un simulateur en état **Booted** ; **appareil** seulement s’il y en a au moins un de connecté. En cas de plusieurs possibilités du même type, une question interactive permet de choisir ; en non-interactif (`-n`), précisez **`--ios-destination=…`** ou un seul candidat doit être disponible (sinon erreur explicite pour device ou simulateur filtré ; sans filtre, priorité au premier simulateur booté puis au premier appareil USB).
- **Appareil** : SDK **iphoneos**, `-destination platform=iOS,id=…` → `Build/Products/Debug-iphoneos/{ios_product_name}.app` ; avec `--install`, déploiement sur le même UDID (`devicectl` / `ios-deploy -i`).
- **Simulateur** : SDK **iphonesimulator**, `-destination platform=iOS Simulator,id=…` → `Build/Products/Debug-iphonesimulator/...` ; avec `--install`, `simctl install <udid>`.

### `native:qr-install`

Sert les artefacts via **`php -S 0.0.0.0`** et un routeur qui propose **APK** (`/d/app.apk`) et éventuellement **IPA** (`/d/app.ipa`). Un **QR code** pointe vers l’URL utile selon `--platform`.

| Option | Défaut | Description |
|--------|--------|-------------|
| `-p`, `--platform` | `all` | **`all`** : une URL racine `http://IP:port/` — le serveur envoie une **redirection HTTP 302** vers l’APK si le `User-Agent` ressemble à Android, vers l’IPA si iPhone/iPad/iPod, sinon une page HTML avec les deux liens. **`android`** : QR direct vers l’APK. **`ios`** : QR direct vers l’IPA (macOS requis pour produire l’IPA). |
| `--host` | (auto) | IPv4 dans l’URL. Sinon `--host=…`. L’auto-détection utilise une socket UDP (extension **sockets** utile). |
| `--port` | `9876` | Port d’écoute (essai des suivants si occupé). |
| `-b`, `--build` | non | Lance `native:build` **sans** installation : Android et/ou iOS selon `--platform`. Sur macOS, la partie iOS exige **au moins un appareil USB** (le premier est utilisé ; avertissement si plusieurs — pour choisir : `native:build --ios-destination=device:UDID`). Sous Linux, `all` ne build que Android. |
| `--apk` | — | Chemin absolu vers un `.apk` (ignore la sortie Gradle). |

**Réseau** : même Wi‑Fi que la machine. **Android** : téléchargement de l’APK puis installation (sources inconnues selon l’appareil). **iOS** : un IPA récupéré en HTTP **ne s’installe pas comme un APK** (pas d’OTA App Store) ; utilisation typique : **Xcode → Fenêtre → Appareils et simulateurs** en important l’IPA, ou outils MDM / TestFlight pour la distribution.

La commande doit être **interactive** (Entrée pour arrêter). Les chemins absolus sont passés au sous-processus `php -S` via **`NATIVE_APK_PATH`**, **`NATIVE_IPA_PATH`**, **`NATIVE_PUBLIC_BASE`** (évite les problèmes d’héritage d’environnement).

**Outils** : ligne de commande **`zip`** pour empaqueter l’IPA à partir du `.app` (présent sur macOS).

### `native:dev`

Affiche un rappel du flux de développement (URL de base, serveur Symfony, émulateur / appareil, liens documentation).

| Option | Description |
|--------|-------------|
| `--server` | Si la CLI `symfony` est dans le `PATH`, tente `symfony server:start` à la racine du projet (processus bloquant). |

## Flux typique

1. Configurer `config/packages/native.yaml` et les variables d’environnement nécessaires.
2. `php bin/console native:init` (éventuellement `--force` pour régénérer).
3. Démarrer le backend pour que l’URL configurée soit joignable depuis l’émulateur ou le téléphone (`native:dev` ou `symfony server:start`, etc.).
4. `php bin/console native:build --platform=android` pour compiler et, par défaut, pousser l’APK sur un appareil USB (`adb`), ou `native:qr-install -p android --build` pour le QR réseau.
5. Sous macOS : `php bin/console native:build --platform=ios --install` pour compiler (iphoneos) et installer sur l’iPhone branché, ou `native:qr-install --platform=all --build` pour un QR unique (redirection Android / iOS selon le téléphone).

## Ressources

- [Hotwire Native](https://native.hotwired.dev/)
- [Symfony UX Native](https://ux.symfony.com/native)

## Licence

MIT (voir le fichier `composer.json` du package).
