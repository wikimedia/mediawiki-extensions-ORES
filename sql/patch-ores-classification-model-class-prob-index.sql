--
-- patch-ores-classification-model-class-prob-index.sql
--
-- Add index on probability to ores_classification

CREATE INDEX /*i*/oresc_model_class_prob ON /*_*/ores_classification (oresc_model, oresc_class, oresc_probability);
