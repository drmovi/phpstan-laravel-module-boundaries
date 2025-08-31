<?php

declare(strict_types=1);

namespace Drmovi\PhpstanLaravelModuleBoundaries\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\GroupUse;

/**
 * PHPStan rule to enforce Laravel module boundaries
 */
class ModuleBoundaryRule
{
    private ?array $moduleConfig = null;
    private ?string $modulesPath = null;
    private ?array $sharedModules = null;

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * Process a node and return any rule violations
     *
     * @param Node $node
     * @param mixed $scope
     * @return array<string>
     */
    public function processNode(Node $node, $scope): array
    {
        if (!$this->isImportNode($node)) {
            return [];
        }

        $this->loadConfiguration($scope);

        if ($this->modulesPath === null) {
            return [];
        }

        $currentFile = $scope->getFile();
        $currentModule = $this->extractModuleFromPath($currentFile);

        if ($currentModule === null) {
            return [];
        }

        $imports = $this->extractImports($node);
        $errors = [];

        foreach ($imports as $import) {
            $errorMessage = $this->validateImport($currentModule, $import, $currentFile);
            if ($errorMessage !== null) {
                $errors[] = $errorMessage;
            }
        }

        return $errors;
    }

    private function isImportNode(Node $node): bool
    {
        return $node instanceof Use_ || $node instanceof GroupUse;
    }

    /**
     * Load Laravel project configuration from composer.json
     */
    private function loadConfiguration($scope): void
    {
        if ($this->moduleConfig !== null) {
            return;
        }

        try {
            $composerPath = $this->findComposerJson($scope->getFile());
            if ($composerPath === null) {
                return;
            }

            $composerContent = file_get_contents($composerPath);
            if ($composerContent === false) {
                return;
            }

            $composer = json_decode($composerContent, true);
            if (!is_array($composer)) {
                return;
            }

            $this->moduleConfig = $composer;
            $this->modulesPath = $composer['extra']['laravel-module']['path'] ?? null;
            $this->sharedModules = $composer['extra']['phpstan-laravel-module-boundries']['shared'] ?? [];
        } catch (\Exception $e) {
            // Configuration loading failed, skip validation
        }
    }

    /**
     * Find composer.json by traversing up the directory tree
     */
    private function findComposerJson(string $currentFile): ?string
    {
        $dir = dirname($currentFile);
        $maxLevels = 10;
        $level = 0;

        while ($level < $maxLevels) {
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                return $composerPath;
            }

            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                break;
            }
            
            $dir = $parentDir;
            $level++;
        }

        return null;
    }

    /**
     * Extract module name from file path
     */
    private function extractModuleFromPath(string $filePath): ?string
    {
        if ($this->modulesPath === null) {
            return null;
        }

        $normalizedModulesPath = rtrim($this->modulesPath, '/');
        
        if (!str_contains($filePath, $normalizedModulesPath)) {
            return null;
        }

        $pattern = '#' . preg_quote($normalizedModulesPath, '#') . '/([^/]+)/#';
        if (preg_match($pattern, $filePath, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract import statements from use nodes
     * 
     * @return array<string>
     */
    private function extractImports(Node $node): array
    {
        $imports = [];

        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $imports[] = $use->name->toString();
            }
        } elseif ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $imports[] = $prefix . '\\' . $use->name->toString();
            }
        }

        return $imports;
    }

    /**
     * Validate if an import violates module boundaries
     */
    private function validateImport(string $currentModule, string $import, string $currentFile): ?string
    {
        $importedModule = $this->extractModuleFromImport($import);
        
        if ($importedModule === null) {
            return null; // Import is not from a module
        }

        if ($importedModule === $currentModule) {
            return null; // Same module import is allowed
        }

        if (in_array($importedModule, $this->sharedModules, true)) {
            return null; // Importing from shared modules is allowed
        }

        if (in_array($currentModule, $this->sharedModules, true) && 
            in_array($importedModule, $this->sharedModules, true)) {
            return null; // Shared modules can import from other shared modules
        }

        // Violation detected
        return sprintf(
            'Module "%s" cannot import "%s" from module "%s". Cross-module imports are only allowed from shared modules (%s).',
            $currentModule,
            $import,
            $importedModule,
            implode(', ', $this->sharedModules)
        );
    }

    /**
     * Extract module name from import namespace
     */
    private function extractModuleFromImport(string $import): ?string
    {
        if ($this->modulesPath === null) {
            return null;
        }

        $pathParts = explode('\\', $import);
        
        // Try to match Laravel module patterns: App\Modules\ModuleName or Modules\ModuleName
        foreach ($pathParts as $index => $part) {
            if (strtolower($part) === 'modules' && isset($pathParts[$index + 1])) {
                return $pathParts[$index + 1];
            }
        }

        // Fallback: check if any namespace part matches a module directory
        if (is_dir($this->modulesPath)) {
            $moduleDirs = $this->getModuleDirectories();
            foreach ($pathParts as $part) {
                if (in_array($part, $moduleDirs, true)) {
                    return $part;
                }
            }
        }

        return null;
    }

    /**
     * Get all module directory names
     * 
     * @return array<string>
     */
    private function getModuleDirectories(): array
    {
        if ($this->modulesPath === null || !is_dir($this->modulesPath)) {
            return [];
        }

        $dirs = scandir($this->modulesPath);
        if ($dirs === false) {
            return [];
        }

        return array_filter($dirs, function ($dir) {
            return $dir !== '.' && $dir !== '..' && is_dir($this->modulesPath . '/' . $dir);
        });
    }
}