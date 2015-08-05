-- ORES automated classifier outputs for a given revision
--
-- Each revision will usually be assigned a probability for all classes in the
-- model's output range.
CREATE TABLE /*_*/ores_classification (
	-- Revision ID
	ores_rev INTEGER(10) NOT NULL,
	-- Model name
	ores_model VARCHAR(32) NOT NULL,
	-- Model version
	ores_model_version VARCHAR(32) NOT NULL,
	-- Classification title
	ores_class VARCHAR(32) NOT NULL,
	-- Estimated classification probability
	ores_probability DECIMAL(10,10) NOT NULL,
	-- Whether this classification has been recommended as the most likely
	-- candidate.
	ores_is_predicted TINYINT(1) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/ores_rev ON /*_*/ores_classification (ores_rev);
CREATE INDEX /*i*/ores_is_predicted ON /*_*/ores_classification (ores_is_predicted);
CREATE INDEX /*i*/ores_winner ON /*_*/ores_classification (ores_rev, ores_is_predicted);
