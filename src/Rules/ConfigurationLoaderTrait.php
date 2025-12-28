<?php

declare(strict_types=1);

namespace Drmovi\PhpstanLaravelModuleBoundaries\Rules;

use PHPStan\Analyser\Scope;

trait ConfigurationLoaderTrait
{
    /**
     * @return array{modulesPath: string, sharedModules: array<string>}|null
     */
    private function loadModuleConfiguration(Scope $scope): ?array
    {
        $composerPath = $this->findComposerJson($scope->getFile());
        if ($composerPath === null) {
            return null;
        }
        
        $content = file_get_contents($composerPath);
        if ($content === false) {
            return null;
        }
        
        $composer = json_decode($content, true);
        if (!is_array($composer)) {
            return null;
        }
        
        $modulesPath = $composer['extra']['modules']['path'] ?? null;
        if ($modulesPath === null) {
            return null;
        }
        
        $sharedModules = $composer['extra']['phpstan-laravel-module-boundaries']['shared'] ?? [];
        
        // Convert relative path to absolute
        if (!str_starts_with($modulesPath, '/')) {
            $modulesPath = dirname($composerPath) . '/' . $modulesPath;
        }
        
        return [
            'modulesPath' => realpath($modulesPath) ?: $modulesPath,
            'sharedModules' => $sharedModules,
        ];
    }
    
    private function findComposerJson(string $file): ?string
    {
        $dir = dirname($file);
        $attempts = 0;
        
        while ($attempts < 10) {
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                // Check if this composer.json has the laravel-module configuration
                $content = file_get_contents($composerPath);
                if ($content !== false) {
                    $composer = json_decode($content, true);
                    if (is_array($composer) && isset($composer['extra']['laravel-module'])) {
                        return $composerPath;
                    }
                }
            }
            
            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                break;
            }
            
            $dir = $parentDir;
            $attempts++;
        }
        
        return null;
    }
    
    private function getCurrentModule(string $filePath, string $modulesPath): ?string
    {
        if (!str_contains($filePath, $modulesPath)) {
            return null;
        }
        
        // Extract module name from file path
        $pattern = '#' . preg_quote($modulesPath, '#') . '/([^/]+)/#';
        if (preg_match($pattern, $filePath, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * @return array<string>
     */
    private function getModuleDirectories(string $modulesPath): array
    {
        if (!is_dir($modulesPath)) {
            return [];
        }
        
        $dirs = scandir($modulesPath);
        if ($dirs === false) {
            return [];
        }
        
        return array_filter($dirs, function (string $dir) use ($modulesPath): bool {
            return $dir !== '.' && $dir !== '..' && is_dir($modulesPath . '/' . $dir);
        });
    }
}
