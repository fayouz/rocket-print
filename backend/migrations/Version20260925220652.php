<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925220652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Printing: printers and print jobs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE print_job (id UUID NOT NULL, printer_name VARCHAR(120) NOT NULL, title VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size INT NOT NULL, copies SMALLINT NOT NULL, duplex BOOLEAN NOT NULL, color BOOLEAN NOT NULL, status VARCHAR(16) NOT NULL, attempts SMALLINT NOT NULL, error TEXT DEFAULT NULL, external_id VARCHAR(120) DEFAULT NULL, printed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, storage_key VARCHAR(80) NOT NULL, content_purged BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, owner_id UUID NOT NULL, application_id UUID DEFAULT NULL, printer_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4FAA469B111795A5 ON print_job (storage_key)');
        $this->addSql('CREATE INDEX idx_print_job_owner_created ON print_job (owner_id, created_at)');
        $this->addSql('CREATE INDEX idx_print_job_status ON print_job (status)');
        $this->addSql('CREATE INDEX IDX_4FAA469B7E3C61F9 ON print_job (owner_id)');
        $this->addSql('CREATE INDEX IDX_4FAA469B3E030ACD ON print_job (application_id)');
        $this->addSql('CREATE INDEX IDX_4FAA469B46EC494A ON print_job (printer_id)');
        $this->addSql('CREATE TABLE printer (id UUID NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, location VARCHAR(180) DEFAULT NULL, connector VARCHAR(16) NOT NULL, uri VARCHAR(500) NOT NULL, username VARCHAR(180) DEFAULT NULL, domain VARCHAR(120) DEFAULT NULL, password TEXT DEFAULT \'\' NOT NULL, color_supported BOOLEAN NOT NULL, duplex_supported BOOLEAN NOT NULL, default_printer BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D4C79ED5E237E06 ON printer (name)');
        $this->addSql('ALTER TABLE print_job ADD CONSTRAINT FK_4FAA469B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE print_job ADD CONSTRAINT FK_4FAA469B3E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE print_job ADD CONSTRAINT FK_4FAA469B46EC494A FOREIGN KEY (printer_id) REFERENCES printer (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE print_job DROP CONSTRAINT FK_4FAA469B7E3C61F9');
        $this->addSql('ALTER TABLE print_job DROP CONSTRAINT FK_4FAA469B3E030ACD');
        $this->addSql('ALTER TABLE print_job DROP CONSTRAINT FK_4FAA469B46EC494A');
        $this->addSql('DROP TABLE print_job');
        $this->addSql('DROP TABLE printer');
    }
}
