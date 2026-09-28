<?php

namespace App\Dashboard;

use Doctrine\DBAL\Connection;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Dashboard\DashboardStats;
use Rocket\Core\Entity\User;
use Symfony\Component\Clock\ClockInterface;

/** Rocket Cast on the dashboard: screens online, playlists, sources in error, screens by last contact. */
final class CastSection implements DashboardSectionInterface
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $online = DashboardStats::sql($this->clock->now()->modify('-180 seconds'));
        $screens = $this->db->fetchAssociative('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE last_seen_at >= :online) AS online FROM screen', ['online' => $online]);
        $playlists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM playlist');
        $sources = $this->db->fetchAssociative('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE last_error IS NOT NULL AND enabled) AS failing FROM source');
        $total = (int) $screens['total'];

        return [
            'kpis' => [
                [
                    'id' => 'cast_screens', 'label' => 'Écrans en ligne', 'value' => (int) $screens['online'], 'format' => 'number',
                    'icon' => 'i-lucide-monitor', 'tone' => 'bg-primary/10 text-primary',
                    'detail' => \sprintf('sur %d écran(s)', $total),
                    'progress' => $total > 0 ? (int) round(100 * (int) $screens['online'] / $total) : 0,
                ],
                [
                    'id' => 'cast_playlists', 'label' => 'Playlists', 'value' => $playlists, 'format' => 'number',
                    'icon' => 'i-lucide-list-video', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                ],
                [
                    'id' => 'cast_sources', 'label' => 'Sources en erreur', 'value' => (int) $sources['failing'], 'format' => 'number',
                    'icon' => 'i-lucide-plug-zap', 'tone' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                    'detail' => \sprintf('sur %d source(s)', $sources['total']),
                ],
            ],
            'recent' => [
                'title' => 'Écrans',
                'link' => '/screens',
                'empty' => 'Aucun écran : créez-en un, ou ouvrez /pair sur la TV pour l’appairer.',
                'items' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['name'],
                    'subtitle' => $row['playlist'] ?? 'Aucune playlist',
                    'at' => null === $row['last_seen_at'] ? DashboardStats::atom($row['created_at']) : DashboardStats::atom($row['last_seen_at']),
                    'badge' => null !== $row['last_seen_at'] && $row['last_seen_at'] >= $online ? 'En ligne' : 'Hors ligne',
                    'badgeColor' => null !== $row['last_seen_at'] && $row['last_seen_at'] >= $online ? 'success' : 'neutral',
                    'link' => '/screens',
                ], $this->db->fetchAllAssociative(
                    'SELECT s.id, s.name, s.last_seen_at, s.created_at, p.name AS playlist FROM screen s LEFT JOIN playlist p ON p.id = s.playlist_id
                     ORDER BY s.last_seen_at DESC NULLS LAST, s.name LIMIT 6',
                )),
            ],
            'quickActions' => [
                ['label' => 'Écrans', 'icon' => 'i-lucide-monitor', 'to' => '/screens', 'tone' => 'bg-primary/10 text-primary'],
                ['label' => 'Playlists', 'icon' => 'i-lucide-list-video', 'to' => '/playlists', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
            ],
        ];
    }
}
