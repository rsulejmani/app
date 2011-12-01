<?php

require_once dirname(__FILE__) . '/ArticleAdLogic.php';
require_once dirname(__FILE__) . '/PartnerWidget.php';

$wgExtensionCredits['other'][] = array(
	'name' => 'AdEngine',
	'author' => 'Inez Korczynski, Nick Sullivan'
);

$wgHooks['BeforePageDisplay'][] = 'adEngineAdditionalScripts';
$wgHooks["MakeGlobalVariablesScript"][] = "wfAdEngineSetupJSVars";

function wfAdEngineSetupJSVars($vars) {
	global $wgRequest, $wgNoExternals, $wgEnableAdsInContent, $wgEnableOpenXSPC,
		$wgAdDriverCookieLifetime, $wgHighValueCountries, $wgDartCustomKeyValues, 
		$wgUser, $wgEnableWikiAnswers, $wgAdDriverUseCookie, $wgAdDriverUseExpiryStorage,
		$wgCityId, $wgEnableAdMeldAPIClient, $wgEnableAdMeldAPIClientPixels;

	$wgNoExternals = $wgRequest->getBool('noexternals', $wgNoExternals);
	$vars['wgNoExternals'] = $wgNoExternals;
	$vars["wgEnableAdsInContent"] = $wgEnableAdsInContent;
	$vars["wgEnableAdMeldAPIClient"] = $wgEnableAdMeldAPIClient;
	$vars["wgEnableAdMeldAPIClientPixels"] = $wgEnableAdMeldAPIClientPixels;

	// OpenX SPC (init in AdProviderOpenX.js)
	$vars['wgEnableOpenXSPC'] = $wgEnableOpenXSPC;

	// category/hub
	$cat = AdEngine::getCachedCategory();
	$vars["cityShort"] = $cat['short'];
	$catInfo = HubService::getComscoreCategory($wgCityId);
	$vars['cscoreCat'] = $catInfo->cat_name;

	// AdDriver
	$vars['wgAdDriverCookieLifetime'] = $wgAdDriverCookieLifetime;
	$highValueCountries = WikiFactory::getVarValueByName('wgHighValueCountries', 177);	// community central
	if (empty($highValueCountries)) {
		$highValueCountries = $wgHighValueCountries;
	}
	$vars['wgHighValueCountries'] = $highValueCountries;
	$vars['wgAdDriverUseExpiryStorage'] = $wgAdDriverUseExpiryStorage;
	$vars['wgAdDriverUseCookie'] = $wgAdDriverUseCookie;

	// ArticleAdLogic
	$vars['adLogicPageType'] = ArticleAdLogic::getPageType();

	// Custom KeyValues (for DART requests)
	$vars['wgDartCustomKeyValues'] = $wgDartCustomKeyValues;

	$vars['wgUserShowAds'] = $wgUser->getOption('showAds');

	// Answers sites
	$vars['wgEnableWikiAnswers'] = $wgEnableWikiAnswers;

	return true;
}

/**
 * Before the page is rendered this gives us a chance to cram some Javascript in.
 */
function adEngineAdditionalScripts( &$out, &$sk ){
	global $IP;
	global $wgExtensionsPath,$wgStyleVersion;

	return true;
} // end adEngineAdditionalScripts()

interface iAdProvider {
	public static function getInstance();
	public function getAd($slotname, $slot, $params = null);
	public function batchCallAllowed();
	public function addSlotToCall($slotname);
	public function getSetupHtml();
	public function getBatchCallHtml();
}

abstract class AdProviderIframeFiller {
        public function getIframeFillHtml($slotname, $slot) {
                global $wgEnableAdsLazyLoad, $wgAdslotsLazyLoad;

                $function_name = AdEngine::fillIframeFunctionPrefix . $slotname;
                $out = $this->getIframeFillFunctionDefinition($function_name, $slotname, $slot);
                if (!$wgEnableAdsLazyLoad || empty($wgAdslotsLazyLoad[$slotname]) || empty($this->enable_lazyload)) {
                	$out .= "\n".'<script type="text/javascript">' . "$function_name();" . '</script>' . "\n";
                }

                return $out;
        }

