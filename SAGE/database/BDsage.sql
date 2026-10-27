


DROP DATABASE IF EXISTS sage_db;
CREATE DATABASE sage_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sage_db;


CREATE TABLE users (
    id           INT          PRIMARY KEY AUTO_INCREMENT,
    username     VARCHAR(50)  UNIQUE NOT NULL,
    password     VARCHAR(255) NOT NULL,               
    role         ENUM('admin','user') DEFAULT 'user',
    is_active    BOOLEAN      DEFAULT TRUE,
    last_login   TIMESTAMP    NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role     (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE students (
    id                      INT          PRIMARY KEY AUTO_INCREMENT,
    nome                    VARCHAR(100) NOT NULL,
    cpf                     VARCHAR(14)  UNIQUE NOT NULL,  
    email                   VARCHAR(100) NULL,
    telefone                VARCHAR(15)  NULL,             
    data_nascimento         DATE         NULL,
    endereco                TEXT         NULL,
    responsavel             VARCHAR(100) NULL,
    telefone_responsavel    VARCHAR(15)  NULL,
    responsavel_falecido    ENUM('sim','não') DEFAULT 'não',
    is_active               BOOLEAN      DEFAULT TRUE,
    created_by              INT          NULL,             
    created_at              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_nome            (nome),
    INDEX idx_cpf             (cpf),
    INDEX idx_responsavel     (responsavel),
    INDEX idx_data_nascimento (data_nascimento),
    INDEX idx_is_active       (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE student_documents (
    id           INT           PRIMARY KEY AUTO_INCREMENT,
    student_id   INT           NOT NULL,
    student_cpf  VARCHAR(14)   NOT NULL,
    filename     VARCHAR(255)  NOT NULL,               
    stored_name  VARCHAR(255)  NOT NULL,               
    mime_type    VARCHAR(100)  NOT NULL,
    file_size    BIGINT        NOT NULL DEFAULT 0,     
    uploaded_by  INT           NULL,                   
    uploaded_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_student_id  (student_id),
    INDEX idx_student_cpf (student_cpf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE audit_log (
    id          INT          PRIMARY KEY AUTO_INCREMENT,
    user_id     INT          NULL,
    action      ENUM('CREATE','UPDATE','DELETE','VIEW','LOGIN','LOGOUT') NOT NULL,
    table_name  VARCHAR(50)  NULL,
    record_id   INT          NULL,
    old_data    JSON         NULL,
    new_data    JSON         NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  TEXT         NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id   (user_id),
    INDEX idx_action    (action),
    INDEX idx_created_at(created_at),
    INDEX idx_record    (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE backup_history (
    id           INT          PRIMARY KEY AUTO_INCREMENT,
    backup_file  VARCHAR(255) NOT NULL,
    backup_size  BIGINT       NULL,
    record_count INT          NULL,
    created_by   INT          NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE system_settings (
    id            INT          PRIMARY KEY AUTO_INCREMENT,
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT         NULL,
    setting_type  ENUM('string','int','boolean','json') DEFAULT 'string',
    description   TEXT         NULL,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE sessions (
    id          VARCHAR(128) PRIMARY KEY,
    user_id     INT          NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  TEXT         NULL,
    payload     TEXT         NULL,
    last_activity TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id      (user_id),
    INDEX idx_last_activity(last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('user',  '$2y$10$TKh8H1.PBkbhHRVmCVxhneYHNhzEMUg0fQVqIWAD.Gkr7iFuFCQe', 'user');

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('system_name',       'SAGE',    'string',  'Nome do sistema exibido na interface'),
('system_version',    '2.0',     'string',  'Versão atual do sistema'),
('default_theme',     'light',   'string',  'Tema padrão (light / dark)'),
('items_per_page',    '20',      'int',     'Itens por página nas tabelas'),
('backup_enabled',    'true',    'boolean', 'Habilitar backups automáticos'),
('backup_frequency',  'daily',   'string',  'Frequência de backup (daily/weekly/monthly)'),
('session_timeout',   '30',      'int',     'Tempo de sessão em minutos'),
('max_upload_size',   '20971520','int',     'Tamanho máximo de upload em bytes (20 MB)'),
('allowed_file_types','pdf,jpg,jpeg,png','string','Extensões de arquivo permitidas no upload')
ON DUPLICATE KEY UPDATE setting_key = setting_key;


CREATE OR REPLACE VIEW vw_active_students AS
SELECT
    s.id,
    s.nome,
    s.cpf,
    s.email,
    s.telefone,
    DATE_FORMAT(s.data_nascimento, '%d/%m/%Y') AS data_nascimento_formatada,
    s.data_nascimento,
    s.endereco,
    s.responsavel,
    s.telefone_responsavel,
    s.responsavel_falecido,
    s.is_active,
    s.created_at,
    s.updated_at,
    u.username AS created_by_username
FROM students s
LEFT JOIN users u ON s.created_by = u.id
WHERE s.is_active = TRUE;


CREATE OR REPLACE VIEW vw_students_by_responsible AS
SELECT
    responsavel_falecido AS status,
    COUNT(*)             AS total,
    CASE
        WHEN responsavel_falecido = 'sim' THEN 'Responsável Falecido'
        ELSE 'Responsável Vivo'
    END AS status_descricao
FROM students
WHERE is_active = TRUE
GROUP BY responsavel_falecido;


CREATE OR REPLACE VIEW vw_recent_students AS
SELECT
    s.*,
    u.username AS created_by_username
FROM students s
LEFT JOIN users u ON s.created_by = u.id
WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND s.is_active = TRUE
ORDER BY s.created_at DESC;

CREATE OR REPLACE VIEW vw_student_documents AS
SELECT
    d.id,
    d.student_id,
    d.student_cpf,
    s.nome          AS student_nome,
    d.filename,
    d.stored_name,
    d.mime_type,
    d.file_size,
    d.uploaded_at,
    u.username      AS uploaded_by_username
FROM student_documents d
LEFT JOIN students s ON d.student_id  = s.id
LEFT JOIN users    u ON d.uploaded_by = u.id;


CREATE OR REPLACE VIEW vw_dashboard_stats AS
SELECT
    (SELECT COUNT(*) FROM students  WHERE is_active = TRUE)  AS total_students,
    (SELECT COUNT(*) FROM students  WHERE is_active = TRUE AND responsavel_falecido = 'sim') AS resp_falecidos,
    (SELECT COUNT(*) FROM students  WHERE is_active = TRUE AND responsavel_falecido = 'não') AS resp_vivos,
    (SELECT COUNT(*) FROM users     WHERE is_active = TRUE)  AS total_users,
    (SELECT COUNT(*) FROM student_documents)                 AS total_documents,
    (SELECT COUNT(*) FROM students  WHERE DATE(created_at) = CURDATE() AND is_active = TRUE) AS cadastros_hoje;



CREATE PROCEDURE sp_search_students(
    IN p_nome           VARCHAR(100),
    IN p_cpf            VARCHAR(14),
    IN p_responsavel    VARCHAR(100),
    IN p_data_nascimento DATE
)
BEGIN
    SELECT * FROM vw_active_students
    WHERE (p_nome            IS NULL OR nome       LIKE CONCAT('%', p_nome, '%'))
      AND (p_cpf             IS NULL OR cpf        LIKE CONCAT('%', p_cpf, '%'))
      AND (p_responsavel     IS NULL OR responsavel LIKE CONCAT('%', p_responsavel, '%'))
      AND (p_data_nascimento IS NULL OR data_nascimento = p_data_nascimento)
    ORDER BY nome;
END //


CREATE PROCEDURE sp_audit_log(
    IN p_user_id    INT,
    IN p_action     VARCHAR(20),
    IN p_table_name VARCHAR(50),
    IN p_record_id  INT,
    IN p_old_data   JSON,
    IN p_new_data   JSON,
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT
)
BEGIN
    INSERT INTO audit_log
        (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent)
    VALUES
        (p_user_id, p_action, p_table_name, p_record_id, p_old_data, p_new_data, p_ip_address, p_user_agent);
END //

CREATE PROCEDURE sp_update_student(
    IN p_student_id          INT,
    IN p_nome                VARCHAR(100),
    IN p_email               VARCHAR(100),
    IN p_telefone            VARCHAR(15),
    IN p_endereco            TEXT,
    IN p_responsavel         VARCHAR(100),
    IN p_telefone_responsavel VARCHAR(15),
    IN p_responsavel_falecido ENUM('sim','não'),
    IN p_user_id             INT,
    IN p_ip_address          VARCHAR(45),
    IN p_user_agent          TEXT
)
BEGIN
    DECLARE v_old_data JSON;
    DECLARE v_new_data JSON;

    SELECT JSON_OBJECT(
        'nome',                 nome,
        'email',                email,
        'telefone',             telefone,
        'endereco',             endereco,
        'responsavel',          responsavel,
        'telefone_responsavel', telefone_responsavel,
        'responsavel_falecido', responsavel_falecido
    ) INTO v_old_data
    FROM students WHERE id = p_student_id;

    UPDATE students SET
        nome                 = p_nome,
        email                = p_email,
        telefone             = p_telefone,
        endereco             = p_endereco,
        responsavel          = p_responsavel,
        telefone_responsavel = p_telefone_responsavel,
        responsavel_falecido = p_responsavel_falecido
    WHERE id = p_student_id;

    SET v_new_data = JSON_OBJECT(
        'nome',                 p_nome,
        'email',                p_email,
        'telefone',             p_telefone,
        'endereco',             p_endereco,
        'responsavel',          p_responsavel,
        'telefone_responsavel', p_telefone_responsavel,
        'responsavel_falecido', p_responsavel_falecido
    );

    CALL sp_audit_log(p_user_id, 'UPDATE', 'students', p_student_id, v_old_data, v_new_data, p_ip_address, p_user_agent);
END //


CREATE PROCEDURE sp_delete_student(
    IN p_student_id INT,
    IN p_user_id    INT,
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT
)
BEGIN
    DECLARE v_old_data JSON;

    SELECT JSON_OBJECT('nome', nome, 'cpf', cpf)
        INTO v_old_data
    FROM students WHERE id = p_student_id;

    UPDATE students SET is_active = FALSE WHERE id = p_student_id;

    CALL sp_audit_log(p_user_id, 'DELETE', 'students', p_student_id, v_old_data, NULL, p_ip_address, p_user_agent);
END //

CREATE PROCEDURE sp_dashboard_stats()
BEGIN
    
    SELECT COUNT(*) AS total_students FROM students WHERE is_active = TRUE;

   
    SELECT responsavel_falecido, COUNT(*) AS count
    FROM students
    WHERE is_active = TRUE
    GROUP BY responsavel_falecido;

    
    SELECT DATE(created_at) AS date, COUNT(*) AS count
    FROM students
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND is_active = TRUE
    GROUP BY DATE(created_at)
    ORDER BY date DESC;

    
    SELECT COUNT(*) AS active_users FROM users WHERE is_active = TRUE;

  
    SELECT COUNT(*) AS total_documents FROM student_documents;
END //


CREATE TRIGGER tr_students_before_update
BEFORE UPDATE ON students
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END //


CREATE TRIGGER tr_users_before_delete
BEFORE DELETE ON users
FOR EACH ROW
BEGIN
    DECLARE admin_count INT;
    SELECT COUNT(*) INTO admin_count
    FROM users
    WHERE role = 'admin' AND is_active = TRUE;

    IF OLD.role = 'admin' AND admin_count <= 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Não é possível excluir o último administrador do sistema.';
    END IF;
END //



CREATE FUNCTION fn_calculate_age(p_birth_date DATE)
RETURNS INT
DETERMINISTIC
BEGIN
    RETURN TIMESTAMPDIFF(YEAR, p_birth_date, CURDATE());
END //


CREATE FUNCTION fn_format_cpf(p_cpf VARCHAR(14))
RETURNS VARCHAR(14)
DETERMINISTIC
BEGIN
    IF p_cpf IS NULL OR p_cpf = '' THEN
        RETURN NULL;
    END IF;

    SET p_cpf = REGEXP_REPLACE(p_cpf, '[^0-9]', '');

    IF LENGTH(p_cpf) = 11 THEN
        RETURN CONCAT(
            SUBSTRING(p_cpf,  1, 3), '.',
            SUBSTRING(p_cpf,  4, 3), '.',
            SUBSTRING(p_cpf,  7, 3), '-',
            SUBSTRING(p_cpf, 10, 2)
        );
    END IF;

    RETURN p_cpf;
END //


CREATE FUNCTION fn_get_user_name(p_user_id INT)
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    DECLARE v_username VARCHAR(50);
    SELECT username INTO v_username FROM users WHERE id = p_user_id;
    RETURN IFNULL(v_username, 'Sistema');
END //

DELIMITER ;


CREATE INDEX idx_students_search   ON students    (nome, cpf, responsavel);
CREATE INDEX idx_audit_user_date   ON audit_log   (user_id, created_at);
CREATE INDEX idx_docs_student_date ON student_documents (student_id, uploaded_at);


