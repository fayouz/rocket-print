<?php

namespace App\Controller;

use App\Entity\Printer;
use App\Print\Connector\PrinterConnectors;
use App\Print\PrintException;
use App\Print\PrintSpooler;
use App\Print\TestPage;
use App\Security\ActorContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PrinterAdminController extends AbstractController
{
    /** Checks that the printer answers, without printing anything. */
    #[Route('/api/admin/printers/{id}/check', name: 'api_printer_check', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function check(Printer $printer, PrinterConnectors $connectors): JsonResponse
    {
        $start = hrtime(true);
        try {
            $detail = $connectors->for($printer)->check($printer);
            $ok = true;
        } catch (PrintException $e) {
            $detail = $e->getMessage();
            $ok = false;
        }

        return $this->json(['ok' => $ok, 'detail' => $detail, 'latencyMs' => (int) ((hrtime(true) - $start) / 1_000_000)]);
    }

    /** Prints a test page on the printer (a regular job of the administrator). */
    #[Route('/api/admin/printers/{id}/test-page', name: 'api_printer_test_page', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function testPage(Printer $printer, PrintSpooler $spooler, ActorContext $actor, ClockInterface $clock): JsonResponse
    {
        $user = $actor->requireUser();
        $path = (new Filesystem())->tempnam(sys_get_temp_dir(), 'test-page');
        file_put_contents($path, TestPage::pdf($printer, $user->getEmail(), $clock->now()));
        $job = $spooler->queue(new File($path), 'Page de test.pdf', $printer, $user);

        return $this->json($job, Response::HTTP_CREATED, context: ['groups' => ['print_job:read', 'tracking']]);
    }
}
