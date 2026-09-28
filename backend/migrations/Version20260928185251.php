<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928185251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rocket Cast: optional Rocket Place place of a screen';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE screen ADD place_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE screen ADD place_name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE screen DROP place_id');
        $this->addSql('ALTER TABLE screen DROP place_name');
    }
}
