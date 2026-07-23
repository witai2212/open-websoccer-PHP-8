# OpenWebSoccer PHP 8 — CM23 Edition

[![Version](https://img.shields.io/badge/version-6.0.0-blue.svg)](#version-600)
[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4.svg)](#requirements)
[![Database](https://img.shields.io/badge/database-MySQL%20%7C%20MariaDB-orange.svg)](#requirements)
[![Language](https://img.shields.io/badge/game%20language-German-black.svg)](#language)
[![License](https://img.shields.io/badge/license-LGPL--3.0--or--later-green.svg)](#license-and-credits)

A heavily extended, PHP 8-compatible football management simulation based on **OpenWebSoccer-Sim**.

**CM23 Version 6.0.0** expands the original browser game into a broader club-management platform with modern transfer logic, manager careers, scouting, youth development, advanced training, club strategy, financial systems, international competitions, and extensive administration tools.

> This is an independent community project and not an official OpenWebSoccer-Sim release. It is not affiliated with Sports Interactive, Eidos, football associations, leagues, clubs, or their rights holders.

## Table of contents

- [Version 6.0.0](#version-600)
- [Main features](#main-features)
- [Gameplay systems](#gameplay-systems)
- [International competitions](#international-competitions)
- [Administration and maintenance](#administration-and-maintenance)
- [Technical architecture](#technical-architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Upgrade notes](#upgrade-notes)
- [Configuration](#configuration)
- [Scheduled jobs](#scheduled-jobs)
- [Language](#language)
- [Security](#security)
- [License and credits](#license-and-credits)

## Version 6.0.0

Version **6.0.0** marks the transition from a PHP 8 compatibility fork into a substantially expanded football management game.

Release date: **23 July 2026**

Major areas introduced or extensively reworked include:

- manager profiles, careers, contracts, applications, competence, characters, missions, and awards;
- transfer-market intelligence, improved CPU bidding, pre-contract negotiations, and duplicate-offer prevention;
- a complete loan system with requests, fees, salary sharing, options, obligations, recalls, and development reports;
- advanced scouting departments, scouting camps, position-specialist scouts, and generated proposals;
- Youth Academy 2.0 with configurable development focus, levels, reputation, captaincy, and CPU management;
- advanced training plans, trainer specializations, individual training, training reports, and training camps;
- tactical identity, team chemistry, fan mood, media pressure, board satisfaction, interviews, and story events;
- club staff, club partnerships, preferred loans, shared scouting, and first-option rights;
- merchandising, sponsorship negotiation, bank loans, stadium naming rights, finance regulation, and improved finance analysis;
- UEFA, CONMEBOL, and CONCACAF competition and ranking support;
- health checks, market-value maintenance, salary repair, economic controls, and additional administration jobs.

## Main features

### Football simulation

- League, cup, international cup, friendly, and youth-match simulation.
- Match reports, live tactical changes, substitutions, cards, injuries, suspensions, set pieces, and player statistics.
- Position-specific strength calculations with primary and secondary positions.
- Home advantage, morale, freshness, satisfaction, stamina, and tactical effects.
- Stadium attendance and ticket-income calculations.
- Automated formations and simulation support for unmanaged clubs.
- Team of the day, league statistics, historical standings, titles, and achievements.

### Manager career

- Human and CPU manager profiles.
- Manager competence from **1 to 20**, including development and career peaks.
- Manager character types with gameplay effects.
- Manager salaries, assignments, contracts, dismissals, and replacements.
- Job offers, manual applications, free-club changes, acceptance deadlines, and withdrawals.
- Country reputation and league-rating checks.
- Career history across clubs.
- Manager objectives and missions with rewards and penalties.
- Manager-of-the-month and manager-of-the-season awards.
- Personal manager notes.

### Squad and player management

- Detailed player attributes, personality, traits, talent, potential, and positional roles.
- Primary and secondary positions.
- Contract lengths measured in matches.
- Squad planner with:
  - squad-depth analysis;
  - age structure;
  - contract risks;
  - sale and loan candidates;
  - youth candidates;
  - positional weaknesses.
- Watchlist status indicators for transfer-list entries and submitted offers.
- Player-search filters for positions, characteristics, contract duration, and other squad-planning criteria.
- Automated correction of attributes above the supported maximum.
- Market-value and salary harmonization tools.
- Talent-distribution controls and talent-change events.

### Transfer market

- Transfer-list auctions and direct transfer offers.
- User and CPU bids with configurable realism limits.
- Prevention of duplicate active offers from the same club for the same player.
- Offer revisions replace or update the existing offer instead of creating competing duplicates.
- Configurable offer deviations, squad limits, budgets, and transaction limits.
- Transfer messages for buying, selling, receiving, accepting, and rejecting offers.
- High-profile transfer news.
- Sorting and improved status visualization across transfer pages.
- Transfer Market Intelligence with:
  - best available players;
  - bargains;
  - overpriced players;
  - loan opportunities;
  - expiring contracts;
  - club needs;
  - global, league, country, and club analysis;
  - administrative market metrics.

### Pre-contract negotiations

- Clubs may approach players whose contracts are close to expiry.
- Human and CPU clubs can submit offers for the following season.
- Decisions consider salary, goal bonus, signing bonus, club quality, league quality, and sporting prospects.
- Players may delay decisions while multiple offers are evaluated.
- Accepted moves are completed during the season rollover.
- Existing clubs must actively compete when another club has submitted a next-season offer.
- Signing bonuses are intended for free transfers rather than fee-paying transfers.

### Loan system

- Separate loan offers and loan requests.
- Configurable duration in matches.
- Per-match loan fees.
- Salary-sharing percentages.
- Optional purchase options and purchase obligations.
- Recall rules and minimum recall periods.
- Loan completion, recall, purchase, cancellation, and expiry states.
- Match-by-match loan reports with minutes, grades, goals, assists, and development effects.
- CPU-controlled loan-list rotation and automatic expiry.
- Position filters for available loan players.
- Club-partnership preferences and development bonuses.

### Scouting

- Buildable scouting departments with configurable levels.
- Limits for scouts and active scouting camps.
- Scouts grouped by positional specialty:
  - goalkeeper;
  - defence;
  - midfield;
  - attack.
- Scouting expertise, nationality, fees, and contract duration.
- Regional scouting-camp locations with travel, quality, and talent modifiers.
- Search criteria for position, age, strength, and budget.
- Generated scouting proposals with uncertain reported values.
- Accept, reject, and expire workflows.
- Shared scouting through active club partnerships.
- Talent visibility based on scouting quality and access rights.

### Youth development

- Youth squads and youth-match simulation.
- Automatic youth generation and management for CPU clubs.
- CPU promotion, buying, selling, transfer listing, and youth-match scheduling.
- Youth Academy 2.0 with:
  - buildable academy levels;
  - maintenance costs;
  - technique, physical, mental, or balanced focus;
  - academy reputation;
  - development and scouting bonuses;
  - stagnation-risk reduction;
  - youth captain;
  - periodic development reports;
  - CPU academy management.
- Youth-player traits.
- Youth transfer offers and abuse-protection logic.
- Promotion tracking for manager missions.

### Training

- Traditional training and advanced training systems.
- Multiple trainers per club.
- Trainer specializations:
  - balanced;
  - technique;
  - fitness;
  - offence;
  - defence;
  - set pieces;
  - goalkeeping;
  - mental;
  - tactics.
- Trainer reputation, expertise, club requirements, salary, and signing fee.
- Training plans with rotating slots and configurable intensity.
- Automatic matchday processing.
- Individual player training with progress tracking and completion reports.
- Training reports for the squad and individual players.
- Training camps with specialized effects, costs, injury risk, and chemistry impact.
- Position-specialty filters to make trainer selection easier.

### Tactical identity and team chemistry

- Tactical DNA styles:
  - pressing;
  - possession;
  - defensive;
  - physical;
  - counterattacking;
  - balanced.
- Squad-fit evaluation for the selected tactical identity.
- Team-chemistry tracking and match effects.
- Chemistry changes from transfers, training, formations, interviews, and club events.
- Staff recommendations for tactical style.
- Change penalties or bonuses when the club identity is adjusted.
- Dedicated tactical and chemistry views.

### Fans, media, board, and club stories

- Fan mood.
- Media pressure.
- Board satisfaction.
- Team reaction and chemistry changes.
- Story rules for events such as:
  - derby results;
  - winning and losing streaks;
  - major wins or defeats;
  - high-profile transfers;
  - youth-player usage;
  - sponsor and stadium events.
- Press interviews with multiple responses and different consequences.
- News and message generation for important events.
- Daily office briefing and “What Changed” matchday summaries.

### Club staff

Clubs can hire specialized employees with configurable levels, salaries, and bonuses:

- assistant manager;
- goalkeeping coach;
- fitness coach;
- youth coach;
- physiotherapist;
- marketing manager;
- financial advisor.

Staff effects are connected to training, youth development, scouting, ticketing, finances, medical treatment, and matchday processing.

### Club partnerships

- One parent club and one development partner per club.
- Mutual confirmation for manager-controlled clubs.
- Preferred loan relationships.
- Shared scouting.
- Development bonuses for suitable loans.
- First-option rights for selected professional and youth players.
- Configurable minimum duration and termination costs.
- Automatic suspension when competition rules create a conflict.
- Automatic closure when the responsible manager relationship ends.
- Optional CPU-generated partnership offers.

### Medical centre

- Injury overview and treatment decisions.
- Physiotherapy.
- Specialist treatment.
- Risky accelerated treatment.
- Treatment costs and configurable detection risk.
- Match-clearance workflow.
- Injury logs and duplicate-processing protection.
- Effects from physiotherapists and stadium facilities.

### Finance and commercial management

- Detailed income and expense history.
- Grouped finance views and category balances.
- Bank loans with principal, interest, repayment schedules, credit rating, and warning states.
- Sponsorship offers and negotiation types.
- Stadium naming-rights contracts and attendance-based payouts.
- Merchandise 2.0 with:
  - product development;
  - seasonal products;
  - player products;
  - stock ordering;
  - delivery times;
  - stadium and online sales;
  - campaigns;
  - clearance and liquidation;
  - manual, advisory, or delegated management;
  - marketing-staff effects.
- Stadium construction, maintenance, quality levels, and environment buildings.
- Stock-market holdings and dividend payments.
- Finance Regulation Center for:
  - economic snapshots;
  - salary-to-income analysis;
  - transfer-spending analysis;
  - human-versus-CPU comparison;
  - simulation of corrections;
  - sponsor, budget, ticket-price, wage, and market-value adjustments;
  - report export.

## International competitions

Version 6.0.0 extends the original competition framework beyond the European modules.

### UEFA

- UEFA country coefficients.
- Champions League support.
- Europa League support.
- Additional continental qualification markings and competition integration.

### CONMEBOL

- Separate CONMEBOL country ranking.
- Multi-season coefficient history.
- Copa Libertadores.
- Copa Sudamericana.
- Qualification allocations independent from UEFA values.

### CONCACAF

- Separate CONCACAF country ranking.
- Multi-season coefficient history.
- CONCACAF Champions Cup.
- Dedicated competition and ranking pages.

### Domestic competitions

- Domestic leagues and divisions.
- National cup framework.
- Qualification markings and promotion/relegation targets.
- Archived cup seasons, winner tracking, and prize money.

## Administration and maintenance

The administration area contains tools for operating a long-running game world.

- User, club, player, league, season, cup, sponsor, trainer, scout, and stadium management.
- Module and configuration management.
- Game Health Check with grouped integrity checks.
- Finance Regulation Center.
- Transfer Market Intelligence dashboard.
- Squad Planner administration.
- Club-partnership administration.
- Fan, media, and board rule administration.
- Scouting department, camp location, and proposal administration.
- Youth-academy level administration.
- Merchandising product and campaign administration.
- Manager-career administration.
- Market-value harmonization and maintenance.
- Player salary repair with audit logging.
- Background-job management.
- News, messages, notifications, and action logs.
- Data-generation and statistics tools.

## Technical architecture

CM23 retains the modular OpenWebSoccer-Sim architecture.

```text
actions/                 Legacy transfer actions
admin/                   Administration frontend and job management
classes/actions/         Request controllers
classes/events/          Domain events
classes/jobs/            Scheduled background jobs
classes/models/          Page and block models
classes/plugins/         Event listeners
classes/services/        Business and data services
modules/                 Module definitions and language files
services/                Legacy service entry points
templates/               Twig templates and frontend views
update/                  Legacy update helper
webservices/             Job, simulation, transfer, RSS, and AJAX endpoints
```

Important implementation characteristics:

- PHP application with no mandatory Composer or Node.js runtime step.
- Bundled Twig template engine.
- MySQLi database access.
- XML-based module, page, action, setting, entity, plugin, and language definitions.
- Event-driven extensions through plugins.
- Configuration-driven gameplay balancing.
- Database-backed sessions.
- Web-based administration and job execution.
- Configurable database-table prefix.

## Requirements

Recommended server environment:

- **PHP 8.x**
- **MySQL or MariaDB**
- InnoDB table support
- PHP extensions:
  - `mysqli`;
  - `dom`;
  - `simplexml`;
  - `json`;
  - `mbstring` recommended;
  - `gd` for image upload and resizing;
  - `curl` for optional external integrations.
- Apache, nginx, or another PHP-capable web server.
- Writable runtime directories for generated configuration, cache files, uploads, and logs.
- A cron job or external scheduler is strongly recommended for simulation and maintenance jobs.

The application is suitable for conventional shared hosting because the runtime does not require Composer, Node.js, or a permanent command-line worker.

## Installation

> The exact database package and deployment configuration can differ between CM23 installations. Always use the schema and migration files supplied with the matching release.

1. Clone or download the repository.

   ```bash
   git clone https://github.com/witai2212/open-websoccer-PHP-8.git
   ```

2. Upload the application to the intended web root.

3. Create an empty MySQL or MariaDB database with an appropriate UTF-8 character set.

4. Import the complete CM23 database schema and required base data for Version 6.0.0.

5. Create the runtime configuration and define at least:

   - database host;
   - database name;
   - database user;
   - database password;
   - database-table prefix;
   - public URL and context root;
   - project name;
   - system email;
   - time zone;
   - enabled modules and gameplay rules.

6. The runtime expects its generated configuration at:

   ```text
   generated/config.inc.php
   ```

   The legacy updater under `/update/` can move older configuration files into the generated directory.

7. Ensure that the required runtime directories are writable by PHP, particularly:

   ```text
   generated/
   cache/
   uploads/
   ```

8. Open the administration area and rebuild or clear the configuration and language caches.

9. Review all module settings and background jobs before opening the game to users.

10. Configure scheduled execution for match simulation, transfers, CPU logic, youth development, scouting, training, and maintenance.

## Upgrade notes

When upgrading an existing OpenWebSoccer or CM23 installation to Version 6.0.0:

1. Back up the complete database and application directory.
2. Put the game into maintenance or offline mode.
3. Deploy the changed application files.
4. Apply every required database migration in release order.
5. Update the internal version marker to:

   ```text
   admin/config/version.txt → 6.0.0
   ```

6. Merge configuration changes manually. Do not overwrite a production configuration with a repository copy.
7. Clear generated configuration, module, event, entity, and language caches.
8. Verify newly introduced tables, columns, indexes, and unique constraints.
9. Review all scheduled jobs and activate only the jobs required by the installation.
10. Run the Game Health Check.
11. Test:
    - login and registration;
    - team selection;
    - formation saving;
    - match simulation;
    - transfer bids and direct offers;
    - pre-contract offers;
    - loan requests;
    - scouting and training;
    - youth development;
    - manager job changes;
    - financial processing;
    - season rollover.
12. Reopen the game only after the checks have completed successfully.

## Configuration

Most gameplay rules are configurable. The configuration includes categories for:

- authentication and registration;
- languages and date formats;
- simulation probabilities and attribute weights;
- contracts and pre-contracts;
- transfer market and direct offers;
- loans;
- CPU transfers and budgets;
- youth generation and youth transfers;
- scouting;
- training and training camps;
- club staff;
- manager careers and missions;
- fan pressure and team chemistry;
- tactical identity;
- medical treatment;
- club partnerships;
- stadiums and naming rights;
- sponsorship;
- merchandising;
- news and notifications;
- finance regulation and market-value maintenance.

Selected Version 6.0.0 balancing defaults in the supplied project configuration include:

| Setting | Default |
|---|---:|
| Maximum player contract | 60 matches |
| Maximum trainers per club | 4 |
| Maximum scouts per club | 4 |
| Maximum CPU active transfer offers per club | 3 |
| Maximum CPU offers per player | 3 |
| Maximum CPU players on the loan list | 3 |
| CPU loan-list duration | 7 days |
| Maximum news entries | 50 |
| CPU minimum budget subsidy | 5,000,000 |
| Talent level 6 distribution | 0.5% |
| Club-partnership development bonus | 5% |
| Club-partnership minimum duration | 30 matches |

These are balancing defaults, not hard requirements. Administrators can adjust them for the size and economy of their game world.

## Scheduled jobs

The game relies on recurring jobs. Available jobs include:

- open-match simulation;
- open-transfer execution;
- CPU transfer activity;
- market-value recalculation;
- player transfer-list maintenance;
- user-inactivity checks;
- league-statistics updates;
- stadium construction and training-camp processing;
- CPU youth generation, transfers, and matches;
- scouting matchday processing;
- automatic training plans;
- club-staff salary processing;
- manager salary and profile processing;
- CPU manager replacement;
- manager missions and other feature-specific maintenance.

Jobs can be executed through the administration area or the protected webservice endpoints. Protect every remote job endpoint with a strong secret and never publish that secret.

## Language

The README is written in English for GitHub.

The actively maintained game language for Version 6.0.0 is **German**. Legacy English and Spanish language files may still exist in the source tree, but they are not guaranteed to contain the complete or current Version 6.0.0 feature set. Translation synchronization is planned for a later stage.

When adding or changing game text:

- update the German XML language files first;
- keep XML valid and UTF-8 encoded;
- avoid ampersands in user-facing XML messages and use words such as `und` instead;
- do not introduce the visible text `&amp;` into game messages.

## Security

Before publishing or deploying the repository:

- never commit production database credentials;
- never commit API keys, job-execution secrets, mail credentials, or payment credentials;
- do not publish production configuration files;
- exclude uploaded user content, generated caches, backups, and runtime logs;
- remove database dumps containing personal data;
- keep administration accounts and job endpoints protected;
- disable debug output in production;
- use HTTPS;
- restrict filesystem permissions to the minimum required;
- review legacy social-login and payment integrations before enabling them;
- rotate any secret that has previously been included in an archive or commit.

A public repository should provide a sanitized configuration example rather than a live `config.inc.php`.

## License and credits

The project is based on **OpenWebSoccer-Sim** and the PHP 8 continuation available at:

https://github.com/witai2212/open-websoccer-PHP-8

Source-file headers identify the OpenWebSoccer-Sim code as licensed under the **GNU Lesser General Public License, version 3 or later**. Preserve all original copyright and license notices.

Bundled third-party libraries remain subject to their own licenses.

Football club names, competition names, logos, images, and trademarks belong to their respective owners. Verify that all included media and data may legally be redistributed before publishing a release.

## Project status

Version **6.0.0** is an extensive custom continuation with many interconnected systems. Administrators should treat major updates as database-and-code releases, apply migrations carefully, and validate the game world with the administration health tools after every deployment.