        abstract protected function getIframeFillFunctionDefinition($function_name, $slotname, $slot);

}

class AdEngine {

	const cacheKeyVersion = "2.03a";
	const cacheTimeout = 1800;
	const lazyLoadAdClass = 'LazyLoadAd';
	const fillIframeFunctionPrefix = 'fillIframe_';
	const fillElemFunctionPrefix = 'fillElem_';

	// TODO: pull these from wikicities.provider
	private $providers = array(
		'1' => 'DART',
		'2' => 'OpenX',
		'3' => 'Google',
		'4' => 'GAM',
		'5' => 'PubMatic',
		'6' => 'Athena',
		'7' => 'ContextWeb',
		'8' => 'DARTMobile',
		'9' => 'Liftium',
		'10' => 'AdDriver',
		'-1' => 'Null'
	);

	private $slots = array();

	private $placeholders = array();

	private $loadType = 'delayed';

	protected static $instance = false;

	// Exclude these $wgDBname's from bucket testing
	private $handsOffWikis = array(
		'masseffect',
		'warhammeronline',
		'starcraft',
		'diablo',
		'blind'
	);

	protected function __construct($slots = null) {
		if (!empty($slots)){
			$this->slots=$slots;
		} else {
			$this->loadConfig();
		}
		if (isset($_GET['athena_debug'])){
			echo "<!-- Ad Slot settings:" . print_r($this->slots, true) . "-->";
		}
		global $wgAutoloadClasses;
		foreach($this->providers as $p) {
			$wgAutoloadClasses['AdProvider' . $p]=dirname(__FILE__) . '/AdProvider'.$p.'.php';
		}

		global $wgRequest,$wgNoExternals,$wgShowAds;
		$wgNoExternals = $wgRequest->getBool('noexternals', $wgNoExternals);
		if(!empty($wgNoExternals)){
			$wgShowAds = false;
		}
	}

	public static function getInstance($slots = null) {
		if(self::$instance == false) {
			self::$instance = new AdEngine($slots);
		}
		return self::$instance;
	}

	// Load up all the providers. For each one, set up

	public function getSetupHtml(){
		global $wgExtensionsPath, $wgCityId;

		static $called = false;
		if ($called) {
			return false;
		}
		$called = true;

		$out = "<!-- #### BEGIN " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";

		// If loading the ads inline, call the set up html for each provider.
		// If loading delayed, this is done in getDelayedAdLoading method instead.
		if ($this->loadType == 'inline'){
			// for loadType set to inline we have to load AdEngine.js here
			// for loadType set to delayed AdEngine.js should be inside of allinone.js
			global $wgExtensionsPath, $wgEnableAdsLazyLoad, $wgAdslotsLazyLoad;
			$out .= '<script type="text/javascript" src="' . $wgExtensionsPath . '/wikia/AdEngine/AdEngine.js?' . self::cacheKeyVersion . '"></script>'. "\n";
                        if ($wgEnableAdsLazyLoad && sizeof($wgAdslotsLazyLoad)) {
				// LazyLoadAds.js moved to StaticChute.php
                        	//$out .= '<script type="text/javascript" src="' . $wgExtensionsPath . '/wikia/AdEngine/LazyLoadAds.js?' . self::cacheKeyVersion . '"></script>'. "\n";
                        }

			foreach($this->slots as $slotname => $slot) {
                        	$AdProvider = $this->getAdProvider($slotname);
                        	// Get setup HTML for each provider. May be empty.
                        	$out .= $AdProvider->getSetupHtml();
                        }
		}

		$out .= "<!-- #### END " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";

		return $out;
	}

