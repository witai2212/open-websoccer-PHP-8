// CM23 | 2026-09-07 | Revision 1 | Task 1022
$(function() {
	
	/**
	 * Grades line chart
	 */
	$('#grades').each(function(index, component) {
		$.plot("#grades", [$(component).data("series")], {
			   xaxis: {
				   tickSize: 1, 
				   tickDecimals: 0
			   },
			   yaxis: {
				   min: 1,
				   max: 4
			   },
			   lines: { show: true },
			   points: { show: true },
			   grid: {
					hoverable: true
				},
		});
	});
	
	var previousPoint = null;
	$("#grades").bind("plothover", function (event, pos, item) {

		if (item) {
			if (previousPoint != item.dataIndex) {

				previousPoint = item.dataIndex;

				$("#graphtooltip").remove();
				y = item.datapoint[1].toFixed(2);

				showGrpahTooltip(item.pageX, item.pageY, y);
			}
		} else {
			$("#graphtooltip").remove();
			previousPoint = null;
		}
	});
	
	var showGrpahTooltip = function(x, y, contents) {
		$("<div id='graphtooltip'>" + contents + "</div>").css({
			position: "absolute",
			display: "none",
			top: y - 30,
			left: x + 5,
			border: "1px solid #fdd",
			padding: "2px",
			"background-color": "#fee",
			opacity: 0.80
		}).appendTo("body").fadeIn(200);
	};

	/**
	 * Player market-value history (Task 1022).
	 */
	$('#marketvalueHistorySource').each(function(index, source) {
		var statisticTab = $('#statistic');
		if (!statisticTab.length) {
			return;
		}

		var series = $.parseJSON($(source).attr('data-series'));
		var labels = $.parseJSON($(source).attr('data-labels').replace(/'/g, '"'));
		var currency = $(source).attr('data-currency') || '';
		if (!series.length) {
			return;
		}

		var chartBlock = $('<div class="cm23-marketvalue-history"><h4>Marktwertentwicklung</h4><div id="marketvalueHistoryChart" style="width: 600px; height: 300px; margin-left: 30px; margin-bottom: 30px"></div></div>');
		statisticTab.append(chartBlock);

		var ticks = [];
		var step = Math.max(1, Math.ceil(labels.length / 6));
		for (var i = 0; i < labels.length; i += step) {
			ticks.push([i + 1, labels[i]]);
		}
		if (labels.length > 1 && ticks[ticks.length - 1][0] !== labels.length) {
			ticks.push([labels.length, labels[labels.length - 1]]);
		}

		$.plot('#marketvalueHistoryChart', [series], {
			xaxis: {
				ticks: ticks,
				tickDecimals: 0
			},
			yaxis: {
				tickDecimals: 0
			},
			lines: { show: true },
			points: { show: true },
			grid: { hoverable: true }
		});

		$('#marketvalueHistoryChart').bind('plothover', function(event, pos, item) {
			if (item) {
				if (previousPoint != 'marketvalue-' + item.dataIndex) {
					previousPoint = 'marketvalue-' + item.dataIndex;
					$('#graphtooltip').remove();
					var dateLabel = labels[item.dataIndex] || '';
					var valueLabel = Math.round(item.datapoint[1]).toLocaleString('de-DE');
					showGrpahTooltip(item.pageX, item.pageY, dateLabel + ': ' + valueLabel + ' ' + currency);
				}
			} else {
				$('#graphtooltip').remove();
				previousPoint = null;
			}
		});
	});
	
	/**
	 * Initialize Pie Charts
	 */
	$(document).ajaxComplete(function() {
		$('.pieChart').each(function(index, component) {
			initPieChart(component);
		});
	});
	
	var initPieChart = function(component) {
		$.plot($(component), $(component).data('series'), {
		    series: {
		        pie: {
		            show: true,
		            radius: 1,
		            label: {
		                show: true,
		                radius: 0.8,
		                formatter: function(label, series){
	                        return '<div style="font-size:8pt;text-align:center;padding:2px;color:white;">'+ Math.round(series.percent)+'%</div>';
	                    },
		                background: {
		                    opacity: 0.5
		                }
		            }
		        }
		    },
		    legend: {
		        show: true,
		        position: 'se',
		        container: $(component).parent().find(".pieChartLabel")
		    }
		});
	};
	
	/**
	 * League history
	 */
	$('#leaguehistorychart').each(function(index, component) {
		$.plot("#leaguehistorychart", [$(component).data("series")], {
			   xaxis: {
				   tickSize: 1, 
				   tickDecimals: 0
			   },
			yaxis: {
				   min: 1,
				   tickDecimals: 0,
				   max: $(component).data("maxpos"),
				   transform: function(v) {
				        return -v;
				    },
				    inverseTransform: function(v) {
				        return -v;
				    }
			   },
			   lines: { show: true },
			   points: { show: true },
			   grid: {
					hoverable: true
				},
		});
	});
	$("#leaguehistorychart").bind("plothover", function (event, pos, item) {

		if (item) {
			if (previousPoint != item.dataIndex) {

				previousPoint = item.dataIndex;

				$("#graphtooltip").remove();
				y = item.datapoint[1];

				showGrpahTooltip(item.pageX, item.pageY, y);
			}
		} else {
			$("#graphtooltip").remove();
			previousPoint = null;
		}
	});
	

});
