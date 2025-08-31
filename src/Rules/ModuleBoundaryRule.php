<?php

declare(strict_types=1);

namespace Drmovi\PhpstanLaravelModuleBoundaries\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Use_>
 */
final class ModuleBoundaryRule implements Rule
{
    use ConfigurationLoaderTrait;

    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        
        // Get configuration
        $config = $this->loadModuleConfiguration($scope);
        if ($config === null) {
            return [];
        }
        
        // Get current module
        $currentModule = $this->getCurrentModule($scope->getFile(), $config['modulesPath']);
        if ($currentModule === null) {
            return [];
        }
        
        // Check each use statement
        foreach ($node->uses as $use) {
            $importName = $use->name->toString();
            $importedModule = $this->getModuleFromImport($importName, $config);
            
            if ($importedModule === null) {
                continue; // Not a module import
            }
            
            if ($importedModule === $currentModule) {
                continue; // Same module import is allowed
            }
            
            if (in_array($importedModule, $config['sharedModules'], true)) {
                continue; // Importing from shared modules is allowed
            }
            
            if (in_array($currentModule, $config['sharedModules'], true) && 
                in_array($importedModule, $config['sharedModules'], true)) {
                continue; // Shared modules can import from other shared modules
            }
            
            // This is a violation
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Module "%s" cannot import "%s" from module "%s". Cross-module imports are only allowed from shared modules (%s).',
                $currentModule,
                $importName,
                $importedModule,
                implode(', ', $config['sharedModules'])
            ))
            ->identifier('moduleBoundary.crossModuleImport')
            ->line($use->getStartLine())
            ->build();
        }
        
        return $errors;
    }
    
    /**
     * @param array{modulesPath: string, sharedModules: array<string>} $config
     */
    private function getModuleFromImport(string $import, array $config): ?string
    {
        $pathParts = explode('\\', $import);
        
        // Get available module directories
        $moduleDirs = $this->getModuleDirectories($config['modulesPath']);
        
        // Check each part of the namespace
        foreach ($pathParts as $part) {
            $lowerPart = strtolower($part);
            foreach ($moduleDirs as $moduleDir) {
                if (strtolower($moduleDir) === $lowerPart) {
                    return $moduleDir;
                }
            }
        }
        
        return null;
    }
}