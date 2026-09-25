<?php

namespace App\Print\Connector;

use App\Entity\Printer;
use App\Enum\PrinterConnectorType;
use App\Print\PrintException;
use App\Print\PrintRequest;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A "printer" that writes documents to a directory under PRINT_FOLDER_ROOT, with their options in a .json file next to them:
 * to try Rocket Print without a printer, in tests and demos, or to archive what would have been printed.
 */
final class FolderConnector implements PrinterConnectorInterface
{
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire(env: 'resolve:PRINT_FOLDER_ROOT')] private readonly string $root,
        private readonly ClockInterface $clock,
    ) {
        $this->fs = new Filesystem();
    }

    public function type(): PrinterConnectorType
    {
        return PrinterConnectorType::Folder;
    }

    public function directory(Printer $printer): string
    {
        $relative = trim($printer->getUri(), '/');
        if ('' === $relative || str_contains('/'.$relative.'/', '/../') || str_contains('/'.$relative.'/', '/./')) {
            throw new PrintException('Invalid folder: expected a sub-directory of PRINT_FOLDER_ROOT.', false);
        }

        return rtrim($this->root, '/').'/'.$relative;
    }

    public function print(Printer $printer, PrintRequest $request): ?string
    {
        $directory = $this->directory($printer);
        $name = \sprintf('%s-%s-%s', $this->clock->now()->format('Ymd-His'), substr($request->reference ?: bin2hex(random_bytes(4)), -8), self::fileName($request->title));
        try {
            $this->fs->mkdir($directory, 0o775);
            $this->fs->copy($request->path, $directory.'/'.$name, true);
            $this->fs->dumpFile($directory.'/'.$name.'.json', json_encode([
                'title' => $request->title,
                'mimeType' => $request->mimeType,
                'user' => $request->user,
                'copies' => $request->copies,
                'duplex' => $request->duplex,
                'color' => $request->color,
                'printedAt' => $this->clock->now()->format(\DATE_ATOM),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        } catch (IOException $e) {
            throw new PrintException('The folder cannot be written: '.$e->getMessage(), true, $e);
        }

        return $name;
    }

    public function check(Printer $printer): string
    {
        $directory = $this->directory($printer);
        try {
            $this->fs->mkdir($directory, 0o775);
        } catch (IOException $e) {
            throw new PrintException('The folder cannot be created: '.$e->getMessage(), false, $e);
        }
        if (!is_writable($directory)) {
            throw new PrintException('The folder is not writable.', false);
        }
        $count = \count(glob($directory.'/*.json') ?: []);

        return \sprintf('Dossier accessible · %d document(s)', $count);
    }

    private static function fileName(string $title): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: ''), '._');

        return '' === $name ? 'document' : substr($name, 0, 120);
    }
}
