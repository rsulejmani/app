/*global require*/
require([
	'jquery',
	'wikia.window',
	'wikia.abTest',
	'wikia.log',
	'ext.wikia.recirculation.tracker',
	'ext.wikia.recirculation.utils',
	'ext.wikia.recirculation.views.incontent',
	'ext.wikia.recirculation.views.rail',
	'ext.wikia.recirculation.views.footer',
	'ext.wikia.recirculation.views.scroller',
	'ext.wikia.recirculation.views.impactFooter',
	'ext.wikia.recirculation.helpers.contentLinks',
	'ext.wikia.recirculation.helpers.fandom',
	'ext.wikia.recirculation.helpers.lateral',
	'ext.wikia.recirculation.helpers.liftigniter',
	'ext.wikia.recirculation.helpers.data',
	'ext.wikia.recirculation.helpers.cakeRelatedContent',
	'ext.wikia.recirculation.helpers.curatedContent',
	'ext.wikia.recirculation.helpers.googleMatch',
	require.optional('videosmodule.controllers.rail')
], function(
	$,
	w,
	abTest,
	log,
	tracker,
	utils,
	incontentView,
	railView,
	footerView,
	scrollerView,
	impactFooterView,
	contentLinksHelper,
	fandomHelper,
	lateralHelper,
	liftigniterHelper,
	dataHelper,
	cakeHelper,
	curatedHelper,
	googleMatchHelper,
	videosModule
) {
	var experimentName = 'RECIRCULATION_PLACEMENT',
		logGroup = 'ext.wikia.recirculation.experiments.placement',
		railContainerId = 'RECIRCULATION_RAIL',
		railSelector = '#' + railContainerId,
		group = abTest.getGroup(experimentName),
		isRail = false,
		errorHandled = false,
		footerView,
		view,
		helper;

	if (w.wgContentLanguage !== 'en') {
		if (videosModule) {
			videosModule(railSelector);
		}
		return;
	}

	switch (group) {
		case 'LI_RAIL':
			renderLiftigniterFandom();
			return;
		case 'LI_COMMUNITY':
			renderLiftigniterCommunity();
			return;
		case 'LI_BOTH':
			renderLiftigniterFandom(true);
			renderLiftigniterCommunity();
			return;
		case 'LATERAL_FANDOM':
			helper = lateralHelper();
			view = railView();
			isRail = true;
			break;
		case 'LATERAL_COMMUNITY':
			helper = lateralHelper({
				type: 'community',
				count: 3
			});
			view = incontentView();
			break;
		case 'LATERAL_SCROLLER':
			helper = lateralHelper({
				type: 'community',
				count: 12
			});
			view = scrollerView();
			break;
		case 'LINKS_SCROLLER':
			helper = contentLinksHelper({
			    count: 6,
			    extra: 6
			});
			view = scrollerView();
			break;
		case 'FANDOM_GENRE':
			helper = fandomHelper({
				type: 'vertical',
				limit: 5
			});
			view = railView();
			isRail = true;
			break;
		case 'FANDOM_TOPIC':
			helper = fandomHelper({
				type: 'community',
				limit: 5
			});
			view = railView();
			isRail = true;
			break;
		case 'FANDOM_HERO':
			helper = fandomHelper({
				type: 'hero',
				limit: 5
			});
			view = railView();
			isRail = true;
			break;
		case 'CONTROL':
			helper = fandomHelper({
				limit: 5
			});
			view = railView();
			isRail = true;
			break;
		case 'GOOGLE_INCONTENT':
			renderGoogleIncontent();
			return;
		case 'LATERAL_BOTH':
			renderBothLateralExperiments();
			return;
		case 'CAKE_RELATED_CONTENT':
			helper = cakeHelper();
			view = railView();
			isRail = true;
			break;
		case 'IMPACT_FOOTER':
			renderImpactFooter();
			return;
		default:
			return;
	}

	if (isRail) {
		utils.afterRailLoads(runRailExperiment);
	} else {
		runExperiment();
	}

	function runExperiment() {
		helper.loadData()
			.then(view.render)
			.then(view.setupTracking(experimentName))
			.fail(handleError);
	}

	function runRailExperiment() {
		var curated = curatedHelper();
		helper.loadData()
			.then(curated.injectContent)
			.then(view.render)
			.then(function($html) {
				view.setupTracking(experimentName)($html);
				curated.setupTracking($html);
			})
			.fail(handleError);
	}

	function handleError(err) {
		if (err) {
			log(err, 'info', logGroup);
		}

		// If there is an error somewhere we render the control group with no tracking
		if (errorHandled) {
			return;
		}

		errorHandled = true;
		utils.afterRailLoads(function() {
			var rail = railView();

			fandomHelper({
				limit: 5
			}).loadData()
				.then(rail.render)
				.fail(function(err) {
					// If this doesn't work, log out why. We tried our best.
					if (err) {
						log(err, 'info', logGroup);
					}
				});
		});
	}

	function renderBothLateralExperiments() {
		var incontent = incontentView();

		lateralHelper({
			type: 'community',
			count: 3
		}).loadData()
			.then(incontent.render)
			.then(incontent.setupTracking(experimentName))
			.fail(function(err) {
				// We fail silently for the in-content widget
				if (err) {
					log(err, 'info', logGroup);
				}
			});

		utils.afterRailLoads(function() {
			var rail = railView();

			lateralHelper({
				type: 'fandom',
				count: 5
			}).loadData()
				.then(rail.render)
				.then(rail.setupTracking(experimentName))
				.fail(handleError);
		});
	}

	function renderGoogleIncontent() {

	}

	function renderImpactFooter() {
		var curated = curatedHelper(),
			fView = impactFooterView(),
			rView = railView(),
			sView = scrollerView();

		contentLinksHelper({
			count: 6,
			extra: 6
		}).loadData()
			.then(sView.render)
			.then(sView.setupTracking(experimentName));

		dataHelper({}).loadData()
			.then(function(data) {
				var fandomData = {
					title: data.fandom.title,
					items: data.fandom.items.splice(0,5)
				};

				fView.render(data)
					.then(fView.setupTracking(experimentName));

				utils.afterRailLoads(function() {
					curated.injectContent(fandomData)
						.then(rView.render)
						.then(rView.setupTracking)
						.then(curatedHelper.setupTracking);
				});
			});
	}

	function renderLiftigniterFandom(waitToFetch) {
		var view = railView(),
			curated = curatedHelper(),
			helper = liftigniterHelper({
				count: 5,
				widget: 'fandom-rec'
			});

		helper.loadData(waitToFetch)
			.then(curated.injectContent)
			.then(view.render)
			.then(function($html) {
				var elements = $html.find('.rail-item').get();

				view.setupTracking(experimentName)($html);
				curated.setupTracking($html);
				helper.setupTracking(elements);
			})
			.fail(handleError);
	}

	function renderLiftigniterCommunity() {
		var view = incontentView(),
			helper = liftigniterHelper({
				count: 3,
				widget: 'in-wikia'
			});

		helper.loadData()
			.then(view.render)
			.then(function($html) {
				var elements = $html.find('.item').get();

				view.setupTracking(experimentName)($html);
				helper.setupTracking(elements);
			});
	}

});
