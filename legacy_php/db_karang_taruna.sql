-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 17 Feb 2026 pada 15.14
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_karang_taruna`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `attendances`
--

CREATE TABLE `attendances` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `type` enum('in','out','izin') NOT NULL DEFAULT 'in',
  `selfie_path` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `status` enum('valid','invalid','pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `attendances`
--

INSERT INTO `attendances` (`id`, `user_id`, `event_id`, `type`, `selfie_path`, `latitude`, `longitude`, `status`, `created_at`, `notes`) VALUES
(30, 3, 12, 'izin', 'selfie_3_1771253996.jpg', -7.54430023, 111.00512832, 'valid', '2026-02-16 14:59:56', 'izin kerja'),
(31, 4, 12, 'in', 'selfie_4_1771254796.jpg', -7.55310000, 111.04080000, 'valid', '2026-02-16 15:13:16', NULL),
(32, 4, 12, 'out', 'selfie_4_1771255397.jpg', -7.55310000, 111.04080000, 'valid', '2026-02-16 15:23:17', NULL),
(34, 4, 15, 'in', 'selfie_4_1771335974.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 13:46:14', NULL),
(35, 4, 15, 'out', 'selfie_4_1771336586.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 13:56:26', NULL),
(36, 7, 15, 'izin', 'selfie_7_1771336623.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 13:57:03', 'izin kerja pak'),
(37, 6, 15, 'in', 'selfie_6_1771336650.jpg', -7.57880000, 110.98310000, 'valid', '2026-02-17 13:57:30', NULL),
(38, 6, 15, 'out', 'selfie_6_1771336673.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 13:57:53', NULL),
(39, 2, 15, 'izin', 'selfie_2_1771336708.jpg', -7.57880000, 110.98310000, 'valid', '2026-02-17 13:58:28', 'masuk siang'),
(40, 5, 15, 'in', 'selfie_5_1771336897.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 14:01:37', NULL),
(41, 5, 15, 'out', 'selfie_5_1771336914.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 14:01:54', NULL),
(42, 1, 15, 'izin', 'selfie_1_1771336956.jpg', -7.57880000, 110.98310000, 'valid', '2026-02-17 14:02:36', 'izin sakit'),
(43, 3, 15, 'in', 'selfie_3_1771337065.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 14:04:25', NULL),
(44, 3, 15, 'out', 'selfie_3_1771337089.jpg', -7.55009723, 111.00777963, 'valid', '2026-02-17 14:04:49', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lon` decimal(11,8) NOT NULL,
  `tolerance_meters` int(11) DEFAULT 100,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `events`
--

INSERT INTO `events` (`id`, `title`, `start_time`, `end_time`, `lat`, `lon`, `tolerance_meters`, `is_active`) VALUES
(12, 'Nyinom', '2026-02-16 21:38:00', '2026-02-16 22:30:00', -7.55310000, 111.04080000, 50, 1),
(15, 'kerja bakti', '2026-02-17 20:45:00', '2026-02-17 21:07:00', -7.55009723, 111.00777963, 50, 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('member','admin','pengurus') DEFAULT 'member',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `password_hash`, `role`, `created_at`) VALUES
(1, 'Taufan Habibullah', 'taufan', '202cb962ac59075b964b07152d234b70', 'pengurus', '2025-11-26 14:40:40'),
(2, 'Varrel Otto Wibowo', 'gundol', '202cb962ac59075b964b07152d234b70', 'member', '2025-11-26 14:40:40'),
(3, 'Musafa Ridwan', 'musa', '202cb962ac59075b964b07152d234b70', 'admin', '2025-11-26 16:04:06'),
(4, 'SHoleh Tegar Pra', 'tegarpra', '202cb962ac59075b964b07152d234b70', 'member', '2025-11-26 16:04:33'),
(5, 'Misye Rike', 'misye', '202cb962ac59075b964b07152d234b70', 'pengurus', '2026-01-21 05:50:35'),
(6, 'Fadillah Syehandi', 'mail', '202cb962ac59075b964b07152d234b70', 'member', '2026-01-21 06:05:35'),
(7, 'Aan Tomi', 'aan', '202cb962ac59075b964b07152d234b70', 'member', '2026-02-17 13:43:59'),
(8, 'Tony Gunawan', 'tony', '202cb962ac59075b964b07152d234b70', 'member', '2026-02-17 14:03:04');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `attendances`
--
ALTER TABLE `attendances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indeks untuk tabel `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `attendances`
--
ALTER TABLE `attendances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT untuk tabel `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `attendances`
--
ALTER TABLE `attendances`
  ADD CONSTRAINT `attendances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `attendances_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
