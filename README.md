# CM23 — Championship Manager / OpenWebSoccer PHP 8 Custom Project

CM23 is a customized football management browser game based on OpenWebSoccer-Sim / open-websoccer-PHP-8. The current codebase keeps the classic OpenWebSoccer structure, but extends it with many additional management, finance, scouting, youth, matchday, statistics, and admin-control modules.

This README documents the complete project as represented by the current project archive and database schema. It is meant for developers and administrators who maintain or deploy this CM23 version.

## Project status

- Base: OpenWebSoccer-Sim / open-websoccer-PHP-8, version marker `5.8.1`.
- Runtime focus: PHP 8.x with MySQL/MariaDB through `mysqli`.
- Main language focus: German (`de`). English and Spanish language files still exist in parts of the upstream structure, but German XML files are the maintained source for current custom modules.
- Main database prefix used by the current live schema: `cm23`.
- Default upstream installer prefix: `ws3`, replaced by the installer when a different prefix is entered.

## Main entry points

| Path | Purpose |
| --- | --- |
| `index.php` | Frontend application entry point. Loads configuration, authentication, navigation, actions, and page rendering. |
| `ajax.php` | AJAX endpoint for actions and block/page rendering. |
| `admin/index.php` | Admin backend entry point. |
| `install/index.php` | Web installer for fresh setup or migration. |
| `update/index.php` | Update workflow for existing installations. |
| `webservices/executeMyJobs.php` | Runs configured background-style jobs from `admin/config/jobs.xml`. |
| `webservices/executeJob.php` | Executes selected jobs. |
| `webservices/simMatches.php` | Match simulation endpoint. |
| `webservices/executeTransfers.php` | Transfer execution endpoint. |

## Directory structure

| Directory | Purpose |
| --- | --- |
| `admin/` | Admin interface, admin pages, configuration files, job configuration, backend assets. |
| `classes/` | Core framework classes, actions, models, services, plugins, jobs, simulation logic, helpers. |
| `css/` | Frontend stylesheets including Bootstrap, skin, formation, charts, stadium styles. |
| `install/` | Installer, installer translations, installer DDL files. |
| `js/` | Frontend JavaScript including formation handling, Bootstrap, charts, stadium scripts. |
| `lib/` | Bundled libraries such as Twig. |
| `modules/` | Module definitions and German message XML files; some upstream English/Spanish files also exist. |
| `templates/default/views/` | Twig templates for frontend pages. |
| `update/` | Update workflow files. |
| `webservices/` | Script endpoints for jobs, simulation, transfers, RSS, and content reloads. |
| `generated/` | Generated runtime configuration and caches; must be writable and should not be committed with secrets. |
| `uploads/` | User/admin uploaded images for clubs, cups, players, sponsors, stadiums, stadium builders/buildings, users. |

## Core architecture

CM23 follows the OpenWebSoccer module pattern:

1. A `modules/<module>/module.xml` file defines pages, actions, permissions, blocks, and admin integration.
2. A model in `classes/models/` prepares data for a frontend page.
3. A Twig template in `templates/default/views/` renders the page.
4. An action controller in `classes/actions/` handles POST/action requests.
5. Service classes in `classes/services/` contain reusable domain logic and database access.
6. Plugins in `classes/plugins/` hook into match completion or other system events.
7. Jobs in `classes/jobs/` process recurring or matchday-related logic.

Important core classes include:

- `WebSoccer.class.php`: central configuration, request, role and runtime helper object.
- `DbConnection.class.php`: `mysqli` database wrapper.
- `I18n.class.php`: language message loading and formatting.
- `ActionHandler.class.php`: action dispatching.
- `ViewHandler.class.php`: page and block rendering.
- `NavigationBuilder.class.php` and `BreadcrumbBuilder.class.php`: navigation and breadcrumb creation.
- `Simulator.class.php`, `MatchSimulationExecutor.class.php`, `YouthMatchSimulationExecutor.class.php`: match simulation flow.

## Main frontend modules and features

### User, profile, access and communication

- User registration, activation, login and password reset.
- User profiles, profile pictures, club selection and user details.
- Messages inbox/outbox, private messages, notifications and shoutbox.
- User absence/deputy handling.
- Online user list, highscore, badges and user activity logs.
- Optional Facebook/Google+ legacy login modules exist but should be treated as legacy integrations.

### Clubs, leagues, cups and competitions

- Club overview with team details, history, players, match results and statistics.
- League overview, league details, tables, schedules, all-time table and table history.
- Cups, Champions League / UEFA style competitions and UEFA ranking pages.
- National teams, national player nominations and national match views.
- Derby/rivalry system with rivalry data and derby match tracking.

