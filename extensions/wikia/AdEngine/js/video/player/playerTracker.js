/*global define, require*/
define('ext.wikia.adEngine.video.player.playerTracker', [
	'ext.wikia.adEngine.adContext',
	'ext.wikia.adEngine.adLogicPageParams',
	'ext.wikia.adEngine.adTracker',
	'ext.wikia.adEngine.slot.slotTargeting',
	'wikia.browserDetect',
	'wikia.geo',
	'wikia.log',
	'wikia.window',
	require.optional('ext.wikia.adEngine.lookup.rubicon.rubiconVulcan'),
	require.optional('ext.wikia.adEngine.video.player.porvata.floater')
], function (adContext, pageLevel, adTracker, slotTargeting, browserDetect, geo, log, win, vulcan, floater) {
	'use strict';
	var context = adContext.getContext(),
		logGroup = 'ext.wikia.adEngine.video.player.playerTracker',
		emptyValue = {
			int: 0,
			string: '(none)',
			price: -1
		};

	function isEnabled() {
		return !!context.opts.playerTracking;
	}

	function prepareData(params, playerName, eventName, errorCode, contentType) {
		var pageLevelParams = pageLevel.getPageLevelParams(),
			canFloat = floater && floater.canFloat(params) ? 'canFloat' : '',
			floatingState = (params.floatingContext && params.floatingContext.state) || (canFloat ? 'never' : ''),
			trackingData = {
				'pv_unique_id': win.adEnginePvUID,
				'pv_number': pageLevelParams.pv,
				'country': geo.getCountryCode(),
				'skin': pageLevelParams.skin,
				'wsi': params.src ? slotTargeting.getWikiaSlotId(params.slotName, params.src) : emptyValue.string,
				'player': playerName,
				'ad_product': params.adProduct,
				'position': params.slotName || emptyValue.string,
				'event_name': eventName,
				'ad_error_code': errorCode || emptyValue.int,
				'content_type': contentType || emptyValue.string,
				'line_item_id': params.lineItemId || emptyValue.int,
				'creative_id': params.creativeId || emptyValue.int,
				'vulcan_network': emptyValue.int,
				'vulcan_advertiser': emptyValue.int,
				'vulcan_price': emptyValue.price,
				'browser': [ browserDetect.getOS(), browserDetect.getBrowser() ].join(' '),
				'additional_1': canFloat,
				'additional_2': floatingState
			},
			vulcanResponse;

		if (vulcan && params.slotName && params.adProduct === 'vulcan') {
			vulcanResponse = vulcan.getSingleResponse(params.slotName);
			trackingData['vast_id'] = [
				vulcanResponse.network || emptyValue.string,
				vulcanResponse.advertiser || emptyValue.string
			].join(':');
			trackingData['vulcan_price'] = vulcan.getBestSlotPrice(params.slotName).vulcan || emptyValue.price;
		}

		if (params.adProduct === 'veles') {
			trackingData['vast_id'] = (params.bid && params.bid.vastId) || emptyValue.string;
		}

		return trackingData;
	}

	/**
	 * @param {object} params
	 * @param {string} params.adProduct
	 * @param {string} [params.creativeId]
	 * @param {string} [params.lineItemId]
	 * @param {string} [params.slotName]
	 * @param {string} [params.src]
	 * @param {string} [params.trackingDisabled]
	 * @param {string} playerName
	 * @param {string} eventName
	 * @param {int} [errorCode]
	 * @param {string} contentType
	 */
	function track(params, playerName, eventName, errorCode, contentType) {
		// Possibility to turn off tracking from single creative/player instance
		if (!isEnabled() || params.trackingDisabled) {
			log(['track', 'Tracking disabled', params], log.levels.debug, logGroup);
			return;
		}

		if (!params.adProduct || !playerName || !eventName) {
			log(['track', 'Missing argument', params.adProduct, playerName, eventName], log.levels.debug, logGroup);
			return;
		}

		var data = prepareData(params, playerName, eventName, errorCode, contentType);

		log(['track', data], log.levels.debug, logGroup);
		adTracker.trackDW(data, 'adengplayerinfo');

		return data;
	}

	return {
		isEnabled: isEnabled,
		track: track
	};
});
