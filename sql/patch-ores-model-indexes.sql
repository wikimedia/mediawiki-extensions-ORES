--
-- patch-ores-model-indexes.sql
--
-- Remove unique contraint from index oresm_model

DROP INDEX /*i*/oresm_model ON /*_*/ores_model;
CREATE INDEX /*i*/oresm_model_status ON /*_*/ores_model (oresm_name, oresm_is_current);
