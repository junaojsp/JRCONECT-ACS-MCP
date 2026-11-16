-- Executar UMA vez por DBA em homologacao, nao no banco de producao
-- antes de confirmar isolamento, permissoes e backup.
-- Tabelas separadas; nunca alterar jrc_device_inventory_*.
CREATE TABLE IF NOT EXISTS jrc_collector_shadow_rows (
  snapshot_id CHAR(32) NOT NULL,
  device_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  collected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (snapshot_id, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jrc_collector_shadow_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  snapshot_id CHAR(32) NOT NULL,
  total INT UNSIGNED NOT NULL,
  ixc_matched INT UNSIGNED NOT NULL,
  metrics_json TEXT NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  completed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
