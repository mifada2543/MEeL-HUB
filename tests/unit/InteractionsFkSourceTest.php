<?php
use PHPUnit\Framework\TestCase;

class InteractionsFkSourceTest extends TestCase
{
    public function testSchemaHasInteractionsForeignKeys(): void
    {
        $sql = (string) file_get_contents(MEEL_ROOT . '/database/schema.sql');

        foreach (['fk_interactions_user', 'fk_interactions_video', 'fk_interactions_music'] as $fk) {
            $this->assertStringContainsString($fk, $sql, $fk . ' harus ada di schema.sql');
        }
        $this->assertSame(
            3,
            preg_match_all('/CONSTRAINT `fk_interactions_\w+` FOREIGN KEY[^)]+\)[^,]*ON DELETE CASCADE/', $sql),
            'Ketiga FK interactions harus ON DELETE CASCADE'
        );
    }

    public function testMigrationDefinesIdempotentCascadeFks(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/database/migrate.php');

        $this->assertStringContainsString(
            'FK interactions (user_id, video_id, music_id) ON DELETE CASCADE',
            $src,
            'Deskripsi migrasi v5 harus jelas'
        );
        $this->assertStringContainsString(
            'DELETE FROM interactions WHERE user_id NOT IN (SELECT id FROM users)',
            $src
        );
        $this->assertStringContainsString(
            'DELETE FROM interactions WHERE video_id IS NOT NULL AND video_id NOT IN (SELECT id FROM video)',
            $src
        );
        $this->assertStringContainsString(
            'DELETE FROM interactions WHERE music_id IS NOT NULL AND music_id NOT IN (SELECT id FROM music)',
            $src
        );

        foreach (['fk_interactions_user', 'fk_interactions_video', 'fk_interactions_music'] as $fk) {
            $this->assertStringContainsString($fk, $src, $fk . ' harus ditambahkan oleh migrasi');
        }
    }
}

/* reference build: MEeL-C10H15N [02156022562025] */
