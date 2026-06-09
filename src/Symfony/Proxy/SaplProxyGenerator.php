<?php

declare(strict_types=1);

namespace Sapl\Symfony\Proxy;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Sapl\Symfony\StreamEnforce;

/**
 * Generates, at container build time, a subclass that overrides each method
 * carrying `#[PreEnforce]` / `#[PostEnforce]` / `#[StreamEnforce]` and routes it
 * through {@see SaplInterceptor::enforce()}. Methods without an enforcement
 * attribute are inherited unchanged.
 */
final class SaplProxyGenerator
{
    public function __construct(
        private readonly string $cacheDirectory,
    ) {
    }

    /**
     * @param class-string $originalClass
     *
     * @return string the generated proxy class name, loaded and ready to use
     */
    public function generate(string $originalClass): string
    {
        $reflection = new ReflectionClass($originalClass);
        $namespace = $reflection->getNamespaceName();
        $proxyShortName = $reflection->getShortName().'_SaplProxy_'.substr(md5($originalClass), 0, 8);
        $proxyClass = '' === $namespace ? $proxyShortName : $namespace.'\\'.$proxyShortName;

        $file = $this->cacheDirectory.'/sapl_proxy_'.str_replace('\\', '_', $proxyClass).'.php';
        if (!is_file($file)) {
            if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0o775, true) && !is_dir($this->cacheDirectory)) {
                throw new RuntimeException('Cannot create proxy cache directory '.$this->cacheDirectory);
            }
            file_put_contents($file, $this->render($reflection, $namespace, $proxyShortName));
        }
        require_once $file;

        return $proxyClass;
    }

    /**
     * True when the class has at least one method to enforce, so the pass only
     * proxies services that actually need it.
     *
     * @param class-string $class
     */
    public static function hasEnforcedMethod(string $class): bool
    {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::isEnforced($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function render(ReflectionClass $reflection, string $namespace, string $proxyShortName): string
    {
        $methods = '';
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::isEnforced($method) && !$method->isStatic() && !$method->isFinal() && !$method->isConstructor()) {
                $methods .= $this->renderMethod($method);
            }
        }
        $namespaceLine = '' === $namespace ? '' : "namespace {$namespace};\n\n";

        return "<?php\n\ndeclare(strict_types=1);\n\n{$namespaceLine}"
            ."use Sapl\\Symfony\\Proxy\\SaplInterceptor;\n"
            ."use Sapl\\Symfony\\Proxy\\SaplProxyMarker;\n\n"
            ."final class {$proxyShortName} extends \\{$reflection->getName()} implements SaplProxyMarker\n{\n"
            ."    private SaplInterceptor \$__saplInterceptor;\n\n"
            ."    public function __saplInit(SaplInterceptor \$interceptor): void\n    {\n"
            ."        \$this->__saplInterceptor = \$interceptor;\n    }\n\n{$methods}}\n";
    }

    private function renderMethod(ReflectionMethod $method): string
    {
        $name = $method->getName();
        $params = implode(', ', array_map(fn (ReflectionParameter $p): string => $this->renderParameter($p), $method->getParameters()));
        $methodReturnType = $method->getReturnType();
        $returnType = null !== $methodReturnType ? ': '.$this->renderType($methodReturnType) : '';
        $byRef = $method->returnsReference() ? '&' : '';
        $returns = $this->isVoid($method) ? '' : 'return ';

        // The proceed closure is not static: parent::method() is an instance call.
        return "    public {$byRef}function {$name}({$params}){$returnType}\n    {\n"
            ."        {$returns}\$this->__saplInterceptor->enforce(parent::class, '{$name}', \\func_get_args(), "
            ."function (array \$saplArgs) { return parent::{$name}(...\$saplArgs); });\n    }\n\n";
    }

    private function renderParameter(ReflectionParameter $parameter): string
    {
        $code = '';
        $parameterType = $parameter->getType();
        if (null !== $parameterType) {
            $code .= $this->renderType($parameterType).' ';
        }
        if ($parameter->isPassedByReference()) {
            $code .= '&';
        }
        if ($parameter->isVariadic()) {
            $code .= '...';
        }
        $code .= '$'.$parameter->getName();
        if ($parameter->isDefaultValueAvailable() && !$parameter->isVariadic()) {
            $code .= ' = '.var_export($parameter->getDefaultValue(), true);
        }

        return $code;
    }

    private function renderType(ReflectionType $type): string
    {
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $t): string => $this->renderType($t), $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $t): string => $this->renderType($t), $type->getTypes()));
        }
        if (!$type instanceof ReflectionNamedType) {
            return 'mixed';
        }
        $name = $type->getName();
        if (!$type->isBuiltin() && !in_array($name, ['self', 'static', 'parent'], true)) {
            $name = '\\'.$name;
        }
        $nullable = $type->allowsNull() && 'mixed' !== $name && 'null' !== $name ? '?' : '';

        return $nullable.$name;
    }

    private function isVoid(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && in_array($type->getName(), ['void', 'never'], true);
    }

    private static function isEnforced(ReflectionMethod $method): bool
    {
        return [] !== $method->getAttributes(PreEnforce::class)
            || [] !== $method->getAttributes(PostEnforce::class)
            || [] !== $method->getAttributes(StreamEnforce::class);
    }
}
