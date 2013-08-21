define('vpt.models.datepicker', [], function() {

	function Datepicker(params) {
		this.language = params.language;
		this.controller = params.controller;
		this.method = params.method;

		this.init();
	}

	// TODO: the callback chains here require a specific order, not good, change this
	Datepicker.prototype = {
		init: function() {
			this.specialDates = {};
			this.collectedMonths = {};

			this.__monthCollectRadius = 2;
		},
		getStatus: function(day) {
			var fullDay = $.datepicker.formatDate('yy-mm-dd', day);

			if (fullDay in this.specialDates) {
				return this.specialDates[fullDay];
			}
		},
		collectData: function(year, month) {
			var beginTimestamp,
				endTimestamp,
				maxDate,
				minDate,
				dates,
				datesLength,
				i;

			dates = this.getMonthsToCollect(year, month);
			datesLength = dates.length;

			if (datesLength) {

				maxDate = new Date(Math.max.apply(null, dates));
				maxDate.setMonth(maxDate.getMonth() + 1);
				endTimestamp = maxDate.getTime() / 1000 - maxDate.getTimezoneOffset() * 60;
				minDate = new Date(Math.min.apply(null, dates));
				beginTimestamp = minDate.getTime() / 1000 - minDate.getTimezoneOffset() *
					60;

				for (i = 0; i < datesLength; i++) {
					this.setCollected(dates[i].getFullYear(), dates[i].getMonth() + 1);
				}

				return $.nirvana.sendRequest({
					controller: this.controller,
					method: this.method,
					type: 'POST',
					data: {
						'langCode': this.language,
						'beginTimestamp': beginTimestamp,
						'endTimestamp': endTimestamp
					},
					callback: $.proxy(function(response) {
						$.extend(this.specialDates, response['calendarData']);
					}, this)
				});
			}
		},
		setCollected: function(theYear, theMonth) {
			this.collectedMonths[this.getMonthKey(theYear, theMonth)] = true;
		},
		isCollected: function(theYear, theMonth) {
			if (this.getMonthKey(theYear, theMonth) in this.collectedMonths) {
				return true;
			}
			return false;
		},
		getMonthsToCollect: function(theYear, theMonth) {
			var out,
				tmpDate,
				i;

			out = [];
			tmpDate = new Date(theYear, theMonth - 1);

			for (i = -this.__monthCollectRadius; i <= this.__monthCollectRadius; i++) {
				tmpDate.setFullYear(theYear);
				tmpDate.setMonth(theMonth - 1 + i);
				if (!this.isCollected(tmpDate.getFullYear(), tmpDate.getMonth() + 1)) {
					out.push(new Date(tmpDate.getFullYear(), tmpDate.getMonth(), 1, 0, 0, 0, 0));
				}
			}
			return out;
		},
		getMonthKey: function(theYear, theMonth) {
			return theYear + '-' + theMonth;
		}
	};

	return Datepicker;
});
