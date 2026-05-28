/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

******************************************************/
$(function() {

	var stadiumGraph = {
		CONFIG: {
			BACKGROUND: '#f7f7f7',
			BOWL_MAX: '#e5e5e5',
			SEATS: '#4f8db3',
			STANDING: '#6db36d',
			VIP: '#c99832',
			PITCH: '#72a66a',
			PITCH_LINE: 'rgba(255,255,255,0.75)',
			TEXT: '#333333',
			TEXT_MUTED: '#777777',
			LABEL_BG: 'rgba(255,255,255,0.88)',
			MAX_SIDE_DEPTH: 85,
			MAX_GRAND_DEPTH: 78,
			MAX_VIP_HEIGHT: 28
		},

		canvas: null,
		ctx: null,

		drawStadium: function(elementId) {
			this.canvas = document.getElementById(elementId);
			if (!this.canvas || !this.canvas.getContext) {
				return;
			}

			this.ctx = this.canvas.getContext('2d');
			this._clear();
			this._drawBackground();
			this._drawMaximumBowl();
			this._drawActualBowl();
			this._drawPitch();
			this._drawLabels();
		},

		_clear: function() {
			this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
		},

		_drawBackground: function() {
			var ctx = this.ctx;
			ctx.fillStyle = this.CONFIG.BACKGROUND;
			ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
		},

		_drawMaximumBowl: function() {
			this._drawBowlParts(100, 100, 100, this.CONFIG.BOWL_MAX, this.CONFIG.BOWL_MAX, this.CONFIG.BOWL_MAX, false);
		},

		_drawActualBowl: function() {
			var ratioSide = this._boundedNumber($(this.canvas).data('ratioside'));
			var ratioGrand = this._boundedNumber($(this.canvas).data('ratiogrand'));
			var ratioVip = this._boundedNumber($(this.canvas).data('ratiovip'));

			this._drawBowlParts(ratioSide, ratioGrand, ratioVip, this.CONFIG.SEATS, this.CONFIG.STANDING, this.CONFIG.VIP, true);
		},

		_drawBowlParts: function(ratioSide, ratioGrand, ratioVip, seatColor, standingColor, vipColor, actual) {
			var ctx = this.ctx;
			var width = this.canvas.width;
			var height = this.canvas.height;
			var padding = 26;
			var sideDepth = Math.max(4, this.CONFIG.MAX_SIDE_DEPTH * ratioSide / 100);
			var grandDepth = Math.max(4, this.CONFIG.MAX_GRAND_DEPTH * ratioGrand / 100);
			var vipHeight = Math.max(0, this.CONFIG.MAX_VIP_HEIGHT * ratioVip / 100);
			var xLeft = padding + this.CONFIG.MAX_SIDE_DEPTH - sideDepth;
			var xRight = width - padding - this.CONFIG.MAX_SIDE_DEPTH;
			var yTop = padding + this.CONFIG.MAX_GRAND_DEPTH - grandDepth;
			var yBottom = height - padding - this.CONFIG.MAX_GRAND_DEPTH;
			var innerLeft = padding + this.CONFIG.MAX_SIDE_DEPTH;
			var innerRight = width - padding - this.CONFIG.MAX_SIDE_DEPTH;
			var innerTop = padding + this.CONFIG.MAX_GRAND_DEPTH;
			var innerBottom = height - padding - this.CONFIG.MAX_GRAND_DEPTH;

			ctx.save();
			ctx.fillStyle = seatColor;

			this._roundedRect(innerLeft, yTop, innerRight - innerLeft, grandDepth, 14, true, false);
			this._roundedRect(innerLeft, yBottom, innerRight - innerLeft, grandDepth, 14, true, false);
			this._roundedRect(xLeft, innerTop, sideDepth, innerBottom - innerTop, 14, true, false);
			this._roundedRect(xRight, innerTop, sideDepth, innerBottom - innerTop, 14, true, false);

			if (actual) {
				var standingGrandRatio = this._boundedNumber($(this.canvas).data('standingratiogrand'));
				var standingSideRatio = this._boundedNumber($(this.canvas).data('standingratioside'));
				var standingGrandWidth = (innerRight - innerLeft) * standingGrandRatio / 100;
				var standingSideHeight = (innerBottom - innerTop) * standingSideRatio / 100;

				ctx.fillStyle = standingColor;
				this._roundedRect(innerLeft + ((innerRight - innerLeft) - standingGrandWidth) / 2, yBottom, standingGrandWidth, grandDepth, 10, true, false);
				this._roundedRect(xLeft, innerTop + ((innerBottom - innerTop) - standingSideHeight) / 2, sideDepth, standingSideHeight, 10, true, false);
				this._roundedRect(xRight, innerTop + ((innerBottom - innerTop) - standingSideHeight) / 2, sideDepth, standingSideHeight, 10, true, false);

				if (vipHeight > 1) {
					ctx.fillStyle = vipColor;
					this._roundedRect(innerLeft + 54, innerTop - vipHeight - 7, (innerRight - innerLeft) - 108, vipHeight, 10, true, false);
				}
			}
			ctx.restore();
		},

		_drawPitch: function() {
			var ctx = this.ctx;
			var pitch = {
				x: 190,
				y: 132,
				w: this.canvas.width - 380,
				h: this.canvas.height - 264
			};

			ctx.save();
			ctx.fillStyle = this.CONFIG.PITCH;
			this._roundedRect(pitch.x, pitch.y, pitch.w, pitch.h, 24, true, false);
			ctx.strokeStyle = this.CONFIG.PITCH_LINE;
			ctx.lineWidth = 2;
			this._roundedRect(pitch.x + 12, pitch.y + 12, pitch.w - 24, pitch.h - 24, 12, false, true);
			ctx.beginPath();
			ctx.moveTo(pitch.x + pitch.w / 2, pitch.y + 12);
			ctx.lineTo(pitch.x + pitch.w / 2, pitch.y + pitch.h - 12);
			ctx.stroke();
			ctx.beginPath();
			ctx.arc(pitch.x + pitch.w / 2, pitch.y + pitch.h / 2, 32, 0, Math.PI * 2, false);
			ctx.stroke();
			ctx.restore();
		},

		_drawLabels: function() {
			var sideLabel = $(this.canvas).data('labelside') + ' · ' + $(this.canvas).data('sidecapacity');
			var grandLabel = $(this.canvas).data('labelgrand') + ' · ' + $(this.canvas).data('grandcapacity');
			var vipLabel = $(this.canvas).data('labelvip') + ' · ' + $(this.canvas).data('vipcapacity');

			this._drawLabel(grandLabel, this.canvas.width / 2, 64, 'center');
			this._drawLabel(sideLabel, 105, this.canvas.height / 2, 'center');
			this._drawLabel(vipLabel, this.canvas.width / 2, 108, 'center');
		},

		_drawLabel: function(text, x, y, align) {
			var ctx = this.ctx;
			ctx.save();
			ctx.font = 'bold 12px Arial';
			var metrics = ctx.measureText(text);
			var boxWidth = metrics.width + 18;
			var boxHeight = 24;
			var startX = align == 'center' ? x - boxWidth / 2 : x;
			var startY = y - boxHeight / 2;

			ctx.fillStyle = this.CONFIG.LABEL_BG;
			this._roundedRect(startX, startY, boxWidth, boxHeight, 12, true, false);
			ctx.fillStyle = this.CONFIG.TEXT;
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(text, x, y + 1);
			ctx.restore();
		},

		_roundedRect: function(x, y, width, height, radius, fill, stroke) {
			var ctx = this.ctx;
			if (width < 0) {
				x += width;
				width = Math.abs(width);
			}
			if (height < 0) {
				y += height;
				height = Math.abs(height);
			}
			radius = Math.min(radius, width / 2, height / 2);
			ctx.beginPath();
			ctx.moveTo(x + radius, y);
			ctx.lineTo(x + width - radius, y);
			ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
			ctx.lineTo(x + width, y + height - radius);
			ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
			ctx.lineTo(x + radius, y + height);
			ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
			ctx.lineTo(x, y + radius);
			ctx.quadraticCurveTo(x, y, x + radius, y);
			ctx.closePath();
			if (fill) {
				ctx.fill();
			}
			if (stroke) {
				ctx.stroke();
			}
		},

		_boundedNumber: function(value) {
			value = parseFloat(value);
			if (isNaN(value)) {
				value = 0;
			}
			return Math.max(0, Math.min(100, value));
		}
	};

	stadiumGraph.drawStadium('stadium');
});
