-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: moritzschneider.net.mysql.service.one.com:3306
-- Erstellungszeit: 11. Feb 2025 um 08:01
-- Server-Version: 10.6.20-MariaDB-ubu2204
-- PHP-Version: 7.4.3-4ubuntu2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `moritzschneider_netcm23`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_achievement`
--

CREATE TABLE `cm23_achievement` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `season_id` int(10) DEFAULT NULL,
  `cup_round_id` int(10) DEFAULT NULL,
  `rank` tinyint(3) DEFAULT NULL,
  `date_recorded` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_admin`
--

CREATE TABLE `cm23_admin` (
  `id` smallint(5) NOT NULL,
  `name` varchar(40) DEFAULT NULL,
  `passwort` varchar(64) DEFAULT NULL,
  `passwort_neu` varchar(64) DEFAULT NULL,
  `passwort_neu_angefordert` int(11) NOT NULL DEFAULT 0,
  `passwort_salt` varchar(5) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `lang` varchar(2) DEFAULT NULL,
  `r_admin` enum('1','0') DEFAULT NULL,
  `r_adminuser` enum('1','0') DEFAULT NULL,
  `r_user` enum('1','0') NOT NULL DEFAULT '0',
  `r_daten` enum('1','0') NOT NULL DEFAULT '0',
  `r_staerken` enum('1','0') NOT NULL DEFAULT '0',
  `r_spiele` enum('1','0') NOT NULL DEFAULT '0',
  `r_news` enum('1','0') NOT NULL DEFAULT '0',
  `r_faq` enum('1','0') NOT NULL DEFAULT '0',
  `r_umfrage` enum('1','0') NOT NULL DEFAULT '0',
  `r_kalender` enum('1','0') NOT NULL DEFAULT '0',
  `r_seiten` enum('1','0') NOT NULL DEFAULT '0',
  `r_design` enum('1','0') NOT NULL DEFAULT '0',
  `r_demo` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_aufstellung`
--

CREATE TABLE `cm23_aufstellung` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) DEFAULT NULL,
  `datum` int(11) DEFAULT NULL,
  `offensive` tinyint(3) DEFAULT 50,
  `spieler1` int(10) DEFAULT NULL,
  `spieler2` int(10) DEFAULT NULL,
  `spieler3` int(10) DEFAULT NULL,
  `spieler4` int(10) DEFAULT NULL,
  `spieler5` int(10) DEFAULT NULL,
  `spieler6` int(10) DEFAULT NULL,
  `spieler7` int(10) DEFAULT NULL,
  `spieler8` int(10) DEFAULT NULL,
  `spieler9` int(10) DEFAULT NULL,
  `spieler10` int(10) DEFAULT NULL,
  `spieler11` int(10) DEFAULT NULL,
  `ersatz1` int(10) DEFAULT NULL,
  `ersatz2` int(10) DEFAULT NULL,
  `ersatz3` int(10) DEFAULT NULL,
  `ersatz4` int(10) DEFAULT NULL,
  `ersatz5` int(10) DEFAULT NULL,
  `w1_raus` int(10) DEFAULT NULL,
  `w1_rein` int(10) DEFAULT NULL,
  `w1_minute` tinyint(2) DEFAULT NULL,
  `w2_raus` int(10) DEFAULT NULL,
  `w2_rein` int(10) DEFAULT NULL,
  `w2_minute` tinyint(2) DEFAULT NULL,
  `w3_raus` int(10) DEFAULT NULL,
  `w3_rein` int(10) DEFAULT NULL,
  `w3_minute` tinyint(2) DEFAULT NULL,
  `setup` varchar(16) DEFAULT NULL,
  `w1_condition` varchar(16) DEFAULT NULL,
  `w2_condition` varchar(16) DEFAULT NULL,
  `w3_condition` varchar(16) DEFAULT NULL,
  `longpasses` enum('1','0') DEFAULT '0',
  `counterattacks` enum('1','0') DEFAULT '0',
  `freekickplayer` int(10) DEFAULT NULL,
  `w1_position` varchar(4) DEFAULT NULL,
  `w2_position` varchar(4) DEFAULT NULL,
  `w3_position` varchar(4) DEFAULT NULL,
  `spieler1_position` varchar(4) DEFAULT NULL,
  `spieler2_position` varchar(4) DEFAULT NULL,
  `spieler3_position` varchar(4) DEFAULT NULL,
  `spieler4_position` varchar(4) DEFAULT NULL,
  `spieler5_position` varchar(4) DEFAULT NULL,
  `spieler6_position` varchar(4) DEFAULT NULL,
  `spieler7_position` varchar(4) DEFAULT NULL,
  `spieler8_position` varchar(4) DEFAULT NULL,
  `spieler9_position` varchar(4) DEFAULT NULL,
  `spieler10_position` varchar(4) DEFAULT NULL,
  `spieler11_position` varchar(4) DEFAULT NULL,
  `match_id` int(10) DEFAULT NULL,
  `templatename` varchar(24) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_badge`
--

CREATE TABLE `cm23_badge` (
  `id` int(10) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `level` enum('bronze','silver','gold') NOT NULL DEFAULT 'bronze',
  `event` enum('membership_since_x_days','win_with_x_goals_difference','completed_season_at_x','x_trades','cupwinner','stadium_construction_by_x') NOT NULL,
  `event_benchmark` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_badge_user`
--

CREATE TABLE `cm23_badge_user` (
  `user_id` int(10) NOT NULL,
  `badge_id` int(10) NOT NULL,
  `date_rewarded` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_briefe`
--

CREATE TABLE `cm23_briefe` (
  `id` int(10) NOT NULL,
  `empfaenger_id` int(10) NOT NULL,
  `absender_id` int(10) NOT NULL,
  `absender_name` varchar(50) DEFAULT NULL,
  `datum` int(10) NOT NULL,
  `betreff` varchar(50) DEFAULT NULL,
  `nachricht` text DEFAULT NULL,
  `gelesen` enum('1','0') NOT NULL DEFAULT '0',
  `typ` enum('eingang','ausgang') NOT NULL DEFAULT 'eingang'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_buildings_of_team`
--

CREATE TABLE `cm23_buildings_of_team` (
  `building_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `construction_deadline` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_country`
--

CREATE TABLE `cm23_country` (
  `id` int(3) NOT NULL,
  `continent` varchar(3) NOT NULL,
  `country` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_cup`
--

CREATE TABLE `cm23_cup` (
  `id` int(10) NOT NULL,
  `name` varchar(64) DEFAULT NULL,
  `winner_id` int(10) DEFAULT NULL,
  `logo` varchar(128) DEFAULT NULL,
  `winner_award` int(10) NOT NULL DEFAULT 0,
  `second_award` int(10) NOT NULL DEFAULT 0,
  `perround_award` int(10) NOT NULL DEFAULT 0,
  `archived` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_cup_round`
--

CREATE TABLE `cm23_cup_round` (
  `id` int(10) NOT NULL,
  `cup_id` int(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `from_winners_round_id` int(10) DEFAULT NULL,
  `from_loosers_round_id` int(10) DEFAULT NULL,
  `firstround_date` int(11) NOT NULL,
  `secondround_date` int(11) DEFAULT NULL,
  `finalround` enum('1','0') NOT NULL DEFAULT '0',
  `groupmatches` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_cup_round_group`
--

CREATE TABLE `cm23_cup_round_group` (
  `cup_round_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `tab_points` int(4) NOT NULL DEFAULT 0,
  `tab_goals` int(4) NOT NULL DEFAULT 0,
  `tab_goalsreceived` int(4) NOT NULL DEFAULT 0,
  `tab_wins` int(4) NOT NULL DEFAULT 0,
  `tab_draws` int(4) NOT NULL DEFAULT 0,
  `tab_losses` int(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_cup_round_group_next`
--

CREATE TABLE `cm23_cup_round_group_next` (
  `cup_round_id` int(10) NOT NULL,
  `groupname` varchar(64) NOT NULL,
  `rank` int(4) NOT NULL DEFAULT 0,
  `target_cup_round_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_cup_round_pending`
--

CREATE TABLE `cm23_cup_round_pending` (
  `team_id` int(10) NOT NULL,
  `cup_round_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_friendly_request`
--

CREATE TABLE `cm23_friendly_request` (
  `id` int(3) NOT NULL,
  `sender_team_id` int(5) NOT NULL,
  `receiver_team_id` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_kontinent`
--

CREATE TABLE `cm23_kontinent` (
  `id` smallint(5) NOT NULL,
  `name` varchar(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_konto`
--

CREATE TABLE `cm23_konto` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL,
  `absender` varchar(150) DEFAULT NULL,
  `betrag` int(10) NOT NULL,
  `datum` int(11) NOT NULL,
  `verwendung` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_kontohistory`
--

CREATE TABLE `cm23_kontohistory` (
  `v1` varchar(20) DEFAULT '0',
  `v2` varchar(20) DEFAULT '0',
  `v3` varchar(20) DEFAULT '0',
  `v4` varchar(20) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_land`
--

CREATE TABLE `cm23_land` (
  `id` int(5) NOT NULL,
  `name` varchar(30) NOT NULL,
  `uefa_s1` decimal(10,3) DEFAULT NULL,
  `uefa_s2` decimal(10,3) DEFAULT NULL,
  `uefa_s3` decimal(10,3) DEFAULT NULL,
  `uefa_s4` decimal(10,3) DEFAULT NULL,
  `uefa_s5` decimal(10,3) DEFAULT NULL,
  `uefa_cl` tinyint(1) NOT NULL,
  `uefa_ul` tinyint(1) NOT NULL,
  `uefa_conf` tinyint(1) NOT NULL,
  `uefa_coeff` decimal(10,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_leaguehistory`
--

CREATE TABLE `cm23_leaguehistory` (
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `user_id` int(10) DEFAULT NULL,
  `matchday` tinyint(3) NOT NULL,
  `rank` tinyint(3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_liga`
--

CREATE TABLE `cm23_liga` (
  `id` smallint(5) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `kurz` varchar(5) DEFAULT NULL,
  `land` varchar(25) DEFAULT NULL,
  `kontinent_id` smallint(5) DEFAULT NULL,
  `division` smallint(2) DEFAULT NULL,
  `p_steh` tinyint(3) NOT NULL,
  `p_sitz` tinyint(3) NOT NULL,
  `p_haupt_steh` tinyint(3) NOT NULL,
  `p_haupt_sitz` tinyint(3) NOT NULL,
  `p_vip` tinyint(3) NOT NULL,
  `preis_steh` smallint(5) NOT NULL,
  `preis_sitz` smallint(5) NOT NULL,
  `preis_vip` smallint(5) NOT NULL,
  `admin_id` smallint(5) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_matchreport`
--

CREATE TABLE `cm23_matchreport` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `message_id` int(10) NOT NULL,
  `minute` tinyint(3) NOT NULL,
  `goals` varchar(8) DEFAULT NULL,
  `playernames` varchar(128) DEFAULT NULL,
  `active_home` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_name`
--

CREATE TABLE `cm23_name` (
  `id` int(10) NOT NULL,
  `name` varchar(40) NOT NULL,
  `continent` varchar(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_nationalplayer`
--

CREATE TABLE `cm23_nationalplayer` (
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_news`
--

CREATE TABLE `cm23_news` (
  `id` int(10) NOT NULL,
  `datum` int(10) NOT NULL,
  `autor_id` smallint(5) NOT NULL,
  `bild_id` int(10) DEFAULT NULL,
  `titel` varchar(100) DEFAULT NULL,
  `nachricht` text DEFAULT NULL,
  `linktext1` varchar(100) DEFAULT NULL,
  `linkurl1` varchar(250) DEFAULT NULL,
  `linktext2` varchar(100) DEFAULT NULL,
  `linkurl2` varchar(250) DEFAULT NULL,
  `linktext3` varchar(100) DEFAULT NULL,
  `linkurl3` varchar(250) DEFAULT NULL,
  `c_br` enum('1','0') NOT NULL DEFAULT '0',
  `c_links` enum('1','0') NOT NULL DEFAULT '0',
  `c_smilies` enum('1','0') NOT NULL DEFAULT '0',
  `status` enum('1','2','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_notification`
--

CREATE TABLE `cm23_notification` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `eventdate` int(11) NOT NULL,
  `eventtype` varchar(128) DEFAULT NULL,
  `message_key` varchar(255) DEFAULT NULL,
  `message_data` varchar(255) DEFAULT NULL,
  `target_pageid` varchar(128) DEFAULT NULL,
  `target_querystr` varchar(255) DEFAULT NULL,
  `seen` enum('1','0') NOT NULL DEFAULT '0',
  `team_id` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_penalty`
--

CREATE TABLE `cm23_penalty` (
  `budget` int(25) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_premiumpayment`
--

CREATE TABLE `cm23_premiumpayment` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `amount` int(10) NOT NULL,
  `created_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_premiumstatement`
--

CREATE TABLE `cm23_premiumstatement` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `action_id` varchar(255) DEFAULT NULL,
  `amount` int(10) NOT NULL,
  `created_date` int(11) NOT NULL,
  `subject_data` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_randomevent`
--

CREATE TABLE `cm23_randomevent` (
  `id` int(10) NOT NULL,
  `message` text DEFAULT NULL,
  `effect` enum('money','player_injured','player_blocked','player_happiness','player_fitness','player_stamina') NOT NULL,
  `effect_money_amount` int(10) NOT NULL DEFAULT 0,
  `effect_blocked_matches` int(10) NOT NULL DEFAULT 0,
  `effect_skillchange` tinyint(3) NOT NULL DEFAULT 0,
  `weight` tinyint(3) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_randomevent_occurrence`
--

CREATE TABLE `cm23_randomevent_occurrence` (
  `user_id` int(10) DEFAULT NULL,
  `team_id` int(10) DEFAULT NULL,
  `event_id` int(10) DEFAULT NULL,
  `occurrence_date` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_saison`
--

CREATE TABLE `cm23_saison` (
  `id` int(10) NOT NULL,
  `name` varchar(20) DEFAULT NULL,
  `liga_id` smallint(5) NOT NULL,
  `platz_1_id` int(10) DEFAULT NULL,
  `platz_2_id` int(10) DEFAULT NULL,
  `platz_3_id` int(10) DEFAULT NULL,
  `platz_4_id` int(10) DEFAULT NULL,
  `platz_5_id` int(10) DEFAULT NULL,
  `beendet` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_scout`
--

CREATE TABLE `cm23_scout` (
  `id` int(10) NOT NULL,
  `name` varchar(35) NOT NULL,
  `nation` varchar(35) NOT NULL,
  `expertise` tinyint(3) NOT NULL,
  `fee` int(10) NOT NULL,
  `speciality` enum('Torwart','Abwehr','Mittelfeld','Sturm') NOT NULL,
  `team_id` int(3) NOT NULL DEFAULT 0,
  `team_matches` int(2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_second_position`
--

CREATE TABLE `cm23_second_position` (
  `second_position` varchar(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_session`
--

CREATE TABLE `cm23_session` (
  `session_id` char(32) NOT NULL,
  `session_data` text NOT NULL,
  `expires` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_shoutmessage`
--

CREATE TABLE `cm23_shoutmessage` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_date` int(11) NOT NULL,
  `match_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_spiel`
--

CREATE TABLE `cm23_spiel` (
  `id` int(10) NOT NULL,
  `spieltyp` enum('Ligaspiel','Pokalspiel','Freundschaft') NOT NULL DEFAULT 'Ligaspiel',
  `elfmeter` enum('1','0') NOT NULL DEFAULT '0',
  `pokalname` varchar(30) DEFAULT NULL,
  `pokalrunde` varchar(30) DEFAULT NULL,
  `pokalgruppe` varchar(64) DEFAULT NULL,
  `liga_id` smallint(5) DEFAULT NULL,
  `saison_id` int(10) DEFAULT NULL,
  `spieltag` tinyint(3) DEFAULT NULL,
  `datum` int(10) NOT NULL,
  `stadion_id` int(10) DEFAULT NULL,
  `minutes` tinyint(3) DEFAULT NULL,
  `player_with_ball` int(10) DEFAULT NULL,
  `prev_player_with_ball` int(10) DEFAULT NULL,
  `home_verein` int(10) NOT NULL,
  `home_noformation` enum('1','0') DEFAULT '0',
  `home_offensive` tinyint(3) DEFAULT NULL,
  `home_offensive_changed` tinyint(2) NOT NULL DEFAULT 0,
  `home_tore` tinyint(2) DEFAULT NULL,
  `home_spieler1` int(10) DEFAULT NULL,
  `home_spieler2` int(10) DEFAULT NULL,
  `home_spieler3` int(10) DEFAULT NULL,
  `home_spieler4` int(10) DEFAULT NULL,
  `home_spieler5` int(10) DEFAULT NULL,
  `home_spieler6` int(10) DEFAULT NULL,
  `home_spieler7` int(10) DEFAULT NULL,
  `home_spieler8` int(10) DEFAULT NULL,
  `home_spieler9` int(10) DEFAULT NULL,
  `home_spieler10` int(10) DEFAULT NULL,
  `home_spieler11` int(10) DEFAULT NULL,
  `home_ersatz1` int(10) DEFAULT NULL,
  `home_ersatz2` int(10) DEFAULT NULL,
  `home_ersatz3` int(10) DEFAULT NULL,
  `home_ersatz4` int(10) DEFAULT NULL,
  `home_ersatz5` int(10) DEFAULT NULL,
  `home_w1_raus` int(10) DEFAULT NULL,
  `home_w1_rein` int(10) DEFAULT NULL,
  `home_w1_minute` tinyint(2) DEFAULT NULL,
  `home_w2_raus` int(10) DEFAULT NULL,
  `home_w2_rein` int(10) DEFAULT NULL,
  `home_w2_minute` tinyint(2) DEFAULT NULL,
  `home_w3_raus` int(10) DEFAULT NULL,
  `home_w3_rein` int(10) DEFAULT NULL,
  `home_w3_minute` tinyint(2) DEFAULT NULL,
  `gast_verein` int(10) NOT NULL,
  `gast_tore` tinyint(2) DEFAULT NULL,
  `guest_noformation` enum('1','0') DEFAULT '0',
  `gast_offensive` tinyint(3) DEFAULT NULL,
  `gast_offensive_changed` tinyint(2) NOT NULL DEFAULT 0,
  `gast_spieler1` int(10) DEFAULT NULL,
  `gast_spieler2` int(10) DEFAULT NULL,
  `gast_spieler3` int(10) DEFAULT NULL,
  `gast_spieler4` int(10) DEFAULT NULL,
  `gast_spieler5` int(10) DEFAULT NULL,
  `gast_spieler6` int(10) DEFAULT NULL,
  `gast_spieler7` int(10) DEFAULT NULL,
  `gast_spieler8` int(10) DEFAULT NULL,
  `gast_spieler9` int(10) DEFAULT NULL,
  `gast_spieler10` int(10) DEFAULT NULL,
  `gast_spieler11` int(10) DEFAULT NULL,
  `gast_ersatz1` int(10) DEFAULT NULL,
  `gast_ersatz2` int(10) DEFAULT NULL,
  `gast_ersatz3` int(10) DEFAULT NULL,
  `gast_ersatz4` int(10) DEFAULT NULL,
  `gast_ersatz5` int(10) DEFAULT NULL,
  `gast_w1_raus` int(10) DEFAULT NULL,
  `gast_w1_rein` int(10) DEFAULT NULL,
  `gast_w1_minute` tinyint(2) DEFAULT NULL,
  `gast_w2_raus` int(10) DEFAULT NULL,
  `gast_w2_rein` int(10) DEFAULT NULL,
  `gast_w2_minute` tinyint(2) DEFAULT NULL,
  `gast_w3_raus` int(10) DEFAULT NULL,
  `gast_w3_rein` int(10) DEFAULT NULL,
  `gast_w3_minute` tinyint(2) DEFAULT NULL,
  `bericht` text DEFAULT NULL,
  `zuschauer` int(6) DEFAULT NULL,
  `berechnet` enum('1','0') NOT NULL DEFAULT '0',
  `soldout` enum('1','0') NOT NULL DEFAULT '0',
  `home_setup` varchar(16) DEFAULT NULL,
  `home_w1_condition` varchar(16) DEFAULT NULL,
  `home_w2_condition` varchar(16) DEFAULT NULL,
  `home_w3_condition` varchar(16) DEFAULT NULL,
  `gast_setup` varchar(16) DEFAULT NULL,
  `gast_w1_condition` varchar(16) DEFAULT NULL,
  `gast_w2_condition` varchar(16) DEFAULT NULL,
  `gast_w3_condition` varchar(16) DEFAULT NULL,
  `home_longpasses` enum('1','0') NOT NULL DEFAULT '0',
  `home_counterattacks` enum('1','0') NOT NULL DEFAULT '0',
  `gast_longpasses` enum('1','0') NOT NULL DEFAULT '0',
  `gast_counterattacks` enum('1','0') NOT NULL DEFAULT '0',
  `home_morale` tinyint(3) NOT NULL DEFAULT 0,
  `gast_morale` tinyint(3) NOT NULL DEFAULT 0,
  `home_user_id` int(10) DEFAULT NULL,
  `gast_user_id` int(10) DEFAULT NULL,
  `home_freekickplayer` int(10) DEFAULT NULL,
  `home_w1_position` varchar(4) DEFAULT NULL,
  `home_w2_position` varchar(4) DEFAULT NULL,
  `home_w3_position` varchar(4) DEFAULT NULL,
  `gast_freekickplayer` int(10) DEFAULT NULL,
  `gast_w1_position` varchar(4) DEFAULT NULL,
  `gast_w2_position` varchar(4) DEFAULT NULL,
  `gast_w3_position` varchar(4) DEFAULT NULL,
  `blocked` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_spieler`
--

CREATE TABLE `cm23_spieler` (
  `id` int(10) NOT NULL,
  `vorname` varchar(30) DEFAULT NULL,
  `nachname` varchar(30) DEFAULT NULL,
  `kunstname` varchar(30) DEFAULT NULL,
  `geburtstag` date NOT NULL,
  `verein_id` int(10) DEFAULT NULL,
  `position` enum('Torwart','Abwehr','Mittelfeld','Sturm') NOT NULL DEFAULT 'Mittelfeld',
  `position_main` enum('T','LV','IV','RV','LM','DM','ZM','OM','RM','LS','MS','RS') DEFAULT NULL,
  `position_second` enum('T','LV','IV','RV','LM','DM','ZM','OM','RM','LS','MS','RS','') DEFAULT NULL,
  `nation` varchar(40) DEFAULT NULL,
  `picture` varchar(128) DEFAULT NULL,
  `verletzt` tinyint(3) NOT NULL DEFAULT 0,
  `gesperrt` tinyint(3) NOT NULL DEFAULT 0,
  `gesperrt_cups` tinyint(3) NOT NULL DEFAULT 0,
  `gesperrt_nationalteam` tinyint(3) NOT NULL DEFAULT 0,
  `transfermarkt` enum('1','0') NOT NULL DEFAULT '0',
  `transfer_start` int(11) NOT NULL DEFAULT 0,
  `transfer_ende` int(11) NOT NULL DEFAULT 0,
  `transfer_mindestgebot` int(11) NOT NULL DEFAULT 0,
  `last_transfer` int(11) DEFAULT 0,
  `w_staerke` tinyint(3) NOT NULL,
  `w_staerke_max` tinyint(3) NOT NULL,
  `w_technik` tinyint(3) NOT NULL,
  `w_kondition` tinyint(3) NOT NULL,
  `w_frische` tinyint(3) NOT NULL,
  `w_zufriedenheit` tinyint(3) NOT NULL,
  `w_talent` tinyint(1) NOT NULL,
  `einzeltraining` enum('1','0') NOT NULL DEFAULT '0',
  `note_last` double(4,2) NOT NULL DEFAULT 0.00,
  `note_schnitt` double(4,2) NOT NULL DEFAULT 0.00,
  `vertrag_gehalt` int(10) NOT NULL,
  `vertrag_spiele` smallint(5) DEFAULT NULL,
  `vertrag_torpraemie` int(10) NOT NULL,
  `marktwert` int(10) NOT NULL DEFAULT 0,
  `st_tore` int(6) NOT NULL DEFAULT 0,
  `st_assists` int(6) NOT NULL DEFAULT 0,
  `st_spiele` smallint(5) NOT NULL DEFAULT 0,
  `st_karten_gelb` smallint(5) NOT NULL DEFAULT 0,
  `st_karten_gelb_rot` smallint(5) NOT NULL DEFAULT 0,
  `st_karten_rot` smallint(5) NOT NULL DEFAULT 0,
  `sa_tore` int(6) NOT NULL DEFAULT 0,
  `sa_assists` int(6) NOT NULL DEFAULT 0,
  `sa_spiele` smallint(5) NOT NULL DEFAULT 0,
  `sa_karten_gelb` smallint(5) NOT NULL DEFAULT 0,
  `sa_karten_gelb_rot` smallint(5) NOT NULL DEFAULT 0,
  `sa_karten_rot` smallint(5) NOT NULL DEFAULT 0,
  `history` text DEFAULT NULL,
  `unsellable` enum('1','0') NOT NULL DEFAULT '0',
  `lending_fee` int(6) NOT NULL DEFAULT 0,
  `lending_matches` tinyint(4) NOT NULL DEFAULT 0,
  `lending_owner_id` int(10) DEFAULT NULL,
  `age` tinyint(3) DEFAULT NULL,
  `status` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_spiel_berechnung`
--

CREATE TABLE `cm23_spiel_berechnung` (
  `id` int(10) NOT NULL,
  `spiel_id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `position` varchar(20) DEFAULT NULL,
  `note` double(4,2) NOT NULL,
  `minuten_gespielt` tinyint(2) NOT NULL DEFAULT 0,
  `karte_gelb` tinyint(1) NOT NULL DEFAULT 0,
  `karte_rot` tinyint(1) NOT NULL DEFAULT 0,
  `verletzt` tinyint(2) NOT NULL DEFAULT 0,
  `gesperrt` tinyint(2) NOT NULL DEFAULT 0,
  `tore` tinyint(2) NOT NULL DEFAULT 0,
  `feld` enum('1','Ersatzbank','Ausgewechselt') NOT NULL DEFAULT '1',
  `position_main` varchar(5) DEFAULT NULL,
  `age` tinyint(2) DEFAULT NULL,
  `w_staerke` tinyint(3) DEFAULT NULL,
  `w_technik` tinyint(3) DEFAULT NULL,
  `w_kondition` tinyint(3) DEFAULT NULL,
  `w_frische` tinyint(3) DEFAULT NULL,
  `w_zufriedenheit` tinyint(3) DEFAULT NULL,
  `ballcontacts` tinyint(3) DEFAULT NULL,
  `wontackles` tinyint(3) DEFAULT NULL,
  `shoots` tinyint(3) DEFAULT NULL,
  `passes_successed` tinyint(3) DEFAULT NULL,
  `passes_failed` tinyint(3) DEFAULT NULL,
  `assists` tinyint(3) DEFAULT NULL,
  `name` varchar(128) DEFAULT NULL,
  `losttackles` tinyint(3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_spiel_text`
--

CREATE TABLE `cm23_spiel_text` (
  `id` int(10) NOT NULL,
  `aktion` enum('Tor','Auswechslung','Zweikampf_gewonnen','Zweikampf_verloren','Pass_daneben','Torschuss_daneben','Torschuss_auf_Tor','Karte_gelb','Karte_rot','Karte_gelb_rot','Verletzung','Elfmeter_erfolg','Elfmeter_verschossen','Taktikaenderung','Ecke','Freistoss_daneben','Freistoss_treffer','Tor_mit_vorlage') DEFAULT NULL,
  `nachricht` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_sponsor`
--

CREATE TABLE `cm23_sponsor` (
  `id` int(10) NOT NULL,
  `name` varchar(30) DEFAULT NULL,
  `bild` varchar(100) DEFAULT NULL,
  `liga_id` smallint(5) NOT NULL,
  `b_spiel` int(10) DEFAULT NULL,
  `b_heimzuschlag` int(10) DEFAULT NULL,
  `b_sieg` int(10) DEFAULT NULL,
  `b_meisterschaft` int(10) DEFAULT NULL,
  `b_cup` int(10) DEFAULT NULL,
  `max_teams` smallint(5) NOT NULL,
  `min_platz` tinyint(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stadion`
--

CREATE TABLE `cm23_stadion` (
  `id` int(10) NOT NULL,
  `name` varchar(60) DEFAULT NULL,
  `stadt` varchar(30) DEFAULT NULL,
  `land` varchar(20) DEFAULT NULL,
  `p_steh` int(6) DEFAULT 0,
  `p_sitz` int(6) DEFAULT NULL,
  `p_haupt_steh` int(6) DEFAULT NULL,
  `p_haupt_sitz` int(6) DEFAULT NULL,
  `p_vip` int(6) DEFAULT NULL,
  `level_pitch` tinyint(2) NOT NULL DEFAULT 3,
  `level_videowall` tinyint(2) NOT NULL DEFAULT 1,
  `level_seatsquality` tinyint(2) NOT NULL DEFAULT 5,
  `level_vipquality` tinyint(2) NOT NULL DEFAULT 5,
  `maintenance_pitch` tinyint(2) NOT NULL DEFAULT 1,
  `maintenance_videowall` tinyint(2) NOT NULL DEFAULT 1,
  `maintenance_seatsquality` tinyint(2) NOT NULL DEFAULT 1,
  `maintenance_vipquality` tinyint(2) NOT NULL DEFAULT 1,
  `picture` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stadiumbuilding`
--

CREATE TABLE `cm23_stadiumbuilding` (
  `id` int(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `required_building_id` int(10) DEFAULT NULL,
  `costs` int(10) NOT NULL,
  `premiumfee` int(10) NOT NULL DEFAULT 0,
  `construction_time_days` int(5) NOT NULL DEFAULT 0,
  `effect_training` decimal(3,3) NOT NULL DEFAULT 0.000,
  `effect_youthscouting` tinyint(3) NOT NULL DEFAULT 0,
  `effect_tickets` tinyint(3) NOT NULL DEFAULT 0,
  `effect_fanpopularity` tinyint(3) NOT NULL DEFAULT 0,
  `effect_injury` tinyint(3) NOT NULL DEFAULT 0,
  `effect_income` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stadium_builder`
--

CREATE TABLE `cm23_stadium_builder` (
  `id` int(10) NOT NULL,
  `name` varchar(32) NOT NULL,
  `picture` varchar(128) DEFAULT NULL,
  `fixedcosts` int(10) NOT NULL DEFAULT 0,
  `cost_per_seat` int(10) NOT NULL DEFAULT 0,
  `construction_time_days` tinyint(3) NOT NULL DEFAULT 0,
  `construction_time_days_min` tinyint(3) NOT NULL DEFAULT 0,
  `min_stadium_size` int(10) NOT NULL DEFAULT 0,
  `max_stadium_size` int(10) NOT NULL DEFAULT 0,
  `reliability` tinyint(3) NOT NULL DEFAULT 100,
  `premiumfee` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stadium_construction`
--

CREATE TABLE `cm23_stadium_construction` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `builder_id` int(10) NOT NULL,
  `started` int(11) NOT NULL,
  `deadline` int(11) NOT NULL,
  `p_steh` int(6) NOT NULL DEFAULT 0,
  `p_sitz` int(6) NOT NULL DEFAULT 0,
  `p_haupt_steh` int(6) NOT NULL DEFAULT 0,
  `p_haupt_sitz` int(6) NOT NULL DEFAULT 0,
  `p_vip` int(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stats_salary`
--

CREATE TABLE `cm23_stats_salary` (
  `id` int(10) NOT NULL,
  `season` int(5) DEFAULT NULL,
  `salary` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stockmarket`
--

CREATE TABLE `cm23_stockmarket` (
  `id` int(5) NOT NULL,
  `abbrev` varchar(8) NOT NULL,
  `team_id` int(5) DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `v1` varchar(10) DEFAULT NULL,
  `v2` varchar(10) DEFAULT NULL,
  `v3` varchar(10) DEFAULT NULL,
  `v4` varchar(10) DEFAULT NULL,
  `v5` varchar(10) DEFAULT NULL,
  `v6` varchar(10) DEFAULT NULL,
  `v7` varchar(10) DEFAULT NULL,
  `v8` varchar(10) DEFAULT NULL,
  `v9` varchar(10) DEFAULT NULL,
  `v10` varchar(10) DEFAULT NULL,
  `quantity` int(10) DEFAULT NULL,
  `timestamp` varchar(20) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_stockmarket_date`
--

CREATE TABLE `cm23_stockmarket_date` (
  `date` varchar(8) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_tabelle_markierung`
--

CREATE TABLE `cm23_tabelle_markierung` (
  `id` smallint(5) NOT NULL,
  `liga_id` smallint(5) NOT NULL,
  `bezeichnung` varchar(50) DEFAULT NULL,
  `farbe` varchar(10) DEFAULT NULL,
  `platz_von` smallint(5) NOT NULL,
  `platz_bis` smallint(5) NOT NULL,
  `target_league_id` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_teamoftheday`
--

CREATE TABLE `cm23_teamoftheday` (
  `id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `matchday` tinyint(3) NOT NULL,
  `statistic_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `position_main` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_team_league_statistics`
--

CREATE TABLE `cm23_team_league_statistics` (
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `total_points` int(6) NOT NULL DEFAULT 0,
  `total_goals` int(6) NOT NULL DEFAULT 0,
  `total_goalsreceived` int(6) NOT NULL DEFAULT 0,
  `total_goalsdiff` int(6) NOT NULL DEFAULT 0,
  `total_wins` int(6) NOT NULL DEFAULT 0,
  `total_draws` int(6) NOT NULL DEFAULT 0,
  `total_losses` int(6) NOT NULL DEFAULT 0,
  `home_points` int(6) NOT NULL DEFAULT 0,
  `home_goals` int(6) NOT NULL DEFAULT 0,
  `home_goalsreceived` int(6) NOT NULL DEFAULT 0,
  `home_goalsdiff` int(6) NOT NULL DEFAULT 0,
  `home_wins` int(6) NOT NULL DEFAULT 0,
  `home_draws` int(6) NOT NULL DEFAULT 0,
  `home_losses` int(6) NOT NULL DEFAULT 0,
  `guest_points` int(6) NOT NULL DEFAULT 0,
  `guest_goals` int(6) NOT NULL DEFAULT 0,
  `guest_goalsreceived` int(6) NOT NULL DEFAULT 0,
  `guest_goalsdiff` int(6) NOT NULL DEFAULT 0,
  `guest_wins` int(6) NOT NULL DEFAULT 0,
  `guest_draws` int(6) NOT NULL DEFAULT 0,
  `guest_losses` int(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_titles_won`
--

CREATE TABLE `cm23_titles_won` (
  `id` int(5) NOT NULL,
  `team_id` int(5) NOT NULL,
  `competition` varchar(40) NOT NULL,
  `saison_name` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_trainer`
--

CREATE TABLE `cm23_trainer` (
  `id` int(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `salary` int(10) NOT NULL,
  `p_technique` tinyint(3) NOT NULL DEFAULT 0,
  `p_stamina` tinyint(3) NOT NULL DEFAULT 0,
  `expertise` tinyint(3) NOT NULL DEFAULT 0,
  `premiumfee` int(10) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_training`
--

CREATE TABLE `cm23_training` (
  `id` smallint(5) NOT NULL,
  `name` varchar(30) DEFAULT NULL,
  `w_staerke` tinyint(3) NOT NULL,
  `w_technik` tinyint(3) NOT NULL,
  `w_kondition` tinyint(3) NOT NULL,
  `w_frische` tinyint(3) NOT NULL,
  `w_zufriedenheit` tinyint(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_trainingslager`
--

CREATE TABLE `cm23_trainingslager` (
  `id` int(10) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `land` varchar(30) DEFAULT NULL,
  `bild` varchar(100) DEFAULT NULL,
  `preis_spieler_tag` int(10) NOT NULL,
  `p_staerke` tinyint(3) NOT NULL,
  `p_technik` tinyint(3) NOT NULL,
  `p_kondition` tinyint(3) NOT NULL,
  `p_frische` tinyint(3) NOT NULL,
  `p_zufriedenheit` tinyint(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_trainingslager_belegung`
--

CREATE TABLE `cm23_trainingslager_belegung` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL,
  `lager_id` int(10) NOT NULL,
  `datum_start` int(10) NOT NULL,
  `datum_ende` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_training_unit`
--

CREATE TABLE `cm23_training_unit` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `trainer_id` int(10) NOT NULL,
  `focus` enum('TE','STA','MOT','FR') NOT NULL DEFAULT 'TE',
  `intensity` tinyint(3) NOT NULL DEFAULT 50,
  `date_executed` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_transfer`
--

CREATE TABLE `cm23_transfer` (
  `id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `seller_user_id` int(10) DEFAULT NULL,
  `seller_club_id` int(10) DEFAULT NULL,
  `buyer_user_id` int(10) DEFAULT NULL,
  `buyer_club_id` int(10) DEFAULT NULL,
  `datum` int(11) DEFAULT NULL,
  `bid_id` int(11) DEFAULT NULL,
  `directtransfer_amount` int(10) DEFAULT NULL,
  `directtransfer_player1` int(10) DEFAULT 0,
  `directtransfer_player2` int(10) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_transfer_angebot`
--

CREATE TABLE `cm23_transfer_angebot` (
  `id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `verein_id` int(10) DEFAULT NULL,
  `user_id` int(10) DEFAULT NULL,
  `datum` int(11) NOT NULL,
  `abloese` int(11) DEFAULT NULL,
  `handgeld` int(11) NOT NULL DEFAULT 0,
  `vertrag_spiele` smallint(5) NOT NULL DEFAULT 60,
  `vertrag_gehalt` int(7) NOT NULL,
  `vertrag_torpraemie` smallint(5) NOT NULL DEFAULT 0,
  `ishighest` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_transfer_log`
--

CREATE TABLE `cm23_transfer_log` (
  `id` int(5) NOT NULL,
  `text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_transfer_offer`
--

CREATE TABLE `cm23_transfer_offer` (
  `id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `sender_user_id` int(10) DEFAULT NULL,
  `sender_club_id` int(10) NOT NULL,
  `receiver_club_id` int(10) NOT NULL,
  `submitted_date` int(11) DEFAULT NULL,
  `offer_amount` int(10) NOT NULL,
  `offer_message` varchar(255) DEFAULT NULL,
  `offer_player1` int(10) NOT NULL DEFAULT 0,
  `offer_player2` int(10) NOT NULL DEFAULT 0,
  `rejected_date` int(11) NOT NULL DEFAULT 0,
  `rejected_message` varchar(255) DEFAULT NULL,
  `rejected_allow_alternative` enum('1','0') NOT NULL DEFAULT '0',
  `admin_approval_pending` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_uefa_temp`
--

CREATE TABLE `cm23_uefa_temp` (
  `id` int(5) NOT NULL,
  `verein_id` int(3) NOT NULL,
  `cup_id` int(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_user`
--

CREATE TABLE `cm23_user` (
  `id` int(10) NOT NULL,
  `nick` varchar(50) DEFAULT NULL,
  `passwort` varchar(64) DEFAULT NULL,
  `passwort_neu` varchar(64) DEFAULT NULL,
  `passwort_neu_angefordert` int(11) NOT NULL DEFAULT 0,
  `passwort_salt` varchar(5) DEFAULT NULL,
  `tokenid` varchar(255) DEFAULT NULL,
  `lang` varchar(2) DEFAULT 'de',
  `email` varchar(150) DEFAULT NULL,
  `datum_anmeldung` int(11) NOT NULL DEFAULT 0,
  `schluessel` varchar(10) DEFAULT NULL,
  `wunschverein` varchar(250) DEFAULT NULL,
  `name` varchar(80) DEFAULT NULL,
  `wohnort` varchar(50) DEFAULT NULL,
  `land` varchar(40) DEFAULT NULL,
  `geburtstag` date DEFAULT NULL,
  `beruf` varchar(50) DEFAULT NULL,
  `interessen` varchar(250) DEFAULT NULL,
  `lieblingsverein` varchar(100) DEFAULT NULL,
  `homepage` varchar(250) DEFAULT NULL,
  `icq` varchar(20) DEFAULT NULL,
  `aim` varchar(30) DEFAULT NULL,
  `yim` varchar(30) DEFAULT NULL,
  `msn` varchar(30) DEFAULT NULL,
  `lastonline` int(11) NOT NULL DEFAULT 0,
  `lastaction` varchar(150) DEFAULT NULL,
  `highscore` int(10) NOT NULL DEFAULT 0,
  `fanbeliebtheit` tinyint(3) NOT NULL DEFAULT 50,
  `c_showemail` enum('1','0') NOT NULL DEFAULT '0',
  `email_transfers` enum('1','0') NOT NULL DEFAULT '0',
  `email_pn` enum('1','0') NOT NULL DEFAULT '0',
  `history` text DEFAULT NULL,
  `ip` varchar(25) DEFAULT NULL,
  `ip_time` int(11) NOT NULL DEFAULT 0,
  `c_hideinonlinelist` enum('1','0') NOT NULL DEFAULT '0',
  `premium_balance` int(6) NOT NULL DEFAULT 0,
  `picture` varchar(255) DEFAULT NULL,
  `status` enum('1','2','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_userabsence`
--

CREATE TABLE `cm23_userabsence` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `deputy_id` int(10) DEFAULT NULL,
  `from_date` int(11) NOT NULL,
  `to_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_useractionlog`
--

CREATE TABLE `cm23_useractionlog` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `action_id` varchar(255) DEFAULT NULL,
  `created_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_user_inactivity`
--

CREATE TABLE `cm23_user_inactivity` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `login` tinyint(3) NOT NULL DEFAULT 0,
  `login_last` int(11) NOT NULL,
  `login_check` int(11) NOT NULL,
  `aufstellung` tinyint(3) NOT NULL DEFAULT 0,
  `transfer` tinyint(3) NOT NULL DEFAULT 0,
  `transfer_check` int(11) NOT NULL,
  `vertragsauslauf` tinyint(3) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_user_stock`
--

CREATE TABLE `cm23_user_stock` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `stock_id` int(10) NOT NULL,
  `qty` int(10) NOT NULL,
  `price` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_verein`
--

CREATE TABLE `cm23_verein` (
  `id` int(10) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `kurz` varchar(5) DEFAULT NULL,
  `bild` varchar(100) DEFAULT NULL,
  `liga_id` smallint(5) DEFAULT NULL,
  `user_id` int(10) DEFAULT 0,
  `parent_club_id` int(5) DEFAULT NULL,
  `stadion_id` int(10) DEFAULT NULL,
  `sponsor_id` int(10) DEFAULT NULL,
  `training_id` int(5) DEFAULT NULL,
  `platz` tinyint(2) DEFAULT NULL,
  `sponsor_spiele` smallint(5) NOT NULL DEFAULT 0,
  `finanz_budget` int(11) NOT NULL,
  `preis_stehen` smallint(4) NOT NULL,
  `preis_sitz` smallint(4) NOT NULL,
  `preis_haupt_stehen` smallint(4) NOT NULL,
  `preis_haupt_sitze` smallint(4) NOT NULL,
  `preis_vip` smallint(4) NOT NULL,
  `last_steh` int(6) NOT NULL DEFAULT 0,
  `last_sitz` int(6) NOT NULL DEFAULT 0,
  `last_haupt_steh` int(6) NOT NULL DEFAULT 0,
  `last_haupt_sitz` int(6) NOT NULL DEFAULT 0,
  `last_vip` int(6) NOT NULL DEFAULT 0,
  `meisterschaften` smallint(4) NOT NULL DEFAULT 0,
  `pokale` smallint(4) DEFAULT NULL,
  `st_tore` int(6) NOT NULL DEFAULT 0,
  `st_gegentore` int(6) NOT NULL DEFAULT 0,
  `st_spiele` smallint(5) NOT NULL DEFAULT 0,
  `st_siege` smallint(5) NOT NULL DEFAULT 0,
  `st_niederlagen` smallint(5) NOT NULL DEFAULT 0,
  `st_unentschieden` smallint(5) NOT NULL DEFAULT 0,
  `st_punkte` int(6) NOT NULL DEFAULT 0,
  `sa_tore` int(6) NOT NULL DEFAULT 0,
  `sa_gegentore` int(6) NOT NULL DEFAULT 0,
  `sa_spiele` smallint(5) NOT NULL DEFAULT 0,
  `sa_siege` smallint(5) NOT NULL DEFAULT 0,
  `sa_niederlagen` smallint(5) NOT NULL DEFAULT 0,
  `sa_unentschieden` smallint(5) NOT NULL DEFAULT 0,
  `sa_punkte` int(6) NOT NULL DEFAULT 0,
  `min_target_rank` smallint(3) NOT NULL DEFAULT 0,
  `history` text DEFAULT NULL,
  `scouting_last_execution` int(11) NOT NULL DEFAULT 0,
  `nationalteam` enum('1','0') NOT NULL DEFAULT '0',
  `captain_id` int(10) DEFAULT NULL,
  `strength` int(10) NOT NULL DEFAULT 0,
  `user_id_actual` int(10) DEFAULT NULL,
  `interimmanager` enum('1','0') NOT NULL DEFAULT '0',
  `board_satisfaction` int(5) NOT NULL DEFAULT 50,
  `min_target_highscore` int(5) DEFAULT NULL,
  `highscore` int(5) DEFAULT 0,
  `superclub` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `cm23_v_transfer_angebot_player`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `cm23_v_transfer_angebot_player` (
`offer` bigint(21)
,`spieler_id` int(10)
);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_watchlist`
--

CREATE TABLE `cm23_watchlist` (
  `id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthmatch`
--

CREATE TABLE `cm23_youthmatch` (
  `id` int(10) NOT NULL,
  `matchdate` int(11) NOT NULL,
  `home_team_id` int(10) NOT NULL,
  `home_noformation` enum('1','0') DEFAULT '0',
  `home_s1_out` int(10) DEFAULT NULL,
  `home_s1_in` int(10) DEFAULT NULL,
  `home_s1_minute` tinyint(3) DEFAULT NULL,
  `home_s1_condition` varchar(16) DEFAULT NULL,
  `home_s1_position` varchar(4) DEFAULT NULL,
  `home_s2_out` int(10) DEFAULT NULL,
  `home_s2_in` int(10) DEFAULT NULL,
  `home_s2_minute` tinyint(3) DEFAULT NULL,
  `home_s2_condition` varchar(16) DEFAULT NULL,
  `home_s2_position` varchar(4) DEFAULT NULL,
  `home_s3_out` int(10) DEFAULT NULL,
  `home_s3_in` int(10) DEFAULT NULL,
  `home_s3_minute` tinyint(3) DEFAULT NULL,
  `home_s3_condition` varchar(16) DEFAULT NULL,
  `home_s3_position` varchar(4) DEFAULT NULL,
  `guest_team_id` int(10) NOT NULL,
  `guest_noformation` enum('1','0') DEFAULT '0',
  `guest_s1_out` int(10) DEFAULT NULL,
  `guest_s1_in` int(10) DEFAULT NULL,
  `guest_s1_minute` tinyint(3) DEFAULT NULL,
  `guest_s1_condition` varchar(16) DEFAULT NULL,
  `guest_s1_position` varchar(4) DEFAULT NULL,
  `guest_s2_out` int(10) DEFAULT NULL,
  `guest_s2_in` int(10) DEFAULT NULL,
  `guest_s2_minute` tinyint(3) DEFAULT NULL,
  `guest_s2_condition` varchar(16) DEFAULT NULL,
  `guest_s2_position` varchar(4) DEFAULT NULL,
  `guest_s3_out` int(10) DEFAULT NULL,
  `guest_s3_in` int(10) DEFAULT NULL,
  `guest_s3_minute` tinyint(3) DEFAULT NULL,
  `guest_s3_condition` varchar(16) DEFAULT NULL,
  `guest_s3_position` varchar(4) DEFAULT NULL,
  `home_goals` tinyint(2) DEFAULT NULL,
  `guest_goals` tinyint(2) DEFAULT NULL,
  `simulated` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthmatch_player`
--

CREATE TABLE `cm23_youthmatch_player` (
  `match_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `playernumber` tinyint(2) NOT NULL,
  `position` varchar(24) NOT NULL,
  `position_main` varchar(8) NOT NULL,
  `grade` double(4,2) NOT NULL DEFAULT 3.00,
  `minutes_played` tinyint(2) NOT NULL DEFAULT 0,
  `card_yellow` tinyint(1) NOT NULL DEFAULT 0,
  `card_red` tinyint(1) NOT NULL DEFAULT 0,
  `goals` tinyint(2) NOT NULL DEFAULT 0,
  `state` enum('1','Ersatzbank','Ausgewechselt') NOT NULL DEFAULT '1',
  `strength` tinyint(3) NOT NULL,
  `ballcontacts` tinyint(3) NOT NULL DEFAULT 0,
  `wontackles` tinyint(3) NOT NULL DEFAULT 0,
  `shoots` tinyint(3) NOT NULL DEFAULT 0,
  `passes_successed` tinyint(3) NOT NULL DEFAULT 0,
  `passes_failed` tinyint(3) NOT NULL DEFAULT 0,
  `assists` tinyint(3) NOT NULL DEFAULT 0,
  `name` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthmatch_reportitem`
--

CREATE TABLE `cm23_youthmatch_reportitem` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `minute` tinyint(3) NOT NULL,
  `message_key` varchar(32) NOT NULL,
  `message_data` varchar(255) DEFAULT NULL,
  `home_on_ball` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthmatch_request`
--

CREATE TABLE `cm23_youthmatch_request` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `matchdate` int(11) NOT NULL,
  `reward` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthplayer`
--

CREATE TABLE `cm23_youthplayer` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `firstname` varchar(32) NOT NULL,
  `lastname` varchar(32) NOT NULL,
  `age` tinyint(4) NOT NULL,
  `position` enum('Torwart','Abwehr','Mittelfeld','Sturm') NOT NULL,
  `nation` varchar(32) DEFAULT NULL,
  `strength` tinyint(3) NOT NULL,
  `strength_last_change` tinyint(3) NOT NULL DEFAULT 0,
  `st_goals` smallint(5) NOT NULL DEFAULT 0,
  `st_matches` smallint(5) NOT NULL DEFAULT 0,
  `st_assists` smallint(5) NOT NULL DEFAULT 0,
  `st_cards_yellow` smallint(5) NOT NULL DEFAULT 0,
  `st_cards_yellow_red` smallint(5) NOT NULL DEFAULT 0,
  `st_cards_red` smallint(5) NOT NULL DEFAULT 0,
  `transfer_fee` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `cm23_youthscout`
--

CREATE TABLE `cm23_youthscout` (
  `id` int(10) NOT NULL,
  `name` varchar(32) NOT NULL,
  `expertise` tinyint(3) NOT NULL,
  `fee` int(10) NOT NULL,
  `speciality` enum('Torwart','Abwehr','Mittelfeld','Sturm') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `cm23_achievement`
--
ALTER TABLE `cm23_achievement`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_admin`
--
ALTER TABLE `cm23_admin`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_aufstellung`
--
ALTER TABLE `cm23_aufstellung`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_badge`
--
ALTER TABLE `cm23_badge`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_badge_user`
--
ALTER TABLE `cm23_badge_user`
  ADD PRIMARY KEY (`user_id`,`badge_id`),
  ADD KEY `badge_id` (`badge_id`);

--
-- Indizes für die Tabelle `cm23_briefe`
--
ALTER TABLE `cm23_briefe`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_briefe_user_id_fk` (`absender_id`);

--
-- Indizes für die Tabelle `cm23_country`
--
ALTER TABLE `cm23_country`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_cup`
--
ALTER TABLE `cm23_cup`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_cup_round`
--
ALTER TABLE `cm23_cup_round`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_cup_round_group_next`
--
ALTER TABLE `cm23_cup_round_group_next`
  ADD PRIMARY KEY (`cup_round_id`) USING BTREE;

--
-- Indizes für die Tabelle `cm23_cup_round_pending`
--
ALTER TABLE `cm23_cup_round_pending`
  ADD PRIMARY KEY (`team_id`,`cup_round_id`);

--
-- Indizes für die Tabelle `cm23_friendly_request`
--
ALTER TABLE `cm23_friendly_request`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_kontinent`
--
ALTER TABLE `cm23_kontinent`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_konto`
--
ALTER TABLE `cm23_konto`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_land`
--
ALTER TABLE `cm23_land`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_leaguehistory`
--
ALTER TABLE `cm23_leaguehistory`
  ADD PRIMARY KEY (`team_id`,`season_id`,`matchday`),
  ADD KEY `season_id` (`season_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indizes für die Tabelle `cm23_liga`
--
ALTER TABLE `cm23_liga`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_matchreport`
--
ALTER TABLE `cm23_matchreport`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_name`
--
ALTER TABLE `cm23_name`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_nationalplayer`
--
ALTER TABLE `cm23_nationalplayer`
  ADD PRIMARY KEY (`team_id`,`player_id`),
  ADD KEY `cm23_nationalp_player_id_fk` (`player_id`);

--
-- Indizes für die Tabelle `cm23_news`
--
ALTER TABLE `cm23_news`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_notification`
--
ALTER TABLE `cm23_notification`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_premiumpayment`
--
ALTER TABLE `cm23_premiumpayment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_premiumpayment_user_id_fk` (`user_id`);

--
-- Indizes für die Tabelle `cm23_premiumstatement`
--
ALTER TABLE `cm23_premiumstatement`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_premium_user_id_fk` (`user_id`);

--
-- Indizes für die Tabelle `cm23_randomevent`
--
ALTER TABLE `cm23_randomevent`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_saison`
--
ALTER TABLE `cm23_saison`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_scout`
--
ALTER TABLE `cm23_scout`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_session`
--
ALTER TABLE `cm23_session`
  ADD PRIMARY KEY (`session_id`);

--
-- Indizes für die Tabelle `cm23_shoutmessage`
--
ALTER TABLE `cm23_shoutmessage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `match_id` (`match_id`);

--
-- Indizes für die Tabelle `cm23_spiel`
--
ALTER TABLE `cm23_spiel`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_spieler`
--
ALTER TABLE `cm23_spieler`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_spiel_berechnung`
--
ALTER TABLE `cm23_spiel_berechnung`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_berechnung_spiel_id_fk` (`spiel_id`),
  ADD KEY `cm23_berechnung_spieler_id_fk` (`spieler_id`);

--
-- Indizes für die Tabelle `cm23_spiel_text`
--
ALTER TABLE `cm23_spiel_text`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_sponsor`
--
ALTER TABLE `cm23_sponsor`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_stadion`
--
ALTER TABLE `cm23_stadion`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_stadiumbuilding`
--
ALTER TABLE `cm23_stadiumbuilding`
  ADD PRIMARY KEY (`id`),
  ADD KEY `required_building_id` (`required_building_id`);

--
-- Indizes für die Tabelle `cm23_stadium_builder`
--
ALTER TABLE `cm23_stadium_builder`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_stadium_construction`
--
ALTER TABLE `cm23_stadium_construction`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_construction_team_id_fk` (`team_id`),
  ADD KEY `cm23_construction_builder_id_fk` (`builder_id`);

--
-- Indizes für die Tabelle `cm23_stats_salary`
--
ALTER TABLE `cm23_stats_salary`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_stockmarket`
--
ALTER TABLE `cm23_stockmarket`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_tabelle_markierung`
--
ALTER TABLE `cm23_tabelle_markierung`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_teamoftheday`
--
ALTER TABLE `cm23_teamoftheday`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_teamofday_season_id_fk` (`season_id`),
  ADD KEY `cm23_teamofday_player_id_fk` (`player_id`);

--
-- Indizes für die Tabelle `cm23_team_league_statistics`
--
ALTER TABLE `cm23_team_league_statistics`
  ADD PRIMARY KEY (`team_id`,`season_id`),
  ADD KEY `cm23_statistics_season_id_fk` (`season_id`);

--
-- Indizes für die Tabelle `cm23_titles_won`
--
ALTER TABLE `cm23_titles_won`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_trainer`
--
ALTER TABLE `cm23_trainer`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_training`
--
ALTER TABLE `cm23_training`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_trainingslager`
--
ALTER TABLE `cm23_trainingslager`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_trainingslager_belegung`
--
ALTER TABLE `cm23_trainingslager_belegung`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_trainingslager_belegung_fk` (`lager_id`),
  ADD KEY `cm23_trainingslager_verein_fk` (`verein_id`);

--
-- Indizes für die Tabelle `cm23_training_unit`
--
ALTER TABLE `cm23_training_unit`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_transfer`
--
ALTER TABLE `cm23_transfer`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_transfer_angebot`
--
ALTER TABLE `cm23_transfer_angebot`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_transfer_log`
--
ALTER TABLE `cm23_transfer_log`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_transfer_offer`
--
ALTER TABLE `cm23_transfer_offer`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_uefa_temp`
--
ALTER TABLE `cm23_uefa_temp`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_user`
--
ALTER TABLE `cm23_user`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_userabsence`
--
ALTER TABLE `cm23_userabsence`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `deputy_id` (`deputy_id`);

--
-- Indizes für die Tabelle `cm23_useractionlog`
--
ALTER TABLE `cm23_useractionlog`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indizes für die Tabelle `cm23_user_inactivity`
--
ALTER TABLE `cm23_user_inactivity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_user_inactivity_user_id_fk` (`user_id`);

--
-- Indizes für die Tabelle `cm23_user_stock`
--
ALTER TABLE `cm23_user_stock`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_verein`
--
ALTER TABLE `cm23_verein`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_watchlist`
--
ALTER TABLE `cm23_watchlist`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_youthmatch`
--
ALTER TABLE `cm23_youthmatch`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_youthmatch_player`
--
ALTER TABLE `cm23_youthmatch_player`
  ADD PRIMARY KEY (`match_id`,`player_id`);

--
-- Indizes für die Tabelle `cm23_youthmatch_reportitem`
--
ALTER TABLE `cm23_youthmatch_reportitem`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_youthmatch_request`
--
ALTER TABLE `cm23_youthmatch_request`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_youthplayer`
--
ALTER TABLE `cm23_youthplayer`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `cm23_youthscout`
--
ALTER TABLE `cm23_youthscout`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `cm23_achievement`
--
ALTER TABLE `cm23_achievement`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_admin`
--
ALTER TABLE `cm23_admin`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_aufstellung`
--
ALTER TABLE `cm23_aufstellung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_badge`
--
ALTER TABLE `cm23_badge`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_briefe`
--
ALTER TABLE `cm23_briefe`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_country`
--
ALTER TABLE `cm23_country`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_cup`
--
ALTER TABLE `cm23_cup`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_cup_round`
--
ALTER TABLE `cm23_cup_round`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_friendly_request`
--
ALTER TABLE `cm23_friendly_request`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_kontinent`
--
ALTER TABLE `cm23_kontinent`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_konto`
--
ALTER TABLE `cm23_konto`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_land`
--
ALTER TABLE `cm23_land`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_liga`
--
ALTER TABLE `cm23_liga`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_matchreport`
--
ALTER TABLE `cm23_matchreport`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_name`
--
ALTER TABLE `cm23_name`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_news`
--
ALTER TABLE `cm23_news`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_notification`
--
ALTER TABLE `cm23_notification`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_premiumpayment`
--
ALTER TABLE `cm23_premiumpayment`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_premiumstatement`
--
ALTER TABLE `cm23_premiumstatement`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_randomevent`
--
ALTER TABLE `cm23_randomevent`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_saison`
--
ALTER TABLE `cm23_saison`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_scout`
--
ALTER TABLE `cm23_scout`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_shoutmessage`
--
ALTER TABLE `cm23_shoutmessage`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_spiel`
--
ALTER TABLE `cm23_spiel`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_spieler`
--
ALTER TABLE `cm23_spieler`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_spiel_berechnung`
--
ALTER TABLE `cm23_spiel_berechnung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_spiel_text`
--
ALTER TABLE `cm23_spiel_text`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_sponsor`
--
ALTER TABLE `cm23_sponsor`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stadion`
--
ALTER TABLE `cm23_stadion`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stadiumbuilding`
--
ALTER TABLE `cm23_stadiumbuilding`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stadium_builder`
--
ALTER TABLE `cm23_stadium_builder`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stadium_construction`
--
ALTER TABLE `cm23_stadium_construction`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stats_salary`
--
ALTER TABLE `cm23_stats_salary`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_stockmarket`
--
ALTER TABLE `cm23_stockmarket`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_tabelle_markierung`
--
ALTER TABLE `cm23_tabelle_markierung`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_teamoftheday`
--
ALTER TABLE `cm23_teamoftheday`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_titles_won`
--
ALTER TABLE `cm23_titles_won`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_trainer`
--
ALTER TABLE `cm23_trainer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_training`
--
ALTER TABLE `cm23_training`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_trainingslager`
--
ALTER TABLE `cm23_trainingslager`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_trainingslager_belegung`
--
ALTER TABLE `cm23_trainingslager_belegung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_training_unit`
--
ALTER TABLE `cm23_training_unit`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_transfer`
--
ALTER TABLE `cm23_transfer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_transfer_angebot`
--
ALTER TABLE `cm23_transfer_angebot`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_transfer_log`
--
ALTER TABLE `cm23_transfer_log`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_transfer_offer`
--
ALTER TABLE `cm23_transfer_offer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_uefa_temp`
--
ALTER TABLE `cm23_uefa_temp`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_user`
--
ALTER TABLE `cm23_user`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_userabsence`
--
ALTER TABLE `cm23_userabsence`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_useractionlog`
--
ALTER TABLE `cm23_useractionlog`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_user_inactivity`
--
ALTER TABLE `cm23_user_inactivity`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_user_stock`
--
ALTER TABLE `cm23_user_stock`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_verein`
--
ALTER TABLE `cm23_verein`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_watchlist`
--
ALTER TABLE `cm23_watchlist`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_youthmatch`
--
ALTER TABLE `cm23_youthmatch`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_youthmatch_reportitem`
--
ALTER TABLE `cm23_youthmatch_reportitem`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_youthmatch_request`
--
ALTER TABLE `cm23_youthmatch_request`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_youthplayer`
--
ALTER TABLE `cm23_youthplayer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `cm23_youthscout`
--
ALTER TABLE `cm23_youthscout`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Struktur des Views `cm23_v_transfer_angebot_player`
--
DROP TABLE IF EXISTS `cm23_v_transfer_angebot_player`;

CREATE ALGORITHM=UNDEFINED DEFINER=`moritzschneider_netcm23`@`%` SQL SECURITY DEFINER VIEW `cm23_v_transfer_angebot_player`  AS SELECT count(`cm23_transfer_angebot`.`spieler_id`) AS `offer`, `cm23_transfer_angebot`.`spieler_id` AS `spieler_id` FROM `cm23_transfer_angebot` GROUP BY `cm23_transfer_angebot`.`spieler_id` ;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `cm23_badge_user`
--
ALTER TABLE `cm23_badge_user`
  ADD CONSTRAINT `cm23_badge_user_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_badge_user_ibfk_2` FOREIGN KEY (`badge_id`) REFERENCES `cm23_badge` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_briefe`
--
ALTER TABLE `cm23_briefe`
  ADD CONSTRAINT `cm23_briefe_user_id_fk` FOREIGN KEY (`absender_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_cup_round`
--
ALTER TABLE `cm23_cup_round`
  ADD CONSTRAINT `cm23_cupround_cup_id_fk` FOREIGN KEY (`cup_id`) REFERENCES `cm23_cup` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_cupround_fromloosers_id_fk` FOREIGN KEY (`from_loosers_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_cupround_fromwinners_id_fk` FOREIGN KEY (`from_winners_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_cup_round_group_next`
--
ALTER TABLE `cm23_cup_round_group_next`
  ADD CONSTRAINT `cm23_groupnext_round_fk` FOREIGN KEY (`cup_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_groupnext_tagetround_fk` FOREIGN KEY (`target_cup_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_leaguehistory`
--
ALTER TABLE `cm23_leaguehistory`
  ADD CONSTRAINT `cm23_leaguehistory_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_leaguehistory_ibfk_2` FOREIGN KEY (`season_id`) REFERENCES `cm23_saison` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_leaguehistory_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `cm23_nationalplayer`
--
ALTER TABLE `cm23_nationalplayer`
  ADD CONSTRAINT `cm23_nationalp_player_id_fk` FOREIGN KEY (`player_id`) REFERENCES `cm23_spieler` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_nationalp_team_id_fk` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_premiumpayment`
--
ALTER TABLE `cm23_premiumpayment`
  ADD CONSTRAINT `cm23_premiumpayment_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_premiumstatement`
--
ALTER TABLE `cm23_premiumstatement`
  ADD CONSTRAINT `cm23_premium_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_spieler`
--
ALTER TABLE `cm23_spieler`
  ADD CONSTRAINT `cm23_spieler_verein_id_fk` FOREIGN KEY (`verein_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_stadiumbuilding`
--
ALTER TABLE `cm23_stadiumbuilding`
  ADD CONSTRAINT `cm23_stadiumbuilding_ibfk_1` FOREIGN KEY (`required_building_id`) REFERENCES `cm23_stadiumbuilding` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `cm23_stadium_construction`
--
ALTER TABLE `cm23_stadium_construction`
  ADD CONSTRAINT `cm23_construction_builder_id_fk` FOREIGN KEY (`builder_id`) REFERENCES `cm23_stadium_builder` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_construction_team_id_fk` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_stadium_construction_ibfk_1` FOREIGN KEY (`builder_id`) REFERENCES `cm23_stadium_builder` (`id`);

--
-- Constraints der Tabelle `cm23_teamoftheday`
--
ALTER TABLE `cm23_teamoftheday`
  ADD CONSTRAINT `cm23_teamofday_player_id_fk` FOREIGN KEY (`player_id`) REFERENCES `cm23_spieler` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_teamofday_season_id_fk` FOREIGN KEY (`season_id`) REFERENCES `cm23_saison` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_team_league_statistics`
--
ALTER TABLE `cm23_team_league_statistics`
  ADD CONSTRAINT `cm23_statistics_season_id_fk` FOREIGN KEY (`season_id`) REFERENCES `cm23_saison` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_statistics_team_id_fk` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_trainingslager_belegung`
--
ALTER TABLE `cm23_trainingslager_belegung`
  ADD CONSTRAINT `cm23_trainingslager_belegung_fk` FOREIGN KEY (`lager_id`) REFERENCES `cm23_trainingslager` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_trainingslager_verein_fk` FOREIGN KEY (`verein_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_transfer_angebot`
--
ALTER TABLE `cm23_transfer_angebot`
  ADD CONSTRAINT `cm23_transfer_angebot_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_userabsence`
--
ALTER TABLE `cm23_userabsence`
  ADD CONSTRAINT `cm23_userabsence_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_userabsence_ibfk_2` FOREIGN KEY (`deputy_id`) REFERENCES `cm23_user` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `cm23_useractionlog`
--
ALTER TABLE `cm23_useractionlog`
  ADD CONSTRAINT `cm23_useractionlog_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `cm23_user_inactivity`
--
ALTER TABLE `cm23_user_inactivity`
  ADD CONSTRAINT `cm23_user_inactivity_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
