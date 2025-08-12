<?php

namespace FacturaScripts\Plugins\OptimizadorImagenes\Lib;

class ImageOptimizer
{
    protected $log = [];
    protected $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function optimizeDirectory(string $sourceDir, string $backupDir, string $logDir, int $maxWidth = null, int $maxHeight = null): string
    {
        $date = date('Y-m-d_H-i-s');
        $logFile = $logDir . '/optimizacion-' . $date . '.txt';
        $this->scanDirectory($sourceDir, $sourceDir, $backupDir, $maxWidth, $maxHeight);
        file_put_contents($logFile, implode("\n", $this->log));
        return $logFile;
    }

    protected function scanDirectory(string $baseDir, string $currentDir, string $backupBase, int $maxWidth = null, int $maxHeight = null)
    {
        $items = scandir($currentDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $currentDir . '/' . $item;
            if (is_dir($path)) {
                $this->scanDirectory($baseDir, $path, $backupBase, $maxWidth, $maxHeight);
            } elseif ($this->isImage($path)) {
                $relativePath = str_replace($baseDir, '', $path);
                $backupPath = $backupBase . $relativePath;

                if (!file_exists($backupPath)) {
                    @mkdir(dirname($backupPath), 0777, true);
                    copy($path, $backupPath);
                    $this->log[] = "Copia original creada: $backupPath";
                } else {
                    $this->log[] = "Ya existe copia original: $backupPath";
                }

                $this->optimizeImage($path, $maxWidth, $maxHeight);
            }
        }
    }

    protected function isImage(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, $this->allowedExtensions);
    }

    protected function optimizeImage(string $filePath, int $maxWidth = null, int $maxHeight = null): void
    {
        $info = getimagesize($filePath);
        if (!$info) return;

        list($width, $height, $type) = $info;

        $createFunc = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null
        };
        $outputFunc = match ($type) {
            IMAGETYPE_JPEG => fn($img, $path) => imagejpeg($img, $path, 75),
            IMAGETYPE_PNG => fn($img, $path) => imagepng($img, $path, 6),
            IMAGETYPE_GIF => fn($img, $path) => imagegif($img, $path),
            IMAGETYPE_WEBP => fn($img, $path) => imagewebp($img, $path, 75),
            default => null
        };

        if (!$createFunc || !$outputFunc) {
            $this->log[] = "Formato no soportado: $filePath";
            return;
        }

        $image = @$createFunc($filePath);
        if (!$image) {
            $this->log[] = "Error al cargar: $filePath";
            return;
        }

        if ($maxWidth && $maxHeight && ($width > $maxWidth || $height > $maxHeight)) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = intval($width * $ratio);
            $newHeight = intval($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $outputFunc($image, $filePath);
        imagedestroy($image);
        $this->log[] = "Optimizada: $filePath";
    }
}