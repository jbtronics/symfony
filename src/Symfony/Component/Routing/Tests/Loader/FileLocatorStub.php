<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Routing\Tests\Loader;

use Symfony\Component\Config\FileLocatorInterface;

class FileLocatorStub implements FileLocatorInterface
{
    public function locate($name, $currentPath = null, $first = true)
    {
        if (str_starts_with($name, 'http')) {
            return $name;
        }

        return rtrim($currentPath, '/').'/'.$name;
    }
}
