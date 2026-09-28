<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928172458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rocket Cast: screens, playlists, sources, pairing requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pairing_request (id UUID NOT NULL, code VARCHAR(6) NOT NULL, secret_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sealed_token TEXT DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, screen_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7702B951B390A88D ON pairing_request (secret_hash)');
        $this->addSql('CREATE INDEX idx_pairing_code ON pairing_request (code)');
        $this->addSql('CREATE INDEX IDX_7702B95141A67722 ON pairing_request (screen_id)');
        $this->addSql('CREATE TABLE playlist (id UUID NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, panels JSON NOT NULL, accent VARCHAR(7) NOT NULL, theme VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE screen (id UUID NOT NULL, name VARCHAR(120) NOT NULL, location VARCHAR(255) DEFAULT NULL, token_hash VARCHAR(64) DEFAULT NULL, token_hint VARCHAR(8) DEFAULT NULL, orientation VARCHAR(16) NOT NULL, timezone VARCHAR(64) NOT NULL, locale VARCHAR(16) NOT NULL, enabled BOOLEAN NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_user_agent VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, playlist_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DF4C6130B3BC57DA ON screen (token_hash)');
        $this->addSql('CREATE INDEX IDX_DF4C61306BBD148 ON screen (playlist_id)');
        $this->addSql('CREATE TABLE source (id UUID NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(32) NOT NULL, config JSON NOT NULL, sealed_secrets TEXT DEFAULT \'\' NOT NULL, refresh_seconds INT NOT NULL, enabled BOOLEAN NOT NULL, payload JSON DEFAULT NULL, fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE pairing_request ADD CONSTRAINT FK_7702B95141A67722 FOREIGN KEY (screen_id) REFERENCES screen (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE screen ADD CONSTRAINT FK_DF4C61306BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pairing_request DROP CONSTRAINT FK_7702B95141A67722');
        $this->addSql('ALTER TABLE screen DROP CONSTRAINT FK_DF4C61306BBD148');
        $this->addSql('DROP TABLE pairing_request');
        $this->addSql('DROP TABLE playlist');
        $this->addSql('DROP TABLE screen');
        $this->addSql('DROP TABLE source');
    }
}
