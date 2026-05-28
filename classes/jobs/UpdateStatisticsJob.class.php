<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License
as published by the Free Software Foundation, either version 3 of
the License, or any later version.

OpenWebSoccer-Sim is distributed in the hope that it will be
useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with OpenWebSoccer-Sim.
If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Compute and update league statistics in order to support tables of all existing seasons.
 *
 * @author Ingo Hofmann
 */
class UpdateStatisticsJob extends AbstractJob {
    
    /**
     * @see AbstractJob::execute()
     */
    public function execute() {
        
        $pointsWin = 3;
        
        $dbPrefix = $this->_websoccer->getConfig('db_prefix');
        
        $statisticTable = $dbPrefix . '_team_league_statistics';
        $clubTable      = $dbPrefix . '_verein';
        $matchTable     = $dbPrefix . '_spiel';
        $seasonTable    = $dbPrefix . '_saison';
        $playerTable    = $dbPrefix . '_spieler';
        
        /*
         * Build/update the cached league statistics.
         *
         * REPLACE INTO is kept from the original implementation:
         * - existing rows with the same (team_id, season_id) are replaced
         * - new rows are inserted
         */
        $query = "REPLACE INTO $statisticTable (
			team_id,
			season_id,
			total_points,
			total_goals,
			total_goalsreceived,
			total_goalsdiff,
			total_wins,
			total_draws,
			total_losses,
			home_points,
			home_goals,
			home_goalsreceived,
			home_goalsdiff,
			home_wins,
			home_draws,
			home_losses,
			guest_points,
			guest_goals,
			guest_goalsreceived,
			guest_goalsdiff,
			guest_wins,
			guest_draws,
			guest_losses
		)
		SELECT
			team_id,
			season_id,
			(home_wins * $pointsWin + home_draws + guest_wins * $pointsWin + guest_draws) AS total_points,
			(home_goals + guest_goals) AS total_goals,
			(home_goalsreceived + guest_goalsreceived) AS total_goalsreceived,
			(home_goals + guest_goals - home_goalsreceived - guest_goalsreceived) AS total_goalsdiff,
			(home_wins + guest_wins) AS total_wins,
			(home_draws + guest_draws) AS total_draws,
			(home_losses + guest_losses) AS total_losses,
			(home_wins * $pointsWin + home_draws) AS home_points,
			home_goals,
			home_goalsreceived,
			(home_goals - home_goalsreceived) AS home_goalsdiff,
			home_wins,
			home_draws,
			home_losses,
			(guest_wins * $pointsWin + guest_draws) AS guest_points,
			guest_goals,
			guest_goalsreceived,
			(guest_goals - guest_goalsreceived) AS guest_goalsdiff,
			guest_wins,
			guest_draws,
			guest_losses
		FROM (
			SELECT
				C.id AS team_id,
				M.saison_id AS season_id,
				
				SUM(
					CASE
						WHEN M.home_verein = C.id
							AND M.home_tore > M.gast_tore
						THEN 1
						ELSE 0
					END
				) AS home_wins,
				
				SUM(
					CASE
						WHEN M.home_verein = C.id
							AND M.home_tore < M.gast_tore
						THEN 1
						ELSE 0
					END
				) AS home_losses,
				
				SUM(
					CASE
						WHEN M.home_verein = C.id
							AND M.home_tore = M.gast_tore
						THEN 1
						ELSE 0
					END
				) AS home_draws,
				
				SUM(
					CASE
						WHEN M.home_verein = C.id
						THEN M.home_tore
						ELSE 0
					END
				) AS home_goals,
				
				SUM(
					CASE
						WHEN M.home_verein = C.id
						THEN M.gast_tore
						ELSE 0
					END
				) AS home_goalsreceived,
				
				SUM(
					CASE
						WHEN M.gast_verein = C.id
							AND M.gast_tore > M.home_tore
						THEN 1
						ELSE 0
					END
				) AS guest_wins,
				
				SUM(
					CASE
						WHEN M.gast_verein = C.id
							AND M.gast_tore < M.home_tore
						THEN 1
						ELSE 0
					END
				) AS guest_losses,
				
				SUM(
					CASE
						WHEN M.gast_verein = C.id
							AND M.home_tore = M.gast_tore
						THEN 1
						ELSE 0
					END
				) AS guest_draws,
				
				SUM(
					CASE
						WHEN M.gast_verein = C.id
						THEN M.gast_tore
						ELSE 0
					END
				) AS guest_goals,
				
				SUM(
					CASE
						WHEN M.gast_verein = C.id
						THEN M.home_tore
						ELSE 0
					END
				) AS guest_goalsreceived
				
			FROM $clubTable AS C
			INNER JOIN $matchTable AS M
				ON M.home_verein = C.id
				OR M.gast_verein = C.id
			INNER JOIN $seasonTable AS S
				ON S.id = M.saison_id
				
			WHERE M.spieltyp = 'Ligaspiel'
				AND M.saison_id > 0
				AND M.berechnet = '1'
				
			GROUP BY C.id, M.saison_id
		) AS matches";
        
        $this->_db->executeQuery($query);
        
        
        /*
         * Update team strengths.
         *
         * - Only active players are counted.
         * - Teams without active players get strength = 0.
         * - AVG(w_staerke) is rounded explicitly before writing to the integer strength field.
         */
        $strengthQuery = "UPDATE $clubTable AS C
			LEFT JOIN (
				SELECT
					verein_id,
					ROUND(AVG(w_staerke), 0) AS strength_avg
				FROM $playerTable
				WHERE status = '1'
					AND verein_id IS NOT NULL
					AND verein_id > 0
				GROUP BY verein_id
			) AS R
				ON R.verein_id = C.id
			SET C.strength = COALESCE(R.strength_avg, 0)";
        
        $this->_db->executeQuery($strengthQuery);
    }
}