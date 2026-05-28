/**
 * Set-piece selector helper.
 * Keeps the original formation JavaScript untouched and only sorts/mirrors
 * the free-kick/corner dropdowns after lineup changes.
 */
(function($) {
	if (!$) {
		return;
	}

	$(function() {
		var refreshTimer = null;
		var refreshing = false;
		var observer = null;

		var parseFreekick = function(value) {
			if (typeof value === 'string') {
				value = value.replace(',', '.');
			}
			var parsed = parseFloat(value);
			return isNaN(parsed) ? 0 : parsed;
		};

		var getPlayerFreekick = function(playerId) {
			var playerInfo = $('#playerinfo' + playerId);
			if (!playerInfo.length) {
				return 0;
			}
			return parseFreekick(playerInfo.attr('data-freekick') || playerInfo.data('freekick'));
		};

		var stripSetPieceSuffix = function(text) {
			return $.trim(String(text || '').replace(/\s*\(FS\s*[^)]*\)\s*$/i, ''));
		};

		var formatFreekick = function(value) {
			value = parseFreekick(value);
			if (Math.round(value) === value) {
				return String(value);
			}
			return String(value);
		};

		var normalizeOption = function(option) {
			var optionElement = $(option);
			var playerId = optionElement.val();
			if (!playerId) {
				return;
			}

			var freekick = parseFreekick(optionElement.attr('data-freekick'));
			if (!freekick) {
				freekick = getPlayerFreekick(playerId);
			}

			var baseName = optionElement.data('base-name');
			if (!baseName) {
				baseName = stripSetPieceSuffix(optionElement.text());
				optionElement.data('base-name', baseName);
			}

			optionElement.attr('data-freekick', String(freekick));
			optionElement.text(baseName + ' (FS ' + formatFreekick(freekick) + ')');
		};

		var optionExists = function(selectElement, playerId) {
			return selectElement.find('option').filter(function() {
				return $(this).val() === String(playerId);
			}).length > 0;
		};

		var ensureEmptyOption = function(selectElement) {
			if (!selectElement.find('option').filter(function() { return $(this).val() === ''; }).length) {
				selectElement.prepend('<option value=""></option>');
			}
		};

		var sortSelect = function(selectElement) {
			if (!selectElement.length) {
				return;
			}

			ensureEmptyOption(selectElement);
			var selectedValue = selectElement.val();
			selectElement.find('option').each(function() {
				normalizeOption(this);
			});

			var emptyOptions = selectElement.find('option').filter(function() {
				return $(this).val() === '';
			}).get();
			var playerOptions = selectElement.find('option').filter(function() {
				return $(this).val() !== '';
			}).get();

			playerOptions.sort(function(a, b) {
				var freekickA = parseFreekick($(a).attr('data-freekick'));
				var freekickB = parseFreekick($(b).attr('data-freekick'));
				if (freekickA === freekickB) {
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
				return freekickB - freekickA;
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

			if (selectedValue && optionExists(selectElement, selectedValue)) {
				selectElement.val(selectedValue);
			} else {
				selectElement.val('');
			}
		};

		var mirrorFreekicksToCorners = function() {
			var freekickSelect = $('#freekickplayer');
			var cornerSelect = $('#cornerplayer');
			if (!freekickSelect.length || !cornerSelect.length) {
				return;
			}

			ensureEmptyOption(cornerSelect);
			var cornerValue = cornerSelect.val();
			var validPlayerIds = {};

			freekickSelect.find('option').each(function() {
				var sourceOption = $(this);
				var playerId = sourceOption.val();
				if (!playerId) {
					return;
				}
				validPlayerIds[String(playerId)] = true;
				normalizeOption(sourceOption);

				var targetOption = cornerSelect.find('option').filter(function() {
					return $(this).val() === String(playerId);
				}).first();

				if (!targetOption.length) {
					targetOption = $('<option></option>').val(playerId);
					cornerSelect.append(targetOption);
				}

				targetOption
					.data('base-name', sourceOption.data('base-name'))
					.attr('data-freekick', sourceOption.attr('data-freekick'))
					.text(sourceOption.text());
			});

			cornerSelect.find('option').filter(function() {
				var value = $(this).val();
				return value !== '' && !validPlayerIds[String(value)];
			}).remove();

			if (cornerValue && optionExists(cornerSelect, cornerValue)) {
				cornerSelect.val(cornerValue);
			}
		};

		var refreshSetPieceSelectors = function() {
			if (refreshing) {
				return;
			}
			refreshing = true;
			mirrorFreekicksToCorners();
			sortSelect($('#freekickplayer'));
			sortSelect($('#cornerplayer'));
			refreshing = false;
		};

		var scheduleRefresh = function() {
			if (refreshTimer) {
				window.clearTimeout(refreshTimer);
			}
			refreshTimer = window.setTimeout(refreshSetPieceSelectors, 0);
		};

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
