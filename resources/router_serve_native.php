<?php

declare(strict_types=1);

$apk = getenv('NATIVE_APK_PATH') ?: '';
$ipa = getenv('NATIVE_IPA_PATH') ?: '';
$base = rtrim(getenv('NATIVE_PUBLIC_BASE') ?: '', '/');
$hasApk = '' !== $apk && is_file($apk);
$hasIpa = '' !== $ipa && is_file($ipa);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = explode('?', $uri, 2)[0];

if ('/d/app.apk' === $uri) {
    if (!$hasApk) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'APK non disponible.';

        return true;
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="app.apk"');
    header('Content-Length: '.(string) filesize($apk));
    readfile($apk);

    return true;
}

if ('/d/app.ipa' === $uri) {
    if (!$hasIpa) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'IPA non disponible.';

        return true;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="app.ipa"');
    header('Content-Length: '.(string) filesize($ipa));
    readfile($ipa);

    return true;
}

if ('/' === $uri || '/index.html' === $uri) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($hasApk && preg_match('/Android/i', $ua)) {
        header('Location: '.$base.'/d/app.apk', true, 302);

        return true;
    }
    if ($hasIpa && preg_match('/iPhone|iPad|iPod/i', $ua)) {
        header('Location: '.$base.'/d/app.ipa', true, 302);

        return true;
    }
    header('Content-Type: text/html; charset=UTF-8');
    $e = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation</title></head><body>';
    echo '<h1>Installation native</h1>';
    if ($hasApk) {
        echo '<p><a href="'.$e($base.'/d/app.apk').'">Android — télécharger l’APK</a></p>';
    }
    if ($hasIpa) {
        echo '<p><a href="'.$e($base.'/d/app.ipa').'">iOS — télécharger l’IPA</a></p>';
        echo '<p><small>Sur iOS, un IPA en HTTP ne s’installe pas comme un APK : utilisez Xcode (Fenêtre → Appareils et simulateurs) pour faire glisser l’IPA, ou un outil MDM / TestFlight pour la distribution.</small></p>';
    }
    if (!$hasApk && !$hasIpa) {
        echo '<p>Aucun fichier disponible sur ce serveur.</p>';
    }
    echo '</body></html>';

    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Non trouvé.';

return true;
