<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Add ON DELETE CASCADE so that node dimension data is removed with node data.
 */
class Version20141003233738 extends AbstractMigration
{
    /**
     * @param Schema $schema
     * @return void
     */
    public function up(Schema $schema)
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

        $this->addSql("ALTER TABLE typo3_typo3cr_domain_model_nodedimension DROP FOREIGN KEY FK_6C144D3693BDC8E2");
        $this->addSql("ALTER TABLE typo3_typo3cr_domain_model_nodedimension ADD CONSTRAINT FK_6C144D3693BDC8E2 FOREIGN KEY (nodedata) REFERENCES typo3_typo3cr_domain_model_nodedata (persistence_object_identifier) ON DELETE CASCADE");
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function down(Schema $schema)
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

        $this->addSql("ALTER TABLE typo3_typo3cr_domain_model_nodedimension DROP FOREIGN KEY FK_6C144D3693BDC8E2");
        $this->addSql("ALTER TABLE typo3_typo3cr_domain_model_nodedimension ADD CONSTRAINT FK_6C144D3693BDC8E2 FOREIGN KEY (nodedata) REFERENCES typo3_typo3cr_domain_model_nodedata (persistence_object_identifier)");
    }
}
