<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Tests\Simple;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Simple\ApcuCache;

/**
 * @group legacy
 */
class ApcuCacheTest extends CacheTestCase
{
    protected $skippedTests = [
        'testSetTtl' => 'Testing expiration slows down the test suite',
        'testSetMultipleTtl' => 'Testing expiration slows down the test suite',
        'testDefaultLifeTime' => 'Testing expiration slows down the test suite',
    ];

    public function createSimpleCache(int $defaultLifetime = 0): CacheInterface
    {
        if (!\function_exists('apcu_fetch') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN) || ('cli' === \PHP_SAPI && !filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOLEAN))) {
            $this->markTestSkipped('APCu extension is required.');
        }
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Fails transiently on Windows.');
        }

        return new ApcuCache(str_replace('\\', '.', __CLASS__), $defaultLifetime);
    }
}
