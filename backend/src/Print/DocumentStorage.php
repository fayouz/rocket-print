<?php

namespace App\Print;

use App\Entity\PrintJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/** Documents waiting to be printed, under DATA_DIR/print-jobs, named by an opaque key (never by the document name). */
class DocumentStorage
{
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire(env: 'resolve:DATA_DIR')] private readonly string $dataDir,
    ) {
        $this->fs = new Filesystem();
    }

    public function path(PrintJob $job): string
    {
        return rtrim($this->dataDir, '/').'/print-jobs/'.$job->getStorageKey();
    }

    public function store(PrintJob $job, File $content): void
    {
        $path = $this->path($job);
        $this->fs->mkdir(\dirname($path), 0o775);
        $content->move(\dirname($path), basename($path));
    }

    public function write(PrintJob $job, string $content): void
    {
        $this->fs->dumpFile($this->path($job), $content);
    }

    public function delete(PrintJob $job): void
    {
        $this->fs->remove($this->path($job));
    }
}
