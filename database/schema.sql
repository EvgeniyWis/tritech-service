-- ТрайтэкСервис: схема БД (MySQL 8 / MariaDB 10.4+), utf8mb4
-- 30 таблиц: общие, модуль «Консалтинг», модули «Разработка» и «Техподдержка».

SET FOREIGN_KEY_CHECKS = 0;

-- ===================== Общие =====================
CREATE TABLE roles (
  id    TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code  VARCHAR(30) NOT NULL UNIQUE,
  name  VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email              VARCHAR(190) NOT NULL UNIQUE,
  password_hash      VARCHAR(255) NOT NULL,
  last_name          VARCHAR(50)  NOT NULL,
  first_name         VARCHAR(50)  NOT NULL,
  middle_name        VARCHAR(50)  NULL,
  phone              VARCHAR(20)  NULL,
  company            VARCHAR(150) NULL,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  pd_consent_at      DATETIME     NULL,
  email_verified_at  DATETIME     NULL,
  verify_token_hash  CHAR(64)     NULL,
  reset_token_hash   CHAR(64)     NULL,
  reset_expires_at   DATETIME     NULL,
  failed_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME     NULL,
  last_login_at      DATETIME     NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
  user_id  INT UNSIGNED NOT NULL,
  role_id  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  name         VARCHAR(60) PRIMARY KEY,
  value        VARCHAR(255) NOT NULL,
  description  VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60)  NOT NULL,
  entity_type  VARCHAR(30)  NULL,
  entity_id    INT UNSIGNED NULL,
  details      VARCHAR(500) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME NOT NULL,
  INDEX idx_log_created (created_at),
  INDEX idx_log_user (user_id),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entity_status_history (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type  ENUM('ticket','task','project') NOT NULL,
  entity_id    INT UNSIGNED NOT NULL,
  old_status   VARCHAR(30) NULL,
  new_status   VARCHAR(30) NOT NULL,
  changed_by   INT UNSIGNED NULL,
  changed_at   DATETIME NOT NULL,
  comment      VARCHAR(255) NULL,
  INDEX idx_esh_entity (entity_type, entity_id, changed_at),
  CONSTRAINT fk_esh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== Консалтинг =====================
CREATE TABLE specializations (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL UNIQUE,
  description  VARCHAR(500) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consultants (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL UNIQUE,
  position          VARCHAR(150) NOT NULL,
  bio               TEXT NULL,
  experience_years  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cons_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consultant_specializations (
  consultant_id      INT UNSIGNED NOT NULL,
  specialization_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (consultant_id, specialization_id),
  CONSTRAINT fk_cs_cons FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_spec FOREIGN KEY (specialization_id) REFERENCES specializations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE services (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  specialization_id  SMALLINT UNSIGNED NOT NULL,
  name               VARCHAR(150) NOT NULL,
  description        VARCHAR(500) NULL,
  duration_min       SMALLINT UNSIGNED NOT NULL,
  price              DECIMAL(10,2) NOT NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT chk_srv_dur CHECK (duration_min BETWEEN 15 AND 480),
  CONSTRAINT chk_srv_price CHECK (price >= 0),
  CONSTRAINT fk_srv_spec FOREIGN KEY (specialization_id) REFERENCES specializations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE work_schedules (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  consultant_id  INT UNSIGNED NOT NULL,
  weekday        TINYINT UNSIGNED NOT NULL,
  start_time     TIME NOT NULL,
  end_time       TIME NOT NULL,
  CONSTRAINT chk_ws_day CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT chk_ws_time CHECK (end_time > start_time),
  INDEX idx_ws_cons (consultant_id, weekday),
  CONSTRAINT fk_ws_cons FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schedule_exceptions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  consultant_id  INT UNSIGNED NOT NULL,
  date           DATE NOT NULL,
  is_working     TINYINT(1) NOT NULL DEFAULT 0,
  start_time     TIME NULL,
  end_time       TIME NULL,
  reason         VARCHAR(200) NULL,
  UNIQUE KEY uq_se (consultant_id, date),
  CONSTRAINT fk_se_cons FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointment_statuses (
  id           TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30) NOT NULL UNIQUE,
  name         VARCHAR(50) NOT NULL,
  blocks_slot  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id         INT UNSIGNED NOT NULL,
  consultant_id     INT UNSIGNED NOT NULL,
  service_id        INT UNSIGNED NOT NULL,
  status_id         TINYINT UNSIGNED NOT NULL,
  start_at          DATETIME NOT NULL,
  end_at            DATETIME NOT NULL,
  price             DECIMAL(10,2) NOT NULL,
  payment_deadline  DATETIME NULL,
  client_comment    TEXT NULL,
  meeting_url       VARCHAR(255) NULL,
  cancelled_by      ENUM('client','consultant','manager','system') NULL,
  cancel_reason     VARCHAR(255) NULL,
  cancelled_at      DATETIME NULL,
  reschedule_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reminder_24_sent  TINYINT(1) NOT NULL DEFAULT 0,
  reminder_1_sent   TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NULL,
  CONSTRAINT chk_app_time CHECK (end_at > start_at),
  INDEX idx_app_cons (consultant_id, start_at),
  INDEX idx_app_client (client_id),
  INDEX idx_app_deadline (status_id, payment_deadline),
  CONSTRAINT fk_app_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_app_cons FOREIGN KEY (consultant_id) REFERENCES consultants(id),
  CONSTRAINT fk_app_srv FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_app_status FOREIGN KEY (status_id) REFERENCES appointment_statuses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE status_history (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id  INT UNSIGNED NOT NULL,
  old_status_id   TINYINT UNSIGNED NULL,
  new_status_id   TINYINT UNSIGNED NOT NULL,
  changed_by      INT UNSIGNED NULL,
  changed_at      DATETIME NOT NULL,
  comment         VARCHAR(255) NULL,
  INDEX idx_sh_app (appointment_id),
  CONSTRAINT fk_sh_app FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id     INT UNSIGNED NOT NULL,
  parent_payment_id  INT UNSIGNED NULL,
  type               ENUM('payment','refund') NOT NULL DEFAULT 'payment',
  amount             DECIMAL(10,2) NOT NULL,
  status             ENUM('pending','succeeded','canceled') NOT NULL DEFAULT 'pending',
  method             VARCHAR(30) NULL,
  external_id        VARCHAR(64) NULL,
  confirmation_url   VARCHAR(500) NULL,
  idempotence_key    CHAR(36) NOT NULL,
  created_at         DATETIME NOT NULL,
  paid_at            DATETIME NULL,
  UNIQUE KEY uq_pay_idem (idempotence_key),
  INDEX idx_pay_app (appointment_id),
  INDEX idx_pay_ext (external_id),
  CONSTRAINT fk_pay_app FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  CONSTRAINT fk_pay_parent FOREIGN KEY (parent_payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consultation_notes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id  INT UNSIGNED NOT NULL,
  author_id       INT UNSIGNED NOT NULL,
  version         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status          ENUM('draft','final') NOT NULL DEFAULT 'draft',
  summary         TEXT NOT NULL,
  recommendations TEXT NULL,
  created_at      DATETIME NOT NULL,
  UNIQUE KEY uq_cn_version (appointment_id, version),
  CONSTRAINT fk_cn_app FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  CONSTRAINT fk_cn_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reviews (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id  INT UNSIGNED NOT NULL UNIQUE,
  client_id       INT UNSIGNED NOT NULL,
  consultant_id   INT UNSIGNED NOT NULL,
  rating          TINYINT UNSIGNED NOT NULL,
  text            TEXT NULL,
  status          ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
  moderated_by    INT UNSIGNED NULL,
  moderated_at    DATETIME NULL,
  created_at      DATETIME NOT NULL,
  CONSTRAINT chk_rev_rating CHECK (rating BETWEEN 1 AND 5),
  INDEX idx_rev_cons (consultant_id, status),
  CONSTRAINT fk_rev_app FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  CONSTRAINT fk_rev_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_rev_cons FOREIGN KEY (consultant_id) REFERENCES consultants(id),
  CONSTRAINT fk_rev_mod FOREIGN KEY (moderated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== Справочники ТП и разработки =====================
CREATE TABLE priorities (
  id                TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(20) NOT NULL UNIQUE,
  name              VARCHAR(50) NOT NULL,
  level             TINYINT UNSIGNED NOT NULL UNIQUE,
  weight            TINYINT UNSIGNED NOT NULL,
  response_minutes  SMALLINT UNSIGNED NOT NULL,
  resolve_hours     SMALLINT UNSIGNED NOT NULL,
  calendar_time     TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ticket_categories (
  id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== Разработка =====================
CREATE TABLE projects (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id       INT UNSIGNED NOT NULL,
  appointment_id  INT UNSIGNED NULL,
  owner_id        INT UNSIGNED NOT NULL,
  name            VARCHAR(200) NOT NULL,
  description     TEXT NULL,
  status          ENUM('planned','in_progress','testing','delivered','support','closed') NOT NULL DEFAULT 'planned',
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NULL,
  INDEX idx_prj_client (client_id),
  CONSTRAINT fk_prj_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_prj_app FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  CONSTRAINT fk_prj_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_members (
  project_id          INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  role_in_project     ENUM('lead','developer','tester','analyst') NOT NULL DEFAULT 'developer',
  allocation_percent  TINYINT UNSIGNED NOT NULL DEFAULT 100,
  joined_at           DATE NOT NULL,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT chk_pm_alloc CHECK (allocation_percent BETWEEN 0 AND 100),
  CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== Техподдержка =====================
CREATE TABLE tickets (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id           INT UNSIGNED NOT NULL,
  project_id          INT UNSIGNED NULL,
  category_id         TINYINT UNSIGNED NOT NULL,
  priority_id         TINYINT UNSIGNED NOT NULL,
  assignee_id         INT UNSIGNED NULL,
  subject             VARCHAR(200) NOT NULL,
  description         TEXT NOT NULL,
  channel             ENUM('web','email','phone') NOT NULL DEFAULT 'web',
  status              ENUM('new','accepted','in_progress','waiting_client','resolved','closed') NOT NULL DEFAULT 'new',
  first_response_due  DATETIME NOT NULL,
  due_at              DATETIME NOT NULL,
  first_response_at   DATETIME NULL,
  resolved_at         DATETIME NULL,
  closed_at           DATETIME NULL,
  auto_assigned       TINYINT(1) NOT NULL DEFAULT 0,
  assigned_load       SMALLINT UNSIGNED NULL,
  sla_warned          TINYINT(1) NOT NULL DEFAULT 0,
  sla_breach_notified TINYINT(1) NOT NULL DEFAULT 0,
  created_by          INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NULL,
  INDEX idx_tk_status (status, due_at),
  INDEX idx_tk_assignee (assignee_id, status),
  INDEX idx_tk_client (client_id),
  CONSTRAINT fk_tk_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_tk_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_tk_cat FOREIGN KEY (category_id) REFERENCES ticket_categories(id),
  CONSTRAINT fk_tk_prio FOREIGN KEY (priority_id) REFERENCES priorities(id),
  CONSTRAINT fk_tk_assignee FOREIGN KEY (assignee_id) REFERENCES users(id),
  CONSTRAINT fk_tk_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE response_templates (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id  TINYINT UNSIGNED NULL,
  title        VARCHAR(150) NOT NULL,
  body         TEXT NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL,
  CONSTRAINT fk_rt_cat FOREIGN KEY (category_id) REFERENCES ticket_categories(id),
  CONSTRAINT fk_rt_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ticket_comments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id    INT UNSIGNED NOT NULL,
  author_id    INT UNSIGNED NOT NULL,
  text         TEXT NOT NULL,
  is_internal  TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  INDEX idx_tc_ticket (ticket_id, created_at),
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== Задачи =====================
CREATE TABLE tasks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  ticket_id       INT UNSIGNED NULL,
  title           VARCHAR(200) NOT NULL,
  description     TEXT NULL,
  type            ENUM('feature','bug','improvement','research') NOT NULL DEFAULT 'feature',
  status          ENUM('backlog','in_progress','testing','done') NOT NULL DEFAULT 'backlog',
  priority_id     TINYINT UNSIGNED NOT NULL,
  assignee_id     INT UNSIGNED NULL,
  reporter_id     INT UNSIGNED NOT NULL,
  estimate_hours  DECIMAL(6,2) NOT NULL DEFAULT 0,
  due_date        DATE NULL,
  position        INT UNSIGNED NOT NULL DEFAULT 0,
  due_notified    TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NULL,
  completed_at    DATETIME NULL,
  CONSTRAINT chk_task_est CHECK (estimate_hours >= 0),
  INDEX idx_task_project (project_id, status),
  INDEX idx_task_assignee (assignee_id, status),
  CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_task_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
  CONSTRAINT fk_task_prio FOREIGN KEY (priority_id) REFERENCES priorities(id),
  CONSTRAINT fk_task_assignee FOREIGN KEY (assignee_id) REFERENCES users(id),
  CONSTRAINT fk_task_reporter FOREIGN KEY (reporter_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id     INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NOT NULL,
  text        TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  CONSTRAINT fk_tkc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tkc_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE time_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  work_date  DATE NOT NULL,
  hours      DECIMAL(4,2) NOT NULL,
  comment    VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT chk_tl_hours CHECK (hours > 0 AND hours <= 24),
  INDEX idx_tl_user (user_id, work_date),
  INDEX idx_tl_task (task_id),
  CONSTRAINT fk_tl_task FOREIGN KEY (task_id) REFERENCES tasks(id),
  CONSTRAINT fk_tl_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id    INT UNSIGNED NULL,
  task_id      INT UNSIGNED NULL,
  uploaded_by  INT UNSIGNED NOT NULL,
  file_name    VARCHAR(255) NOT NULL,
  file_path    VARCHAR(500) NOT NULL,
  file_size    INT UNSIGNED NOT NULL,
  mime         VARCHAR(100) NULL,
  created_at   DATETIME NOT NULL,
  CONSTRAINT chk_att_owner CHECK ((ticket_id IS NULL) <> (task_id IS NULL)),
  CONSTRAINT fk_att_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
  CONSTRAINT fk_att_task FOREIGN KEY (task_id) REFERENCES tasks(id),
  CONSTRAINT fk_att_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  type            VARCHAR(40) NOT NULL,
  title           VARCHAR(200) NOT NULL,
  body            VARCHAR(500) NULL,
  appointment_id  INT UNSIGNED NULL,
  ticket_id       INT UNSIGNED NULL,
  task_id         INT UNSIGNED NULL,
  is_read         TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  INDEX idx_ntf_user (user_id, is_read, created_at),
  CONSTRAINT fk_ntf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ntf_app FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
  CONSTRAINT fk_ntf_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  CONSTRAINT fk_ntf_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ===================== Представления =====================
CREATE VIEW v_consultant_rating AS
SELECT c.id AS consultant_id, ROUND(AVG(r.rating), 2) AS avg_rating, COUNT(r.id) AS reviews_count
FROM consultants c LEFT JOIN reviews r ON r.consultant_id = c.id AND r.status = 'published'
GROUP BY c.id;

CREATE VIEW v_monthly_revenue AS
SELECT DATE_FORMAT(COALESCE(p.paid_at, p.created_at), '%Y-%m') AS month,
       SUM(CASE WHEN p.type = 'payment' THEN p.amount ELSE -p.amount END) AS revenue,
       SUM(p.type = 'payment') AS payments_count,
       SUM(p.type = 'refund') AS refunds_count
FROM payments p WHERE p.status = 'succeeded'
GROUP BY DATE_FORMAT(COALESCE(p.paid_at, p.created_at), '%Y-%m');

CREATE VIEW v_sla_report AS
SELECT t.id AS ticket_id, t.priority_id, t.category_id, t.assignee_id, t.created_at,
       t.first_response_due, t.first_response_at, t.due_at, t.resolved_at,
       (t.first_response_at IS NOT NULL AND t.first_response_at <= t.first_response_due) AS response_ok,
       (t.resolved_at IS NOT NULL AND t.resolved_at <= t.due_at) AS resolve_ok
FROM tickets t;

CREATE VIEW v_dev_workload AS
SELECT u.id AS user_id, CONCAT(u.last_name, ' ', u.first_name) AS name,
       COUNT(t.id) AS open_tasks, COALESCE(SUM(t.estimate_hours), 0) AS estimate_hours
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id AND r.code = 'developer'
LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status <> 'done'
GROUP BY u.id, u.last_name, u.first_name;
