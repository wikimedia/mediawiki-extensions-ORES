--
-- patch-ores-classification-unique-indexes.sql
--
-- Add unique indexes, and drop oresc_winner (redundant with oresc_rev_predicted_model)

DROP INDEX /*i*/oresc_winner ON /*_*/ores_classification;
CREATE UNIQUE INDEX /*i*/oresc_rev_model_class ON /*_*/ores_classification (oresc_rev, oresc_model, oresc_class);
CREATE UNIQUE INDEX /*i*/oresc_rev_predicted_model ON /*_*/ores_classification (oresc_rev, oresc_is_predicted, oresc_model);
