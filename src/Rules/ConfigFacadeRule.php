<?php

declare(strict_types=1);

namespace Drmovi\PhpstanLaravelModuleBoundaries\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<StaticCall>
 */
final class ConfigFacadeRule implements Rule
{
    use ConfigurationLoaderTrait;

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isConfigFacadeCall($node)) {
            return [];
        }

        $configKey = $this->extractConfigKey($node);
        if ($configKey === null) {
            return [];
        }

        return $this->validateConfigAccess($configKey, $scope, $node);
    }

    private function isConfigFacadeCall(StaticCall $node): bool
    {
        if (!$node->class instanceof Name) {
            return false;
        }

        $className = $node->class->toString();
        if ($className !== 'Config' && $className !== 'Illuminate\Support\Facades\Config') {
            return false;
        }

        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        $methodName = $node->name->toString();
        return in_array($methodName, ['get', 'set', 'has', 'forget'], true);
    }

    private function extractConfigKey(StaticCall $node): ?string
    {
        if (count($node->args) === 0) {
            return null;
        }

        $firstArg = $node->args[0]->value;
        if (!$firstArg instanceof String_) {
            return null;
        }

        return $firstArg->value;
    }

    /**
     * @return array<\PHPStan\Rules\RuleError>
     */
    private function validateConfigAccess(string $configKey, Scope $scope, StaticCall $node): array
    {
        $config = $this->loadModuleConfiguration($scope);
        if ($config === null) {
            return [];
        }

        $currentModule = $this->getCurrentModule($scope->getFile(), $config['modulesPath']);
        if ($currentModule === null) {
            return [];
        }

        $configModule = $this->getModuleFromConfigKey($configKey, $config);
        if ($configModule === null) {
            return []; // Not a module config
        }

        if ($configModule === $currentModule) {
            return []; // Same module config access is allowed
        }

        if (in_array($configModule, $config['sharedModules'], true)) {
            return []; // Accessing shared module configs is allowed
        }

        if (in_array($currentModule, $config['sharedModules'], true) && 
            in_array($configModule, $config['sharedModules'], true)) {
            return []; // Shared modules can access other shared module configs
        }

        $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : 'unknown';

        // This is a violation
        return [
            RuleErrorBuilder::message(sprintf(
                'Module "%s" cannot access config "%s" from module "%s" using Config::%s(). Cross-module config access is only allowed from shared modules (%s).',
                $currentModule,
                $configKey,
                $configModule,
                $methodName,
                implode(', ', $config['sharedModules'])
            ))
            ->identifier('moduleBoundary.crossModuleConfig')
            ->build()
        ];
    }

    /**
     * @param array{modulesPath: string, sharedModules: array<string>} $config
     */
    private function getModuleFromConfigKey(string $configKey, array $config): ?string
    {
        $parts = explode('.', $configKey);
        if (count($parts) === 0) {
            return null;
        }

        $configFile = $parts[0];
        
        // Check if this config file exists in any module's config directory
        $moduleDirs = $this->getModuleDirectories($config['modulesPath']);
        
        foreach ($moduleDirs as $moduleDir) {
            $configPath = $config['modulesPath'] . '/' . $moduleDir . '/config/' . $configFile . '.php';
            if (file_exists($configPath)) {
                return $moduleDir;
            }
        }

        return null;
    }
}