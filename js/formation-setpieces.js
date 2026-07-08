/**
 * Set-piece selector helper.
 * Keeps the original formation JavaScript untouched and only sorts/mirrors
 * the free-kick/corner dropdowns after lineup changes.
 *
 * Corner score (ECK) is calculated from passing, creativity and free-kick.
 * Default behavior:
 * - if no corner taker is saved yet, select the best available ECK player.
 * - if a corner taker is saved, show that saved player again after reload.
 * - if the saved corner taker equals the free-kick taker from the old duplicated
 *   default and a better ECK player exists, replace it with the best ECK player.
 */
(function($) {
	if (!$) {
		return;
	}

	$(function() {
		var refreshTimer = null;
		var refreshing = false;
		var observer = null;

		var parseScore = function(value) {
			if (typeof value === 'string') {
				value = value.replace(',', '.');
			}
			var parsed = parseFloat(value);
			return isNaN(parsed) ? 0 : parsed;
		};

		var getPlayerScore = function(playerId, scoreKey) {
			var playerInfo = $('#playerinfo' + playerId);
			if (!playerInfo.length) {
				return 0;
			}

			var value = playerInfo.attr('data-' + scoreKey);
			if (typeof value === 'undefined' || value === '') {
				value = playerInfo.data(scoreKey);
			}

			return parseScore(value);
		};

		var stripSetPieceSuffix = function(text) {
			return $.trim(String(text || '').replace(/\s*\((FS|ECK)\s*[^)]*\)\s*$/i, ''));
		};

		var formatScore = function(value) {
			value = parseScore(value);
			if (Math.round(value) === value) {
				return String(value);
			}
			return String(Math.round(value));
		};

		var getOptionByValue = function(selectElement, playerId) {
			return selectElement.find('option').filter(function() {
				return $(this).val() === String(playerId);
			}).first();
		};

		var optionExists = function(selectElement, playerId) {
			return getOptionByValue(selectElement, playerId).length > 0;
		};

		var ensureEmptyOption = function(selectElement) {
			if (!selectElement.find('option').filter(function() { return $(this).val() === ''; }).length) {
				selectElement.prepend('<option value=""></option>');
			}
		};

		var getPreselectValue = function(selectElement) {
			var preselectedValue = selectElement.attr('data-preselect');
			if (typeof preselectedValue === 'undefined' || preselectedValue === '') {
				preselectedValue = selectElement.data('preselect');
			}
			return preselectedValue ? String(preselectedValue) : '';
		};

		var applyPreselect = function(selectElement) {
			var preselectedValue = getPreselectValue(selectElement);
			if (preselectedValue && optionExists(selectElement, preselectedValue)) {
				selectElement.val(preselectedValue);
				return true;
			}
			return false;
		};

		var normalizeOption = function(option, scoreKey, label) {
			var optionElement = $(option);
			var playerId = optionElement.val();
			if (!playerId) {
				return;
			}

			var score = optionElement.attr('data-' + scoreKey);
			if (typeof score === 'undefined' || score === '') {
				score = getPlayerScore(playerId, scoreKey);
			}
			score = parseScore(score);

			var baseName = optionElement.data('base-name');
			if (!baseName) {
				baseName = stripSetPieceSuffix(optionElement.text());
				optionElement.data('base-name', baseName);
			}

			optionElement.attr('data-' + scoreKey, String(score));
			optionElement.text(baseName + ' (' + label + ' ' + formatScore(score) + ')');
		};

		var getBestPlayerValue = function(selectElement, scoreKey) {
			var bestValue = '';
			var bestScore = -1;
			selectElement.find('option').each(function() {
				var optionElement = $(this);
				var value = optionElement.val();
				if (!value) {
					return;
				}
				var score = parseScore(optionElement.attr('data-' + scoreKey));
				if (score > bestScore) {
					bestScore = score;
					bestValue = value;
				}
			});
			return bestValue;
		};

		var getSelectedScore = function(selectElement, scoreKey) {
			var value = selectElement.val();
			if (!value) {
				return 0;
			}
			return parseScore(getOptionByValue(selectElement, value).attr('data-' + scoreKey));
		};

		var sortSelect = function(selectElement, scoreKey, label, options) {
			if (!selectElement.length) {
				return;
			}
			options = options || {};

			ensureEmptyOption(selectElement);
			var selectedValue = selectElement.val();
			selectElement.find('option').each(function() {
				normalizeOption(this, scoreKey, label);
			});

			var emptyOptions = selectElement.find('option').filter(function() {
				return $(this).val() === '';
			}).get();
			var playerOptions = selectElement.find('option').filter(function() {
				return $(this).val() !== '';
			}).get();

			playerOptions.sort(function(a, b) {
				var scoreA = parseScore($(a).attr('data-' + scoreKey));
				var scoreB = parseScore($(b).attr('data-' + scoreKey));
				if (scoreA === scoreB) {
					var nameA = stripSetPieceSuffix($(a).text()).toLowerCase();
					var nameB = stripSetPieceSuffix($(b).text()).toLowerCase();
					if (nameA < nameB) {
						return -1;
					}
					if (nameA > nameB) {
						return 1;
					}
					return 0;
				}
				return scoreB - scoreA;
			});

			selectElement.empty();
			if (emptyOptions.length) {
				selectElement.append(emptyOptions[0]);
			} else {
				selectElement.append('<option value=""></option>');
			}
			$.each(playerOptions, function(index, option) {
				selectElement.append(option);
			});

			var preselectApplied = applyPreselect(selectElement);
			if (!preselectApplied && selectedValue && optionExists(selectElement, selectedValue)) {
				selectElement.val(selectedValue);
			}

			if (options.autoSelectBest && !selectElement.data('userselected')) {
				var bestValue = getBestPlayerValue(selectElement, scoreKey);
				var freekickPreselect = getPreselectValue($('#freekickplayer'));
				var cornerPreselect = getPreselectValue(selectElement);
				var currentValue = selectElement.val();
				var selectedLooksLikeOldFreekickDefault = cornerPreselect && freekickPreselect && String(cornerPreselect) === String(freekickPreselect);

				if (bestValue && (!cornerPreselect || !preselectApplied)) {
					selectElement.val(bestValue);
				} else if (bestValue && selectedLooksLikeOldFreekickDefault && String(bestValue) !== String(currentValue)) {
					var bestScore = parseScore(getOptionByValue(selectElement, bestValue).attr('data-' + scoreKey));
					var currentScore = getSelectedScore(selectElement, scoreKey);
					if (bestScore > currentScore) {
						selectElement.val(bestValue);
					}
				}
			}
		};

		var mirrorLineupToSetPieceSelect = function(sourceSelect, targetSelect, targetScoreKey) {
			if (!sourceSelect.length || !targetSelect.length) {
				return;
			}

			ensureEmptyOption(targetSelect);
			var targetValue = targetSelect.val();
			var validPlayerIds = {};

			sourceSelect.find('option').each(function() {
				var sourceOption = $(this);
				var playerId = sourceOption.val();
				if (!playerId) {
					return;
				}
				validPlayerIds[String(playerId)] = true;

				var targetOption = getOptionByValue(targetSelect, playerId);
				if (!targetOption.length) {
					targetOption = $('<option></option>').val(playerId);
					targetSelect.append(targetOption);
				}

				var baseName = sourceOption.data('base-name');
				if (!baseName) {
					baseName = stripSetPieceSuffix(sourceOption.text());
				}

				targetOption
					.data('base-name', baseName)
					.attr('data-' + targetScoreKey, String(getPlayerScore(playerId, targetScoreKey)))
					.text(baseName);
			});

			targetSelect.find('option').filter(function() {
				var value = $(this).val();
				return value !== '' && !validPlayerIds[String(value)];
			}).remove();

			if (targetValue && optionExists(targetSelect, targetValue)) {
				targetSelect.val(targetValue);
			}
		};

		var refreshSetPieceSelectors = function() {
			if (refreshing) {
				return;
			}
			refreshing = true;
			var freekickSelect = $('#freekickplayer');
			var cornerSelect = $('#cornerplayer');

			// The core formation script only fills the free-kick dropdown on the
			// normal formation page. Mirror the current starting lineup to the
			// corner dropdown and then sort both lists independently.
			mirrorLineupToSetPieceSelect(freekickSelect, cornerSelect, 'corner');
			sortSelect(freekickSelect, 'freekick', 'FS', { autoSelectBest: false });
			sortSelect(cornerSelect, 'corner', 'ECK', { autoSelectBest: true });

			window.setTimeout(function() {
				refreshing = false;
			}, 0);
		};

		var scheduleRefresh = function() {
			if (refreshTimer) {
				window.clearTimeout(refreshTimer);
			}
			refreshTimer = window.setTimeout(refreshSetPieceSelectors, 0);
		};

		$(document).on('change', '#freekickplayer, #cornerplayer', function() {
			$(this).data('userselected', '1');
		});

		refreshSetPieceSelectors();

		var freekickElement = $('#freekickplayer').get(0);
		if (freekickElement && typeof window.MutationObserver !== 'undefined') {
			observer = new window.MutationObserver(function() {
				if (!refreshing) {
					scheduleRefresh();
				}
			});
			observer.observe(freekickElement, { childList: true });
		}

		// Fallback for older browsers/jQuery setups: refresh after the known UI actions.
		$(document).on('click dblclick drop', '.playerAddToPitchLinkItem, .playerRemoveLink, .positionPlayerRemove, .clearAllBtn, .position, .playerDraggable', function() {
			scheduleRefresh();
		});
	});
})(window.jQuery);
