<?php

namespace App\Controller;

use App\Entity\PrintJob;
use App\Message\PrintDocument;
use App\Print\DocumentStorage;
use App\Print\PrintSpooler;
use App\Repository\PrinterRepository;
use App\Security\ActorContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

final class PrintJobController extends AbstractController
{
    private const CONTEXT = ['groups' => ['print_job:read', 'tracking']];

    /**
     * Prints a document. Multipart: "file", "printer" (id; default printer when omitted), "copies" (1-99), "duplex", "color"
     * (0/1, ignored when the printer cannot), optional "title". Applications print on behalf of a user (X-Impersonate-User).
     */
    #[Route('/api/print-jobs', name: 'api_print_job_create', methods: ['POST'])]
    public function create(
        #[MapUploadedFile([new Assert\NotNull()])] UploadedFile $file,
        Request $request,
        PrintSpooler $spooler,
        PrinterRepository $printers,
        ActorContext $actor,
    ): JsonResponse {
        $user = $actor->requireUser();
        $printerId = (string) preg_replace('#^/api/(admin/)?printers/#', '', $request->request->getString('printer'));
        $printer = '' === $printerId
            ? $printers->findOneBy(['defaultPrinter' => true, 'enabled' => true])
            : (preg_match('#^'.Requirement::UUID.'$#', $printerId) ? $printers->find($printerId) : null);
        if (null === $printer || !$printer->isEnabled()) {
            return $this->json(['detail' => '' === $printerId ? 'No default printer: choose a printer.' : 'This printer does not exist.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = $spooler->submit($file, $printer, $user, $actor->getApplication(), [
            'copies' => $request->request->getInt('copies', 1),
            'duplex' => $request->request->getBoolean('duplex'),
            'color' => $request->request->getBoolean('color'),
        ], $request->request->getString('title') ?: null);

        return $this->json($job, Response::HTTP_CREATED, context: self::CONTEXT);
    }

    /** Limits of the print page: maximum size and accepted formats. */
    #[Route('/api/print-jobs/settings', name: 'api_print_job_settings', methods: ['GET'], priority: 10)]
    public function settings(PrintSpooler $spooler): JsonResponse
    {
        return $this->json(['maxFileSize' => $spooler->maxFileSize(), 'formats' => PrintSpooler::FORMATS]);
    }

    /** Removes a job from the queue before it is printed. */
    #[Route('/api/print-jobs/{id}/cancel', name: 'api_print_job_cancel', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function cancel(PrintJob $job, ActorContext $actor, EntityManagerInterface $em): JsonResponse
    {
        $this->denyUnlessAllowed($job, $actor);
        if (!$job->isCancellable()) {
            return $this->json(['detail' => 'Only a job waiting in the queue can be cancelled.'], Response::HTTP_CONFLICT);
        }
        $job->cancel();
        $em->flush();

        return $this->json($job, context: self::CONTEXT);
    }

    /** Prints a failed or cancelled job again, with a fresh set of attempts. */
    #[Route('/api/print-jobs/{id}/retry', name: 'api_print_job_retry', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function retry(PrintJob $job, ActorContext $actor, EntityManagerInterface $em, MessageBusInterface $bus): JsonResponse
    {
        $this->denyUnlessAllowed($job, $actor);
        if (!$job->isRetryable()) {
            return $this->json(['detail' => 'This job cannot be printed again (not failed or cancelled, printer deleted or document purged).'], Response::HTTP_CONFLICT);
        }
        $job->requeue();
        $em->flush();
        $bus->dispatch(new PrintDocument($job->getId()->toRfc4122()));
        $em->refresh($job);

        return $this->json($job, context: self::CONTEXT);
    }

    /** The printed document, as long as it is kept (PRINT_RETENTION_DAYS). */
    #[Route('/api/print-jobs/{id}/content', name: 'api_print_job_content', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function content(PrintJob $job, ActorContext $actor, DocumentStorage $storage): BinaryFileResponse
    {
        $this->denyUnlessAllowed($job, $actor);
        $path = $storage->path($job);
        if ($job->isContentPurged() || !is_file($path)) {
            throw $this->createNotFoundException('The document is no longer kept.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $job->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $job->getTitle()) ?: ''), '_') ?: 'document';
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, str_replace(['/', '\\'], '_', $job->getTitle()), $fallback);

        return $response;
    }

    /** Owners manage their jobs, administrators all of them; others do not see they exist. */
    private function denyUnlessAllowed(PrintJob $job, ActorContext $actor): void
    {
        if ($job->getOwner() !== $actor->requireUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }
    }
}
