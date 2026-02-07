<?php

namespace Ksfraser\SchemaManager;

use Ksfraser\ModulesDAO\Db\DbAdapterInterface;

/**
 * Schema Manager for Product Attributes Database Tables
 *
 * This class provides programmatic schema management for the product attributes module.
 * It supports both MySQL/MariaDB (FrontAccounting's primary database) and SQLite databases.
 *
 * WHY PROGRAMMATIC SCHEMA MANAGEMENT:
 * - Unlike sql/schema.sql (used for manual installation), this enables automatic
 *   schema creation during testing, development, and runtime initialization
 * - Handles database dialect differences (MySQL vs SQLite syntax)
 * - Can be called safely multiple times (CREATE TABLE IF NOT EXISTS)
 * - Enables seamless integration with different database backends
 * - sql/schema.sql uses {{prefix}} placeholders for manual replacement
 * - SchemaManager uses programmatic prefix substitution for automation
 *
 * SCHEMA OVERVIEW:
 * - product_attribute_categories: Defines attribute categories (e.g., Color, Size)
 * - product_attribute_values: Defines values within categories (e.g., Red, Blue)
 * - product_attribute_assignments: Links products to their attribute values
 *
 * The schema follows royal order principles with sort_order fields for proper
 * attribute sequencing (Size, Color, Material, etc.).
 */
class SchemaManager
{
    /**
     * Ensure the complete product attributes schema exists
     *
     * This method creates all necessary tables if they don't already exist.
     * It's safe to call multiple times and handles both MySQL and SQLite dialects.
     *
     * @param DbAdapterInterface $db Database adapter to execute schema creation
     */
    public function ensureSchema(DbAdapterInterface $db): void
    {
        $p = $db->getTablePrefix();

        // SQLite uses different syntax for auto-increment and data types
        if ($db->getDialect() === 'sqlite') {
            $this->ensureSqliteSchema($db, $p);
            return;
        }

        // MySQL/MariaDB schema (FrontAccounting's primary database)
        $this->ensureMysqlSchema($db, $p);
    }

    /**
     * Create MySQL/MariaDB schema
     *
     * Uses MySQL-specific syntax: AUTO_INCREMENT, INT(11), TINYINT(1), etc.
     * This matches FrontAccounting's database structure.
     */
    private function ensureMysqlSchema(DbAdapterInterface $db, string $p): void
    {
        // Categories table: Defines attribute types (Color, Size, Material, etc.)
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_categories` (\n"
            . "  id INT(11) NOT NULL AUTO_INCREMENT,\n"
            . "  code VARCHAR(64) NOT NULL,\n"           // Unique identifier (e.g., 'color', 'size')
            . "  label VARCHAR(64) NOT NULL,\n"          // Human-readable name (e.g., 'Color', 'Size')
            . "  description VARCHAR(255) NULL,\n"       // Optional description
            . "  sort_order INT(11) NOT NULL DEFAULT 0,\n" // Royal order sorting (size=1, color=2, etc.)
            . "  active TINYINT(1) NOT NULL DEFAULT 1,\n"   // Soft delete flag
            . "  updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (id),\n"
            . "  UNIQUE KEY uq_code (code)\n"            // Ensure unique category codes
            . ");"
        );

        // Values table: Defines specific values within categories
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_values` (\n"
            . "  id INT(11) NOT NULL AUTO_INCREMENT,\n"
            . "  category_id INT(11) NOT NULL,\n"        // Foreign key to categories
            . "  value VARCHAR(64) NOT NULL,\n"          // Display value (e.g., 'Red', 'Large')
            . "  slug VARCHAR(32) NOT NULL,\n"           // URL-safe identifier (e.g., 'red', 'large')
            . "  sort_order INT(11) NOT NULL DEFAULT 0,\n" // Within-category sorting
            . "  active TINYINT(1) NOT NULL DEFAULT 1,\n"   // Soft delete flag
            . "  updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (id),\n"
            . "  UNIQUE KEY uq_category_slug (category_id, slug),\n" // Unique slugs per category
            . "  KEY idx_category (category_id)\n"       // Index for category lookups
            . ");"
        );

        // Assignments table: Links products to their attribute values
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_assignments` (\n"
            . "  id INT(11) NOT NULL AUTO_INCREMENT,\n"
            . "  stock_id VARCHAR(32) NOT NULL,\n"       // FrontAccounting stock_id (SKU)
            . "  category_id INT(11) NOT NULL,\n"        // Attribute category
            . "  value_id INT(11) NOT NULL,\n"           // Specific value
            . "  sort_order INT(11) NOT NULL DEFAULT 0,\n" // Assignment ordering
            . "  updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (id),\n"
            . "  UNIQUE KEY uq_stock_category_value (stock_id, category_id, value_id),\n" // Prevent duplicates
            . "  KEY idx_stock (stock_id),\n"            // Index for product lookups
            . "  KEY idx_category (category_id),\n"      // Index for category filtering
            . "  KEY idx_value (value_id)\n"             // Index for value filtering
            . ");"
        );

        // Category Assignments table: Links products to their attribute categories
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_category_assignments` (\n"
            . "  id INT(11) NOT NULL AUTO_INCREMENT,\n"
            . "  stock_id VARCHAR(32) NOT NULL,\n"       // FrontAccounting stock_id (SKU)
            . "  category_id INT(11) NOT NULL,\n"        // Attribute category
            . "  updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (id),\n"
            . "  UNIQUE KEY uq_stock_category (stock_id, category_id),\n" // Prevent duplicate category assignments
            . "  KEY idx_stock (stock_id),\n"            // Index for product lookups
            . "  KEY idx_category (category_id)\n"       // Index for category filtering
            . ");"
        );
    }

