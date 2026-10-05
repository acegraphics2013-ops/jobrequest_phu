-- =============================================================
-- HPU Workbench - IEC Request Link (Phase 2)
-- Links job_request_iec_items rows to the iec_materials library
-- and adds fulfilled-quantity tracking for the monthly
-- consolidation on reports/evaluation_analytics.php.
--
-- INSTALLATION
-- 1. Back up your project and database.
-- 2. Import this file into the hpu_job_request database, OR
--    let user/save_request.php auto-apply the same columns on
--    the next submit (identical definitions, FK added only when
--    the iec_materials table exists).
--
-- NOTES
-- - iec_material_id is BIGINT UNSIGNED to match
--   iec_materials.id. Legacy free-text rows keep NULL.
-- - fulfilled_qty defaults to 0; staff update it on
--   staff/review_request.php (IEC Fulfillment card).
-- =============================================================

ALTER TABLE job_request_iec_items
  ADD COLUMN iec_material_id BIGINT UNSIGNED NULL AFTER material_name,
  ADD COLUMN fulfilled_qty INT NOT NULL DEFAULT 0 AFTER quantity,
  ADD INDEX idx_iec_item_material (iec_material_id);

-- The foreign key is optional; skip it when iec_materials is absent.
ALTER TABLE job_request_iec_items
  ADD CONSTRAINT fk_iec_item_material
  FOREIGN KEY (iec_material_id)
  REFERENCES iec_materials (id)
  ON DELETE SET NULL;