	public function loadConfig() {
		global $wgAdSlots, $wgUser;

		$skin_name = null;
		if ( is_object($wgUser)){
				$skin_name = $wgUser->getSkin()->getSkinName();
		}

		// sometimes no skin set yet; hack copied from Interstitial::getCss
		if (empty($skin_name)) $skin_name = substr(get_class($wgUser->getSkin()), 4);

		if ($skin_name == 'awesome' || $skin_name == 'answers' || $skin_name == 'monaco'){
			$skin_name = 'oasis';
		}

		$this->slots = $wgAdSlots[$skin_name];
		if (empty($this->slots) || !is_array($this->slots)) {
			$this->slots = array();
		}
		foreach ($this->slots as $slot=>&$slotdata) {
			// set provider (for information only)
			$slotdata['provider'] = isset($this->providers[$slotdata['provider_id']]) ? $this->providers[$slotdata['provider_id']] : 'null';
		}
		$this->applyWikiOverrides();

		global $wgDartCustomKeyValues;
		if (!empty($wgDartCustomKeyValues)) {
			// warning, legacy overcomplication ahead
			$dart_key_values = array();
			foreach(explode(";", $wgDartCustomKeyValues) as $keyval) {
				if (!empty($keyval)) {
					list($key, $val) = explode("=", $keyval);
					if (!empty($key) && !empty($val)) {
						$dart_key_values[] = array("keyname" => $key, "keyvalue" => $val);
					}
				}
			}
			if (!empty($dart_key_values)) {
			 foreach($this->slots as $slotname => $slot) {
			 	if($slot['provider_id'] == /* dart */ 1
				|| $slot['provider_id'] == /* AdDriver */ 10){
					$this->slots[$slotname]['provider_values'] = $dart_key_values;
			 	}
			 }
			}
		}

		global $wgShowAds;
		if( empty( $wgShowAds ) ) {
			// clear out all slots except OpenX slots. RT #68545
			foreach ($this->slots as $slotname=>$slot) {
				if ($slot['provider_id'] != 2) {
					unset($this->slots[$slotname]);
				}
			}
		}

		return true;
	}


	function getProviderid($provider){
		foreach($this->providers as $id => $p) {
			if (strtolower($provider) == strtolower($p) ){
				return $id;
			}
		}
		return false;
	}


	/* Allow Wiki Factory variables to override what is in the slots */
	function applyWikiOverrides(){
		foreach($this->slots as $slotname => $slot) {
			$name = 'wgAdslot_' . $slotname;
			if (!empty($GLOBALS[$name])){
				$provider_id = $this->getProviderid($GLOBALS[$name]);
				if ($provider_id === false ){
					trigger_error("Invalid value for $name ({$GLOBALS[$name]})", E_USER_WARNING);
					continue;
				}
				$this->slots[$slotname]['provider_id'] = $provider_id;
				$this->slots[$slotname]['provider'] = $GLOBALS[$name];
				$this->slots[$slotname]['overridden_by'] = $name;
				$this->slots[$slotname]['enabled'] = "Yes";
			}
		}
	}


	/* Simple accessor for slots array */
	public function getSlots() {
		return $this->slots;
	}


	/* Category name/id is needed multiple times for multiple providers. Be gentle on our dbs by adding a thin caching layer. */
	public function getCachedCategory(){
		static $cat;
		if (! empty($cat)){
			// This function already called
			return $cat;
		}

		if (!empty($_GET['forceCategory'])){
			// Passed in through the url, or hard coded on a test_page. ;-)
			return $_GET['forceCategory'];
		}

		global $wgMemc, $wgCityId, $wgRequest;
		$cacheKey = wfMemcKey(__CLASS__ . 'category', self::cacheKeyVersion);

		$cat = $wgMemc->get($cacheKey);
		if (!empty($cat) && $wgRequest->getVal('action') != 'purge'){
			return $cat;
		}

		$hub = WikiFactoryHub::getInstance();
		$cat = array(
			'id'=>$hub->getCategoryId($wgCityId),
			'name'=>$hub->getCategoryName($wgCityId),
			'short'=>$hub->getCategoryShort($wgCityId),
		);

		$wgMemc->set($cacheKey, $cat, self::cacheTimeout);
		return $cat;
	}

