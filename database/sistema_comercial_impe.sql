-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 30-08-2026 a las 05:53:54
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `sistema_comercial_impe`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asignaciones_territorio`
--

CREATE TABLE `asignaciones_territorio` (
  `id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo_asignacion` enum('CUENTA_CLAVE','ANALISTA_DATOS') NOT NULL,
  `cuenta_clave_asignacion_id` int(11) DEFAULT NULL,
  `es_principal` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `observaciones` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `asignaciones_territorio`
--

INSERT INTO `asignaciones_territorio` (`id`, `estado_id`, `usuario_id`, `tipo_asignacion`, `cuenta_clave_asignacion_id`, `es_principal`, `fecha_inicio`, `fecha_fin`, `activo`, `observaciones`, `created_at`, `updated_at`) VALUES
(1, 2, 4, 'CUENTA_CLAVE', NULL, 0, '2026-08-29', '2026-08-29', 0, '', '2026-08-29 19:12:39', '2026-08-29 19:16:19'),
(2, 2, 5, 'ANALISTA_DATOS', 1, 0, '2026-08-29', '2026-08-29', 0, '', '2026-08-29 19:12:58', '2026-08-29 19:15:32'),
(3, 2, 5, 'ANALISTA_DATOS', 1, 0, '2026-08-29', '2026-08-29', 0, '', '2026-08-29 19:15:46', '2026-08-29 19:16:19'),
(4, 2, 4, 'CUENTA_CLAVE', NULL, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:16:39', '2026-08-29 19:16:39'),
(5, 2, 5, 'ANALISTA_DATOS', 4, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:16:44', '2026-08-29 19:16:44'),
(6, 3, 4, 'CUENTA_CLAVE', NULL, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:16:51', '2026-08-29 19:16:51'),
(7, 3, 5, 'ANALISTA_DATOS', 6, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:16:57', '2026-08-29 19:16:57'),
(8, 9, 4, 'CUENTA_CLAVE', NULL, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:17:18', '2026-08-29 19:17:18'),
(9, 9, 5, 'ANALISTA_DATOS', 8, 0, '2026-08-29', NULL, 1, '', '2026-08-29 19:17:31', '2026-08-29 19:17:31');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados`
--