    /**
     * Create SQLite schema
     *
     * Uses SQLite-specific syntax: INTEGER PRIMARY KEY AUTOINCREMENT, TEXT, etc.
     * Separate indexes are created explicitly as SQLite handles them differently.
     */
    private function ensureSqliteSchema(DbAdapterInterface $db, string $p): void
    {
        // Categories table for SQLite
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_categories` (\n"
            . "  id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  code TEXT NOT NULL UNIQUE,\n"
            . "  label TEXT NOT NULL,\n"
            . "  description TEXT NULL,\n"
            . "  sort_order INTEGER NOT NULL DEFAULT 0,\n"
            . "  active INTEGER NOT NULL DEFAULT 1,\n"
            . "  updated_ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            . ");"
        );

        // Values table for SQLite
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_values` (\n"
            . "  id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  category_id INTEGER NOT NULL,\n"
            . "  value TEXT NOT NULL,\n"
            . "  slug TEXT NOT NULL,\n"
            . "  sort_order INTEGER NOT NULL DEFAULT 0,\n"
            . "  active INTEGER NOT NULL DEFAULT 1,\n"
            . "  updated_ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  UNIQUE(category_id, slug)\n"            // SQLite inline unique constraint
            . ");"
        );
        // Explicit index for category lookups
        $db->execute("CREATE INDEX IF NOT EXISTS idx_pav_category ON `{$p}product_attribute_values`(category_id);");

        // Assignments table for SQLite
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_assignments` (\n"
            . "  id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  stock_id TEXT NOT NULL,\n"
            . "  category_id INTEGER NOT NULL,\n"
            . "  value_id INTEGER NOT NULL,\n"
            . "  sort_order INTEGER NOT NULL DEFAULT 0,\n"
            . "  updated_ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  UNIQUE(stock_id, category_id, value_id)\n" // Prevent duplicate assignments
            . ");"
        );
        // Explicit indexes for performance
        $db->execute("CREATE INDEX IF NOT EXISTS idx_paa_stock ON `{$p}product_attribute_assignments`(stock_id);");
        $db->execute("CREATE INDEX IF NOT EXISTS idx_paa_category ON `{$p}product_attribute_assignments`(category_id);");
        $db->execute("CREATE INDEX IF NOT EXISTS idx_paa_value ON `{$p}product_attribute_assignments`(value_id);");

        // Category Assignments table for SQLite
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}product_attribute_category_assignments` (\n"
            . "  id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  stock_id TEXT NOT NULL,\n"
            . "  category_id INTEGER NOT NULL,\n"
            . "  updated_ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  UNIQUE(stock_id, category_id)\n" // Prevent duplicate category assignments
            . ");"
        );
        // Explicit indexes for performance
        $db->execute("CREATE INDEX IF NOT EXISTS idx_paca_stock ON `{$p}product_attribute_category_assignments`(stock_id);");
        $db->execute("CREATE INDEX IF NOT EXISTS idx_paca_category ON `{$p}product_attribute_category_assignments`(category_id);");
    }
}
