-- BeSix Time — DB setup
-- Spustit jednou na sdíleném DB serveru

-- Registrace aplikace
INSERT IGNORE INTO apps (app_key, app_name) VALUES ('time', 'BeSix Time — Harmonogram');

-- Tabulka pro harmonogramy (1 harmonogram per projekt)
CREATE TABLE IF NOT EXISTS time_schedules (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  project_id  INT NOT NULL,
  data        LONGTEXT NOT NULL,
  updated_by  INT DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
