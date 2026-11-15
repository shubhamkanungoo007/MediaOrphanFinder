<?php
declare(strict_types=1);

namespace Shubham\MediaOrphanFinder\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class OrphanScanner
{
    private const IGNORED_DIRS = ['cache', 'watermark', 'placeholder', 'tmp'];
    private const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Filesystem $filesystem,
        private readonly FileDriver $fileDriver
    ) {}

    /**
     * Fetch all active product media paths from DB as an associative hash map for O(1) lookup.
     *
     * @return array<string, bool>
     */
    public function getActiveMediaMap(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $activeMap = [];
        // 1. catalog_product_entity_media_gallery
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $selectGallery = $connection->select()
            ->from($galleryTable, ['value'])
            ->where('value IS NOT NULL');
        foreach ($connection->fetchCol($selectGallery) as $path) {
            $activeMap[$this->normalizePath((string)$path)] = true;
        }
        // 2. EAV varchar image attributes (image, small_image, thumbnail, swatch_image)
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $attrTable = $this->resourceConnection->getTableName('eav_attribute');
        $selectEav = $connection->select()
            ->distinct()
            ->from(['v' => $varcharTable], ['value'])
            ->join(['a' => $attrTable], 'v.attribute_id = a.attribute_id', [])
            ->where('a.attribute_code IN (?)', ['image', 'small_image', 'thumbnail', 'swatch_image'])
            ->where('v.value IS NOT NULL')
            ->where('v.value != ?', 'no_selection');

        foreach ($connection->fetchCol($selectEav) as $path) {
            $activeMap[$this->normalizePath((string)$path)] = true;
        }

        return $activeMap;
    }

    /**
     * Scan pub/media/catalog/product and return orphaned file metadata.
     *
     * @param array<string, bool> $activeMap
     * @return array{total_scanned: int, total_bytes: int, orphans: array<int, array{relative: string, full: string, size: int}>}
     */
    public function scan(array $activeMap): array
    {
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $pubDir = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
        $candidates = [
            $mediaDir->getAbsolutePath('catalog/product'),
            $pubDir->getAbsolutePath('media/catalog/product'),
        ];
        $productMediaRoot = null;
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)) {
                $productMediaRoot = rtrim($resolved, '/');
                break;
            }
        }
        if ($productMediaRoot === null) {
            throw new \RuntimeException(
                'Product media directory not found. Checked: ' . implode(', ', $candidates)
            );
        }
        $dirIterator = new RecursiveDirectoryIterator(
            $productMediaRoot,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        );
        $filterIterator = new RecursiveCallbackFilterIterator(
            $dirIterator,
            function (\SplFileInfo $current) use ($productMediaRoot): bool {
                if ($current->isDir()) {
                    $parentPath = rtrim($current->getPath(), '/');
                    if ($parentPath === $productMediaRoot && in_array($current->getFilename(), self::IGNORED_DIRS, true)) {
                        return false;
                    }
                }
                return true;
            }
        );
        $iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::LEAVES_ONLY);
        $totalScanned = 0;
        $totalBytes = 0;
        $orphans = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() && !$file->isLink()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::ALLOWED_EXTS, true)) {
                continue;
            }
            $totalScanned++;
            $fullPath = $file->getPathname();
            $relativePath = $this->normalizePath(substr($fullPath, strlen($productMediaRoot)));
            if (!isset($activeMap[$relativePath])) {
                $size = $file->getSize() ?: 0;
                $totalBytes += $size;
                $orphans[] = [
                    'relative' => $relativePath,
                    'full' => $fullPath,
                    'size' => $size,
                ];
            }
        }
        return [
            'total_scanned' => $totalScanned,
            'total_bytes' => $totalBytes,
            'orphans' => $orphans,
        ];
    }

    /**
     * Move orphaned files safely to var/orphan_media_quarantine/
     *
     * @param array<int, array{relative: string, full: string, size: int}> $orphans
     */
    public function quarantine(array $orphans): string
    {
        $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $quarantineBase = rtrim($varDir->getAbsolutePath('orphan_media_quarantine'), '/');
        foreach ($orphans as $item) {
            $destPath = $quarantineBase . $item['relative'];
            $destFolder = dirname($destPath);
            if (!$this->fileDriver->isDirectory($destFolder)) {
                $this->fileDriver->createDirectory($destFolder, 0755);
            }
            $this->fileDriver->rename($item['full'], $destPath);
        }
        return $quarantineBase;
    }

    private function normalizePath(string $path): string
    {
        $cleaned = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');
        return $cleaned;
    }
}
