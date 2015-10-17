-- Cached model information used to detect updated versions
CREATE TABLE /*_*/ores_model (
	-- Model name
	ores_model VARCHAR(32) NOT NULL,
	-- Most recent model version seen
	ores_model_version VARCHAR(32) NOT NULL
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/ores_model ON /*_*/ores_model (ores_model);