	// For the provided $slotname, get an ad tag.
	public function getAd($slotname, $params = null) {
		$AdProvider = $this->getAdProvider($slotname);
		return $AdProvider->getAd($slotname, empty($this->slots[$slotname]) ? array() : $this->slots[$slotname], $params);
	}

	/**
	 * assumes all eleemnts in $slotnames are the same ad provider
	 */
	public function getLazyLoadableAdGroup($adGroupName, Array $slotnames, $params=null) {
		if (!sizeof($slotnames)) {
			return '';
		}

		$AdProvider = $this->getAdProvider($slotnames[0]);
		if (method_exists($AdProvider, 'getLazyLoadableAdGroup')) {
			return $AdProvider->getLazyLoadableAdGroup($adGroupName, $slotnames, $params);
		}
		else {
			return '';
		}
	}

	// Logic for hiding/displaying ads should be here, not in the skin.
	private function getAdProvider($slotname) {
		global $wgShowAds, $wgUser, $wgLanguageCode, $wgNoExternals;


		/* Note: Don't throw an exception on error. Fail gracefully for ads,
		 * don't under any circumstances fail the rendering of the page.
		 * Instead, return a "AdProviderNull" object with an error message.
		 * Note that the second parameter for AdProviderNull constructor
		 * is a boolean for whether or not to log it as an error
		 */

		// First handle error conditions
		if (!empty($wgNoExternals)){
			return new AdProviderNull('Externals (including ads) are not allowed right now.');

		} else if (empty($this->slots[$slotname])) {
			return new AdProviderNull('Unrecognized slot', false);

		} else if ($this->slots[$slotname]['enabled'] == 'No'){
			return new AdProviderNull("Slot is disabled", false);

		// As long as they are enabled via config, spotlights are always displayed...
		} else if ( AdEngine::getInstance()->getAdType($slotname) == 'spotlight' ){
			return $this->getProviderFromId($this->slots[$slotname]['provider_id']);

		// Now some toggles based on preferences and logged in/out
		} else if (! ArticleAdLogic::isMandatoryAd($slotname) &&
			     empty($_GET['showads']) && $wgShowAds == false ){
			return new AdProviderNull('$wgShowAds set to false', false);

		} else if (! ArticleAdLogic::isMandatoryAd($slotname) && empty($_GET['showads']) &&
			   is_object($wgUser) && $wgUser->isLoggedIn() && !$wgUser->getOption('showAds') ){
			return new AdProviderNull('User is logged in', false);

		} else if (!empty($_GET['forceProviderid'])){
			// For debugging, allow ad providers to be forced
			return $this->getProviderFromId($_GET['forceProviderid']);

		// Special case for this type of ad. Not in Athena
		} else if ($slotname == 'RIGHT_SKYSCRAPER_1'){
			return $this->getProviderFromId($this->slots[$slotname]['provider_id']);

		// All of the errors and toggles are handled, now switch based on language
		} else {

			if (! in_array($wgLanguageCode, AdProviderGoogle::getSupportedLanguages())){
				// Google's TOS prevents serving ads for some languages
				return new AdProviderNull("Unsupported language for Google Adsense ($wgLanguageCode)", false);
			} else {
			 	return $this->getProviderFromId($this->slots[$slotname]['provider_id']);
			}
		}

		// Should never happen, but be sure that an AdProvider object is always returned.
		return new AdProviderNull('Logic error in ' . __METHOD__, true);
	}


