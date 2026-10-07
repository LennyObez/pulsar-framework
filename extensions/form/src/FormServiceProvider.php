<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Form\Binding\FormDataBinder;
use Pulsar\Extension\Form\Binding\PropertyAccessor;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Contract\FormRendererInterface;
use Pulsar\Extension\Form\Csrf\FormCsrfManager;
use Pulsar\Extension\Form\Renderer\HtmlFormRenderer;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;
use Pulsar\Extension\Form\Upload\MimeSniffer;
use Pulsar\Extension\Form\Upload\UploadedFileHandler;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;
use Pulsar\Http\Validation\Validator;
use Pulsar\Security\Session\SessionInterface;

/**
 * Service provider for the form extension.
 *
 * Binds all form services to the container.
 */
final readonly class FormServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(FormConfig::class, static function () use ($container): FormConfig {
            $configData = $container->has(ExtensionConfigRegistry::class)
                ? $container->get(ExtensionConfigRegistry::class)->section('form')
                : [];

            return FormConfig::fromArray($configData);
        });

        // CSRF
        $container->bind(FormCsrfManager::class, static function () use ($container): FormCsrfManager {
            /** @var FormConfig $config */
            $config = $container->get(FormConfig::class);

            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);

            return new FormCsrfManager($session, $config->csrf);
        });

        // Renderer
        $container->bind(FormRendererInterface::class, static function () use ($container): FormRendererInterface {
            /** @var FormConfig $config */
            $config = $container->get(FormConfig::class);

            return new HtmlFormRenderer($config->renderer);
        });

        $container->bind(HtmlFormRenderer::class, static function () use ($container): HtmlFormRenderer {
            /** @var FormRendererInterface $renderer */
            $renderer = $container->get(FormRendererInterface::class);
            /** @var HtmlFormRenderer $renderer */

            return $renderer;
        });

        // Form builder
        $container->bind(FormBuilder::class, static function () use ($container): FormBuilder {
            /** @var FormConfig $config */
            $config = $container->get(FormConfig::class);

            /** @var Validator $validator */
            $validator = $container->get(Validator::class);

            /** @var FormCsrfManager|null $csrfManager */
            $csrfManager = $config->csrf->enabled && $container->has(FormCsrfManager::class)
                ? $container->get(FormCsrfManager::class)
                : null;

            /** @var AntivirusPort|null $antivirusPort */
            $antivirusPort = $container->has(AntivirusPort::class)
                ? $container->get(AntivirusPort::class)
                : null;

            return new FormBuilder($config, $validator, $csrfManager, $antivirusPort);
        });

        // Data binding
        $container->bind(PropertyAccessor::class, static fn(): PropertyAccessor => new PropertyAccessor());

        $container->bind(FormDataBinder::class, static function () use ($container): FormDataBinder {
            /** @var PropertyAccessor $accessor */
            $accessor = $container->get(PropertyAccessor::class);

            return new FormDataBinder($accessor);
        });

        // File upload
        $container->bind(MimeSniffer::class, static fn(): MimeSniffer => new MimeSniffer());
        $container->bind(FilenameSanitizer::class, static fn(): FilenameSanitizer => new FilenameSanitizer());

        $container->bind(UploadedFileHandler::class, static function () use ($container): UploadedFileHandler {
            /** @var FormConfig $config */
            $config = $container->get(FormConfig::class);

            /** @var MimeSniffer $mimeSniffer */
            $mimeSniffer = $container->get(MimeSniffer::class);

            /** @var FilenameSanitizer $sanitizer */
            $sanitizer = $container->get(FilenameSanitizer::class);

            /** @var AntivirusPort|null $antivirusPort */
            $antivirusPort = $container->has(AntivirusPort::class)
                ? $container->get(AntivirusPort::class)
                : null;

            /** @var LoggerInterface|null $logger */
            $logger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null;

            return new UploadedFileHandler($config->upload, $mimeSniffer, $sanitizer, $antivirusPort, $logger);
        });

        // Resume token manager
        $container->bind(ResumeTokenManager::class, static function () use ($container): ResumeTokenManager {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);

            return new ResumeTokenManager($session);
        });
    }

    #[Override]
    public function provides(): array
    {
        return [
            FormConfig::class,
            FormCsrfManager::class,
            FormRendererInterface::class,
            HtmlFormRenderer::class,
            FormBuilder::class,
            PropertyAccessor::class,
            FormDataBinder::class,
            MimeSniffer::class,
            FilenameSanitizer::class,
            UploadedFileHandler::class,
            ResumeTokenManager::class,
        ];
    }
}
