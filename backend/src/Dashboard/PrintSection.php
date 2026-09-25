<?php

namespace App\Dashboard;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Rocket Print on the dashboard: print jobs over 30 days, success rate, queue and failures.
 * A user sees their own jobs, an administrator the whole platform.
 */
final class PrintSection implements DashboardSectionInterface
{
    private const STATUS = [
        'queued' => ['En attente', 'neutral'],
        'printing' => ['En cours', 'info'],
        'printed' => ['Imprimé', 'success'],
        'failed' => ['Échec', 'error'],
        'cancelled' => ['Annulé', 'neutral'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $params = ['user' => $user->getId()->toRfc4122()];
        [$scope, $scopeParams] = $admin ? ['TRUE', []] : ['j.owner_id = :user', $params];

        $daily = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', j.created_at), 'YYYY-MM-DD') AS day, j.status, COUNT(*) AS n FROM print_job j
             WHERE $scope AND j.created_at >= :from GROUP BY 1, 2",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        ) as $row) {
            $key = 'failed' === $row['status'] ? 'failed' : 'jobs';
            $daily[$row['day']][$key] = ($daily[$row['day']][$key] ?? 0) + (int) $row['n'];
        }
        $period = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE j.status = 'printed') AS printed, COUNT(*) FILTER (WHERE j.status = 'failed') AS failed,
                    COALESCE(SUM(j.copies) FILTER (WHERE j.status = 'printed'), 0) AS copies
             FROM print_job j WHERE $scope AND j.created_at >= :from",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        );
        $previous = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM print_job j WHERE $scope AND j.created_at >= :from AND j.created_at < :to",
            $scopeParams + ['from' => DashboardStats::sql($previousFrom), 'to' => DashboardStats::sql($from)],
        );
        $queue = (int) $this->db->fetchOne("SELECT COUNT(*) FROM print_job j WHERE $scope AND j.status IN ('queued', 'printing')", $scopeParams);
        $finished = (int) $period['printed'] + (int) $period['failed'];

        return [
            'kpis' => [
                [
                    'id' => 'print_jobs',
                    'label' => $admin ? 'Impressions (30 j)' : 'Mes impressions (30 j)',
                    'value' => (int) $period['total'],
                    'format' => 'number',
                    'icon' => 'i-lucide-printer',
                    'tone' => 'bg-primary/10 text-primary',
                    'series' => 'jobs',
                    'previous' => $previous,
                    'detail' => \sprintf('%d exemplaire(s) imprimé(s)', $period['copies']),
                ],
                [
                    'id' => 'print_success',
                    'label' => 'Taux de réussite',
                    'value' => $finished > 0 ? round(100 * (int) $period['printed'] / $finished, 1) : null,
                    'format' => 'percent',
                    'icon' => 'i-lucide-circle-check',
                    'tone' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    'detail' => \sprintf('%d imprimée(s) sur %d terminée(s)', $period['printed'], $finished),
                ],
                [
                    'id' => 'print_queue',
                    'label' => 'File d’impression',
                    'value' => $queue,
                    'format' => 'number',
                    'icon' => 'i-lucide-clock',
                    'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                    'detail' => 'en attente ou en cours',
                ],
                [
                    'id' => 'print_failed',
                    'label' => 'Échecs (30 j)',
                    'value' => (int) $period['failed'],
                    'format' => 'number',
                    'icon' => 'i-lucide-triangle-alert',
                    'tone' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                    'series' => 'failed',
                ],
            ],
            'series' => [
                ['key' => 'jobs', 'label' => 'Impressions', 'color' => 'bg-primary'],
                ['key' => 'failed', 'label' => 'Échecs', 'color' => 'bg-rose-500'],
            ],
            'daily' => $daily,
            // Always the user's own jobs, even for administrators.
            'recent' => [
                'title' => 'Mes dernières impressions',
                'link' => '/jobs',
                'empty' => 'Aucune impression pour le moment : envoyez un document depuis « Imprimer ».',
                'items' => array_map(static fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'subtitle' => $row['printer_name'].($row['copies'] > 1 ? \sprintf(' · %d exemplaires', $row['copies']) : ''),
                    'at' => DashboardStats::atom($row['created_at']),
                    'badge' => self::STATUS[$row['status']][0] ?? $row['status'],
                    'badgeColor' => self::STATUS[$row['status']][1] ?? 'neutral',
                    'link' => '/jobs',
                ], $this->db->fetchAllAssociative(
                    'SELECT id, title, printer_name, copies, status, created_at FROM print_job WHERE owner_id = :user ORDER BY created_at DESC LIMIT 6',
                    $params,
                )),
            ],
            'activity' => array_map(static fn (array $row) => [
                'type' => 'print.failed',
                'at' => DashboardStats::atom($row['updated_at']),
                'title' => $row['title'],
                'actor' => $row['email'].' · '.$row['printer_name'],
                'link' => $admin ? '/jobs?all=1' : '/jobs',
                'icon' => 'i-lucide-printer',
                'label' => 'Impression en échec',
                'color' => 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
            ], $this->db->fetchAllAssociative(
                "SELECT j.title, j.printer_name, j.updated_at, u.email FROM print_job j JOIN \"user\" u ON u.id = j.owner_id
                 WHERE $scope AND j.status = 'failed' ORDER BY j.updated_at DESC LIMIT 8",
                $scopeParams,
            )),
            'quickActions' => [
                ['label' => 'Imprimer un document', 'icon' => 'i-lucide-printer', 'to' => '/print', 'tone' => 'bg-primary/10 text-primary'],
                ['label' => 'Mes impressions', 'icon' => 'i-lucide-list', 'to' => '/jobs', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
            ],
        ];
    }
}