### Squad, players and formation

- Player details, player statistics, top scorers/strikers and discipline pages.
- Formation page with tactical setup, substitutions, offensive settings, long passes/counterattacks and set-piece players.
- Formation templates and future match formation submission.
- Player search outside the classic transfer list with watchlist integration.
- Player personality, hidden strength attributes support and expanded player attributes such as passing, shooting, heading, tackling, freekicks, pace, creativity, influence, flair, penalty and penalty killing.
- Team strength ranking and team rating support.

### Match simulation and matchday reporting

- League, cup, friendly and youth match simulation.
- Match reports, match details, live changes and match analytics.
- Team of the day and match/player statistics.
- What-changed summaries after matchdays.
- Plugins for badges, staff, fan pressure, medical center, merchandising, rivalries, scouting, stadium environment, stadium naming rights, stock market control, team chemistry and what-changed summaries.

### Training and team development

- Trainer selection and trainer detail pages.
- Training plans and automatic matchday processing.
- Individual player training with progress tracking.
- Training camps with camp booking, reports, costs, injuries and team chemistry impact.
- Team chemistry module with logs and match effects.
- Tactical style support for clubs.

### Youth system

- Youth team page, youth players, youth marketplace and youth scouting.
- Youth match requests, youth formation and youth match simulation.
- Move youth players to professional squad.
- Computer youth teams and computer youth match generation.
- Youth-related manager missions and youth development tracking.

### Transfers, direct offers and loans

- Classic transfer market with player selling, transfer bids and bid withdrawal.
- Direct transfer offers between clubs, including offer acceptance, rejection and cancellation.
- Watchlist support.
- Transfer penalties and penalty pool support.
- Computer transfers via configured jobs.
- Loan module with lender offers, borrower requests, loan duration, loan fee per match, salary share, buy option/buy obligation, recall and loan reports.

### Scouting

- Scouting department construction and upgrades.
- Scout hiring/firing.
- Scouting camps by location, position, age, strength and budget range.
- Scouting proposals with reported/hidden values and proposal expiry.
- Matchday scouting processing and scouting costs.

### Finances, market and economy

- Finance overview and account statements.
- Financial forecast.
- Bank and loan module with credit rating, repayment state, early repayment and board warnings.
- Sponsor contracts and sponsor negotiations.
- Stadium naming rights and naming payouts.
- Merchandising products, team product settings, sales and profit logs.
- Stock market module with club stocks, shares, portfolio, stock charts, dividends and dividend payments.
- Fix-term deposit support.
- Transfer penalty pool and redistribution during season/new-season workflows.
- Admin financial economy statistics / market regulation dashboard.

### Stadium and infrastructure

- Stadium overview and ticket pricing.
- Stadium expansion and builder flow.
- Stadium upgrades and stadium environment buildings.
- Stadium attendance logs and efficiency statistics.
- Stadium naming rights.
- Buildings can affect training, youth scouting, tickets, fan popularity, injury risk, income and merchandising.

### Staff, medical center, fan pressure and random events

- Club staff module with roles such as assistant manager, goalkeeping coach, fitness coach, youth coach, physio, marketing manager and financial advisor.
- Staff assignments per club and role, staff salary processing and staff bonus effects.
- Medical center treatments, injury logs, injury clearance and treatment risk/cost tracking.
- Fan mood, media pressure and board satisfaction logs.
- Random event chains with selectable choices, effects and expiry.
- Manager career mode with job offers, free-club switching and career history.
- Manager missions with rewards/penalties and mission progress.

### Statistics / Destatis

The Destatis/statistics section contains broad reporting pages, including:

- Best players, goal scorers, average gates and average ratings.
- Worst discipline, richest clubs, salary statistics and hall of fame.
- Largest stadiums, most valuable teams, highscore teams and team strength ranking.
- Player attribute ranking, stadium efficiency, manager rankings and financial economy statistics.

## Admin area highlights

Admin pages include:

- Global settings and module settings.
- User, club, player, league, cup, season, stadium, sponsor and news administration.
- Match execution, match completion, schedule generation and cup generation.
- Table maker, teams generator and players generator.
- Computer transfers administration.
- Financial economy statistics and market regulation support.
- New season / season rollover workflows.
- Jobs management through `admin/config/jobs.xml`.
- Cache clearing.

## Configured jobs

The current job configuration includes:

