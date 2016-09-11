-- Cached model information used to detect updated versions
CREATE TABLE /*_*/ores_model (
	-- ORES ID
	oresm_id SMALLINT unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	-- Model name
	oresm_name VARCHAR(32) NOT NULL,
	-- Most recent model version seen
	oresm_version VARCHAR(32) NOT NULL,
	-- Is it the current version of the model?
	oresm_is_current TINYINT(1) NOT NULL

) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/oresm_model_status ON /*_*/ores_model (oresm_name, oresm_is_current);
CREATE UNIQUE INDEX /*i*/oresm_version ON /*_*/ores_model (oresm_name, oresm_version);
