-- Base separada para la suite de tests.
--
-- Existe para que los tests corran contra el MISMO motor que produccion
-- (MySQL 8.4) y no contra SQLite. La diferencia no es cosmetica: los indices
-- compuestos de T-009, los ENUM, `ON UPDATE CURRENT_TIMESTAMP`, el bloqueo de
-- filas (`FOR UPDATE`) y la semantica de las transacciones se comportan
-- distinto en SQLite, y un test verde ahi no prueba nada sobre produccion.
--
-- Se ejecuta solo cuando el volumen de MySQL se crea de cero. Si la base ya
-- existia, aplicalo a mano:
--   docker compose exec -T mysql mysql -uroot -psecret < docker/mysql/init/01-create-testing-db.sql

CREATE DATABASE IF NOT EXISTS agendallena_testing
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- El usuario de la app lo crea la propia imagen a partir de MYSQL_USER, pero
-- solo con permisos sobre MYSQL_DATABASE. Sin este GRANT, los tests fallan con
-- "Access denied" al intentar migrar.
GRANT ALL PRIVILEGES ON agendallena_testing.* TO 'agendallena'@'%';

FLUSH PRIVILEGES;
