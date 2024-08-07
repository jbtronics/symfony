<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Compiler;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\ServiceSubscriberInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\BarTagClass;
use Symfony\Component\DependencyInjection\Tests\Fixtures\FooBarTaggedClass;
use Symfony\Component\DependencyInjection\Tests\Fixtures\FooBarTaggedForDefaultPriorityClass;
use Symfony\Component\DependencyInjection\Tests\Fixtures\FooTagClass;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * This class tests the integration of the different compiler passes.
 */
class IntegrationTest extends TestCase
{
    /**
     * This tests that dependencies are correctly processed.
     *
     * We're checking that:
     *
     *   * A is public, B/C are private
     *   * A -> C
     *   * B -> C
     */
    public function testProcessRemovesAndInlinesRecursively()
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);

        $a = $container
            ->register('a', '\stdClass')
            ->addArgument(new Reference('c'))
            ->setPublic(true)
        ;

        $container
            ->register('b', '\stdClass')
            ->addArgument(new Reference('c'))
            ->setPublic(false)
        ;

        $c = $container
            ->register('c', '\stdClass')
            ->setPublic(false)
        ;

        $container->compile();

        $this->assertTrue($container->hasDefinition('a'));
        $arguments = $a->getArguments();
        $this->assertSame($c, $arguments[0]);
        $this->assertFalse($container->hasDefinition('b'));
        $this->assertFalse($container->hasDefinition('c'));
    }

    public function testProcessInlinesReferencesToAliases()
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);

        $a = $container
            ->register('a', '\stdClass')
            ->addArgument(new Reference('b'))
            ->setPublic(true)
        ;

        $container->setAlias('b', new Alias('c', false));

        $c = $container
            ->register('c', '\stdClass')
            ->setPublic(false)
        ;

        $container->compile();

        $this->assertTrue($container->hasDefinition('a'));
        $arguments = $a->getArguments();
        $this->assertSame($c, $arguments[0]);
        $this->assertFalse($container->hasAlias('b'));
        $this->assertFalse($container->hasDefinition('c'));
    }

    public function testProcessInlinesWhenThereAreMultipleReferencesButFromTheSameDefinition()
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);

        $container
            ->register('a', '\stdClass')
            ->addArgument(new Reference('b'))
            ->addMethodCall('setC', [new Reference('c')])
            ->setPublic(true)
        ;

        $container
            ->register('b', '\stdClass')
            ->addArgument(new Reference('c'))
            ->setPublic(false)
        ;

        $container
            ->register('c', '\stdClass')
            ->setPublic(false)
        ;

        $container->compile();

        $this->assertTrue($container->hasDefinition('a'));
        $this->assertFalse($container->hasDefinition('b'));
        $this->assertFalse($container->hasDefinition('c'), 'Service C was not inlined.');
    }

    public function testCanDecorateServiceSubscriberUsingBinding()
    {
        $container = new ContainerBuilder();
        $container->register(ServiceSubscriberStub::class)
            ->addTag('container.service_subscriber')
            ->setPublic(true);

        $container->register(DecoratedServiceSubscriber::class)
            ->setProperty('inner', new Reference(DecoratedServiceSubscriber::class.'.inner'))
            ->setDecoratedService(ServiceSubscriberStub::class);

        $container->compile();

        $this->assertInstanceOf(DecoratedServiceSubscriber::class, $container->get(ServiceSubscriberStub::class));
        $this->assertInstanceOf(ServiceSubscriberStub::class, $container->get(ServiceSubscriberStub::class)->inner);
        $this->assertInstanceOf(ServiceLocator::class, $container->get(ServiceSubscriberStub::class)->inner->container);
    }

    public function testCanDecorateServiceSubscriberReplacingArgument()
    {
        $container = new ContainerBuilder();
        $container->register(ServiceSubscriberStub::class)
            ->setArguments([new Reference(ContainerInterface::class)])
            ->addTag('container.service_subscriber')
            ->setPublic(true);

        $container->register(DecoratedServiceSubscriber::class)
            ->setProperty('inner', new Reference(DecoratedServiceSubscriber::class.'.inner'))
            ->setDecoratedService(ServiceSubscriberStub::class);

        $container->compile();

        $this->assertInstanceOf(DecoratedServiceSubscriber::class, $container->get(ServiceSubscriberStub::class));
        $this->assertInstanceOf(ServiceSubscriberStub::class, $container->get(ServiceSubscriberStub::class)->inner);
        $this->assertInstanceOf(ServiceLocator::class, $container->get(ServiceSubscriberStub::class)->inner->container);
    }

    public function testCanDecorateServiceLocator()
    {
        $container = new ContainerBuilder();

        $container->register('foo', 'stdClass')->setPublic(true);

        $container->register(ServiceLocator::class)
            ->addTag('container.service_locator')
            ->setArguments([[new Reference('foo')]])
        ;

        $container->register(DecoratedServiceLocator::class)
            ->setDecoratedService(ServiceLocator::class)
            ->setPublic(true)
            ->setArguments([new Reference(DecoratedServiceLocator::class.'.inner')])
        ;

        $container->compile();

        $this->assertInstanceOf(DecoratedServiceLocator::class, $container->get(DecoratedServiceLocator::class));
        $this->assertSame($container->get('foo'), $container->get(DecoratedServiceLocator::class)->get('foo'));
    }

    /**
     * @dataProvider getYamlCompileTests
     */
    public function testYamlContainerCompiles($directory, $actualServiceId, $expectedServiceId, ContainerBuilder $mainContainer = null)
    {
        // allow a container to be passed in, which might have autoconfigure settings
        $container = $mainContainer ?? new ContainerBuilder();
        $container->setResourceTracking(false);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Fixtures/yaml/integration/'.$directory));
        $loader->load('main.yml');
        $container->compile();
        $actualService = $container->getDefinition($actualServiceId);

        // create a fresh ContainerBuilder, to avoid autoconfigure stuff
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Fixtures/yaml/integration/'.$directory));
        $loader->load('expected.yml');
        $container->compile();
        $expectedService = $container->getDefinition($expectedServiceId);

        // reset changes, we don't care if these differ
        $actualService->setChanges([]);
        $expectedService->setChanges([]);

        $this->assertEquals($expectedService, $actualService);
    }

    public function getYamlCompileTests()
    {
        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(IntegrationTestStub::class);
        yield [
            'autoconfigure_child_not_applied',
            'child_service',
            'child_service_expected',
            $container,
        ];

        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(IntegrationTestStub::class);
        yield [
            'autoconfigure_parent_child',
            'child_service',
            'child_service_expected',
            $container,
        ];

        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(IntegrationTestStub::class)
            ->addTag('from_autoconfigure');
        yield [
            'autoconfigure_parent_child_tags',
            'child_service',
            'child_service_expected',
            $container,
        ];

        yield [
            'child_parent',
            'child_service',
            'child_service_expected',
        ];

        yield [
            'defaults_child_tags',
            'child_service',
            'child_service_expected',
        ];

        yield [
            'defaults_instanceof_importance',
            'main_service',
            'main_service_expected',
        ];

        yield [
            'defaults_parent_child',
            'child_service',
            'child_service_expected',
        ];

        yield [
            'instanceof_parent_child',
            'child_service',
            'child_service_expected',
        ];

        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(IntegrationTestStub::class)
            ->addMethodCall('setSunshine', ['supernova']);
        yield [
            'instanceof_and_calls',
            'main_service',
            'main_service_expected',
            $container,
        ];
    }

    public function testTaggedServiceWithIndexAttribute()
    {
        $container = new ContainerBuilder();
        $container->register(BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'bar'])
        ;
        $container->register(FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register(FooBarTaggedClass::class)
            ->addArgument(new TaggedIteratorArgument('foo_bar', 'foo'))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get(FooBarTaggedClass::class);

        $param = iterator_to_array($s->getParam()->getIterator());
        $this->assertSame(['bar' => $container->get(BarTagClass::class), 'foo_tag_class' => $container->get(FooTagClass::class)], $param);
    }

    public function testTaggedServiceWithIndexAttributeAndDefaultMethod()
    {
        $container = new ContainerBuilder();
        $container->register(BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register(FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'foo'])
        ;
        $container->register(FooBarTaggedClass::class)
            ->addArgument(new TaggedIteratorArgument('foo_bar', 'foo', 'getFooBar'))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get(FooBarTaggedClass::class);

        $param = iterator_to_array($s->getParam()->getIterator());
        $this->assertSame(['bar_tab_class_with_defaultmethod' => $container->get(BarTagClass::class), 'foo' => $container->get(FooTagClass::class)], $param);
    }

    public function testTaggedIteratorWithMultipleIndexAttribute()
    {
        $container = new ContainerBuilder();
        $container->register(BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'bar'])
            ->addTag('foo_bar', ['foo' => 'bar_duplicate'])
        ;
        $container->register(FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
            ->addTag('foo_bar')
        ;
        $container->register(FooBarTaggedClass::class)
            ->addArgument(new TaggedIteratorArgument('foo_bar', 'foo'))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get(FooBarTaggedClass::class);

        $param = iterator_to_array($s->getParam()->getIterator());
        $this->assertSame(['bar' => $container->get(BarTagClass::class), 'bar_duplicate' => $container->get(BarTagClass::class), 'foo_tag_class' => $container->get(FooTagClass::class)], $param);
    }

    public function testTaggedServiceWithDefaultPriorityMethod()
    {
        $container = new ContainerBuilder();
        $container->register(BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register(FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'foo'])
        ;
        $container->register(FooBarTaggedForDefaultPriorityClass::class)
            ->addArgument(new TaggedIteratorArgument('foo_bar', null, null, false, 'getPriority'))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get(FooBarTaggedForDefaultPriorityClass::class);

        $param = iterator_to_array($s->getParam()->getIterator());
        $this->assertSame([$container->get(FooTagClass::class), $container->get(BarTagClass::class)], $param);
    }

    public function testTaggedServiceLocatorWithIndexAttribute()
    {
        $container = new ContainerBuilder();
        $container->register('bar_tag', BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'bar'])
        ;
        $container->register('foo_tag', FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register('foo_bar_tagged', FooBarTaggedClass::class)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('foo_bar', 'foo', null, true)))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get('foo_bar_tagged');

        /** @var ServiceLocator $serviceLocator */
        $serviceLocator = $s->getParam();
        $this->assertTrue($s->getParam() instanceof ServiceLocator, sprintf('Wrong instance, should be an instance of ServiceLocator, %s given', \is_object($serviceLocator) ? \get_class($serviceLocator) : \gettype($serviceLocator)));

        $same = [
            'bar' => $serviceLocator->get('bar'),
            'foo_tag_class' => $serviceLocator->get('foo_tag_class'),
        ];
        $this->assertSame(['bar' => $container->get('bar_tag'), 'foo_tag_class' => $container->get('foo_tag')], $same);
    }

    public function testTaggedServiceLocatorWithMultipleIndexAttribute()
    {
        $container = new ContainerBuilder();
        $container->register('bar_tag', BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'bar'])
            ->addTag('foo_bar', ['foo' => 'bar_duplicate'])
        ;
        $container->register('foo_tag', FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
            ->addTag('foo_bar')
        ;
        $container->register('foo_bar_tagged', FooBarTaggedClass::class)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('foo_bar', 'foo', null, true)))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get('foo_bar_tagged');

        /** @var ServiceLocator $serviceLocator */
        $serviceLocator = $s->getParam();
        $this->assertTrue($s->getParam() instanceof ServiceLocator, sprintf('Wrong instance, should be an instance of ServiceLocator, %s given', \is_object($serviceLocator) ? \get_class($serviceLocator) : \gettype($serviceLocator)));

        $same = [
            'bar' => $serviceLocator->get('bar'),
            'bar_duplicate' => $serviceLocator->get('bar_duplicate'),
            'foo_tag_class' => $serviceLocator->get('foo_tag_class'),
        ];
        $this->assertSame(['bar' => $container->get('bar_tag'), 'bar_duplicate' => $container->get('bar_tag'), 'foo_tag_class' => $container->get('foo_tag')], $same);
    }

    public function testTaggedServiceLocatorWithIndexAttributeAndDefaultMethod()
    {
        $container = new ContainerBuilder();
        $container->register('bar_tag', BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register('foo_tag', FooTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar', ['foo' => 'foo'])
        ;
        $container->register('foo_bar_tagged', FooBarTaggedClass::class)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('foo_bar', 'foo', 'getFooBar', true)))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get('foo_bar_tagged');

        /** @var ServiceLocator $serviceLocator */
        $serviceLocator = $s->getParam();
        $this->assertTrue($s->getParam() instanceof ServiceLocator, sprintf('Wrong instance, should be an instance of ServiceLocator, %s given', \is_object($serviceLocator) ? \get_class($serviceLocator) : \gettype($serviceLocator)));

        $same = [
            'bar_tab_class_with_defaultmethod' => $serviceLocator->get('bar_tab_class_with_defaultmethod'),
            'foo' => $serviceLocator->get('foo'),
        ];
        $this->assertSame(['bar_tab_class_with_defaultmethod' => $container->get('bar_tag'), 'foo' => $container->get('foo_tag')], $same);
    }

    public function testTaggedServiceLocatorWithFallback()
    {
        $container = new ContainerBuilder();
        $container->register('bar_tag', BarTagClass::class)
            ->setPublic(true)
            ->addTag('foo_bar')
        ;
        $container->register('foo_bar_tagged', FooBarTaggedClass::class)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('foo_bar', null, null, true)))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get('foo_bar_tagged');

        /** @var ServiceLocator $serviceLocator */
        $serviceLocator = $s->getParam();
        $this->assertTrue($s->getParam() instanceof ServiceLocator, sprintf('Wrong instance, should be an instance of ServiceLocator, %s given', \is_object($serviceLocator) ? \get_class($serviceLocator) : \gettype($serviceLocator)));

        $expected = [
            'bar_tag' => $container->get('bar_tag'),
        ];
        $this->assertSame($expected, ['bar_tag' => $serviceLocator->get('bar_tag')]);
    }

    public function testTaggedServiceLocatorWithDefaultIndex()
    {
        $container = new ContainerBuilder();
        $container->register('bar_tag', BarTagClass::class)
            ->setPublic(true)
            ->addTag('app.foo_bar', ['foo_bar' => 'baz'])
        ;
        $container->register('foo_bar_tagged', FooBarTaggedClass::class)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('app.foo_bar', null, null, true)))
            ->setPublic(true)
        ;

        $container->compile();

        $s = $container->get('foo_bar_tagged');

        /** @var ServiceLocator $serviceLocator */
        $serviceLocator = $s->getParam();
        $this->assertTrue($s->getParam() instanceof ServiceLocator, sprintf('Wrong instance, should be an instance of ServiceLocator, %s given', \is_object($serviceLocator) ? \get_class($serviceLocator) : \gettype($serviceLocator)));

        $expected = [
            'baz' => $container->get('bar_tag'),
        ];
        $this->assertSame($expected, ['baz' => $serviceLocator->get('baz')]);
    }
}

class ServiceSubscriberStub implements ServiceSubscriberInterface
{
    public $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedServices(): array
    {
        return [];
    }
}

class DecoratedServiceSubscriber
{
    public $inner;
}

class DecoratedServiceLocator implements ServiceProviderInterface
{
    /**
     * @var ServiceLocator
     */
    private $locator;

    public function __construct(ServiceLocator $locator)
    {
        $this->locator = $locator;
    }

    /**
     * @return mixed
     */
    public function get($id)
    {
        return $this->locator->get($id);
    }

    public function has($id): bool
    {
        return $this->locator->has($id);
    }

    public function getProvidedServices(): array
    {
        return $this->locator->getProvidedServices();
    }
}

class IntegrationTestStub extends IntegrationTestStubParent
{
}

class IntegrationTestStubParent
{
    public function enableSummer($enable)
    {
        // methods used in calls - added here to prevent errors for not existing
    }

    public function setSunshine($type)
    {
    }
}
