<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo-admin-area:import',
    description: 'Importe des zones administratives depuis un fichier GeoJSON dans geo_admin_area.'
)]
final class ImportGeoAdminAreaCommand extends Command
{
    public function __construct(private Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier GeoJSON')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Vide geo_admin_area avant import')
            ->addOption('name-field', null, InputOption::VALUE_REQUIRED, 'Champ propriété pour le nom', 'name')
            ->addOption('type-field', null, InputOption::VALUE_REQUIRED, 'Champ propriété pour le type', 'type')
            ->addOption('id-field', null, InputOption::VALUE_REQUIRED, 'Champ propriété pour l’identifiant source', 'id')
            ->addOption('parent-field', null, InputOption::VALUE_REQUIRED, 'Champ propriété pour l’identifiant parent', 'parent_id')
            ->addOption('default-type', null, InputOption::VALUE_REQUIRED, 'Type par défaut si absent', 'unknown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        $replace = (bool) $input->getOption('replace');
        $nameField = (string) $input->getOption('name-field');
        $typeField = (string) $input->getOption('type-field');
        $idField = (string) $input->getOption('id-field');
        $parentField = (string) $input->getOption('parent-field');
        $defaultType = trim((string) $input->getOption('default-type'));

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Fichier introuvable ou illisible: %s', $file));

            return Command::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('GeoJSON invalide: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $features = $this->extractFeatures($payload);
        if ($features === []) {
            $io->error('Aucune feature trouvée dans le fichier GeoJSON.');

            return Command::FAILURE;
        }

        $inserted = 0;
        $skipped = 0;
        $sourceIdToDbId = [];
        $pendingParents = [];

        $this->db->beginTransaction();

        try {
            if ($replace) {
                $this->db->executeStatement('TRUNCATE TABLE geo_admin_area RESTART IDENTITY CASCADE');
            }

            foreach ($features as $index => $feature) {
                if (!is_array($feature)) {
                    $skipped++;
                    continue;
                }

                $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                $geometry = $feature['geometry'] ?? null;

                $name = trim((string) ($properties[$nameField] ?? ''));
                if ($name === '') {
                    $skipped++;
                    $io->warning(sprintf('Feature #%d ignorée: champ "%s" absent.', $index, $nameField));
                    continue;
                }

                $type = trim((string) ($properties[$typeField] ?? $defaultType));
                if ($type === '') {
                    $type = 'unknown';
                }

                $geometryJson = null;
                if (is_array($geometry)) {
                    $geometryJson = json_encode($geometry, JSON_THROW_ON_ERROR);
                }

                $dbId = (int) $this->db->fetchOne(
                    '
                    INSERT INTO geo_admin_area (name, type, parent_id, boundary)
                    VALUES (
                        :name,
                        :type,
                        NULL,
                        CASE
                            WHEN :geometry IS NULL THEN NULL
                            ELSE ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326))::geography
                        END
                    )
                    RETURNING id
                    ',
                    [
                        'name' => $name,
                        'type' => $type,
                        'geometry' => $geometryJson,
                    ]
                );

                $inserted++;

                $sourceId = $properties[$idField] ?? null;
                if ($sourceId !== null && $sourceId !== '') {
                    $sourceIdToDbId[(string) $sourceId] = $dbId;
                }

                $parentSourceId = $properties[$parentField] ?? null;
                if ($parentSourceId !== null && $parentSourceId !== '') {
                    $pendingParents[] = [
                        'db_id' => $dbId,
                        'parent_source_id' => (string) $parentSourceId,
                    ];
                }
            }

            foreach ($pendingParents as $link) {
                $parentDbId = $sourceIdToDbId[$link['parent_source_id']] ?? null;
                if ($parentDbId === null) {
                    continue;
                }

                $this->db->executeStatement(
                    'UPDATE geo_admin_area SET parent_id = :parentId WHERE id = :id',
                    [
                        'parentId' => $parentDbId,
                        'id' => $link['db_id'],
                    ]
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $io->error(sprintf('Import interrompu: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Import terminé. %d zone(s) insérée(s), %d ignorée(s).',
            $inserted,
            $skipped
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractFeatures(array $payload): array
    {
        if (($payload['type'] ?? null) === 'FeatureCollection' && is_array($payload['features'] ?? null)) {
            return $payload['features'];
        }

        if (($payload['type'] ?? null) === 'Feature') {
            return [$payload];
        }

        return [];
    }
}
