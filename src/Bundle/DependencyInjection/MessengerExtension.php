<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MessengerBundle\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\DependencyInjection\Configuration as FrameworkBundleConfiguration;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\ChainSender;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class MessengerExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @var array
     */
    private $frameworkConfiguration;

    public function prepend(ContainerBuilder $container)
    {
        if (!$container->hasExtension('framework')) {
            throw new \LogicException(
                'The MessengerBundle requires symfony/framework-bundle installed'
            );
        }

        $this->frameworkConfiguration = $container->getExtensionConfig('framework');
    }


    public function load(array $config, ContainerBuilder $container)
    {
        $config = $this->processConfiguration($this->getConfiguration($config, $container), $config);
        $frameworkConfig = $this->processConfiguration(
            new FrameworkBundleConfiguration($container->getParameter('kernel.debug')),
            $this->frameworkConfiguration
        );

        $loader = new XmlFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('console.xml');

        if ($this->isConfigEnabled($container, $config['messenger'])) {
            $this->registerMessengerConfiguration(
                $config['messenger'],
                $container,
                $loader,
                $frameworkConfig['serializer'],
                $frameworkConfig['validation']
            );

            if (method_exists($container, 'registerForAutoconfiguration')) {
                $container->registerForAutoconfiguration(MessageHandlerInterface::class)
                          ->addTag('messenger.message_handler');
                $container->registerForAutoconfiguration(TransportFactoryInterface::class)
                          ->addTag('messenger.transport_factory');
            }

            $loader->load('messenger_debug.xml');
        } else {
            $container->removeDefinition('console.command.messenger_consume_messages');
            $container->removeDefinition('console.command.messenger_debug');
        }
    }

    private function registerMessengerConfiguration(array $config, ContainerBuilder $container, XmlFileLoader $loader, array $serializerConfig, array $validationConfig)
    {
        if (!interface_exists(MessageBusInterface::class)) {
            throw new LogicException('Messenger support cannot be enabled as the Messenger component is not installed. Try running "composer require symfony/messenger".');
        }
        $loader->load('messenger.xml');
        if (empty($config['transports'])) {
            $container->removeDefinition('messenger.transport.symfony_serializer');
            $container->removeDefinition('messenger.transport.amqp.factory');
        } else {
            if ('messenger.transport.symfony_serializer' === $config['serializer']['id']) {
                if (!$this->isConfigEnabled($container, $serializerConfig)) {
                    throw new LogicException('The default Messenger serializer cannot be enabled as the Serializer support is not available. Try enabling it or running "composer require symfony/serializer-pack".');
                }
                $container->getDefinition('messenger.transport.symfony_serializer')
                    ->replaceArgument(1, $config['serializer']['format'])
                    ->replaceArgument(2, $config['serializer']['context']);
            }
            if ($config['serializer']['id']) {
                $container->setAlias('messenger.transport.serializer', $config['serializer']['id']);
            } else {
                $container->removeDefinition('messenger.transport.amqp.factory');
            }
        }
        if (null === $config['default_bus'] && 1 === \count($config['buses'])) {
            $config['default_bus'] = key($config['buses']);
        }
        $defaultMiddleware = array(
            'before' => array(array('id' => 'logging')),
            'after' => array(array('id' => 'send_message'), array('id' => 'handle_message')),
        );
        foreach ($config['buses'] as $busId => $bus) {
            $middleware = $bus['middleware'];
            if ($bus['default_middleware']) {
                if ('allow_no_handlers' === $bus['default_middleware']) {
                    $defaultMiddleware['after'][1]['arguments'] = array(true);
                } else {
                    unset($defaultMiddleware['after'][1]['arguments']);
                }
                $middleware = array_merge($defaultMiddleware['before'], $middleware, $defaultMiddleware['after']);
            }
            foreach ($middleware as $middlewareItem) {
                if (!$validationConfig['enabled'] && \in_array($middlewareItem['id'], array('validation', 'messenger.middleware.validation'), true)) {
                    throw new LogicException('The Validation middleware is only available when the Validator component is installed and enabled. Try running "composer require symfony/validator".');
                }
            }
            if ($container->getParameter('kernel.debug') && class_exists(Stopwatch::class)) {
                array_unshift($middleware, array('id' => 'traceable', 'arguments' => array($busId)));
            }
            $container->setParameter($busId.'.middleware', $middleware);
            $container->register($busId, MessageBus::class)->addArgument(array())->addTag('messenger.bus');
            if ($busId === $config['default_bus']) {
                $container->setAlias('message_bus', $busId)->setPublic(true);
                $container->setAlias(MessageBusInterface::class, $busId);
            } else {
                $this->registerAliasForArgument($container, $busId, MessageBusInterface::class);
            }
        }
        $senderAliases = array();
        foreach ($config['transports'] as $name => $transport) {
            if (0 === strpos($transport['dsn'], 'amqp://') && !$container->hasDefinition('messenger.transport.amqp.factory')) {
                throw new LogicException('The default AMQP transport is not available. Make sure you have installed and enabled the Serializer component. Try enabling it or running "composer require symfony/serializer-pack".');
            }
            $transportDefinition = (new Definition(TransportInterface::class))
                ->setFactory(array(new Reference('messenger.transport_factory'), 'createTransport'))
                ->setArguments(array($transport['dsn'], $transport['options']))
                ->addTag('messenger.receiver', array('alias' => $name))
            ;
            $container->setDefinition($transportId = 'messenger.transport.'.$name, $transportDefinition);
            $senderAliases[$name] = $transportId;
        }
        $messageToSendersMapping = array();
        $messagesToSendAndHandle = array();
        foreach ($config['routing'] as $message => $messageConfiguration) {
            if ('*' !== $message && !class_exists($message) && !interface_exists($message, false)) {
                throw new LogicException(sprintf('Invalid Messenger routing configuration: class or interface "%s" not found.', $message));
            }
            $senders = array();
            foreach ($messageConfiguration['senders'] as $sender) {
                $senders[$sender] = new Reference($senderAliases[$sender] ?? $sender);
            }
            $sendersId = 'messenger.senders.'.$message;
            $container->register($sendersId, RewindableGenerator::class)
                ->setFactory('current')
                ->addArgument(array(new IteratorArgument($senders)));
            $messageToSendersMapping[$message] = new Reference($sendersId);
            $messagesToSendAndHandle[$message] = $messageConfiguration['send_and_handle'];
        }
        $container->getDefinition('messenger.senders_locator')
            ->replaceArgument(0, $messageToSendersMapping)
            ->replaceArgument(1, $messagesToSendAndHandle)
        ;
    }

    public function getXsdValidationBasePath()
    {
        return \dirname(__DIR__).'/Resources/config/schema';
    }

    public function getNamespace()
    {
        return 'http://symfony.com/schema/dic/messenger';
    }

    /**
     * Registers an autowiring alias that only binds to a specific argument name.
     *
     * The argument name is derived from $name if provided (from $id otherwise)
     * using camel case: "foo.bar" or "foo_bar" creates an alias bound to
     * "$fooBar"-named arguments with $type as type-hint. Such arguments will
     * receive the service $id when autowiring is used.
     */
    private function registerAliasForArgument(ContainerBuilder $container, string $id, string $type, string $name = null): Alias
    {
        $name = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9\x7f-\xff]++/', ' ', $name ?? $id))));
        if (!preg_match('/^[a-zA-Z_\x7f-\xff]/', $name)) {
            throw new InvalidArgumentException(sprintf('Invalid argument name "%s" for service "%s": the first character must be a letter.', $name, $id));
        }
        return $container->setAlias($type.' $'.$name, $id);
    }
}
