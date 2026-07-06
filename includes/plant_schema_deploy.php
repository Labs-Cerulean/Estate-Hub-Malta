<?php
/**
 * Plant Hub schema — manual deploy only.
 * Run sql/plant_hub_schema_phpmyadmin.sql in phpMyAdmin before enabling new Plant features.
 * This function is retained for backwards compatibility but performs no DDL at runtime.
 */
function plantDeploySchema(PDO $pdo): void {
    // Schema is managed manually via sql/plant_hub_schema_phpmyadmin.sql — no runtime ALTER/CREATE.
}