	public function getProviderFromId ($provider_id) {
		switch (strtolower($this->providers[$provider_id])){
			case 'dart': return AdProviderDART::getInstance();
			case 'openx': return AdProviderOpenX::getInstance();
			case 'google': return AdProviderGoogle::getInstance();
			case 'gam': return AdProviderGAM::getInstance();
			case 'pubmatic': return AdProviderPubMatic::getInstance();
			case 'athena': return AdProviderAthena::getInstance();
			case 'contextweb': return AdProviderContextWeb::getInstance();
			case 'dartmobile': return AdProviderDARTMobile::getInstance();
			case 'liftium': return AdProviderLiftium::getInstance();
			case 'addriver': return AdProviderAdDriver::getInstance();
			case 'null': return new AdProviderNull('Slot disabled in WF', false);
			default: return new AdProviderNull('Unrecognized provider id', true);
		}
	}

	/* Size is stored as $widthx$size character column. Split here.
 	 * You may be asking, why not just store it as separate values to be begin with?
 	 * Because size is not always height/width. Possible values for size include:
 	 * 728x60
 	 * 300x250,300x600
 	 * 728x*
 	 *
 	 * Do the best you can to return a height/width
 	 */
        public static function getHeightWidthFromSize($size){
                if (preg_match('/^([0-9]{2,4})x([0-9]{2,4})/', $size, $matches)){
                        return array('width'=>$matches[1], 'height'=>$matches[2]);
                } else if (preg_match('/^([0-9]{2,4})x\*/', $size, $matches)){
                        return array('width'=>$matches[1], 'height'=>'*');
                } else {
                        return false;
                }
        }

	public function getPlaceHolderIframe($slotname, $reserveSpace=true){
                global $wgEnableAdsLazyLoad, $wgAdslotsLazyLoad;

		$html = null;
		wfRunHooks("fillInAdPlaceholder", array("iframe", $slotname, &$this, &$html));
		if (!empty($html)) return $html;

		$AdProvider = $this->getAdProvider($slotname);
		// If it's a Null Ad, just return an empty comment, and don't store in place holder array.
		if ($AdProvider instanceof AdProviderNull){
			return "<!-- Null Ad from " . __METHOD__ . "-->" . $AdProvider->getAd($slotname, array());
		}

		// FIXME make it more general...
		if ($AdProvider instanceof AdProviderGAM){
			return "<!-- Fall back to getAd from " . __METHOD__ . "-->" . $this->getAd($slotname); 
		}

		$this->placeholders[$slotname]=$this->slots[$slotname]['load_priority'];

		if ($reserveSpace) {
			$dim = self::getHeightWidthFromSize($this->slots[$slotname]['size']);
			$h = $dim['height'];
			$w = $dim['width'];
		} else {
			$h = 0;
			$w = 0;
		}

		// Make the 300x250 on the home page a 300x600
		global $wgEnableHome300x600;
		if ($slotname == "HOME_TOP_RIGHT_BOXAD" && $wgEnableHome300x600){
			$h = 300;
			$h = 600;
		}

		// Make the 300x250 on the article pages a 300x600
		global $wgEnableArticle300x600;
		if ($slotname == "TOP_RIGHT_BOXAD" && $wgEnableArticle300x600){
			$w = 300;
			$h = 600;
		}

		static $adnum = 0;
		$adnum++;
		if ($AdProvider instanceof AdProviderLiftium){
			$slotdiv = "Liftium_" . $this->slots[$slotname]['size'] . "_" . $adnum . "_php";
		} else {
			$slotdiv = "Wikia_" . $this->slots[$slotname]['size'] . "_" . $adnum;
		}
		
		$slotiframe_class = '';
		if (!empty($wgEnableAdsLazyLoad)) {
			if (!empty($wgAdslotsLazyLoad[$slotname])) {
				if (!empty($AdProvider->enable_lazyload)) {
					$slotiframe_class = self::lazyLoadAdClass;
				}
			}
		}

		$style = '';
		if($slotname == 'PREFOOTER_LEFT_BOXAD' || $slotname == 'PREFOOTER_RIGHT_BOXAD' || $slotname == 'LEFT_SKYSCRAPER_2' || $slotname == 'LEFT_SKYSCRAPER_3') {
			$style = ' style="display:none;"';
		}

		return '<div id="' . htmlspecialchars($slotname) . '" class="wikia-ad noprint"'.$style.'>' .
			'<div id="' . htmlspecialchars($slotdiv) . '">' .
			'<iframe width="' . intval($w) . '" height="' . intval($h) . '" ' .
			'id="' . htmlspecialchars($slotname) . '_iframe" class="' . $slotiframe_class . '" ' .
                	'noresize="true" scrolling="no" frameborder="0" marginheight="0" ' .
			'marginwidth="0" style="border:none" target="_blank"></iframe></div></div>';
	}

