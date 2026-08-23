<?php declare(strict_types=1);
namespace TarBSD\Compile;

use Symfony\Component\Finder\Finder;

use ReflectionClass;
use ArrayObject;
use Throwable;

/****
 * Derived from Symfony\Component\ClassLoader\ClassCollectionLoader
 * 
 * https://github.com/symfony/class-loader/blob/3.4/ClassCollectionLoader.php
 * 
 * Copyright (c) 2004-2020 Fabien Potencier
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 ****/
class ClassOrderer
{
    private static $seen;

    public static function orderClasses(string $dir, string $ns) : array
    {
        $classes = [];
        foreach((new Finder)->directories()->in($dir)->depth(0) as $subDir)
        {
            $relativePath = substr((string) $subDir, strlen($dir) + 1);
            $subNs = $ns . '\\' . str_replace('/', '\\', $relativePath);
            $classes = array_merge($classes, static::orderClasses((string) $subDir, $subNs, false));
        }
        foreach((new Finder)->files()->in($dir)->depth(0)->name("*.php") as $file)
        {
            $relativePath = substr((string) $file, strlen($dir) + 1);
            $className = $ns . '\\' . str_replace('/', '\\', substr($relativePath, 0, -4));
            try
            {
                if (!preg_match('/Redis/', $className)) // some weird issue in symfony/cache
                {
                    $classes[] = new ReflectionClass($className);
                }
            }
            catch(Throwable $e)
            {}
        }
        return static::doOrderClasses($classes);
    }

    private static function doOrderClasses(array $classes) : array
    {
        static::$seen = [];

        $map = $classNames = [];

        foreach ($classes as $ref)
        {
            $classNames[] = $ref->getName();
            $map = array_merge($map, static::getClassHierarchy($ref));
        }

        return array_filter($map, function(ReflectionClass $ref) use ($classNames)
        {
            return in_array($ref->getName(), $classNames);
        });
    }

    private static function getClassHierarchy(ReflectionClass $class) : array
    {
        if (isset(static::$seen[$class->getName()]))
        {
            return [];
        }

        static::$seen[$class->getName()] = true;

        $classes = [$class];
        $parent = $class;
        while (($parent = $parent->getParentClass()) && $parent->isUserDefined() && !isset(static::$seen[$parent->getName()]))
        {
            static::$seen[$parent->getName()] = true;

            array_unshift($classes, $parent);
        }

        $traits = [];

        foreach ($classes as $c)
        {
            foreach (static::resolveDependencies(static::getTraits($c), $c) as $trait)
            {
                if ($trait !== $c)
                {
                    $traits[] = $trait;
                }
            }
        }

        return array_merge(static::getInterfaces($class), $traits, $classes);
    }

    private static function getInterfaces(ReflectionClass $class) : array
    {
        $classes = [];

        foreach ($class->getInterfaces() as $interface)
        {
            $classes = array_merge($classes, static::getInterfaces($interface));
        }

        if ($class->isUserDefined() && $class->isInterface() && !isset(static::$seen[$class->getName()]))
        {
            static::$seen[$class->getName()] = true;

            $classes[] = $class;
        }

        return $classes;
    }

    private static function getTraits(ReflectionClass $class) : array
    {
        $traits = $class->getTraits();
        $deps = [$class->getName() => $traits];
        while ($trait = array_pop($traits))
        {
            if ($trait->isUserDefined() && !isset(static::$seen[$trait->getName()]))
            {
                static::$seen[$trait->getName()] = true;
                $traitDeps = $trait->getTraits();
                $deps[$trait->getName()] = $traitDeps;
                $traits = array_merge($traits, $traitDeps);
            }
        }
        return $deps;
    }

    private static function resolveDependencies(
        array $tree,
        ReflectionClass $node,
        ?ArrayObject $resolved = null,
        ?ArrayObject $unresolved = null
    ) : ArrayObject {
        $resolved = $resolved ?? new ArrayObject;
        $unresolved = $unresolved ?? new ArrayObject;

        if (isset($tree[$nodeName = $node->getName()]))
        {
            $unresolved[$nodeName] = $node;
            foreach ($tree[$nodeName] as $dependency)
            {
                if (!isset($resolved[$dependency->getName()]))
                {
                    static::resolveDependencies($tree, $dependency, $resolved, $unresolved);
                }
            }
            $resolved[$nodeName] = $node;
            unset($unresolved[$nodeName]);
        }

        return $resolved;
    }
}
