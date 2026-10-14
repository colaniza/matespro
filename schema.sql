-- =========================================================
-- SCRIPT DE BASE DE DATOS ECOWATTS (phpMyAdmin / MySQL)
-- Codificación: UTF-8 mb4 (Soporte completo para tildes y Ñ)
-- =========================================================

CREATE DATABASE IF NOT EXISTS `ecowatts` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ecowatts`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- 1. TABLA `comunas_tarifas`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `comunas_tarifas`;
CREATE TABLE `comunas_tarifas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `region` VARCHAR(100) NOT NULL,
  `comuna` VARCHAR(100) NOT NULL,
  `empresa` VARCHAR(100) NOT NULL,
  `tarifa_kwh` DECIMAL(10, 2) NOT NULL COMMENT 'Costo en CLP por kWh'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos Semilla con ortografía y tildes corregidas
INSERT INTO `comunas_tarifas` (`id`, `region`, `comuna`, `empresa`, `tarifa_kwh`) VALUES
(1, 'Atacama', 'Vallenar', 'CGE', 145.50),
(2, 'Atacama', 'Alto del Carmen', 'CGE', 145.50),
(3, 'Atacama', 'Freirina', 'CGE', 145.50),
(4, 'Atacama', 'Huasco', 'CGE', 145.50),
(5, 'Atacama', 'Copiapó', 'CGE', 148.00),
(6, 'Atacama', 'Caldera', 'CGE', 148.00),
(7, 'Metropolitana', 'Santiago Centro', 'Enel', 158.20),
(8, 'Metropolitana', 'Providencia', 'Enel', 158.20),
(9, 'Metropolitana', 'Las Condes', 'Enel', 158.20),
(10, 'Metropolitana', 'Maipú', 'Enel', 158.20),
(11, 'Valparaíso', 'Valparaíso', 'Chilquinta', 152.40),
(12, 'Valparaíso', 'Viña del Mar', 'Chilquinta', 152.40),
(13, 'Coquimbo', 'La Serena', 'CGE', 149.80),
(14, 'Coquimbo', 'Coquimbo', 'CGE', 149.80),
(15, 'Biobío', 'Concepción', 'CGE', 155.10),
(16, 'Antofagasta', 'Antofagasta', 'CGE', 160.00),
(17, 'La Araucanía', 'Temuco', 'Saesa', 162.50),
(18, 'Los Lagos', 'Puerto Montt', 'Saesa', 165.00),
(19, 'Magallanes', 'Punta Arenas', 'Edelmag', 138.90);

-- ---------------------------------------------------------
-- 2. TABLA `usuarios`
-- (Administrador: manuel / clave: manuel123)
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NULL,
  `apellido` VARCHAR(100) NULL,
  `usuario` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(150) NULL,
  `pass` VARCHAR(255) NOT NULL,
  `perfil` VARCHAR(50) NOT NULL DEFAULT 'cliente',
  `comuna_id` INT NULL DEFAULT 1,
  `comuna` VARCHAR(100) NOT NULL DEFAULT 'Vallenar',
  `fecha_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_usuario_comuna` FOREIGN KEY (`comuna_id`) REFERENCES `comunas_tarifas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario Administrador Manuel (Clave por defecto: manuel123)
-- Hash bcrypt generado para 'manuel123': $2y$10$E4.7H/.4PqXlqX8o6pU68.0N6.K4u.Z4Qn/E.hN9oW4Qx.aE1kS2O
INSERT INTO `usuarios` (`id`, `nombre`, `apellido`, `usuario`, `email`, `pass`, `perfil`, `comuna_id`, `comuna`) VALUES
(1, 'Manuel', 'Administrador', 'manuel', 'manuel@ecowatts.cl', '$2y$10$E4.7H/.4PqXlqX8o6pU68.0N6.K4u.Z4Qn/E.hN9oW4Qx.aE1kS2O', 'admin', 1, 'Vallenar');

-- ---------------------------------------------------------
-- 3. TABLA `dispositivos_predefinidos` (Catálogo maestro)
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `dispositivos_predefinidos`;
CREATE TABLE `dispositivos_predefinidos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `watts_promedio` INT NOT NULL,
  `horas_estandar_dia` DECIMAL(5, 2) NOT NULL DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dispositivos_predefinidos` (`id`, `nombre`, `watts_promedio`, `horas_estandar_dia`) VALUES
(1, 'Refrigerador No Frost', 200, 8.00),
(2, 'Hervidor Eléctrico', 1800, 0.30),
(3, 'Horno Microondas', 1200, 0.50),
(4, 'Televisor LED 55"', 110, 5.00),
(5, 'Computador Escritorio (PC)', 350, 6.00),
(6, 'Notebook / Laptop', 65, 7.00),
(7, 'Aire Acondicionado Split', 1200, 4.00),
(8, 'Ampolleta LED 9W', 9, 6.00),
(9, 'Lavadora Automática', 500, 1.00),
(10, 'Plancha de Ropa', 1500, 0.50),
(11, 'Estufa Eléctrica / Calefactor', 1500, 3.00),
(12, 'Secador de Pelo', 1600, 0.20);

-- ---------------------------------------------------------
-- 4. TABLA `carrito_usuario` (Ejemplos Demostrativos)
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `carrito_usuario`;
CREATE TABLE `carrito_usuario` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `nombre_dispositivo` VARCHAR(150) NOT NULL,
  `watts` INT NOT NULL,
  `horas_uso_dia` DECIMAL(5, 2) NULL DEFAULT NULL,
  `cantidad` INT NOT NULL DEFAULT 1,
  `fecha_agregado` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_carrito_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ítems Demostrativos del Carrito para el usuario Manuel (ID 1)
INSERT INTO `carrito_usuario` (`usuario_id`, `nombre_dispositivo`, `watts`, `horas_uso_dia`, `cantidad`) VALUES
(1, 'Refrigerador No Frost', 200, NULL, 1),
(1, 'Televisor LED 55"', 110, 4.50, 1),
(1, 'Hervidor Eléctrico', 1800, NULL, 1),
(1, 'Ampolleta LED 9W', 9, 6.00, 5);

SET FOREIGN_KEY_CHECKS = 1;