	/* For delayed ad loading, we have a place holder div that gets placed in the content,
	   to be loaded at the bottom of the page with an absolute position.
	   Keep track fo the placeholders for future refence */
	public function getPlaceHolderDiv($slotname, $reserveSpace=true){
		$html = null;
		wfRunHooks("fillInAdPlaceholder", array("div", $slotname, &$this, &$html));
		if (!empty($html)) return $html;

		$AdProvider = $this->getAdProvider($slotname);
		// If it's a Null Ad, just return an empty comment, and don't store in place holder array.
		if ($AdProvider instanceof AdProviderNull){
			return "<div id=\"$slotname\" style=\"display:none\">" . $AdProvider->getAd($slotname, array()) . "</div>";
		}

		$styles = array();
		$dim = self::getHeightWidthFromSize($this->slots[$slotname]['size']);
		if (!empty($dim['width'])){
			array_push($styles, "width: {$dim['width']}px;");
			array_push($styles, "height: {$dim['height']}px;");
		}

		if($this->slots[$slotname]['enabled'] == 'No' || $reserveSpace == false){
			array_push($styles, "display: none;");
		}

		$style = ' style="'. implode(" ", $styles) .'" class="wikia_ad_placeholder"';

		// We will use these at the bottom of the page for ads, if delayed ad loading is enabled
		$this->placeholders[$slotname]=$this->slots[$slotname]['load_priority'];

		// Fill in slotsToCall with a list of slotnames that will be used. Needed for getBatchCallHtml
		$AdProvider->addSlotToCall($slotname);

		return "<div id=\"$slotname\"$style></div>";
	}

	public function getDelayedLoadingCode(){
		global $wgExtensionsPath;

		if (empty($this->placeholders)){
			// No delayed ads on this page
			return '<!-- No placeholders called for ' . __METHOD__ . " -->\n";
		}

		// Sort by load_priority
		asort($this->placeholders);
		$this->placeholders = array_reverse($this->placeholders);

		$out = "<!-- #### BEGIN " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";

		global $wgCityId;

		// Get the setup code for ad providers used on this page. This is for Ad Providers that support multi-call.
		foreach ($this->placeholders as $slotname => $load_priority){
	                $AdProvider = $this->getAdProvider($slotname);

			// Get setup HTML for each provider. May be empty.
			$out .= $AdProvider->getSetupHtml();
		}

		foreach ($this->placeholders as $slotname => $load_priority){
			$AdProvider = $this->getAdProvider($slotname);

			// Hmm. Should we just use: class="wikia_$adtype"?
			$class = self::getAdType($slotname) == 'spotlight' ? ' class="wikia_spotlight"' : ' class="wikia_ad"';
			// This may be better, but needs more testing. $out .= '<div id="' . $slotname . '_load"' . $class . '>' . $AdProvider->getAd($slotname, $this->slots[$slotname]) . "</div>\n";
                        $out .= '<div id="' . $slotname . '_load" style="display: none; position: absolute;"'.$class.'>' . $AdProvider->getAd($slotname, $this->slots[$slotname]) . "</div>\n";


			/* This image is what will be returned if there is NO AD to be displayed.
 			 * If this happens, we want leave the div collapsed.
			 * We tried for a more elegant solution, but were a bit constrained on the
			 * code that could be returned from the ad networks we deal with.
			 * I'd like to see a better solution for this, someday
			 * See Christian or Nick for more info.
			*/
			$out .= '<script type="text/javascript">' .
				'AdEngine.displaySlotIfAd("'. addslashes($slotname) .'");' .
				'</script>' . "\n";
		}
		$out .= "<!-- #### END " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";
		return $out;
	}


