DROP DATABASE IF EXISTS syncora;

CREATE DATABASE IF NOT EXISTS syncora;

USE syncora;

CREATE TABLE IF NOT EXISTS tb_usuario (
    user_id INTEGER AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    user_password VARCHAR(300) NOT NULL
);

insert into tb_usuario(username, email, user_password) values("Davi", "davi@gmail.com", "12345");

CREATE TABLE IF NOT EXISTS tb_historico (
    historico_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_file VARCHAR(300) NOT NULL,
    user_id INT NOT NULL,
    numbers_page INT UNSIGNED NOT NULL DEFAULT 0,
    stored_file_name VARCHAR(255) NOT NULL,
    stored_file_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (historico_id),
    KEY idx_historico_user_created (user_id , created_at)
);

CREATE TABLE IF NOT EXISTS tb_chat_mensagem (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    historico_id INT UNSIGNED NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    page_number INT UNSIGNED NOT NULL,
    role_dif ENUM('user', 'assistant') NOT NULL DEFAULT 'assistant',
    content LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_chat_historico_created (historico_id , created_at),
    KEY idx_chat_conversation (historico_id , conversation_id , page_number)
);