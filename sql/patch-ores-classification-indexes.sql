--
-- patch-ores-classification-indexes.sql
--
-- Drop redundant oresc_rev index in favor of orecs_winner

DROP INDEX /*i*/oresc_rev ON /*_*/ores_classification;
