--
-- patch-ores-classification-indexes-part-ii.sql
--
-- Drop too restrictive and not useful index

DROP INDEX /*i*/oresc_rev_predicted_model ON /*_*/ores_classification;