CREATE TABLE `estados` (
  `id` int(11) NOT NULL,
  `clave_inegi` varchar(5) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `nombre_corto` varchar(50) DEFAULT NULL,
  `capital` varchar(120) DEFAULT NULL,
  `mapa_estado` varchar(255) DEFAULT NULL,
  `titular_gobierno` varchar(150) DEFAULT NULL,
  `foto_titular` varchar(255) DEFAULT NULL,
  `cargo_titular` varchar(100) DEFAULT 'Gobernador(a)',
  `partido_politico` varchar(80) DEFAULT NULL,
  `poblacion` bigint(20) DEFAULT NULL,
  `total_municipios` int(11) DEFAULT NULL,
  `total_secretarias` int(11) DEFAULT NULL,
  `periodo_gobierno` varchar(30) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `redes_sociales` text DEFAULT NULL,
  `actividad_economica` text DEFAULT NULL,
  `poder_adquisitivo` text DEFAULT NULL,
  `fuente` varchar(100) DEFAULT NULL,
  `fecha_actualizacion` datetime DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados`
--

INSERT INTO `estados` (`id`, `clave_inegi`, `nombre`, `nombre_corto`, `capital`, `mapa_estado`, `titular_gobierno`, `foto_titular`, `cargo_titular`, `partido_politico`, `poblacion`, `total_municipios`, `total_secretarias`, `periodo_gobierno`, `telefono`, `redes_sociales`, `actividad_economica`, `poder_adquisitivo`, `fuente`, `fecha_actualizacion`, `estado`, `created_at`, `updated_at`) VALUES
(1, '01', 'Aguascalientes', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(2, '02', 'Baja California', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(3, '03', 'Baja California Sur', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(4, '04', 'Campeche', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(5, '05', 'Coahuila', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(6, '06', 'Colima', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(7, '07', 'Chiapas', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(8, '08', 'Chihuahua', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(9, '09', 'Ciudad de México', 'CDMX', NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-28 21:01:23'),
(10, '10', 'Durango', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(11, '11', 'Guanajuato', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(12, '12', 'Guerrero', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(13, '13', 'Hidalgo', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(14, '14', 'Jalisco', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(15, '15', 'Estado de México', 'EDOMEX', NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-28 21:01:23'),
(16, '16', 'Michoacán', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(17, '17', 'Morelos', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(18, '18', 'Nayarit', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(19, '19', 'Nuevo León', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(20, '20', 'Oaxaca', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(21, '21', 'Puebla', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(22, '22', 'Querétaro', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(23, '23', 'Quintana Roo', 'Q. Roo', NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-28 21:01:23'),
(24, '24', 'San Luis Potosí', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(25, '25', 'Sinaloa', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(26, '26', 'Sonora', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(27, '27', 'Tabasco', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(28, '28', 'Tamaulipas', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(29, '29', 'Tlaxcala', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(30, '30', 'Veracruz', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(31, '31', 'Yucatán', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54'),
(32, '32', 'Zacatecas', NULL, NULL, NULL, NULL, NULL, 'Gobernador(a)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-28 21:01:23', '2026-08-29 17:39:54');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fuentes_datos_territoriales`
--

CREATE TABLE `fuentes_datos_territoriales` (
  `id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL,
  `seccion` enum('GENERAL','ACTIVIDAD_ECONOMICA','PODER_ADQUISITIVO','EDUCACION','SECRETARIAS','MUNICIPIOS') NOT NULL,
  `fuente` varchar(150) NOT NULL,
  `url_fuente` varchar(500) DEFAULT NULL,
  `periodo` varchar(50) DEFAULT NULL,
  `tipo_actualizacion` enum('AUTOMATICA','IMPORTACION','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `fecha_consulta` datetime DEFAULT NULL,
  `usuario_verifico_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `municipios`
--

CREATE TABLE `municipios` (
  `id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL,
  `clave_inegi` varchar(10) DEFAULT NULL,
  `numero_excel` int(11) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `poblacion` bigint(20) DEFAULT NULL,
  `presidente_municipal` varchar(180) DEFAULT NULL,
  `partido_politico` varchar(80) DEFAULT NULL,
  `redes_sociales` text DEFAULT NULL,
  `fotografia` varchar(255) DEFAULT NULL,
  `fecha_actualizacion` datetime DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permisos`
--

CREATE TABLE `permisos` (
  `id` int(11) NOT NULL,
  `modulo` varchar(80) NOT NULL,
  `codigo` varchar(100) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `permisos`
--

INSERT INTO `permisos` (`id`, `modulo`, `codigo`, `nombre`, `descripcion`, `estado`, `created_at`, `updated_at`) VALUES
(1, 'Usuarios', 'usuarios.ver', 'Ver usuarios', 'Consultar usuarios registrados.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(2, 'Usuarios', 'usuarios.crear', 'Crear usuarios', 'Registrar nuevas cuentas de usuario.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(3, 'Usuarios', 'usuarios.editar', 'Editar usuarios', 'Actualizar datos de usuario.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(4, 'Usuarios', 'usuarios.cambiar_estado', 'Activar / desactivar usuarios', 'Modificar el estado de una cuenta.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(5, 'Roles y permisos', 'roles.ver', 'Ver roles', 'Consultar roles y permisos.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(6, 'Roles y permisos', 'roles.crear', 'Crear roles', 'Registrar nuevos perfiles de acceso.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(7, 'Roles y permisos', 'roles.editar', 'Editar roles', 'Actualizar datos de roles.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(8, 'Roles y permisos', 'roles.cambiar_estado', 'Activar / desactivar roles', 'Modificar estado de roles.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(9, 'Roles y permisos', 'roles.asignar_permisos', 'Asignar permisos', 'Administrar permisos por rol.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(10, 'Prospectos', 'prospectos.ver_todos', 'Ver todos los prospectos', 'Consultar prospectos de todos los equipos.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(11, 'Prospectos', 'prospectos.ver_propios', 'Ver prospectos propios', 'Consultar prospectos asignados al usuario.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(12, 'Prospectos', 'prospectos.editar', 'Editar prospectos', 'Actualizar información de prospectos.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(13, 'Prospectos', 'prospectos.asignar', 'Asignar prospectos', 'Asignar prospectos a usuarios o equipos.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(14, 'Seguimientos', 'seguimientos.ver', 'Ver seguimientos', 'Consultar seguimientos registrados.', 0, '2026-08-28 19:25:19', '2026-08-28 19:58:54'),
(15, 'Seguimientos', 'seguimientos.crear', 'Crear seguimientos', 'Registrar nuevos seguimientos.', 0, '2026-08-28 19:25:19', '2026-08-28 19:58:54'),
(16, 'Seguimientos', 'seguimientos.editar', 'Editar seguimientos', 'Actualizar seguimientos.', 0, '2026-08-28 19:25:19', '2026-08-28 19:58:54'),
(17, 'Finanzas', 'pagos.ver', 'Ver pagos', 'Consultar pagos registrados.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(18, 'Finanzas', 'pagos.validar', 'Validar pagos', 'Validar pagos e inscripciones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(19, 'Organizaciones', 'organizaciones.ver', 'Ver organizaciones', 'Consultar organizaciones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(20, 'Organizaciones', 'organizaciones.crear', 'Crear organizaciones', 'Registrar organizaciones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(21, 'Organizaciones', 'organizaciones.editar', 'Editar organizaciones', 'Actualizar organizaciones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(22, 'Organizaciones', 'organizaciones.validar', 'Validar organizaciones', 'Validar información institucional.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(23, 'Oficios', 'oficios.ver', 'Ver oficios', 'Consultar oficios.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(24, 'Oficios', 'oficios.generar', 'Generar oficios', 'Generar documentos oficiales.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(25, 'Oficios', 'oficios.enviar', 'Enviar oficios', 'Enviar oficios a destinatarios.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(26, 'Reuniones', 'reuniones.ver', 'Ver reuniones', 'Consultar reuniones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(27, 'Reuniones', 'reuniones.gestionar', 'Gestionar reuniones', 'Administrar reuniones.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(28, 'Convenios', 'convenios.ver', 'Ver convenios', 'Consultar convenios.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(29, 'Convenios', 'convenios.gestionar', 'Gestionar convenios', 'Administrar convenios.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(30, 'Reportes', 'reportes.ver', 'Ver reportes', 'Consultar reportes.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(31, 'Reportes', 'reportes.exportar', 'Exportar reportes', 'Exportar información del sistema.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(32, 'Respaldos', 'respaldos.generar', 'Generar respaldos', 'Crear respaldos del sistema.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(33, 'Respaldos', 'respaldos.restaurar', 'Restaurar respaldos', 'Restaurar información desde respaldo.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(34, 'Configuración', 'configuracion.ver', 'Ver configuración', 'Consultar configuración del sistema.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(35, 'Configuración', 'configuracion.editar', 'Editar configuración', 'Actualizar configuración del sistema.', 1, '2026-08-28 19:25:19', '2026-08-28 19:25:19'),
(1029, 'Seguimientos comerciales', 'seguimientos_comerciales.ver_todos', 'Ver todos los seguimientos comerciales', 'Consultar seguimientos comerciales de todos los equipos.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1030, 'Seguimientos comerciales', 'seguimientos_comerciales.ver_propios', 'Ver seguimientos comerciales propios', 'Consultar seguimientos comerciales asignados al usuario.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1031, 'Seguimientos comerciales', 'seguimientos_comerciales.crear', 'Crear seguimientos comerciales', 'Registrar nuevos seguimientos comerciales.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1032, 'Seguimientos comerciales', 'seguimientos_comerciales.editar', 'Editar seguimientos comerciales', 'Actualizar cualquier seguimiento comercial.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1033, 'Seguimientos comerciales', 'seguimientos_comerciales.editar_propios', 'Editar seguimientos comerciales propios', 'Actualizar solo seguimientos comerciales asignados al usuario.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1034, 'Seguimientos de vinculación', 'seguimientos_vinculacion.ver', 'Ver seguimientos de vinculación', 'Consultar seguimientos de vinculación institucional.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1035, 'Seguimientos de vinculación', 'seguimientos_vinculacion.crear', 'Crear seguimientos de vinculación', 'Registrar seguimientos de vinculación institucional.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1036, 'Seguimientos de vinculación', 'seguimientos_vinculacion.editar', 'Editar seguimientos de vinculación', 'Actualizar seguimientos de vinculación institucional.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1047, 'Reuniones', 'reuniones.solicitar', 'Solicitar reuniones', 'Registrar solicitudes de reunión para seguimiento institucional.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1051, 'Difusión', 'difusion.ver', 'Ver difusión', 'Consultar campañas, convocatorias o ligas de registro.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1052, 'Difusión', 'difusion.crear', 'Crear difusión', 'Registrar nuevas convocatorias o ligas de registro.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1053, 'Difusión', 'difusion.enviar', 'Enviar difusión', 'Enviar convocatorias o ligas a instituciones autorizadas.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(1054, 'Difusión', 'difusion.gestionar', 'Gestionar difusión', 'Administrar el proceso de difusión institucional.', 1, '2026-08-28 19:58:54', '2026-08-28 19:58:54'),
(2955, 'Territorios', 'territorios.ver', 'Ver territorios', 'Consultar estados y responsables territoriales.', 1, '2026-08-28 21:27:04', '2026-08-28 21:27:04'),
(2956, 'Territorios', 'territorios.editar', 'Editar territorios', 'Actualizar información general de los territorios.', 1, '2026-08-28 21:27:04', '2026-08-28 21:27:04'),
(2957, 'Territorios', 'territorios.asignar', 'Asignar responsables', 'Gestionar responsables territoriales por estado.', 1, '2026-08-28 21:27:04', '2026-08-28 21:27:04'),
(4302, 'Territorios', 'territorios.actualizar_ficha', 'Actualizar ficha territorial', 'Actualizar información de investigación de los territorios.', 1, '2026-08-29 19:01:33', '2026-08-29 19:01:33'),
(4940, 'Información territorial', 'data_territorial.ver', 'Consultar información territorial', 'Consultar la información territorial de los estados asignados.', 1, '2026-08-29 21:20:47', '2026-08-30 02:35:20'),
(4941, 'Información territorial', 'data_territorial.editar', 'Editar información territorial', 'Actualizar información territorial de los estados asignados.', 1, '2026-08-29 21:20:47', '2026-08-30 02:35:20'),
(4942, 'Información territorial', 'data_territorial.gestionar_secretarias', 'Gestionar secretarías', 'Registrar y actualizar secretarías estatales.', 1, '2026-08-29 21:20:47', '2026-08-30 02:35:20'),
(4943, 'Información territorial', 'data_territorial.gestionar_municipios', 'Gestionar municipios', 'Registrar y actualizar información municipal.', 1, '2026-08-29 21:20:47', '2026-08-30 02:35:20'),
(4944, 'Información territorial', 'data_territorial.gestionar_indicadores', 'Gestionar indicadores educativos', 'Registrar y actualizar indicadores educativos territoriales.', 1, '2026-08-29 21:20:47', '2026-08-30 02:35:20');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rezago_educativo`
--

CREATE TABLE `rezago_educativo` (
  `id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL,
  `situacion` varchar(200) NOT NULL,
  `porcentaje` decimal(6,2) DEFAULT NULL,
  `cantidad_aproximada` int(11) DEFAULT NULL,
  `fuente` varchar(150) DEFAULT NULL,
  `periodo` varchar(50) DEFAULT NULL,
  `fecha_consulta` datetime DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 1,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `descripcion`, `estado`, `created_at`, `updated_at`) VALUES
(1, 'Administrador', 'Administración general del sistema', 1, '2026-08-27 14:57:07', '2026-08-27 14:57:07'),
(2, 'Coordinador Comercial', 'Supervisión del proceso comercial', 1, '2026-08-27 14:57:07', '2026-08-28 21:29:10'),
(3, 'Asesor de Ventas', 'Atención y seguimiento de prospectos', 1, '2026-08-27 14:57:07', '2026-08-27 14:57:07'),
(4, 'Analista de Datos', 'Investigación, validación y vinculación con organizaciones', 1, '2026-08-27 14:57:07', '2026-08-27 14:57:07'),
(5, 'Finanzas', 'Validación de cierres e inscripciones', 1, '2026-08-27 14:57:07', '2026-08-28 21:29:14'),
(6, 'Cuenta Clave', 'Responsable de reuniones, convenios y seguimiento institucional', 1, '2026-08-28 19:09:14', '2026-08-28 20:09:16');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rol_permisos`
--

CREATE TABLE `rol_permisos` (
  `rol_id` int(11) NOT NULL,
  `permiso_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `rol_permisos`
--

INSERT INTO `rol_permisos` (`rol_id`, `permiso_id`, `created_at`) VALUES
(1, 1, '2026-08-28 19:25:19'),
(1, 2, '2026-08-28 19:25:19'),
(1, 3, '2026-08-28 19:25:19'),
(1, 4, '2026-08-28 19:25:19'),
(1, 5, '2026-08-28 19:25:19'),
(1, 6, '2026-08-28 19:25:19'),
(1, 7, '2026-08-28 19:25:19'),
(1, 8, '2026-08-28 19:25:19'),
(1, 9, '2026-08-28 19:25:19'),
(1, 10, '2026-08-28 19:25:19'),
(1, 11, '2026-08-28 19:25:19'),
(1, 12, '2026-08-28 19:25:19'),
(1, 13, '2026-08-28 19:25:19'),
(1, 14, '2026-08-28 19:25:19'),
(1, 15, '2026-08-28 19:25:19'),
(1, 16, '2026-08-28 19:25:19'),
(1, 17, '2026-08-28 19:25:19'),
(1, 18, '2026-08-28 19:25:19'),
(1, 19, '2026-08-28 19:25:19'),
(1, 20, '2026-08-28 19:25:19'),
(1, 21, '2026-08-28 19:25:19'),
(1, 22, '2026-08-28 19:25:19'),
(1, 23, '2026-08-28 19:25:19'),
(1, 24, '2026-08-28 19:25:19'),
(1, 25, '2026-08-28 19:25:19'),
(1, 26, '2026-08-28 19:25:19'),
(1, 27, '2026-08-28 19:25:19'),
(1, 28, '2026-08-28 19:25:19'),
(1, 29, '2026-08-28 19:25:19'),
(1, 30, '2026-08-28 19:25:19'),
(1, 31, '2026-08-28 19:25:19'),
(1, 32, '2026-08-28 19:25:19'),
(1, 33, '2026-08-28 19:25:19'),
(1, 34, '2026-08-28 19:25:19'),
(1, 35, '2026-08-28 19:25:19'),
(1, 1029, '2026-08-28 19:58:54'),
(1, 1030, '2026-08-28 19:58:54'),
(1, 1031, '2026-08-28 19:58:54'),
(1, 1032, '2026-08-28 19:58:54'),
(1, 1033, '2026-08-28 19:58:54'),
(1, 1034, '2026-08-28 19:58:54'),
(1, 1035, '2026-08-28 19:58:54'),
(1, 1036, '2026-08-28 19:58:54'),
(1, 1047, '2026-08-28 19:58:54'),
(1, 1051, '2026-08-28 19:58:54'),
(1, 1052, '2026-08-28 19:58:54'),
(1, 1053, '2026-08-28 19:58:54'),
(1, 1054, '2026-08-28 19:58:54'),
(1, 2955, '2026-08-28 21:27:04'),
(1, 2956, '2026-08-28 21:27:04'),
(1, 2957, '2026-08-28 21:27:04'),
(1, 4302, '2026-08-29 19:01:33'),
(1, 4940, '2026-08-29 21:20:47'),
(1, 4941, '2026-08-29 21:20:47'),
(1, 4942, '2026-08-29 21:20:47'),
(1, 4943, '2026-08-29 21:20:47'),
(1, 4944, '2026-08-29 21:20:47'),
(2, 10, '2026-08-28 19:58:54'),
(2, 12, '2026-08-28 19:58:54'),
(2, 13, '2026-08-28 19:58:54'),
(2, 30, '2026-08-28 19:58:54'),
(2, 31, '2026-08-28 19:58:54'),
(2, 1029, '2026-08-28 19:58:54'),
(2, 1031, '2026-08-28 19:58:54'),
(2, 1032, '2026-08-28 19:58:54'),
(3, 11, '2026-08-28 19:58:54'),
(3, 12, '2026-08-28 19:58:54'),
(3, 1030, '2026-08-28 19:58:54'),
(3, 1031, '2026-08-28 19:58:54'),
(3, 1033, '2026-08-28 19:58:54'),
(4, 19, '2026-08-28 19:58:54'),
(4, 20, '2026-08-28 19:58:54'),
(4, 21, '2026-08-28 19:58:54'),
(4, 22, '2026-08-28 19:58:54'),
(4, 23, '2026-08-28 19:58:54'),
(4, 24, '2026-08-28 19:58:54'),
(4, 25, '2026-08-28 19:58:54'),
(4, 26, '2026-08-28 19:58:54'),
(4, 28, '2026-08-28 19:58:54'),
(4, 1034, '2026-08-28 19:58:54'),
(4, 1047, '2026-08-28 19:58:54'),
(4, 2955, '2026-08-28 21:27:04'),
(4, 4302, '2026-08-29 19:01:33'),
(4, 4940, '2026-08-29 21:20:47'),
(4, 4941, '2026-08-29 21:20:47'),
(4, 4942, '2026-08-29 21:20:47'),
(4, 4943, '2026-08-29 21:20:47'),
(4, 4944, '2026-08-29 21:20:47'),
(5, 17, '2026-08-28 19:58:54'),
(5, 18, '2026-08-28 19:58:54'),
(5, 30, '2026-08-28 19:58:54'),
(6, 19, '2026-08-28 20:00:15'),
(6, 23, '2026-08-28 20:00:15'),
(6, 26, '2026-08-28 20:00:15'),
(6, 27, '2026-08-28 20:00:15'),
(6, 28, '2026-08-28 20:00:15'),
(6, 29, '2026-08-28 20:00:15'),
(6, 30, '2026-08-28 20:00:15'),
(6, 1034, '2026-08-28 20:00:15'),
(6, 1035, '2026-08-28 20:00:15'),
(6, 1036, '2026-08-28 20:00:15'),
(6, 1051, '2026-08-28 20:00:15'),
(6, 1052, '2026-08-28 20:00:15'),
(6, 1053, '2026-08-28 20:00:15'),
(6, 1054, '2026-08-28 20:00:15'),
(6, 2955, '2026-08-28 21:27:04'),
(6, 4940, '2026-08-29 21:20:47');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `secretarias_estatales`
--

CREATE TABLE `secretarias_estatales` (
  `id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `titular` varchar(180) DEFAULT NULL,
  `cargo_titular` varchar(120) DEFAULT NULL,
  `correo` varchar(180) DEFAULT NULL,
  `telefono` varchar(80) DEFAULT NULL,
  `sitio_web` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellidos` varchar(150) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `correo` varchar(150) NOT NULL,
  `usuario` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol_id` int(11) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `requiere_cambio_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_temporal_expira` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `apellidos`, `telefono`, `foto_perfil`, `correo`, `usuario`, `password`, `rol_id`, `estado`, `ultimo_acceso`, `created_at`, `updated_at`, `requiere_cambio_password`, `password_temporal_expira`) VALUES
(1, 'Administrador', 'Sistema', '', 'public/uploads/usuarios/usuario_20260828095732_672ef20f1000.png', 'davidvaldez050708@gmail.com', 'admin', '$2y$10$y46sO49gndLXmQzEYmdc..aMOPYALoRNDcIln64pCLng.1WC1UvQC', 1, 1, '2026-08-29 20:08:52', '2026-08-27 15:01:42', '2026-08-30 02:08:52', 0, NULL),
(3, 'Diana', 'Estrada', '7777885173', 'public/uploads/usuarios/usuario_20260828132441_f457e25af90b.jpg', 'vfdo230232@upemor.edu.mx', 'dianita', '$2y$10$qIkD6Mq.saaBGYx3kC3cNu.QrC1SCM1YQXNRrM1W52l2/zsHhifSG', 3, 0, NULL, '2026-08-28 19:02:00', '2026-08-28 19:24:41', 0, NULL),
(4, 'Susana', 'Molina Medrano', '7777885173', 'public/uploads/usuarios/usuario_20260829140232_411d260b216d.jpg', 'susana.molina@test.local', 'susana.molina', '$2y$10$f8w8kheYLTlJX2vHK.iqtORlaiiFicuQuJ39IxcE0LsEd9TqQPH9S', 6, 1, '2026-08-29 20:07:15', '2026-08-29 19:10:32', '2026-08-30 02:07:15', 0, NULL),
(5, 'Diego', 'Bahena', '7771234567', '', 'diego.bahena@test.local', 'diego.bahena', '$2y$10$CvN6jRGeq/OaD/1NaSULf.0I20XPOTsIl6UMhDB4t1X0kiun7ax76', 4, 1, '2026-08-29 20:07:38', '2026-08-29 19:11:32', '2026-08-30 02:07:38', 0, NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `asignaciones_territorio`
--
ALTER TABLE `asignaciones_territorio`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_asignacion_estado` (`estado_id`),
  ADD KEY `fk_asignacion_usuario` (`usuario_id`),
  ADD KEY `idx_cuenta_clave_asignacion` (`cuenta_clave_asignacion_id`);

--
-- Indices de la tabla `estados`
--
ALTER TABLE `estados`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD UNIQUE KEY `clave_inegi` (`clave_inegi`);

--
-- Indices de la tabla `fuentes_datos_territoriales`
--
ALTER TABLE `fuentes_datos_territoriales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fuente_territorio_estado` (`estado_id`),
  ADD KEY `fk_fuente_territorio_usuario` (`usuario_verifico_id`);

--
-- Indices de la tabla `municipios`
--
ALTER TABLE `municipios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_estado_municipio` (`estado_id`,`nombre`);

--
-- Indices de la tabla `permisos`
--
ALTER TABLE `permisos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indices de la tabla `rezago_educativo`
--
ALTER TABLE `rezago_educativo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rezago_estado` (`estado_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `rol_permisos`
--
ALTER TABLE `rol_permisos`
  ADD PRIMARY KEY (`rol_id`,`permiso_id`),
  ADD KEY `fk_rol_permisos_permiso` (`permiso_id`);

--
-- Indices de la tabla `secretarias_estatales`
--
ALTER TABLE `secretarias_estatales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_secretaria_estado` (`estado_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD UNIQUE KEY `usuario` (`usuario`),
  ADD KEY `fk_usuario_rol` (`rol_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `asignaciones_territorio`
--
ALTER TABLE `asignaciones_territorio`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `estados`
--
ALTER TABLE `estados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT de la tabla `fuentes_datos_territoriales`
--
ALTER TABLE `fuentes_datos_territoriales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `municipios`
--
ALTER TABLE `municipios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `permisos`
--
ALTER TABLE `permisos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8226;

--
-- AUTO_INCREMENT de la tabla `rezago_educativo`
--
ALTER TABLE `rezago_educativo`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `secretarias_estatales`
--
ALTER TABLE `secretarias_estatales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asignaciones_territorio`
--
ALTER TABLE `asignaciones_territorio`
  ADD CONSTRAINT `fk_asignacion_cuenta_clave` FOREIGN KEY (`cuenta_clave_asignacion_id`) REFERENCES `asignaciones_territorio` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_asignacion_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`),
  ADD CONSTRAINT `fk_asignacion_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `fuentes_datos_territoriales`
--
ALTER TABLE `fuentes_datos_territoriales`
  ADD CONSTRAINT `fk_fuente_territorio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fuente_territorio_usuario` FOREIGN KEY (`usuario_verifico_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `municipios`
--
ALTER TABLE `municipios`
  ADD CONSTRAINT `fk_municipio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `rezago_educativo`
--
ALTER TABLE `rezago_educativo`
  ADD CONSTRAINT `fk_rezago_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `rol_permisos`
--
ALTER TABLE `rol_permisos`
  ADD CONSTRAINT `fk_rol_permisos_permiso` FOREIGN KEY (`permiso_id`) REFERENCES `permisos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rol_permisos_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `secretarias_estatales`
--
ALTER TABLE `secretarias_estatales`
  ADD CONSTRAINT `fk_secretaria_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
