<?php

namespace App\Statement;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps uploaded statements until the worker imports them, out of the public directory.
 */
final readonly class StatementStorage
{
    public function __construct(
        // Shared between the web server and the Messenger worker
        #[Autowire('%kernel.share_dir%/statements')]
        private string $directory,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @return string the path of the stored file, relative to the storage directory
     */
    public function store(UploadedFile $file): string
    {
        $this->filesystem->mkdir($this->directory, 0o700);
        $name = bin2hex(random_bytes(16)).'.csv';
        $file->move($this->directory, $name);

        return $name;
    }

    public function file(string $path): \SplFileInfo
    {
        return new \SplFileInfo($this->directory.'/'.basename($path));
    }

    public function delete(string $path): void
    {
        $this->filesystem->remove($this->directory.'/'.basename($path));
    }
}
