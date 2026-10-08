<?php

namespace Akyos\UxNativeCliBundle;

use Akyos\UxNativeCliBundle\Service\InitScaffolder;
use Akyos\UxNativeCliBundle\Service\OfflineScaffolder;
use Akyos\UxNativeCliBundle\Command\NativeQrInstallCommand;
use Akyos\UxNativeCliBundle\Service\NativeAndroidApkLocator;
use Akyos\UxNativeCliBundle\Service\NativeBuildRunner;
use Akyos\UxNativeCliBundle\Service\NativeDevHelper;
use Akyos\UxNativeCliBundle\Service\NativeIosAppLocator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AkyosUxNativeCliBundle extends AbstractBundle
{
    protected string $extensionAlias = 'native';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('app_name')->defaultValue('NativeApp')->end()
                ->scalarNode('url')->defaultValue('http://127.0.0.1:8000')->end()
                ->scalarNode('application_id')->defaultValue('com.example.nativeapp')->end()
                ->scalarNode('bundle_id')->defaultValue('com.example.nativeapp')->end()
                ->scalarNode('android_path')->defaultNull()->end()
                ->scalarNode('ios_path')->defaultNull()->end()
                ->scalarNode('android_gradle_task')->defaultValue('assembleDebug')->end()
                ->scalarNode('ios_scheme')->defaultValue('NativeApp')->end()
                ->scalarNode('ios_project')->defaultValue('NativeApp.xcodeproj')->end()
                ->scalarNode('ios_development_team')
                    ->defaultNull()
                    ->info('Apple Team ID (10 caractères), réinjecté dans DEVELOPMENT_TEAM à chaque native:init')
                    ->validate()
                        ->ifTrue(static fn ($v) => null !== $v && !preg_match('/^[A-Z0-9]{10}$/', (string) $v))
                        ->thenInvalid('native.ios_development_team doit être un Team ID Apple de 10 caractères (A-Z, 0-9), reçu %s.')
                    ->end()
                ->end()
                ->scalarNode('camera_usage_description')
                    ->defaultValue('Scanner un QR code.')
                    ->cannotBeEmpty()
                    ->info('Texte iOS NSCameraUsageDescription, écrit avec native:init --barcode-scanner')
                ->end()
                ->arrayNode('admob')
                    ->addDefaultsIfNotSet()
                    ->info('Identifiants d\'app AdMob, écrits avec native:init --admob. Défaut : identifiants de test Google.')
                    ->children()
                        ->scalarNode('android_app_id')
                            ->defaultValue('ca-app-pub-3940256099942544~3347511713')
                            ->validate()
                                ->ifTrue(static fn ($v) => !preg_match('/^ca-app-pub-\d+~\d+$/', (string) $v))
                                ->thenInvalid('native.admob.android_app_id doit être un identifiant AdMob d\'app (ca-app-pub-…~…), reçu %s.')
                            ->end()
                        ->end()
                        ->scalarNode('ios_app_id')
                            ->defaultValue('ca-app-pub-3940256099942544~1458002511')
                            ->validate()
                                ->ifTrue(static fn ($v) => !preg_match('/^ca-app-pub-\d+~\d+$/', (string) $v))
                                ->thenInvalid('native.admob.ios_app_id doit être un identifiant AdMob d\'app (ca-app-pub-…~…), reçu %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('ios_product_name')->defaultNull()->end()
                ->scalarNode('ios_derived_data_path')->defaultNull()->end()
                ->scalarNode('android_home')->defaultNull()->end()
                ->scalarNode('java_home')->defaultNull()->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import($this->getPath().'/config/services.yaml');

        $container->services()
            ->get(InitScaffolder::class)
            ->arg('$config', $config)
            ->arg('$bundleRoot', $this->getPath());

        $container->services()
            ->get(OfflineScaffolder::class)
            ->arg('$bundleRoot', $this->getPath());

        $container->services()
            ->get(NativeAndroidApkLocator::class)
            ->arg('$config', $config);

        $container->services()
            ->get(NativeIosAppLocator::class)
            ->arg('$config', $config);

        $container->services()
            ->get(NativeBuildRunner::class)
            ->arg('$config', $config);

        $container->services()
            ->get(NativeQrInstallCommand::class)
            ->arg('$bundleRoot', $this->getPath());

        $container->services()
            ->get(NativeDevHelper::class)
            ->arg('$config', $config);
    }
}