| Job id | Class | Purpose |
| --- | --- | --- |
| `correctplayers` | `CorrectPlayerValuesJob` | Correct/update player values. |
| `addplyr` | `AddPlayerWithoutTeamToTransfermarketJob` | Add clubless players to transfer market. Disabled in current config. |
| `extransf` | `ExecuteTransfersJob` | Execute open classic transfers. |
| `sim` | `SimulateMatchesJob` | Simulate open matches. |
| `usractv` | `UserInactivityCheckJob` | Compute/update user inactivity. |
| `stats` | `UpdateStatisticsJob` | Update league statistics. |
| `stadium` | `AcceptStadiumConstructionWorkJob` | Execute due stadium works and training camp bookings. |
| `comptransf` | `ComputerTransfersJob` | Computer-managed teams execute transfers. |
| `trainer` | `GenerateTrainerJob` | Generate trainers. |
| `youth` | `ComputerYouthTeamsJob` | Generate/process computer youth teams and youth matches. |
| `scouting` | `ScoutingMatchdayJob` | Process scouting matchdays. |
| `trainingmatchday` | `TrainingMatchdayJob` | Process automatic training plans. |
| `clubstaff` | `ClubStaffMatchdayJob` | Process club staff salaries. |

Jobs are executed by webservice scripts and controlled by `admin/config/jobs.xml`. They are not OS-level cron jobs by themselves; a real cron should call the relevant webservice endpoint regularly.

## Database overview

The current schema contains the classic OpenWebSoccer tables plus many CM23-specific tables. Important table groups include:

- Users and clubs: `*_user`, `*_verein`, `*_user_inactivity`, `*_userabsence`, `*_useractionlog`.
- Players and teams: `*_spieler`, `*_aufstellung`, `*_spiel`, `*_spiel_berechnung`, `*_spiel_text`, `*_team_league_statistics`.
- Finance: `*_konto`, `*_bank`, `*_penalty`, `*_sponsor_contract`, `*_sponsor_negotiation`, `*_stadium_naming_contract`, `*_stadium_naming_payout`, `*_merchandising_*`, `*_stockmarket*`, `*_user_stock`.
- Loans and transfers: `*_transfer`, `*_transfer_angebot`, `*_transfer_offer`, `*_loan`, `*_loan_offer`, `*_loan_request`, `*_loan_report`.
- Training and development: `*_training_*`, `*_trainingslager*`, `*_individual_training`, `*_team_chemistry_log`.
- Youth: `*_youthplayer`, `*_youthscout`, `*_youthmatch*`, `*_youthmatch_request`.
- Scouting: `*_scout`, `*_scouting_department*`, `*_scouting_camp*`, `*_scouting_proposal`.
- Staff/medical/fan pressure: `*_club_staff*`, `*_injury_*`, `*_fan_mood_log`.
- Career and missions: `*_manager_career_history`, `*_manager_job_offer`, `*_manager_mission*`.
- Badges/random events/rivalries/what changed: `*_badge*`, `*_randomevent*`, `*_rivalry`, `*_derby_match`, `*_what_changed`.

The database prefix is configurable. Documentation examples use `<prefix>_` where the installed prefix can be `cm23_`, `ws3_`, or another value selected in the installer.

## Configuration notes

- Runtime configuration is generated into `generated/config.inc.php` by the installer.
- Do not commit real database credentials or production secrets.
- German XML files are the maintained language files for current custom modules.
- After changing module XML or message XML files, clear/regenerate caches from the admin backend or by deleting generated cache files, depending on your deployment workflow.
- Some upstream English/Spanish message files are incomplete for custom CM23 modules; keep German as source of truth until translations are intentionally maintained.

## Deployment notes

- Always take a file and database backup before deploying changes.
- Keep `generated/` and `uploads/` writable by the web server, but protect them from direct execution where your host allows it.
- Remove or protect `/install` and `/update` after production installation.
- Make sure cron/webservice calls are protected from public abuse, for example with server rules, secret URLs or IP restrictions.
- Do not keep duplicate development files such as `* (2).php` in production unless they are intentionally required.
- Check logs after every deployment, especially after schema changes or matchday simulation.

## Development conventions

- Keep domain logic in `classes/services/` where possible.
- Keep request handling in `classes/actions/`.
- Keep page data preparation in `classes/models/`.
- Keep frontend output in Twig templates under `templates/default/views/`.
- Register pages/actions in the corresponding `modules/<module>/module.xml`.
- Add German messages to the relevant `messages_de.xml`, `adminmessages_de.xml` or `entitymessages_de.xml` file.
- Use the configured database prefix via `$website->getConfig('db_prefix')` rather than hardcoding `cm23_`.
- Prefer idempotent SQL migrations for existing installations.

## License

The project inherits the OpenWebSoccer-Sim license header: GNU Lesser General Public License, version 3 or later. Keep upstream license notices intact when modifying files.
