<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Metadata\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Exception\MetadataStorageError;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsSet;
use Doctrine\Migrations\MigrationRepository;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use InvalidArgumentException;
use function array_change_key_case;
use function floatval;
use function round;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
use const CASE_LOWER;

final class TableMetadataStorage implements MetadataStorage
{
    /** @var Connection */
    private $connection;

    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var AbstractPlatform */
    private $platform;

    /** @var TableMetadataStorageConfiguration */
    private $configuration;

    /** @var MigrationRepository|null */
    private $migrationRepository;

    public function __construct(
        Connection $connection,
        ?MetadataStorageConfiguration $configuration = null,
        ?MigrationRepository $migrationRepository = null
    ) {
        $this->migrationRepository = $migrationRepository;
        $this->connection          = $connection;
        $this->schemaManager       = $connection->getSchemaManager();
        $this->platform            = $connection->getDatabasePlatform();

        if ($configuration !== null && ! ($configuration instanceof TableMetadataStorageConfiguration)) {
            throw new InvalidArgumentException(sprintf('%s accepts only %s as configuration', self::class, TableMetadataStorageConfiguration::class));
        }

        $this->configuration = $configuration ?: new TableMetadataStorageConfiguration();
    }

    public function getExecutedMigrations() : ExecutedMigrationsSet
    {
        $this->checkInitialization();
        $rows = $this->connection->fetchAll(sprintf('SELECT * FROM %s', $this->configuration->getTableName()));

        $migrations = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);

            $version = new Version($row[strtolower($this->configuration->getVersionColumnName())]);

            $executedAt = $row[strtolower($this->configuration->getExecutedAtColumnName())] ?? '';
            $executedAt = $executedAt !== ''
                ? DateTimeImmutable::createFromFormat($this->platform->getDateTimeFormatString(), $executedAt)
                : null;

            $executionTime = isset($row[strtolower($this->configuration->getExecutionTimeColumnName())])
                ? floatval($row[strtolower($this->configuration->getExecutionTimeColumnName())]/1000)
                : null;

            $migration = new ExecutedMigration(
                $version,
                $executedAt instanceof DateTimeImmutable ? $executedAt : null,
                $executionTime
            );

            $migrations[(string) $version] = $migration;
        }

        return new ExecutedMigrationsSet($migrations);
    }

    public function reset() : void
    {
        $this->checkInitialization();

        $this->connection->executeUpdate(
            sprintf(
                'DELETE FROM %s WHERE 1 = 1',
                $this->platform->quoteIdentifier($this->configuration->getTableName())
            )
        );
    }

    public function complete(ExecutionResult $result) : void
    {
        $this->checkInitialization();

        if ($result->getDirection() === Direction::DOWN) {
            $this->connection->delete($this->configuration->getTableName(), [
                $this->configuration->getVersionColumnName() => (string) $result->getVersion(),
            ]);
        } else {
            $this->connection->insert($this->configuration->getTableName(), [
                $this->configuration->getVersionColumnName() => (string) $result->getVersion(),
                $this->configuration->getExecutedAtColumnName() => $result->getExecutedAt(),
                $this->configuration->getExecutionTimeColumnName() => $result->getTime() === null ? null : round($result->getTime()*1000),
            ], [
                Type::STRING,
                Type::DATETIME,
                Type::INTEGER,
            ]);
        }
    }

    public function ensureInitialized() : void
    {
        $expectedSchemaChangelog = $this->getExpectedTable();

        if (! $this->isInitialized($expectedSchemaChangelog)) {
            $this->schemaManager->createTable($expectedSchemaChangelog);

            return;
        }

        $diff = $this->needsUpdate($expectedSchemaChangelog);
        if ($diff === null) {
            return;
        }

        $this->schemaManager->alterTable($diff);
        $this->updateMigratedVersionsFromV1orV2toV3();
    }

    private function needsUpdate(Table $expectedTable) : ?TableDiff
    {
        $comparator   = new Comparator();
        $currentTable = $this->schemaManager->listTableDetails($this->configuration->getTableName());
        $diff         = $comparator->diffTable($currentTable, $expectedTable);

        return $diff instanceof TableDiff ? $diff : null;
    }

    private function isInitialized(Table $expectedTable) : bool
    {
        if ($this->connection instanceof MasterSlaveConnection) {
            $this->connection->connect('master');
        }

        return $this->schemaManager->tablesExist([$expectedTable->getName()]);
    }

    private function checkInitialization() : void
    {
        $expectedTable = $this->getExpectedTable();

        if (! $this->isInitialized($expectedTable)) {
            throw MetadataStorageError::notInitialized();
        }

        if ($this->needsUpdate($expectedTable) !== null) {
            throw MetadataStorageError::notUpToDate();
        }
    }

    private function getExpectedTable() : Table
    {
        $schemaChangelog = new Table($this->configuration->getTableName());

        $schemaChangelog->addColumn(
            $this->configuration->getVersionColumnName(),
            'string',
            ['notnull' => true, 'length' => $this->configuration->getVersionColumnLength()]
        );
        $schemaChangelog->addColumn($this->configuration->getExecutedAtColumnName(), 'datetime', ['notnull' => false]);
        $schemaChangelog->addColumn($this->configuration->getExecutionTimeColumnName(), 'integer', ['notnull' => false]);

        $schemaChangelog->setPrimaryKey([$this->configuration->getVersionColumnName()]);

        return $schemaChangelog;
    }

    private function updateMigratedVersionsFromV1orV2toV3() : void
    {
        if ($this->migrationRepository === null) {
            return;
        }

        $availableMigrations = $this->migrationRepository->getMigrations()->getItems();
        $executedMigrations  = $this->getExecutedMigrations()->getItems();

        foreach ($availableMigrations as $availableMigration) {
            foreach ($executedMigrations as $k => $executedMigration) {
                if ($this->isAlreadyV3Format($availableMigration, $executedMigration)) {
                    continue;
                }

                $this->connection->update(
                    $this->configuration->getTableName(),
                    [
                        $this->configuration->getVersionColumnName() => (string) $availableMigration->getVersion(),
                    ],
                    [
                        $this->configuration->getVersionColumnName() => (string) $executedMigration->getVersion(),
                    ]
                );
                unset($executedMigrations[$k]);
            }
        }
    }

    private function isAlreadyV3Format(AvailableMigration $availableMigration, ExecutedMigration $executedMigration) : bool
    {
        return strpos(
            (string) $availableMigration->getVersion(),
            (string) $executedMigration->getVersion()
        ) !== strlen((string) $availableMigration->getVersion()) -
                strlen((string) $executedMigration->getVersion());
    }
}