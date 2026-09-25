<?php

namespace App\Command;

use App\Entity\Printer;
use App\Enum\PrinterConnectorType;
use App\Print\PrintSpooler;
use App\Print\TestPage;
use App\Repository\PrinterRepository;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Demo printers: a "folder" printer (documents written under PRINT_FOLDER_ROOT/demo), and, with DEMO_SAMBA_PRINTER,
 * the Samba print server of the demo (compose.demo.yaml). Alice gets a few print jobs.
 */
final class PrintDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrinterRepository $printers,
        private readonly PrintSpooler $spooler,
        private readonly SecretBox $secrets,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'DEMO_SAMBA_PRINTER')] private readonly string $sambaPrinter = '',
        #[Autowire(env: 'DEMO_SAMBA_USER')] private readonly string $sambaUser = '',
        #[Autowire(env: 'DEMO_SAMBA_PASSWORD')] #[\SensitiveParameter] private readonly string $sambaPassword = '',
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        if (null !== $this->printers->findOneBy(['name' => 'Accueil (dossier de démo)'])) {
            return;
        }
        $folder = (new Printer())
            ->setName('Accueil (dossier de démo)')
            ->setDescription('Imprimante fictive : les documents sont écrits dans un dossier du serveur, avec leurs options.')
            ->setLocation('Rez-de-chaussée, accueil')
            ->setConnector(PrinterConnectorType::Folder)
            ->setUri('demo')
            ->setColorSupported(true)
            ->setDuplexSupported(true)
            ->setDefaultPrinter(true);
        $this->em->persist($folder);

        if ('' !== $this->sambaPrinter) {
            $samba = (new Printer())
                ->setName('Laser 2e étage (Samba)')
                ->setDescription('Partage d’impression du serveur Samba de la démo.')
                ->setLocation('2e étage, open space')
                ->setConnector(PrinterConnectorType::Samba)
                ->setUri($this->sambaPrinter)
                ->setUsername('' === $this->sambaUser ? null : $this->sambaUser)
                ->setDomain('WORKGROUP')
                ->setDuplexSupported(true);
            if ('' !== $this->sambaPassword) {
                $samba->setEncryptedPassword($this->secrets->encrypt($this->sambaPassword));
            }
            $this->em->persist($samba);
        }
        $this->em->flush();

        $alice = $users['alice@example.org'] ?? null;
        if (null !== $alice) {
            $documents = [
                ['Compte rendu réunion.txt', "Compte rendu — réunion d'équipe\n\n1. Lancement de Rocket Print\n2. Imprimantes de chaque étage\n", ['copies' => 2]],
                ['Page de test.pdf', TestPage::pdf($folder, $alice->getEmail(), $this->clock->now()), ['duplex' => true, 'color' => true]],
            ];
            foreach ($documents as [$title, $content, $options]) {
                $path = (new Filesystem())->tempnam(sys_get_temp_dir(), 'demo');
                file_put_contents($path, $content);
                $this->spooler->queue(new File($path), $title, $folder, $alice, null, $options);
            }
        }
        $io->text('Imprimantes de démo'.('' !== $this->sambaPrinter ? ' (dossier et Samba)' : ' (dossier)').', et deux impressions d’Alice.');
    }
}
