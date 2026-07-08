SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `cywcu8ykg_cm23` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `cywcu8ykg_cm23`;

CREATE TABLE `cm23_achievement` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `season_id` int(10) DEFAULT NULL,
  `cup_round_id` int(10) DEFAULT NULL,
  `rank` tinyint(3) DEFAULT NULL,
  `date_recorded` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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
  `cornerplayer` int(10) DEFAULT NULL,
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

CREATE TABLE `cm23_backup_saison_before_results_fix_20260525` (
  `id` int(10) NOT NULL DEFAULT 0,
  `name` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `liga_id` smallint(5) NOT NULL,
  `platz_1_id` int(10) DEFAULT NULL,
  `platz_2_id` int(10) DEFAULT NULL,
  `platz_3_id` int(10) DEFAULT NULL,
  `platz_4_id` int(10) DEFAULT NULL,
  `platz_5_id` int(10) DEFAULT NULL,
  `beendet` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_backup_spiel_before_results_fix_20260525` (
  `id` int(10) NOT NULL DEFAULT 0,
  `spieltyp` enum('Ligaspiel','Pokalspiel','Freundschaft') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'Ligaspiel',
  `elfmeter` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `pokalname` varchar(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pokalrunde` varchar(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pokalgruppe` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `liga_id` smallint(5) DEFAULT NULL,
  `saison_id` int(10) DEFAULT NULL,
  `spieltag` tinyint(3) DEFAULT NULL,
  `datum` int(10) NOT NULL,
  `stadion_id` int(10) DEFAULT NULL,
  `minutes` tinyint(3) DEFAULT NULL,
  `regular_end_minute` tinyint(3) DEFAULT NULL,
  `player_with_ball` int(10) DEFAULT NULL,
  `prev_player_with_ball` int(10) DEFAULT NULL,
  `home_verein` int(10) NOT NULL,
  `home_noformation` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
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
  `home_w1_minute` int(3) DEFAULT NULL,
  `home_w2_raus` int(10) DEFAULT NULL,
  `home_w2_rein` int(10) DEFAULT NULL,
  `home_w2_minute` int(3) DEFAULT NULL,
  `home_w3_raus` int(10) DEFAULT NULL,
  `home_w3_rein` int(10) DEFAULT NULL,
  `home_w3_minute` int(3) DEFAULT NULL,
  `gast_verein` int(10) NOT NULL,
  `gast_tore` tinyint(2) DEFAULT NULL,
  `guest_noformation` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `gast_offensive` int(3) DEFAULT NULL,
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
  `gast_w1_minute` int(3) DEFAULT NULL,
  `gast_w2_raus` int(10) DEFAULT NULL,
  `gast_w2_rein` int(10) DEFAULT NULL,
  `gast_w2_minute` int(3) DEFAULT NULL,
  `gast_w3_raus` int(10) DEFAULT NULL,
  `gast_w3_rein` int(10) DEFAULT NULL,
  `gast_w3_minute` int(3) DEFAULT NULL,
  `bericht` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `zuschauer` int(6) DEFAULT NULL,
  `berechnet` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `soldout` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `home_setup` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_w1_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_w2_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_w3_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_setup` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_w1_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_w2_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_w3_condition` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_longpasses` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `home_counterattacks` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `gast_longpasses` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `gast_counterattacks` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0',
  `home_morale` tinyint(3) NOT NULL DEFAULT 0,
  `gast_morale` tinyint(3) NOT NULL DEFAULT 0,
  `home_user_id` int(10) DEFAULT NULL,
  `gast_user_id` int(10) DEFAULT NULL,
  `home_freekickplayer` int(10) DEFAULT NULL,
  `home_w1_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_w2_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `home_w3_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_freekickplayer` int(10) DEFAULT NULL,
  `gast_w1_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_w2_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `gast_w3_position` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `blocked` enum('1','0') CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_badge` (
  `id` int(10) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `level` enum('bronze','silver','gold') NOT NULL DEFAULT 'bronze',
  `event` enum('membership_since_x_days','win_with_x_goals_difference','completed_season_at_x','x_trades','cupwinner','stadium_construction_by_x','derby_wins','youth_developer','giant_killer','comeback_king','financial_genius','transfer_master') NOT NULL,
  `event_benchmark` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_badge_event_log` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) DEFAULT NULL,
  `season_id` int(10) DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `event_value` int(10) NOT NULL DEFAULT 1,
  `reference_key` varchar(128) NOT NULL,
  `event_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_badge_user` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) DEFAULT NULL,
  `season_id` int(10) DEFAULT NULL,
  `badge_id` int(10) NOT NULL,
  `date_rewarded` int(10) NOT NULL,
  `award_key` varchar(128) DEFAULT NULL,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_bank` (
  `id` int(3) NOT NULL,
  `verein_id` int(5) NOT NULL,
  `amount` varchar(20) NOT NULL,
  `matches` int(3) NOT NULL,
  `interest` varchar(7) NOT NULL,
  `original_amount` int(10) NOT NULL DEFAULT 0,
  `remaining_principal` int(10) NOT NULL DEFAULT 0,
  `interest_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_interest` int(10) NOT NULL DEFAULT 0,
  `remaining_interest` int(10) NOT NULL DEFAULT 0,
  `total_repayment` int(10) NOT NULL DEFAULT 0,
  `remaining_amount` int(10) NOT NULL DEFAULT 0,
  `matches_total` int(3) NOT NULL DEFAULT 0,
  `matches_left` int(3) NOT NULL DEFAULT 0,
  `status` enum('active','repaid','defaulted') NOT NULL DEFAULT 'active',
  `offer_type` varchar(16) NOT NULL DEFAULT 'legacy',
  `credit_rating` char(1) NOT NULL DEFAULT 'C',
  `credit_score` tinyint(3) NOT NULL DEFAULT 50,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `last_payment_match_id` int(10) NOT NULL DEFAULT 0,
  `last_payment_date` int(11) NOT NULL DEFAULT 0,
  `repaid_date` int(11) NOT NULL DEFAULT 0,
  `board_warning_sent` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_briefe` (
  `id` int(10) NOT NULL,
  `empfaenger_id` int(10) NOT NULL,
  `absender_id` int(10) DEFAULT NULL,
  `absender_name` varchar(50) DEFAULT NULL,
  `datum` int(10) NOT NULL,
  `betreff` varchar(50) DEFAULT NULL,
  `nachricht` text DEFAULT NULL,
  `gelesen` enum('1','0') NOT NULL DEFAULT '0',
  `typ` enum('eingang','ausgang') NOT NULL DEFAULT 'eingang'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_buildings_of_team` (
  `building_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `construction_deadline` int(11) DEFAULT NULL,
  `fanpopularity_applied` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_club_partnership` (
  `id` int(10) NOT NULL,
  `parent_team_id` int(10) NOT NULL,
  `partner_team_id` int(10) NOT NULL,
  `parent_user_id` int(10) NOT NULL DEFAULT 0,
  `partner_user_id` int(10) NOT NULL DEFAULT 0,
  `requested_by_user_id` int(10) NOT NULL DEFAULT 0,
  `requested_by_team_id` int(10) NOT NULL DEFAULT 0,
  `pending_user_id` int(10) NOT NULL DEFAULT 0,
  `status` enum('pending','active','rejected','stopped','suspended') NOT NULL DEFAULT 'pending',
  `shared_scouting` enum('1','0') NOT NULL DEFAULT '1',
  `preferred_loans` enum('1','0') NOT NULL DEFAULT '1',
  `first_option` enum('1','0') NOT NULL DEFAULT '1',
  `development_bonus_percent` tinyint(3) NOT NULL DEFAULT 5,
  `suspended_reason` varchar(128) DEFAULT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `confirmed_date` int(11) NOT NULL DEFAULT 0,
  `stopped_date` int(11) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_club_partnership_log` (
  `id` int(10) NOT NULL,
  `partnership_id` int(10) NOT NULL DEFAULT 0,
  `event_key` varchar(64) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `user_id` int(10) NOT NULL DEFAULT 0,
  `admin_id` int(10) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_club_staff` (
  `id` int(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('assistant_manager','goalkeeping_coach','fitness_coach','youth_coach','physio','marketing_manager','financial_advisor') NOT NULL,
  `level` tinyint(3) NOT NULL DEFAULT 1,
  `salary` int(10) NOT NULL DEFAULT 0,
  `bonus` tinyint(3) NOT NULL DEFAULT 2,
  `description` varchar(255) DEFAULT NULL,
  `active` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_club_staff_assignment` (
  `team_id` int(10) NOT NULL,
  `role` enum('assistant_manager','goalkeeping_coach','fitness_coach','youth_coach','physio','marketing_manager','financial_advisor') NOT NULL,
  `staff_id` int(10) NOT NULL,
  `hired_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_cl_temp` (
  `id` int(3) NOT NULL,
  `verein_id` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_config` (
  `id` int(3) NOT NULL,
  `name` varchar(20) NOT NULL,
  `zeitstempel` int(10) DEFAULT NULL,
  `descr` varchar(35) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_conmebol_temp` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL,
  `cup_name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_country` (
  `id` int(3) NOT NULL,
  `continent` varchar(3) NOT NULL,
  `country` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_cup_round_group_next` (
  `cup_round_id` int(10) NOT NULL,
  `groupname` varchar(64) NOT NULL,
  `rank` int(4) NOT NULL DEFAULT 0,
  `target_cup_round_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_cup_round_pending` (
  `team_id` int(10) NOT NULL,
  `cup_round_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_derby_match` (
  `match_id` int(10) NOT NULL,
  `rivalry_id` int(10) DEFAULT NULL,
  `home_team_id` int(10) NOT NULL,
  `guest_team_id` int(10) NOT NULL,
  `strength` tinyint(3) NOT NULL DEFAULT 50,
  `detection_source` enum('manual','automatic') NOT NULL DEFAULT 'automatic',
  `business_bonus_percent` tinyint(3) NOT NULL DEFAULT 10,
  `pre_news_created` enum('1','0') NOT NULL DEFAULT '0',
  `post_processed` enum('1','0') NOT NULL DEFAULT '0',
  `winner_team_id` int(10) DEFAULT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `completed_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_el_temp` (
  `id` int(3) NOT NULL,
  `verein_id` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_fanpressure_interview_occurrence` (
  `id` int(10) NOT NULL,
  `question_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL DEFAULT 0,
  `event_key` varchar(128) NOT NULL,
  `reference_key` varchar(160) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `expires_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('open','answered','expired') NOT NULL DEFAULT 'open',
  `answer_key` char(1) DEFAULT NULL,
  `answered_date` int(11) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_fanpressure_interview_question` (
  `id` int(10) NOT NULL,
  `event_key` varchar(128) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer_a_label` varchar(160) NOT NULL,
  `answer_a_mood` tinyint(4) NOT NULL DEFAULT 0,
  `answer_a_pressure` tinyint(4) NOT NULL DEFAULT 0,
  `answer_a_board` tinyint(4) NOT NULL DEFAULT 0,
  `answer_a_chemistry` tinyint(4) NOT NULL DEFAULT 0,
  `answer_b_label` varchar(160) NOT NULL,
  `answer_b_mood` tinyint(4) NOT NULL DEFAULT 0,
  `answer_b_pressure` tinyint(4) NOT NULL DEFAULT 0,
  `answer_b_board` tinyint(4) NOT NULL DEFAULT 0,
  `answer_b_chemistry` tinyint(4) NOT NULL DEFAULT 0,
  `answer_c_label` varchar(160) NOT NULL,
  `answer_c_mood` tinyint(4) NOT NULL DEFAULT 0,
  `answer_c_pressure` tinyint(4) NOT NULL DEFAULT 0,
  `answer_c_board` tinyint(4) NOT NULL DEFAULT 0,
  `answer_c_chemistry` tinyint(4) NOT NULL DEFAULT 0,
  `active` enum('1','0') NOT NULL DEFAULT '1',
  `weight` tinyint(3) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_fanpressure_story_log` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL DEFAULT 0,
  `event_key` varchar(128) NOT NULL,
  `reference_key` varchar(160) NOT NULL,
  `event_date` int(11) NOT NULL DEFAULT 0,
  `source` varchar(32) NOT NULL DEFAULT 'match',
  `title` varchar(160) NOT NULL,
  `message` text DEFAULT NULL,
  `mood_change` tinyint(4) NOT NULL DEFAULT 0,
  `pressure_change` tinyint(4) NOT NULL DEFAULT 0,
  `board_change` tinyint(4) NOT NULL DEFAULT 0,
  `chemistry_change` tinyint(4) NOT NULL DEFAULT 0,
  `new_mood` tinyint(3) NOT NULL DEFAULT 50,
  `new_pressure` tinyint(3) NOT NULL DEFAULT 30,
  `new_board_satisfaction` tinyint(3) NOT NULL DEFAULT 50,
  `new_chemistry` tinyint(3) NOT NULL DEFAULT 50,
  `match_id` int(10) NOT NULL DEFAULT 0,
  `news_id` int(10) NOT NULL DEFAULT 0,
  `notification_created` enum('1','0') NOT NULL DEFAULT '0',
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_fanpressure_story_rule` (
  `event_key` varchar(128) NOT NULL,
  `label` varchar(160) NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'match',
  `active` enum('1','0') NOT NULL DEFAULT '1',
  `mood_change` tinyint(4) NOT NULL DEFAULT 0,
  `pressure_change` tinyint(4) NOT NULL DEFAULT 0,
  `board_change` tinyint(4) NOT NULL DEFAULT 0,
  `chemistry_change` tinyint(4) NOT NULL DEFAULT 0,
  `create_notification` enum('1','0') NOT NULL DEFAULT '1',
  `create_news` enum('1','0') NOT NULL DEFAULT '0',
  `interview_chance` tinyint(3) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_fan_mood_log` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `event_date` int(11) NOT NULL DEFAULT 0,
  `source` enum('match','derby','ticket','transfer','youth','mission','board','interview') NOT NULL DEFAULT 'match',
  `reason_key` varchar(128) NOT NULL,
  `mood_change` tinyint(4) NOT NULL DEFAULT 0,
  `old_mood` tinyint(3) NOT NULL DEFAULT 50,
  `new_mood` tinyint(3) NOT NULL DEFAULT 50,
  `pressure_change` tinyint(4) NOT NULL DEFAULT 0,
  `old_pressure` tinyint(3) NOT NULL DEFAULT 30,
  `new_pressure` tinyint(3) NOT NULL DEFAULT 30,
  `board_change` tinyint(4) NOT NULL DEFAULT 0,
  `old_board_satisfaction` tinyint(3) NOT NULL DEFAULT 50,
  `new_board_satisfaction` tinyint(3) NOT NULL DEFAULT 50,
  `match_id` int(10) DEFAULT NULL,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_finance_regulation_log` (
  `id` int(10) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `admin_id` int(10) NOT NULL DEFAULT 0,
  `action_key` varchar(64) NOT NULL,
  `action_label` varchar(128) NOT NULL,
  `mode` enum('simulate','apply','export','snapshot') NOT NULL DEFAULT 'simulate',
  `parameters_json` mediumtext NOT NULL,
  `result_json` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_finance_regulation_setting` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `description` varchar(255) DEFAULT NULL,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `updated_by_admin_id` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_finance_regulation_snapshot` (
  `id` int(10) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `admin_id` int(10) NOT NULL DEFAULT 0,
  `title` varchar(128) NOT NULL,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `scope` varchar(32) NOT NULL DEFAULT 'global',
  `metrics_json` mediumtext NOT NULL,
  `recommendations_json` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_friendly_request` (
  `id` int(3) NOT NULL,
  `sender_team_id` int(5) NOT NULL,
  `receiver_team_id` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_individual_training` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `attribute_key` varchar(32) NOT NULL,
  `progress_points` decimal(7,2) NOT NULL DEFAULT 0.00,
  `required_points` decimal(7,2) NOT NULL DEFAULT 100.00,
  `progress_matches` smallint(5) NOT NULL DEFAULT 0,
  `last_match_id` int(10) NOT NULL DEFAULT 0,
  `started_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `completed_date` int(11) NOT NULL DEFAULT 0,
  `old_value` decimal(5,2) NOT NULL DEFAULT 0.00,
  `new_value` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_injury_clearance` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `processed_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('open','used','expired') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_injury_log` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'match',
  `source_id` int(10) NOT NULL DEFAULT 0,
  `injury_matches` tinyint(3) NOT NULL DEFAULT 0,
  `event_date` int(11) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `reference_key` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_injury_treatment` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `treatment` varchar(32) NOT NULL,
  `costs` int(10) NOT NULL DEFAULT 0,
  `injury_reduction` tinyint(3) NOT NULL DEFAULT 0,
  `caught_chance` tinyint(3) NOT NULL DEFAULT 0,
  `outcome` varchar(32) NOT NULL DEFAULT 'success',
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_kontinent` (
  `id` smallint(5) NOT NULL,
  `name` varchar(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_konto` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL,
  `absender` varchar(150) DEFAULT NULL,
  `betrag` int(10) NOT NULL,
  `datum` int(11) NOT NULL,
  `verwendung` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_kontohistory` (
  `v1` varchar(20) DEFAULT '0',
  `v2` varchar(20) DEFAULT '0',
  `v3` varchar(20) DEFAULT '0',
  `v4` varchar(20) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_land` (
  `id` int(5) NOT NULL,
  `name` varchar(30) NOT NULL,
  `continent` varchar(16) NOT NULL DEFAULT 'UEFA',
  `uefa_s1` decimal(10,3) DEFAULT NULL,
  `uefa_s2` decimal(10,3) DEFAULT NULL,
  `uefa_s3` decimal(10,3) DEFAULT NULL,
  `uefa_s4` decimal(10,3) DEFAULT NULL,
  `uefa_s5` decimal(10,3) DEFAULT NULL,
  `uefa_cl` tinyint(1) NOT NULL,
  `uefa_ul` tinyint(1) NOT NULL,
  `uefa_conf` tinyint(1) NOT NULL,
  `uefa_coeff` decimal(10,3) NOT NULL,
  `conmebol_s1` decimal(10,3) DEFAULT 0.000,
  `conmebol_s2` decimal(10,3) DEFAULT 0.000,
  `conmebol_s3` decimal(10,3) DEFAULT 0.000,
  `conmebol_s4` decimal(10,3) DEFAULT 0.000,
  `conmebol_s5` decimal(10,3) DEFAULT 0.000,
  `conmebol_lib` tinyint(1) NOT NULL DEFAULT 0,
  `conmebol_sud` tinyint(1) NOT NULL DEFAULT 0,
  `conmebol_coeff` decimal(10,3) NOT NULL DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_leaguehistory` (
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `user_id` int(10) DEFAULT NULL,
  `matchday` tinyint(3) NOT NULL,
  `rank` tinyint(3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_loan` (
  `id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `lender_team_id` int(10) NOT NULL,
  `borrower_team_id` int(10) NOT NULL,
  `start_date` int(11) NOT NULL DEFAULT 0,
  `total_matches` tinyint(3) NOT NULL DEFAULT 0,
  `remaining_matches` tinyint(3) NOT NULL DEFAULT 0,
  `matches_completed` tinyint(3) NOT NULL DEFAULT 0,
  `loan_fee_per_match` int(10) NOT NULL DEFAULT 0,
  `salary_share_percent` tinyint(3) NOT NULL DEFAULT 100,
  `option_type` enum('none','buy_option','buy_obligation') NOT NULL DEFAULT 'none',
  `buy_fee` int(10) NOT NULL DEFAULT 0,
  `min_recall_matches` tinyint(3) NOT NULL DEFAULT 5,
  `total_development_bonus` decimal(7,3) NOT NULL DEFAULT 0.000,
  `status` enum('active','completed','recalled','bought','cancelled') NOT NULL DEFAULT 'active',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `completed_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_loan_offer` (
  `id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `lender_team_id` int(10) NOT NULL,
  `loan_fee_per_match` int(10) NOT NULL DEFAULT 0,
  `salary_share_percent` tinyint(3) NOT NULL DEFAULT 100,
  `option_type` enum('none','buy_option','buy_obligation') NOT NULL DEFAULT 'none',
  `buy_fee` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `created_by_computer` enum('1','0') NOT NULL DEFAULT '0',
  `status` enum('open','accepted','closed','expired') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_loan_report` (
  `id` int(10) NOT NULL,
  `loan_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `match_date` int(11) NOT NULL DEFAULT 0,
  `minutes_played` smallint(5) NOT NULL DEFAULT 0,
  `grade` double(4,2) NOT NULL DEFAULT 0.00,
  `goals` tinyint(3) NOT NULL DEFAULT 0,
  `assists` tinyint(3) NOT NULL DEFAULT 0,
  `destination_quality` tinyint(3) NOT NULL DEFAULT 50,
  `development_bonus` decimal(7,3) NOT NULL DEFAULT 0.000,
  `attribute_key` varchar(32) NOT NULL DEFAULT '',
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_loan_request` (
  `id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `lender_team_id` int(10) NOT NULL,
  `borrower_team_id` int(10) NOT NULL,
  `borrower_user_id` int(10) DEFAULT NULL,
  `requested_matches` tinyint(3) NOT NULL,
  `loan_fee_per_match` int(10) NOT NULL DEFAULT 0,
  `total_fee` int(10) NOT NULL DEFAULT 0,
  `salary_share_percent` tinyint(3) NOT NULL DEFAULT 100,
  `option_type` enum('none','buy_option','buy_obligation') NOT NULL DEFAULT 'none',
  `buy_fee` int(10) NOT NULL DEFAULT 0,
  `created_by_computer` enum('1','0') NOT NULL DEFAULT '0',
  `status` enum('open','accepted','rejected','expired') NOT NULL DEFAULT 'open',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `answered_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_application` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `source_team_id` int(10) NOT NULL DEFAULT 0,
  `target_team_id` int(10) NOT NULL,
  `manager_score` int(10) NOT NULL DEFAULT 0,
  `club_score` int(10) NOT NULL DEFAULT 0,
  `application_score` int(10) NOT NULL DEFAULT 0,
  `acceptance_chance` tinyint(3) NOT NULL DEFAULT 0,
  `status` enum('open','accepted','rejected','withdrawn','expired') NOT NULL DEFAULT 'open',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `decision_date` int(11) NOT NULL DEFAULT 0,
  `expires_date` int(11) NOT NULL DEFAULT 0,
  `answered_date` int(11) NOT NULL DEFAULT 0,
  `offer_id` int(10) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_award` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL DEFAULT 0,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `award_type` enum('manager_of_month','manager_of_season') NOT NULL,
  `award_key` varchar(128) NOT NULL,
  `title` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `score_value` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_career_history` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `old_team_id` int(10) NOT NULL DEFAULT 0,
  `new_team_id` int(10) NOT NULL,
  `offer_id` int(10) NOT NULL DEFAULT 0,
  `origin` enum('job_offer','free_club','additional_club','manual_application','sacked') NOT NULL DEFAULT 'free_club',
  `old_club_score` int(10) NOT NULL DEFAULT 0,
  `new_club_score` int(10) NOT NULL DEFAULT 0,
  `highscore_bonus` int(10) NOT NULL DEFAULT 0,
  `change_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_contract` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `start_date` int(11) NOT NULL DEFAULT 0,
  `contract_until_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','ended','terminated','sacked') NOT NULL DEFAULT 'active',
  `low_board_checks` tinyint(3) NOT NULL DEFAULT 0,
  `last_sack_check_match_id` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `ended_date` int(11) NOT NULL DEFAULT 0,
  `end_reason` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_country_reputation` (
  `user_id` int(10) NOT NULL,
  `country` varchar(40) NOT NULL,
  `reputation` tinyint(3) NOT NULL DEFAULT 0,
  `last_change` tinyint(4) NOT NULL DEFAULT 0,
  `last_reason` varchar(128) NOT NULL DEFAULT '',
  `updated_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_job_offer` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `source_team_id` int(10) NOT NULL DEFAULT 0,
  `target_team_id` int(10) NOT NULL,
  `manager_score` int(10) NOT NULL DEFAULT 0,
  `club_score` int(10) NOT NULL DEFAULT 0,
  `status` enum('open','accepted','declined','expired') NOT NULL DEFAULT 'open',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `expires_date` int(11) NOT NULL DEFAULT 0,
  `created_match_id` int(10) NOT NULL DEFAULT 0,
  `accepted_date` int(11) NOT NULL DEFAULT 0,
  `declined_date` int(11) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_mission` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `mission_type` enum('league_rank','highscore','salary_reduce','youth_promotion_played','cup_round','board_satisfaction') NOT NULL,
  `baseline_value` int(10) NOT NULL DEFAULT 0,
  `target_value` int(10) NOT NULL DEFAULT 0,
  `progress_value` int(10) NOT NULL DEFAULT 0,
  `status` enum('open','completed','failed','cancelled') NOT NULL DEFAULT 'open',
  `rewarded` enum('1','0') NOT NULL DEFAULT '0',
  `penalized` enum('1','0') NOT NULL DEFAULT '0',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `completed_date` int(11) NOT NULL DEFAULT 0,
  `failed_date` int(11) NOT NULL DEFAULT 0,
  `checked_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_manager_mission_youth_promotion` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `youth_player_id` int(10) NOT NULL DEFAULT 0,
  `professional_player_id` int(10) NOT NULL,
  `player_name` varchar(128) NOT NULL,
  `promoted_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_matchreport` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `message_id` int(10) NOT NULL,
  `minute` tinyint(3) NOT NULL,
  `goals` varchar(8) DEFAULT NULL,
  `playernames` varchar(128) DEFAULT NULL,
  `active_home` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_merchandising_product` (
  `id` int(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `purchase_price` int(10) NOT NULL DEFAULT 0,
  `sales_price` int(10) NOT NULL DEFAULT 0,
  `base_demand` decimal(6,4) NOT NULL DEFAULT 0.0100,
  `active` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_merchandising_sales` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `product_id` int(10) NOT NULL,
  `units_sold` int(10) NOT NULL DEFAULT 0,
  `revenue` int(10) NOT NULL DEFAULT 0,
  `costs` int(10) NOT NULL DEFAULT 0,
  `profit` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_merchandising_team_product` (
  `team_id` int(10) NOT NULL,
  `product_id` int(10) NOT NULL,
  `enabled` enum('1','0') NOT NULL DEFAULT '1',
  `price_factor` decimal(4,2) NOT NULL DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_name` (
  `id` int(10) NOT NULL,
  `name` varchar(40) NOT NULL,
  `continent` varchar(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_nationalplayer` (
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_penalty` (
  `id` int(2) NOT NULL,
  `budget` bigint(20) NOT NULL DEFAULT 0,
  `penalty` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_premiumpayment` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `amount` int(10) NOT NULL,
  `created_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_premiumstatement` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `action_id` varchar(255) DEFAULT NULL,
  `amount` int(10) NOT NULL,
  `created_date` int(11) NOT NULL,
  `subject_data` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_randomevent` (
  `id` int(10) NOT NULL,
  `message` text DEFAULT NULL,
  `effect` enum('money','player_injured','player_blocked','player_happiness','player_fitness','player_stamina') NOT NULL,
  `effect_money_amount` int(10) NOT NULL DEFAULT 0,
  `effect_blocked_matches` int(10) NOT NULL DEFAULT 0,
  `effect_skillchange` tinyint(3) NOT NULL DEFAULT 0,
  `weight` tinyint(3) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_randomevent_chain` (
  `id` int(10) NOT NULL,
  `event_key` varchar(64) NOT NULL,
  `title` varchar(128) NOT NULL,
  `message` text NOT NULL,
  `event_type` enum('player_unhappy','sponsor_scandal','youth_discovered','stadium_damage') NOT NULL,
  `weight` tinyint(3) NOT NULL DEFAULT 1,
  `active` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_randomevent_chain_choice` (
  `id` int(10) NOT NULL,
  `chain_id` int(10) NOT NULL,
  `choice_key` varchar(64) NOT NULL,
  `label` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `effect_money_amount` int(10) NOT NULL DEFAULT 0,
  `effect_player_happiness` tinyint(3) NOT NULL DEFAULT 0,
  `effect_player_fitness` tinyint(3) NOT NULL DEFAULT 0,
  `effect_player_stamina` tinyint(3) NOT NULL DEFAULT 0,
  `effect_blocked_matches` tinyint(3) NOT NULL DEFAULT 0,
  `effect_injured_matches` tinyint(3) NOT NULL DEFAULT 0,
  `effect_board_satisfaction` tinyint(3) NOT NULL DEFAULT 0,
  `effect_fanpopularity` tinyint(3) NOT NULL DEFAULT 0,
  `effect_stadium_attendance` tinyint(3) NOT NULL DEFAULT 0,
  `set_player_transfermarket` enum('1','0') NOT NULL DEFAULT '0',
  `create_youthplayer` enum('1','0') NOT NULL DEFAULT '0',
  `keep_open` enum('1','0') NOT NULL DEFAULT '0',
  `is_default` enum('1','0') NOT NULL DEFAULT '0',
  `sort_order` tinyint(3) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_randomevent_chain_occurrence` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `chain_id` int(10) NOT NULL,
  `player_id` int(10) DEFAULT NULL,
  `created_youthplayer_id` int(10) DEFAULT NULL,
  `selected_choice_id` int(10) DEFAULT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `created_matchday` tinyint(3) NOT NULL DEFAULT 0,
  `expires_matchday` tinyint(3) NOT NULL DEFAULT 0,
  `status` enum('open','resolved','expired') NOT NULL DEFAULT 'open',
  `resolved_date` int(11) NOT NULL DEFAULT 0,
  `context_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_randomevent_chain_roll` (
  `team_id` int(10) NOT NULL,
  `matchday` tinyint(3) NOT NULL,
  `roll_date` int(11) NOT NULL DEFAULT 0,
  `created_occurrence_id` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_randomevent_occurrence` (
  `user_id` int(10) DEFAULT NULL,
  `team_id` int(10) DEFAULT NULL,
  `event_id` int(10) DEFAULT NULL,
  `occurrence_date` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_rivalry` (
  `id` int(10) NOT NULL,
  `team1_id` int(10) NOT NULL,
  `team2_id` int(10) NOT NULL,
  `strength` tinyint(3) NOT NULL DEFAULT 50,
  `manual` enum('1','0') NOT NULL DEFAULT '1',
  `active` enum('1','0') NOT NULL DEFAULT '1',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_scouting_camp` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `location_id` int(10) NOT NULL,
  `scout_id` int(10) NOT NULL,
  `position` enum('Torwart','Abwehr','Mittelfeld','Sturm') DEFAULT NULL,
  `age_min` tinyint(3) NOT NULL DEFAULT 16,
  `age_max` tinyint(3) NOT NULL DEFAULT 35,
  `strength_min` tinyint(3) NOT NULL DEFAULT 1,
  `strength_max` tinyint(3) NOT NULL DEFAULT 100,
  `budget_min` int(10) NOT NULL DEFAULT 0,
  `budget_max` int(10) NOT NULL DEFAULT 0,
  `fee_per_matchday` int(10) NOT NULL DEFAULT 0,
  `next_proposal_after_matches` tinyint(3) NOT NULL DEFAULT 15,
  `matches_until_next_proposal` tinyint(3) NOT NULL DEFAULT 15,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `last_execution` int(11) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_scouting_camp_location` (
  `id` int(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `country` varchar(40) DEFAULT NULL,
  `continent` varchar(3) DEFAULT NULL,
  `base_fee` int(10) NOT NULL DEFAULT 0,
  `travel_cost_modifier` smallint(5) NOT NULL DEFAULT 0,
  `quality_modifier` smallint(5) NOT NULL DEFAULT 0,
  `talent_modifier` smallint(5) NOT NULL DEFAULT 0,
  `min_department_level` tinyint(3) NOT NULL DEFAULT 1,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_scouting_department` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `level` tinyint(3) NOT NULL DEFAULT 1,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `maintenance_fee` int(10) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_scouting_department_level` (
  `id` int(10) NOT NULL,
  `level` tinyint(3) NOT NULL,
  `name` varchar(80) NOT NULL,
  `build_cost` int(10) NOT NULL DEFAULT 0,
  `maintenance_fee` int(10) NOT NULL DEFAULT 0,
  `max_scouts` tinyint(3) NOT NULL DEFAULT 1,
  `max_camps` smallint(5) NOT NULL DEFAULT 1,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_scouting_proposal` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `camp_id` int(10) NOT NULL,
  `scout_id` int(10) DEFAULT NULL,
  `location_id` int(10) DEFAULT NULL,
  `firstname` varchar(32) NOT NULL,
  `lastname` varchar(32) NOT NULL,
  `birthday` date NOT NULL,
  `age` tinyint(3) NOT NULL,
  `nation` varchar(40) DEFAULT NULL,
  `position` enum('Torwart','Abwehr','Mittelfeld','Sturm') NOT NULL DEFAULT 'Mittelfeld',
  `position_main` enum('T','LV','IV','RV','LM','DM','ZM','OM','RM','LS','MS','RS') DEFAULT NULL,
  `position_second` enum('T','LV','IV','RV','LM','DM','ZM','OM','RM','LS','MS','RS','') DEFAULT NULL,
  `real_w_staerke` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_staerke_max` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_talent` tinyint(1) NOT NULL DEFAULT 1,
  `real_w_technik` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_kondition` decimal(4,2) NOT NULL DEFAULT 50.00,
  `real_w_frische` decimal(4,2) NOT NULL DEFAULT 50.00,
  `real_w_zufriedenheit` decimal(4,2) NOT NULL DEFAULT 50.00,
  `real_w_passing` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_shooting` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_heading` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_tackling` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_freekick` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_pace` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_creativity` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_influence` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_flair` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_penalty` decimal(4,2) NOT NULL DEFAULT 1.00,
  `real_w_penalty_killing` decimal(4,2) NOT NULL DEFAULT 1.00,
  `reported_strength` varchar(32) DEFAULT NULL,
  `reported_talent` varchar(32) DEFAULT NULL,
  `reported_potential` varchar(32) DEFAULT NULL,
  `reported_summary` varchar(255) DEFAULT NULL,
  `transfer_fee` int(10) NOT NULL DEFAULT 0,
  `salary` int(10) NOT NULL DEFAULT 0,
  `contract_matches` smallint(5) NOT NULL DEFAULT 60,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `expires_date` int(11) NOT NULL DEFAULT 0,
  `expires_after_matches` tinyint(3) NOT NULL DEFAULT 20,
  `status` enum('open','accepted','rejected','expired') NOT NULL DEFAULT 'open',
  `accepted_date` int(11) NOT NULL DEFAULT 0,
  `rejected_date` int(11) NOT NULL DEFAULT 0,
  `created_player_id` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_second_position` (
  `second_position` varchar(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_session` (
  `session_id` char(32) NOT NULL,
  `session_data` text NOT NULL,
  `expires` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_shoutmessage` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_date` int(11) NOT NULL,
  `match_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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
  `regular_end_minute` tinyint(3) DEFAULT NULL,
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
  `home_w1_minute` int(3) DEFAULT NULL,
  `home_w2_raus` int(10) DEFAULT NULL,
  `home_w2_rein` int(10) DEFAULT NULL,
  `home_w2_minute` int(3) DEFAULT NULL,
  `home_w3_raus` int(10) DEFAULT NULL,
  `home_w3_rein` int(10) DEFAULT NULL,
  `home_w3_minute` int(3) DEFAULT NULL,
  `gast_verein` int(10) NOT NULL,
  `gast_tore` tinyint(2) DEFAULT NULL,
  `guest_noformation` enum('1','0') DEFAULT '0',
  `gast_offensive` int(3) DEFAULT NULL,
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
  `gast_w1_minute` int(3) DEFAULT NULL,
  `gast_w2_raus` int(10) DEFAULT NULL,
  `gast_w2_rein` int(10) DEFAULT NULL,
  `gast_w2_minute` int(3) DEFAULT NULL,
  `gast_w3_raus` int(10) DEFAULT NULL,
  `gast_w3_rein` int(10) DEFAULT NULL,
  `gast_w3_minute` int(3) DEFAULT NULL,
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
  `home_cornerplayer` int(10) DEFAULT NULL,
  `home_w1_position` varchar(4) DEFAULT NULL,
  `home_w2_position` varchar(4) DEFAULT NULL,
  `home_w3_position` varchar(4) DEFAULT NULL,
  `gast_freekickplayer` int(10) DEFAULT NULL,
  `gast_cornerplayer` int(10) DEFAULT NULL,
  `gast_w1_position` varchar(4) DEFAULT NULL,
  `gast_w2_position` varchar(4) DEFAULT NULL,
  `gast_w3_position` varchar(4) DEFAULT NULL,
  `blocked` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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
  `w_staerke_calc` varchar(6) DEFAULT '0',
  `w_staerke` decimal(4,2) NOT NULL,
  `w_staerke_max` varchar(6) NOT NULL,
  `w_technik` varchar(6) NOT NULL,
  `w_kondition` varchar(6) NOT NULL,
  `w_frische` varchar(6) NOT NULL,
  `w_zufriedenheit` varchar(6) NOT NULL,
  `w_talent` tinyint(1) NOT NULL,
  `personality` enum('leader','professional','injury_prone','inconsistent','loyal','ambitious','big_game_player','troublemaker') NOT NULL DEFAULT 'professional',
  `w_passing` varchar(6) DEFAULT '0',
  `w_shooting` varchar(6) DEFAULT '0',
  `w_heading` varchar(6) DEFAULT '0',
  `w_tackling` varchar(6) DEFAULT '0',
  `w_freekick` varchar(6) DEFAULT '0',
  `w_pace` varchar(10) DEFAULT '0',
  `w_creativity` varchar(6) DEFAULT '0',
  `w_influence` varchar(6) DEFAULT '0',
  `w_flair` varchar(6) DEFAULT '0',
  `w_penalty` varchar(6) DEFAULT '0',
  `w_penalty_killing` varchar(6) DEFAULT '0',
  `einzeltraining` enum('1','0') NOT NULL DEFAULT '0',
  `note_last` double(4,2) NOT NULL DEFAULT 0.00,
  `note_schnitt` double(4,2) NOT NULL DEFAULT 0.00,
  `vertrag_gehalt` int(10) NOT NULL,
  `vertrag_spiele` smallint(5) DEFAULT NULL,
  `vertrag_torpraemie` int(10) NOT NULL,
  `marktwert` varchar(15) NOT NULL DEFAULT '0',
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
  `status` enum('1','0') NOT NULL DEFAULT '0',
  `on_update` varchar(20) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_spiel_berechnung` (
  `id` int(10) NOT NULL,
  `spiel_id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `position` varchar(20) DEFAULT NULL,
  `note` double(4,2) NOT NULL,
  `minuten_gespielt` int(5) NOT NULL DEFAULT 0,
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
  `w_talent` varchar(5) DEFAULT NULL,
  `w_passing` varchar(6) DEFAULT NULL,
  `w_shooting` varchar(6) DEFAULT NULL,
  `w_heading` varchar(6) DEFAULT NULL,
  `w_tackling` varchar(6) DEFAULT NULL,
  `w_creativity` varchar(6) DEFAULT NULL,
  `w_influence` varchar(6) DEFAULT NULL,
  `w_flair` varchar(6) DEFAULT NULL,
  `w_freekick` varchar(6) DEFAULT NULL,
  `w_pace` varchar(6) DEFAULT NULL,
  `w_penalty` varchar(6) DEFAULT NULL,
  `w_penalty_killing` varchar(6) DEFAULT NULL,
  `ballcontacts` tinyint(3) DEFAULT NULL,
  `wontackles` tinyint(3) DEFAULT NULL,
  `shoots` tinyint(3) DEFAULT NULL,
  `passes_successed` tinyint(3) DEFAULT NULL,
  `passes_failed` tinyint(3) DEFAULT NULL,
  `assists` tinyint(3) DEFAULT NULL,
  `freekicks` tinyint(3) DEFAULT NULL,
  `freekicks_successed` tinyint(3) DEFAULT NULL,
  `freekicks_failed` tinyint(3) DEFAULT NULL,
  `name` varchar(128) DEFAULT NULL,
  `losttackles` tinyint(3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_spiel_text` (
  `id` int(10) NOT NULL,
  `aktion` enum('Tor','Auswechslung','Zweikampf_gewonnen','Zweikampf_verloren','Pass_daneben','Torschuss_daneben','Torschuss_auf_Tor','Karte_gelb','Karte_rot','Karte_gelb_rot','Verletzung','Elfmeter_erfolg','Elfmeter_verschossen','Taktikaenderung','Ecke','Freistoss_daneben','Freistoss_treffer','Tor_mit_vorlage') DEFAULT NULL,
  `nachricht` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_sponsor_contract` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `sponsor_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `offer_type` enum('safe','risky','fan','cup','legacy') NOT NULL DEFAULT 'safe',
  `sponsor_name` varchar(30) DEFAULT NULL,
  `sponsor_picture` varchar(100) DEFAULT NULL,
  `b_spiel` int(10) NOT NULL DEFAULT 0,
  `b_heimzuschlag` int(10) NOT NULL DEFAULT 0,
  `b_sieg` int(10) NOT NULL DEFAULT 0,
  `b_meisterschaft` int(10) NOT NULL DEFAULT 0,
  `b_cup` int(10) NOT NULL DEFAULT 0,
  `b_attendance_percent` tinyint(3) NOT NULL DEFAULT 0,
  `negotiation_level` tinyint(3) NOT NULL DEFAULT 0,
  `signed_date` int(11) NOT NULL DEFAULT 0,
  `ended_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','expired','cancelled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_sponsor_negotiation` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `sponsor_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `offer_type` enum('safe','risky','fan','cup') NOT NULL,
  `negotiation_level` tinyint(3) NOT NULL DEFAULT 0,
  `status` enum('open','signed','withdrawn') NOT NULL DEFAULT 'open',
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_stadiumbuilding` (
  `id` int(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `required_building_id` int(10) DEFAULT NULL,
  `costs` int(10) NOT NULL,
  `premiumfee` int(10) NOT NULL DEFAULT 0,
  `construction_time_days` int(5) NOT NULL DEFAULT 0,
  `effect_training` varchar(5) NOT NULL DEFAULT '0.000',
  `effect_youthscouting` tinyint(3) NOT NULL DEFAULT 0,
  `effect_tickets` tinyint(3) NOT NULL DEFAULT 0,
  `effect_fanpopularity` tinyint(3) NOT NULL DEFAULT 0,
  `effect_injury` tinyint(3) NOT NULL DEFAULT 0,
  `effect_income` int(10) NOT NULL DEFAULT 0,
  `effect_merchandising` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_stadium_attendance_log` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `stadium_id` int(10) DEFAULT NULL,
  `standing_visitors` int(10) NOT NULL DEFAULT 0,
  `seating_visitors` int(10) NOT NULL DEFAULT 0,
  `vip_visitors` int(10) NOT NULL DEFAULT 0,
  `total_visitors` int(10) NOT NULL DEFAULT 0,
  `standing_capacity` int(10) NOT NULL DEFAULT 0,
  `seating_capacity` int(10) NOT NULL DEFAULT 0,
  `vip_capacity` int(10) NOT NULL DEFAULT 0,
  `total_capacity` int(10) NOT NULL DEFAULT 0,
  `standing_revenue` int(10) NOT NULL DEFAULT 0,
  `seating_revenue` int(10) NOT NULL DEFAULT 0,
  `vip_revenue` int(10) NOT NULL DEFAULT 0,
  `total_revenue` int(10) NOT NULL DEFAULT 0,
  `standing_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `seating_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vip_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `average_ticket_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_stadium_naming_contract` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `stadium_id` int(10) NOT NULL,
  `sponsor_id` int(10) NOT NULL DEFAULT 0,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `sponsor_name` varchar(30) NOT NULL,
  `stadium_name` varchar(60) NOT NULL,
  `original_stadium_name` varchar(60) NOT NULL,
  `base_payout_per_match` int(10) NOT NULL DEFAULT 0,
  `signed_date` int(11) NOT NULL DEFAULT 0,
  `ended_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','expired','cancelled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_stadium_naming_payout` (
  `id` int(10) NOT NULL,
  `contract_id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `base_payout` int(10) NOT NULL DEFAULT 0,
  `attendance_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `payout_amount` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_stats_salary` (
  `id` int(10) NOT NULL,
  `season` int(5) DEFAULT NULL,
  `salary` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_stockmarket_date` (
  `date` varchar(8) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_stockmarket_dividend` (
  `id` int(10) NOT NULL,
  `stock_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `dividend_pool` int(10) NOT NULL DEFAULT 0,
  `dividend_per_share` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_stockmarket_dividend_payment` (
  `id` int(10) NOT NULL,
  `dividend_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `shares` int(10) NOT NULL DEFAULT 0,
  `amount` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_tabelle_markierung` (
  `id` smallint(5) NOT NULL,
  `liga_id` smallint(5) NOT NULL,
  `bezeichnung` varchar(50) DEFAULT NULL,
  `farbe` varchar(10) DEFAULT NULL,
  `platz_von` smallint(5) NOT NULL,
  `platz_bis` smallint(5) NOT NULL,
  `target_league_id` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_teamoftheday` (
  `id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL,
  `matchday` tinyint(3) NOT NULL,
  `statistic_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `position_main` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_team_chemistry_log` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `event_date` int(11) NOT NULL DEFAULT 0,
  `source` varchar(32) NOT NULL DEFAULT 'manual',
  `old_score` tinyint(3) NOT NULL DEFAULT 50,
  `new_score` tinyint(3) NOT NULL DEFAULT 50,
  `match_effect` tinyint(3) NOT NULL DEFAULT 0,
  `match_id` int(10) DEFAULT NULL,
  `breakdown_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_titles_won` (
  `id` int(5) NOT NULL,
  `team_id` int(5) NOT NULL,
  `competition` varchar(40) NOT NULL,
  `saison_name` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_trainer` (
  `id` int(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `salary` int(10) NOT NULL,
  `p_technique` tinyint(3) NOT NULL DEFAULT 0,
  `p_stamina` tinyint(3) NOT NULL DEFAULT 0,
  `expertise` tinyint(3) NOT NULL DEFAULT 0,
  `premiumfee` int(10) DEFAULT 0,
  `specialization` enum('balanced','technique','fitness','offense','defense','setpieces','goalkeeper','mental','tactics') NOT NULL DEFAULT 'balanced',
  `p_offense` tinyint(3) NOT NULL DEFAULT 60,
  `p_defense` tinyint(3) NOT NULL DEFAULT 60,
  `p_tactics` tinyint(3) NOT NULL DEFAULT 60,
  `p_goalkeeping` tinyint(3) NOT NULL DEFAULT 60,
  `p_mental` tinyint(3) NOT NULL DEFAULT 60
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_training` (
  `id` smallint(5) NOT NULL,
  `name` varchar(30) DEFAULT NULL,
  `w_staerke` tinyint(3) NOT NULL,
  `w_technik` tinyint(3) NOT NULL,
  `w_kondition` tinyint(3) NOT NULL,
  `w_frische` tinyint(3) NOT NULL,
  `w_zufriedenheit` tinyint(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_trainingslager` (
  `id` int(10) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `land` varchar(30) DEFAULT NULL,
  `bild` varchar(100) DEFAULT NULL,
  `camp_type` enum('balanced','fitness','recovery','technique','teambuilding','tactics','youth','mental') NOT NULL DEFAULT 'balanced',
  `preis_spieler_tag` int(10) NOT NULL,
  `p_staerke` tinyint(3) NOT NULL,
  `p_technik` tinyint(3) NOT NULL,
  `p_kondition` tinyint(3) NOT NULL,
  `p_frische` tinyint(3) NOT NULL,
  `p_zufriedenheit` tinyint(3) NOT NULL,
  `p_passing` tinyint(3) NOT NULL DEFAULT 0,
  `p_shooting` tinyint(3) NOT NULL DEFAULT 0,
  `p_heading` tinyint(3) NOT NULL DEFAULT 0,
  `p_tackling` tinyint(3) NOT NULL DEFAULT 0,
  `p_freekick` tinyint(3) NOT NULL DEFAULT 0,
  `p_pace` tinyint(3) NOT NULL DEFAULT 0,
  `p_creativity` tinyint(3) NOT NULL DEFAULT 0,
  `p_influence` tinyint(3) NOT NULL DEFAULT 0,
  `p_flair` tinyint(3) NOT NULL DEFAULT 0,
  `p_penalty` tinyint(3) NOT NULL DEFAULT 0,
  `p_penalty_killing` tinyint(3) NOT NULL DEFAULT 0,
  `p_team_chemistry` tinyint(3) NOT NULL DEFAULT 1,
  `injury_risk` tinyint(3) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_trainingslager_belegung` (
  `id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL,
  `lager_id` int(10) NOT NULL,
  `datum_start` int(10) NOT NULL,
  `datum_ende` int(10) NOT NULL,
  `player_count` int(10) NOT NULL DEFAULT 0,
  `total_costs` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_trainingslager_report` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `camp_id` int(10) NOT NULL DEFAULT 0,
  `camp_name` varchar(100) NOT NULL,
  `camp_type` varchar(32) NOT NULL DEFAULT 'balanced',
  `date_start` int(10) NOT NULL DEFAULT 0,
  `date_end` int(10) NOT NULL DEFAULT 0,
  `completed_date` int(11) NOT NULL DEFAULT 0,
  `duration_days` tinyint(3) NOT NULL DEFAULT 0,
  `player_count` int(10) NOT NULL DEFAULT 0,
  `total_costs` int(10) NOT NULL DEFAULT 0,
  `effect_strength_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_technique_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_stamina_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_freshness_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_satisfaction_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_passing_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_shooting_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_heading_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_tackling_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_freekick_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_pace_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_creativity_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_influence_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_flair_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_penalty_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_penalty_killing_total` tinyint(4) NOT NULL DEFAULT 0,
  `effect_chemistry_total` tinyint(4) NOT NULL DEFAULT 0,
  `injuries` tinyint(3) NOT NULL DEFAULT 0,
  `old_chemistry` tinyint(3) NOT NULL DEFAULT 0,
  `new_chemistry` tinyint(3) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_training_plan` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `current_slot_no` tinyint(3) NOT NULL DEFAULT 1,
  `last_match_id` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_training_plan_slot` (
  `id` int(10) NOT NULL,
  `plan_id` int(10) NOT NULL,
  `slot_no` tinyint(3) NOT NULL,
  `training_type` varchar(32) NOT NULL,
  `intensity` tinyint(3) NOT NULL DEFAULT 50
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_training_report` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `trainer_id` int(10) DEFAULT NULL,
  `training_unit_id` int(10) DEFAULT NULL,
  `training_type` varchar(32) NOT NULL,
  `intensity` tinyint(3) NOT NULL DEFAULT 50,
  `matchday` tinyint(3) NOT NULL DEFAULT 0,
  `match_id` int(10) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `player_count` int(10) NOT NULL DEFAULT 0,
  `best_player_id` int(10) DEFAULT NULL,
  `injuries` tinyint(3) NOT NULL DEFAULT 0,
  `old_chemistry` tinyint(3) NOT NULL DEFAULT 0,
  `new_chemistry` tinyint(3) NOT NULL DEFAULT 0,
  `summary_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_training_report_player` (
  `id` int(10) NOT NULL,
  `report_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `total_effect` decimal(7,3) NOT NULL DEFAULT 0.000,
  `effect_data` text DEFAULT NULL,
  `injured` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_training_unit` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `trainer_id` int(10) NOT NULL,
  `focus` varchar(32) DEFAULT NULL,
  `intensity` tinyint(3) NOT NULL DEFAULT 50,
  `date_executed` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_transfer_log` (
  `id` int(5) NOT NULL,
  `text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_uefa_temp` (
  `id` int(5) NOT NULL,
  `verein_id` int(3) NOT NULL,
  `cup_id` int(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_ul_temp` (
  `id` int(3) NOT NULL,
  `verein_id` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `manager_character` varchar(32) NOT NULL DEFAULT '',
  `manager_character_set_date` int(11) NOT NULL DEFAULT 0,
  `manager_character_last_change` int(11) NOT NULL DEFAULT 0,
  `manager_character_last_change_matches` int(10) NOT NULL DEFAULT 0,
  `manager_character_changes` smallint(5) NOT NULL DEFAULT 0,
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

CREATE TABLE `cm23_userabsence` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `deputy_id` int(10) DEFAULT NULL,
  `from_date` int(11) NOT NULL,
  `to_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_useractionlog` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `action_id` varchar(255) DEFAULT NULL,
  `created_date` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_user_stock` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `stock_id` int(10) NOT NULL,
  `qty` int(10) NOT NULL,
  `price` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_verein` (
  `id` int(10) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `kurz` varchar(5) DEFAULT NULL,
  `bild` varchar(100) DEFAULT NULL,
  `liga_id` smallint(5) DEFAULT NULL,
  `user_id` int(10) DEFAULT 0,
  `parent_club_id` int(5) DEFAULT NULL,
  `parent_club_status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `parent_club_suspended_reason` varchar(128) DEFAULT NULL,
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
  `fan_mood` tinyint(3) NOT NULL DEFAULT 50,
  `media_pressure` tinyint(3) NOT NULL DEFAULT 30,
  `team_chemistry` tinyint(3) NOT NULL DEFAULT 50,
  `team_chemistry_effect` tinyint(3) NOT NULL DEFAULT 0,
  `team_chemistry_updated` int(11) NOT NULL DEFAULT 0,
  `tactical_style` varchar(32) NOT NULL DEFAULT '',
  `tactical_style_fit` tinyint(3) NOT NULL DEFAULT 0,
  `tactical_style_effect` tinyint(4) NOT NULL DEFAULT 0,
  `tactical_style_updated` int(11) NOT NULL DEFAULT 0,
  `min_target_highscore` int(5) DEFAULT NULL,
  `highscore` int(5) DEFAULT 0,
  `superclub` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '0',
  `tactical_style_last_change` int(11) NOT NULL DEFAULT 0,
  `tactical_style_change_effect` tinyint(4) NOT NULL DEFAULT 0,
  `tactical_style_staff_advice` varchar(32) NOT NULL DEFAULT '',
  `tactical_style_staff_advice_updated` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_v_transfer_angebot_player` (
  `offer` bigint(21) NOT NULL DEFAULT 0,
  `spieler_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_watchlist` (
  `id` int(10) NOT NULL,
  `spieler_id` int(10) NOT NULL,
  `verein_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_what_changed` (
  `id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `previous_match_id` int(10) NOT NULL DEFAULT 0,
  `match_date` int(11) NOT NULL DEFAULT 0,
  `matchday` tinyint(3) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `summary_title` varchar(128) NOT NULL,
  `summary_data` mediumtext NOT NULL,
  `news_id` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `cm23_youthmatch_player` (
  `match_id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL,
  `playernumber` tinyint(2) NOT NULL,
  `position` varchar(24) DEFAULT NULL,
  `position_main` varchar(8) DEFAULT NULL,
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

CREATE TABLE `cm23_youthmatch_reportitem` (
  `id` int(10) NOT NULL,
  `match_id` int(10) NOT NULL,
  `minute` tinyint(3) NOT NULL,
  `message_key` varchar(32) NOT NULL,
  `message_data` varchar(255) DEFAULT NULL,
  `home_on_ball` enum('1','0') NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_youthmatch_request` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `matchdate` int(11) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `reward` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

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

CREATE TABLE `cm23_youthscout` (
  `id` int(10) NOT NULL,
  `name` varchar(32) NOT NULL,
  `expertise` tinyint(3) NOT NULL,
  `fee` int(10) NOT NULL,
  `speciality` enum('Torwart','Abwehr','Mittelfeld','Sturm') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `cm23_youth_academy` (
  `team_id` int(10) NOT NULL,
  `level` tinyint(3) NOT NULL DEFAULT 1,
  `focus` enum('technique','physical','mental','balanced') NOT NULL DEFAULT 'balanced',
  `reputation` tinyint(3) NOT NULL DEFAULT 50,
  `youth_captain_id` int(10) NOT NULL DEFAULT 0,
  `missed_payments` tinyint(3) NOT NULL DEFAULT 0,
  `last_cost_match_id` int(10) NOT NULL DEFAULT 0,
  `last_report_date` int(11) NOT NULL DEFAULT 0,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `updated_date` int(11) NOT NULL DEFAULT 0,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_youth_academy_level` (
  `id` int(10) NOT NULL,
  `level` tinyint(3) NOT NULL,
  `name` varchar(100) NOT NULL,
  `build_cost` int(10) NOT NULL DEFAULT 0,
  `maintenance_fee` int(10) NOT NULL DEFAULT 0,
  `development_bonus` tinyint(3) NOT NULL DEFAULT 0,
  `scouting_bonus` tinyint(3) NOT NULL DEFAULT 0,
  `stagnation_reduction` tinyint(3) NOT NULL DEFAULT 0,
  `reputation_bonus` tinyint(3) NOT NULL DEFAULT 0,
  `max_reputation` tinyint(3) NOT NULL DEFAULT 100,
  `status` enum('1','0') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cm23_youth_academy_log` (
  `id` int(10) NOT NULL,
  `team_id` int(10) NOT NULL,
  `player_id` int(10) NOT NULL DEFAULT 0,
  `type` enum('development','scouting','risk','system') NOT NULL DEFAULT 'system',
  `old_strength` smallint(5) NOT NULL DEFAULT 0,
  `new_strength` smallint(5) NOT NULL DEFAULT 0,
  `change_amount` smallint(5) NOT NULL DEFAULT 0,
  `message` varchar(100) NOT NULL,
  `created_date` int(11) NOT NULL DEFAULT 0,
  `match_id` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE `cm23_achievement`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_admin`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_aufstellung`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_badge`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_badge_event_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_badge_event_log_reference` (`user_id`,`event`,`reference_key`),
  ADD KEY `idx_badge_event_log_user_event` (`user_id`,`event`),
  ADD KEY `idx_badge_event_log_team` (`team_id`),
  ADD KEY `idx_badge_event_log_season` (`season_id`);

ALTER TABLE `cm23_badge_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_badge_user_award` (`user_id`,`badge_id`,`award_key`),
  ADD KEY `badge_id` (`badge_id`),
  ADD KEY `idx_badge_user_user_badge` (`user_id`,`badge_id`),
  ADD KEY `idx_badge_user_team` (`team_id`);

ALTER TABLE `cm23_bank`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_team_status` (`verein_id`,`status`),
  ADD KEY `idx_bank_payment_match` (`last_payment_match_id`);

ALTER TABLE `cm23_briefe`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_briefe_user_id_fk` (`absender_id`);

ALTER TABLE `cm23_club_partnership`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club_partnership_parent_status` (`parent_team_id`,`status`),
  ADD KEY `idx_club_partnership_partner_status` (`partner_team_id`,`status`),
  ADD KEY `idx_club_partnership_pending_user` (`pending_user_id`,`status`),
  ADD KEY `idx_club_partnership_status_date` (`status`,`updated_date`);

ALTER TABLE `cm23_club_partnership_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club_partnership_log_partnership` (`partnership_id`,`created_date`),
  ADD KEY `idx_club_partnership_log_event` (`event_key`,`created_date`);

ALTER TABLE `cm23_club_staff`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club_staff_role_active` (`role`,`active`);

ALTER TABLE `cm23_club_staff_assignment`
  ADD PRIMARY KEY (`team_id`,`role`),
  ADD KEY `idx_club_staff_assignment_staff` (`staff_id`);

ALTER TABLE `cm23_cl_temp`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_config`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_conmebol_temp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conmebol_temp_cup` (`cup_name`),
  ADD KEY `idx_conmebol_temp_team` (`verein_id`);

ALTER TABLE `cm23_country`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_cup`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_cup_round`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_cupround_cup_id_fk` (`cup_id`),
  ADD KEY `cm23_cupround_fromloosers_id_fk` (`from_loosers_round_id`),
  ADD KEY `cm23_cupround_fromwinners_id_fk` (`from_winners_round_id`);

ALTER TABLE `cm23_cup_round_group_next`
  ADD PRIMARY KEY (`cup_round_id`) USING BTREE,
  ADD KEY `cm23_groupnext_tagetround_fk` (`target_cup_round_id`);

ALTER TABLE `cm23_cup_round_pending`
  ADD PRIMARY KEY (`team_id`,`cup_round_id`);

ALTER TABLE `cm23_derby_match`
  ADD PRIMARY KEY (`match_id`),
  ADD KEY `idx_derby_match_rivalry` (`rivalry_id`),
  ADD KEY `idx_derby_match_home_guest` (`home_team_id`,`guest_team_id`),
  ADD KEY `idx_derby_match_winner` (`winner_team_id`),
  ADD KEY `idx_derby_match_processed` (`post_processed`);

ALTER TABLE `cm23_el_temp`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_fanpressure_interview_occurrence`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_fanpressure_interview_reference` (`reference_key`),
  ADD KEY `idx_fanpressure_interview_user_status` (`user_id`,`team_id`,`status`),
  ADD KEY `idx_fanpressure_interview_question` (`question_id`);

ALTER TABLE `cm23_fanpressure_interview_question`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fanpressure_interview_event` (`event_key`,`active`,`weight`);

ALTER TABLE `cm23_fanpressure_story_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_fanpressure_story_reference` (`reference_key`),
  ADD KEY `idx_fanpressure_story_team_date` (`team_id`,`event_date`),
  ADD KEY `idx_fanpressure_story_event` (`event_key`);

ALTER TABLE `cm23_fanpressure_story_rule`
  ADD PRIMARY KEY (`event_key`),
  ADD KEY `idx_fanpressure_story_rule_active` (`active`,`source`);

ALTER TABLE `cm23_fan_mood_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fan_mood_log_team_date` (`team_id`,`event_date`),
  ADD KEY `idx_fan_mood_log_user` (`user_id`),
  ADD KEY `idx_fan_mood_log_match` (`match_id`),
  ADD KEY `idx_fan_mood_log_source` (`source`);

ALTER TABLE `cm23_finance_regulation_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_finance_reg_log_date` (`created_date`),
  ADD KEY `idx_finance_reg_log_mode` (`mode`);

ALTER TABLE `cm23_finance_regulation_setting`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `cm23_finance_regulation_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_finance_reg_snapshot_date` (`created_date`),
  ADD KEY `idx_finance_reg_snapshot_season` (`season_id`);

ALTER TABLE `cm23_friendly_request`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_individual_training`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_individual_training_team_status` (`team_id`,`status`),
  ADD KEY `idx_individual_training_player_status` (`player_id`,`status`),
  ADD KEY `idx_individual_training_last_match` (`last_match_id`);

ALTER TABLE `cm23_injury_clearance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_injury_clearance_player_match` (`player_id`,`match_id`),
  ADD KEY `idx_injury_clearance_match_status` (`match_id`,`status`),
  ADD KEY `idx_injury_clearance_team` (`team_id`);

ALTER TABLE `cm23_injury_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_injury_log_reference` (`reference_key`),
  ADD KEY `idx_injury_log_team_date` (`team_id`,`event_date`),
  ADD KEY `idx_injury_log_player` (`player_id`);

ALTER TABLE `cm23_injury_treatment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_injury_treatment_team_date` (`team_id`,`created_date`),
  ADD KEY `idx_injury_treatment_player` (`player_id`);

ALTER TABLE `cm23_kontinent`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_konto`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_land`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_leaguehistory`
  ADD PRIMARY KEY (`team_id`,`season_id`,`matchday`);

ALTER TABLE `cm23_liga`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_loan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_loan_player_status` (`player_id`,`status`),
  ADD KEY `idx_loan_lender_status` (`lender_team_id`,`status`),
  ADD KEY `idx_loan_borrower_status` (`borrower_team_id`,`status`);

ALTER TABLE `cm23_loan_offer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_loan_offer_player_status` (`player_id`,`status`),
  ADD KEY `idx_loan_offer_lender_status` (`lender_team_id`,`status`);

ALTER TABLE `cm23_loan_report`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_loan_report_match` (`loan_id`,`match_id`),
  ADD KEY `idx_loan_report_player` (`player_id`),
  ADD KEY `idx_loan_report_match` (`match_id`);

ALTER TABLE `cm23_loan_request`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_loan_request_lender_status` (`lender_team_id`,`status`),
  ADD KEY `idx_loan_request_borrower_status` (`borrower_team_id`,`status`),
  ADD KEY `idx_loan_request_player_status` (`player_id`,`status`);

ALTER TABLE `cm23_manager_application`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manager_application_user_status` (`user_id`,`status`),
  ADD KEY `idx_manager_application_target_status` (`target_team_id`,`status`),
  ADD KEY `idx_manager_application_decision` (`status`,`decision_date`),
  ADD KEY `idx_manager_application_offer` (`offer_id`);

ALTER TABLE `cm23_manager_award`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_manager_award_key` (`award_key`),
  ADD KEY `idx_manager_award_user_date` (`user_id`,`created_date`),
  ADD KEY `idx_manager_award_team` (`team_id`),
  ADD KEY `idx_manager_award_season` (`season_id`);

ALTER TABLE `cm23_manager_career_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manager_career_user_date` (`user_id`,`change_date`),
  ADD KEY `idx_manager_career_new_team` (`new_team_id`),
  ADD KEY `idx_manager_career_old_team` (`old_team_id`);

ALTER TABLE `cm23_manager_contract`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manager_contract_user_status` (`user_id`,`status`),
  ADD KEY `idx_manager_contract_team_status` (`team_id`,`status`),
  ADD KEY `idx_manager_contract_sack_check` (`last_sack_check_match_id`);

ALTER TABLE `cm23_manager_country_reputation`
  ADD PRIMARY KEY (`user_id`,`country`),
  ADD KEY `idx_manager_country_reputation_country` (`country`,`reputation`);

ALTER TABLE `cm23_manager_job_offer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manager_job_offer_user_status` (`user_id`,`status`),
  ADD KEY `idx_manager_job_offer_target_status` (`target_team_id`,`status`),
  ADD KEY `idx_manager_job_offer_created_match` (`created_match_id`),
  ADD KEY `idx_manager_job_offer_expiry` (`status`,`expires_date`);

ALTER TABLE `cm23_manager_mission`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_manager_mission` (`user_id`,`team_id`,`season_id`,`mission_type`),
  ADD KEY `idx_manager_mission_team_season` (`team_id`,`season_id`),
  ADD KEY `idx_manager_mission_status` (`status`);

ALTER TABLE `cm23_manager_mission_youth_promotion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_manager_mission_professional_player` (`professional_player_id`),
  ADD KEY `idx_manager_mission_youth_team_season` (`team_id`,`season_id`),
  ADD KEY `idx_manager_mission_youth_user` (`user_id`);

ALTER TABLE `cm23_matchreport`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_match_id` (`match_id`);

ALTER TABLE `cm23_merchandising_product`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_merchandising_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_match_id_team_id` (`match_id`,`team_id`);

ALTER TABLE `cm23_merchandising_team_product`
  ADD PRIMARY KEY (`team_id`,`product_id`);

ALTER TABLE `cm23_name`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_nationalplayer`
  ADD PRIMARY KEY (`team_id`,`player_id`),
  ADD KEY `cm23_nationalp_player_id_fk` (`player_id`);

ALTER TABLE `cm23_news`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_notification`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_penalty`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_premiumpayment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_premiumpayment_user_id_fk` (`user_id`);

ALTER TABLE `cm23_premiumstatement`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_premium_user_id_fk` (`user_id`);

ALTER TABLE `cm23_randomevent`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_randomevent_chain`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_randomevent_chain_key` (`event_key`),
  ADD KEY `idx_randomevent_chain_active` (`active`,`weight`),
  ADD KEY `idx_randomevent_chain_type` (`event_type`);

ALTER TABLE `cm23_randomevent_chain_choice`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_randomevent_chain_choice` (`chain_id`,`choice_key`),
  ADD KEY `idx_randomevent_chain_choice_chain` (`chain_id`),
  ADD KEY `idx_randomevent_chain_choice_default` (`chain_id`,`is_default`);

ALTER TABLE `cm23_randomevent_chain_occurrence`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_randomevent_chain_occurrence_user_team_status` (`user_id`,`team_id`,`status`),
  ADD KEY `idx_randomevent_chain_occurrence_team_status` (`team_id`,`status`),
  ADD KEY `idx_randomevent_chain_occurrence_matchday` (`team_id`,`created_matchday`),
  ADD KEY `idx_randomevent_chain_occurrence_chain` (`chain_id`),
  ADD KEY `idx_randomevent_chain_occurrence_choice` (`selected_choice_id`);

ALTER TABLE `cm23_randomevent_chain_roll`
  ADD PRIMARY KEY (`team_id`,`matchday`),
  ADD KEY `idx_randomevent_chain_roll_occurrence` (`created_occurrence_id`);

ALTER TABLE `cm23_rivalry`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rivalry_team1` (`team1_id`),
  ADD KEY `idx_rivalry_team2` (`team2_id`),
  ADD KEY `idx_rivalry_active_manual` (`active`,`manual`);

ALTER TABLE `cm23_saison`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_scout`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_scouting_camp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scouting_camp_team_status` (`team_id`,`status`),
  ADD KEY `idx_scouting_camp_location` (`location_id`),
  ADD KEY `idx_scouting_camp_scout` (`scout_id`),
  ADD KEY `idx_scouting_camp_next_proposal` (`matches_until_next_proposal`);

ALTER TABLE `cm23_scouting_camp_location`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scouting_camp_location_name` (`name`),
  ADD KEY `idx_scouting_camp_location_country` (`country`),
  ADD KEY `idx_scouting_camp_location_continent` (`continent`),
  ADD KEY `idx_scouting_camp_location_status` (`status`),
  ADD KEY `idx_scouting_camp_location_level` (`min_department_level`);

ALTER TABLE `cm23_scouting_department`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scouting_department_team` (`team_id`),
  ADD KEY `idx_scouting_department_level` (`level`),
  ADD KEY `idx_scouting_department_status` (`status`);

ALTER TABLE `cm23_scouting_department_level`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scouting_department_level` (`level`),
  ADD KEY `idx_scouting_department_level_status` (`status`);

ALTER TABLE `cm23_scouting_proposal`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scouting_proposal_team_status` (`team_id`,`status`),
  ADD KEY `idx_scouting_proposal_camp` (`camp_id`),
  ADD KEY `idx_scouting_proposal_scout` (`scout_id`),
  ADD KEY `idx_scouting_proposal_location` (`location_id`),
  ADD KEY `idx_scouting_proposal_created_player` (`created_player_id`),
  ADD KEY `idx_scouting_proposal_expiry` (`expires_after_matches`,`status`);

ALTER TABLE `cm23_session`
  ADD PRIMARY KEY (`session_id`);

ALTER TABLE `cm23_shoutmessage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `match_id` (`match_id`);

ALTER TABLE `cm23_spiel`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_berechnet` (`berechnet`),
  ADD KEY `idx_auto_saison_id` (`saison_id`);

ALTER TABLE `cm23_spieler`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_verein_id_verletzt_gesperrt_status` (`verein_id`,`verletzt`,`gesperrt`,`status`),
  ADD KEY `idx_auto_transfermarkt` (`transfermarkt`),
  ADD KEY `idx_auto_status` (`status`);

ALTER TABLE `cm23_spiel_berechnung`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_spiel_id_team_id` (`spiel_id`,`team_id`),
  ADD KEY `idx_auto_spieler_id` (`spieler_id`);

ALTER TABLE `cm23_spiel_text`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_sponsor`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_sponsor_contract`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sponsor_contract_team_status` (`team_id`,`status`),
  ADD KEY `idx_sponsor_contract_sponsor_season` (`sponsor_id`,`season_id`,`status`),
  ADD KEY `idx_sponsor_contract_season_status` (`season_id`,`status`);

ALTER TABLE `cm23_sponsor_negotiation`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sponsor_negotiation_offer` (`team_id`,`sponsor_id`,`season_id`,`offer_type`),
  ADD KEY `idx_sponsor_negotiation_status` (`team_id`,`season_id`,`status`);

ALTER TABLE `cm23_stadion`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_stadiumbuilding`
  ADD PRIMARY KEY (`id`),
  ADD KEY `required_building_id` (`required_building_id`);

ALTER TABLE `cm23_stadium_attendance_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stadium_attendance_match_team` (`match_id`,`team_id`),
  ADD KEY `idx_stadium_attendance_team_date` (`team_id`,`created_date`),
  ADD KEY `idx_stadium_attendance_stadium` (`stadium_id`);

ALTER TABLE `cm23_stadium_builder`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_stadium_construction`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_stadium_naming_contract`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stadium_naming_team_status` (`team_id`,`status`),
  ADD KEY `idx_stadium_naming_season_status` (`season_id`,`status`),
  ADD KEY `idx_stadium_naming_stadium` (`stadium_id`);

ALTER TABLE `cm23_stadium_naming_payout`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stadium_naming_payout_match` (`contract_id`,`match_id`),
  ADD KEY `idx_stadium_naming_payout_team_date` (`team_id`,`created_date`),
  ADD KEY `idx_stadium_naming_payout_match` (`match_id`);

ALTER TABLE `cm23_stats_salary`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_stockmarket`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_stockmarket_dividend`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_dividend_season` (`stock_id`,`team_id`,`season_id`),
  ADD KEY `idx_stockmarket_dividend_team_season` (`team_id`,`season_id`);

ALTER TABLE `cm23_stockmarket_dividend_payment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dividend_payment_user` (`user_id`,`created_date`),
  ADD KEY `idx_dividend_payment_dividend` (`dividend_id`);

ALTER TABLE `cm23_tabelle_markierung`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_teamoftheday`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_team_chemistry_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team_chemistry_log_team_date` (`team_id`,`event_date`),
  ADD KEY `idx_team_chemistry_log_match` (`match_id`);

ALTER TABLE `cm23_team_league_statistics`
  ADD PRIMARY KEY (`team_id`,`season_id`),
  ADD KEY `cm23_statistics_season_id_fk` (`season_id`);

ALTER TABLE `cm23_titles_won`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_trainer`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_training`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_trainingslager`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_trainingslager_belegung`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_trainingslager_belegung_fk` (`lager_id`),
  ADD KEY `cm23_trainingslager_verein_fk` (`verein_id`);

ALTER TABLE `cm23_trainingslager_report`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trainingslager_report_team_date` (`team_id`,`completed_date`),
  ADD KEY `idx_trainingslager_report_camp` (`camp_id`);

ALTER TABLE `cm23_training_plan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_training_plan_team` (`team_id`),
  ADD KEY `idx_training_plan_status` (`status`);

ALTER TABLE `cm23_training_plan_slot`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_training_plan_slot` (`plan_id`,`slot_no`),
  ADD KEY `idx_training_plan_slot_plan` (`plan_id`);

ALTER TABLE `cm23_training_report`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_training_report_team_date` (`team_id`,`created_date`),
  ADD KEY `idx_training_report_match` (`match_id`);

ALTER TABLE `cm23_training_report_player`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_training_report_player_report` (`report_id`),
  ADD KEY `idx_training_report_player_player` (`player_id`);

ALTER TABLE `cm23_training_unit`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_transfer`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_transfer_angebot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_transfer_angebot_user_id_fk` (`user_id`);

ALTER TABLE `cm23_transfer_log`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_transfer_offer`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_uefa_temp`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_ul_temp`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_user`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_manager_character` (`manager_character`);

ALTER TABLE `cm23_userabsence`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `deputy_id` (`deputy_id`);

ALTER TABLE `cm23_useractionlog`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `cm23_user_inactivity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cm23_user_inactivity_user_id_fk` (`user_id`);

ALTER TABLE `cm23_user_stock`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_verein`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_verein_parent_club` (`parent_club_id`);

ALTER TABLE `cm23_watchlist`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_what_changed`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_what_changed_team_match` (`team_id`,`match_id`),
  ADD KEY `idx_what_changed_user_team` (`user_id`,`team_id`),
  ADD KEY `idx_what_changed_match_date` (`team_id`,`match_date`);

ALTER TABLE `cm23_youthmatch`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_youthmatch_player`
  ADD PRIMARY KEY (`match_id`,`player_id`);

ALTER TABLE `cm23_youthmatch_reportitem`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_youthmatch_request`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_youthplayer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auto_team_id` (`team_id`);

ALTER TABLE `cm23_youthscout`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `cm23_youth_academy`
  ADD PRIMARY KEY (`team_id`),
  ADD KEY `idx_youth_academy_level` (`level`),
  ADD KEY `idx_youth_academy_captain` (`youth_captain_id`);

ALTER TABLE `cm23_youth_academy_level`
  ADD PRIMARY KEY (`level`),
  ADD UNIQUE KEY `idx_youth_academy_level_id` (`id`);

ALTER TABLE `cm23_youth_academy_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_youth_academy_log_team_date` (`team_id`,`created_date`),
  ADD KEY `idx_youth_academy_log_player` (`player_id`);


ALTER TABLE `cm23_achievement`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_admin`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_aufstellung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_badge`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_badge_event_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_badge_user`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_bank`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_briefe`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_club_partnership`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_club_partnership_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_club_staff`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_cl_temp`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_config`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_conmebol_temp`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_country`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_cup`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_cup_round`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_el_temp`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_fanpressure_interview_occurrence`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_fanpressure_interview_question`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_fanpressure_story_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_fan_mood_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_finance_regulation_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_finance_regulation_snapshot`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_friendly_request`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_individual_training`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_injury_clearance`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_injury_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_injury_treatment`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_kontinent`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_konto`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_land`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_liga`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_loan`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_loan_offer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_loan_report`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_loan_request`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_application`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_award`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_career_history`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_contract`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_job_offer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_mission`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_manager_mission_youth_promotion`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_matchreport`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_merchandising_product`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_merchandising_sales`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_name`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_news`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_notification`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_penalty`
  MODIFY `id` int(2) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_premiumpayment`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_premiumstatement`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_randomevent`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_randomevent_chain`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_randomevent_chain_choice`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_randomevent_chain_occurrence`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_rivalry`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_saison`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scout`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scouting_camp`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scouting_camp_location`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scouting_department`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scouting_department_level`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_scouting_proposal`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_shoutmessage`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_spiel`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_spieler`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_spiel_berechnung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_spiel_text`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_sponsor`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_sponsor_contract`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_sponsor_negotiation`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadion`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadiumbuilding`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadium_attendance_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadium_builder`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadium_construction`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadium_naming_contract`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stadium_naming_payout`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stats_salary`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stockmarket`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stockmarket_dividend`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_stockmarket_dividend_payment`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_tabelle_markierung`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_teamoftheday`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_team_chemistry_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_titles_won`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_trainer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training`
  MODIFY `id` smallint(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_trainingslager`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_trainingslager_belegung`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_trainingslager_report`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training_plan`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training_plan_slot`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training_report`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training_report_player`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_training_unit`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_transfer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_transfer_angebot`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_transfer_log`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_transfer_offer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_uefa_temp`
  MODIFY `id` int(5) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_ul_temp`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_user`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_userabsence`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_useractionlog`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_user_inactivity`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_user_stock`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_verein`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_watchlist`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_what_changed`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youthmatch`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youthmatch_reportitem`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youthmatch_request`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youthplayer`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youthscout`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youth_academy_level`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cm23_youth_academy_log`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;


ALTER TABLE `cm23_badge_user`
  ADD CONSTRAINT `cm23_badge_user_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_badge_user_ibfk_2` FOREIGN KEY (`badge_id`) REFERENCES `cm23_badge` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_briefe`
  ADD CONSTRAINT `cm23_briefe_user_id_fk` FOREIGN KEY (`absender_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_cup_round`
  ADD CONSTRAINT `cm23_cupround_cup_id_fk` FOREIGN KEY (`cup_id`) REFERENCES `cm23_cup` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_cupround_fromloosers_id_fk` FOREIGN KEY (`from_loosers_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_cupround_fromwinners_id_fk` FOREIGN KEY (`from_winners_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_cup_round_group_next`
  ADD CONSTRAINT `cm23_groupnext_round_fk` FOREIGN KEY (`cup_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_groupnext_tagetround_fk` FOREIGN KEY (`target_cup_round_id`) REFERENCES `cm23_cup_round` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_nationalplayer`
  ADD CONSTRAINT `cm23_nationalp_player_id_fk` FOREIGN KEY (`player_id`) REFERENCES `cm23_spieler_copy2` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_nationalp_team_id_fk` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein_` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_premiumpayment`
  ADD CONSTRAINT `cm23_premiumpayment_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_premiumstatement`
  ADD CONSTRAINT `cm23_premium_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_stadiumbuilding`
  ADD CONSTRAINT `cm23_stadiumbuilding_ibfk_1` FOREIGN KEY (`required_building_id`) REFERENCES `cm23_stadiumbuilding` (`id`) ON DELETE SET NULL;

ALTER TABLE `cm23_team_league_statistics`
  ADD CONSTRAINT `cm23_statistics_season_id_fk` FOREIGN KEY (`season_id`) REFERENCES `cm23_saison` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_statistics_team_id_fk` FOREIGN KEY (`team_id`) REFERENCES `cm23_verein` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_trainingslager_belegung`
  ADD CONSTRAINT `cm23_trainingslager_belegung_fk` FOREIGN KEY (`lager_id`) REFERENCES `cm23_trainingslager` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_trainingslager_verein_fk` FOREIGN KEY (`verein_id`) REFERENCES `cm23_verein` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_transfer_angebot`
  ADD CONSTRAINT `cm23_transfer_angebot_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_userabsence`
  ADD CONSTRAINT `cm23_userabsence_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cm23_userabsence_ibfk_2` FOREIGN KEY (`deputy_id`) REFERENCES `cm23_user` (`id`) ON DELETE SET NULL;

ALTER TABLE `cm23_useractionlog`
  ADD CONSTRAINT `cm23_useractionlog_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;

ALTER TABLE `cm23_user_inactivity`
  ADD CONSTRAINT `cm23_user_inactivity_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `cm23_user` (`id`) ON DELETE CASCADE;
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
