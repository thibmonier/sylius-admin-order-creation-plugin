<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the admin order creation table (tmonier/sylius-admin-order-creation-plugin).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('tmonier_admin_order_creation');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('order_id', Types::INTEGER);
        $table->addColumn('admin_user_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('source_order_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('admin_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('confirmation_email_sent', Types::BOOLEAN);
        $table->addColumn('payment_link_sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['order_id'], 'UNIQ_D4846D838D9F6D38');
        $table->addIndex(['admin_user_id'], 'IDX_D4846D836352511C');
        $table->addIndex(['source_order_id'], 'IDX_D4846D83CEBF5BEA');
        $table->addIndex(['completed_at'], 'tmonier_admin_order_creation_completed_idx');
        $table->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_D4846D838D9F6D38');
        $table->addForeignKeyConstraint('sylius_admin_user', ['admin_user_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_D4846D836352511C');
        $table->addForeignKeyConstraint('sylius_order', ['source_order_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_D4846D83CEBF5BEA');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_admin_order_creation');
    }
}