	public function getDelayedIframeLoadingCode(){
		global $wgExtensionsPath, $wgEnableAdsLazyLoad;

		if (empty($this->placeholders)){
			// No delayed ads on this page
			return '<!-- No iframe placeholders called for ' . __METHOD__ . " -->\n";
		}

		// Sort by load_priority
		asort($this->placeholders);
		$this->placeholders = array_reverse($this->placeholders);

		$out = "<!-- #### BEGIN " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";

		// Get the setup code for ad providers used on this page. This is for Ad Providers that support multi-call.
		foreach ($this->placeholders as $slotname => $load_priority){
	                $AdProvider = $this->getAdProvider($slotname);

			// Get setup HTML for each provider. May be empty.
			$out .= $AdProvider->getSetupHtml();
		}

		// Call the code to set the iframe urls for the iframes
                foreach ($this->placeholders as $slotname => $load_priority){
	                $AdProvider = $this->getAdProvider($slotname);
			// Currently only supported by GAM and Athena
			if (method_exists($AdProvider, "getIframeFillHtml")){
                        	$out .= $AdProvider->getIframeFillHtml($slotname, $this->slots[$slotname]);
			}
		}

		$out .= "<!-- #### END " . __CLASS__ . '::' . __METHOD__ . " ####-->\n";
		return $out;
	}

	public function getPlaceHolders(){
		return $this->placeholders;
	}

	/* Sometimes there is different behavior for different types of ad. Reduce the number of
	 * hacks and hard coded slot names by providing a grouping on type of based on size.
	 * Possible return values:
	 *  "spotlight" , "leaderboard", "boxad", "skyscraper"
	 *
	 * NULL will be returned if this function is unable to determine the type of ad
	 *
	 * Long term, this should be a column in the ad_slots table. This will happen when
	 * we build the UI for managing those tables.
	 */
	public function getAdType($slotname){
		if (empty($this->slots[$slotname]['size'])){
			return NULL;
		}

		switch ($this->slots[$slotname]['size']){
			case '200x75': return 'spotlight';
			case '125x125': return 'spotlight';
			case '269x143': return 'spotlight';
			case '728x90': return 'leaderboard';
			case '300x250': return 'boxad';
			case '160x600': return 'skyscraper';
			case '120x600': return 'skyscraper';
			case '200x200': return 'navbox';
			case '0x0': return 'invisible';
			default: return NULL;
		}
	}


	/* Either 'delayed' or 'inline' */
	public function setLoadType($loadType){
		$this->loadType = $loadType;
		if ($loadType == 'inline'){
			// Fill in slotsToCall with a list of slotnames that will be used. Needed for getBatchCallHtml
			foreach($this->slots as $slotname => $slot) {
				$AdProvider = $this->getAdProvider($slotname);
				$AdProvider->addSlotToCall($slotname);
			}
		}
	}

	public function getSlotNamesForProvider($provider_id){
		$out = array();
		foreach($this->slots as $slotname => $data ){
			if ($data['enabled'] == 'Yes' && $data['provider_id'] == $provider_id){
				$out [] = $slotname;
			}
		}
		return $out;
	}

	public function getProviderNameForSlotname($slotname) {
		return isset($this->slots[$slotname]) &&
			isset($this->slots[$slotname]['provider_id']) &&
			isset($this->providers[$this->slots[$slotname]['provider_id']])
			? $this->providers[$this->slots[$slotname]['provider_id']]
			: '';
	}
}
