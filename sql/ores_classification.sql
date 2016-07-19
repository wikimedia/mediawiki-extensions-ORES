-- ORES automated classifier outputs for a given revision
--
-- Each revision will usually be assigned a probability for all classes in the
-- model's output range.
CREATE TABLE /*_*/ores_classification (
	-- ORES ID
	oresc_id bigint unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	-- Revision ID
	oresc_rev INTEGER(10) unsigned NOT NULL,
	-- Model name (foreign key to ores_model.oresm_id)
	oresc_model SMALLINT NOT NULL,
	-- Classification title
	oresc_class TINYINT NOT NULL,
	-- Estimated classification probability
	oresc_probability DECIMAL(3,3) NOT NULL,
	-- Whether this classification has been recommended as the most likely
	-- candidate.
	oresc_is_predicted TINYINT(1) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/oresc_winner ON /*_*/ores_classification (oresc_rev, oresc_is_predicted);
