<?php
class WikiaSearchController extends WikiaSpecialPageController {

	const RESULTS_PER_PAGE = 25;
	const PAGES_PER_WINDOW = 5;

	/**
	 * Responsible for search queries
	 * @var WikiaSearch
	 */
	protected $wikiaSearch;

	/**
	 * Responsible for building data used in indexing
	 * @var WikiaSearchIndexer
	 */
	protected $wikiaSearchIndexer;

	/**
	 * Handles dependency-building and special page routing before calling controller actions
	 */
	public function __construct() {
        // note: this is required since we haven't constructed $this->wg yet
		global $wgWikiaSearchIsDefault;
		// Solarium_Client dependency handled in class constructor call in WikiaSearch.setup.php
		$this->wikiaSearch			= F::build('WikiaSearch');
		$this->wikiaSearchIndexer	= F::build('WikiaSearchIndexer');
		$specialPageName 			= $wgWikiaSearchIsDefault ? 'Search' : 'WikiaSearch';

		parent::__construct( $specialPageName, $specialPageName, false );
	}

	/**
	 * Controller Actions
	 *---------------------------------------------------------------------------------*/

	/**
	 * This is the main search action. Special:Search points here.
	 */
	public function index() {
		$this->wg->Out->addHTML( F::build('JSSnippets')->addToStack( array( "/extensions/wikia/Search/js/WikiaSearch.js" ) ) );
		$this->wg->SuppressRail = true;

		$this->handleSkinSettings( $this->wg->User->getSkin() );
		
		$searchConfig = new Wikia\Search\Config();
		$dcParams = array(
					'config' => $searchConfig,
					);
		$container = new Wikia\Search\QueryService\DependencyContainer( $dcParams );
		$this->wikiaSearch = Wikia\Search\QueryService\Factory::getInstance()->get( $container ); 

		$resultsPerPage = empty( $this->wg->SearchResultsPerPage ) ? self::RESULTS_PER_PAGE : $this->wg->SearchResultsPerPage;
		
		$searchConfig
			->setQuery			( $this->getVal('query', $this->getVal('search') ) )
			->setCityId			( $this->wg->CityId )
			->setLimit			( $this->getVal('limit', $resultsPerPage ) )
			->setPage			( $this->getVal('page', 1) )
			->setRank			( $this->getVal('rank', 'default') )
			->setDebug			( $this->request->getBool('debug', false) )
			->setSkipCache		( $this->request->getBool('skipCache', false) )
			->setAdvanced		( $this->request->getBool( 'advanced', false ) )
			->setHub			( ($this->getVal('nohub') != '1') ? $this->getVal('hub', false) : false )
			->setRedirs			( $searchConfig->getAdvanced() ? $this->request->getBool('redirs', false) : false )
			->setIsInterWiki	( $this->request->getBool('crossWikia', false) || $this->isCorporateWiki() )
			->setVideoSearch	( $this->getVal('videoSearch', false) )
			->setGroupResults	( $searchConfig->isInterWiki() || $this->getVal('grouped', false) )
			->setFilterQueriesFromCodes( $this->getVal( 'filters', array() ) )
		 ;

		$this->setNamespacesFromRequest( $searchConfig, $this->wg->User );

		if($this->isCorporateWiki()) {
			OasisController::addBodyClass('inter-wiki-search');
		}

		if( $searchConfig->getQueryNoQuotes( true ) ) {
			$this->wikiaSearch->getMatch( $searchConfig );
			if ( $searchConfig->getPage() == 1 ) {
				$this->handleArticleMatchTracking( $searchConfig, F::build( 'Track' ) );
			}
			
			$this->wikiaSearch->search();

			$this->wg->Out->setPageTitle( $this->wf->msg( 'wikiasearch2-page-title-with-query',
												array( ucwords( $searchConfig->getQuery( Wikia\Search\Config::QUERY_RAW ) ), $this->wg->Sitename) )  );
		} else {
			if( $searchConfig->getIsInterWiki() ) {
				$this->wg->Out->setPageTitle( $this->wf->msg( 'wikiasearch2-page-title-no-query-interwiki' ) );
			} else {
				$this->wg->Out->setPageTitle( $this->wf->msg( 'wikiasearch2-page-title-no-query-intrawiki',
													array($this->wg->Sitename) )  );
			}
		}

		if(! $searchConfig->getIsInterWiki() ) {
			$this->setVal( 'advancedSearchBox', $this->sendSelfRequest( 'advancedBox', array( 'config' => $searchConfig ) ) );
		}

		$format = $this->response->getFormat();
		if( ($format == 'json' || $format == 'jsonp') && ($searchConfig->getResultsFound() > 0) ){
			$searchConfig->setResults( $searchConfig->getResults()->toNestedArray() );
		}

		$tabsArgs = array(
				'config'		=> $searchConfig,
				'by_category'	=> $this->getVal('by_category', false)
				);

		$this->setVal( 'results',				$searchConfig->getResults() );
		$this->setVal( 'resultsFound',			$searchConfig->getResultsFound() );
		$this->setVal( 'resultsFoundTruncated', $this->wg->Lang->formatNum( $searchConfig->getTruncatedResultsNum() ) );
		$this->setVal( 'isOneResultsPageOnly',	$searchConfig->getNumPages() < 2 );
		$this->setVal( 'pagesCount', 			$searchConfig->getNumPages() );
		$this->setVal( 'currentPage', 			$searchConfig->getPage() );
		$this->setVal( 'paginationLinks',		$this->sendSelfRequest( 'pagination', $tabsArgs ) );
		$this->setVal( 'tabs', 					$this->sendSelfRequest( 'tabs', $tabsArgs ) );
		$this->setVal( 'query',					$searchConfig->getQuery( Wikia\Search\Config::QUERY_ENCODED ) );
		$this->setVal( 'resultsPerPage',		$searchConfig->getLimit() );
		$this->setVal( 'pageUrl',				$this->wg->Title->getFullUrl() );
		$this->setVal( 'debug',					$searchConfig->getDebug() );
		$this->setVal( 'solrHost',				$this->wg->SolrHost);
		$this->setVal( 'isInterWiki',			$searchConfig->getIsInterWiki() );
		$this->setVal( 'relevancyFunctionId',	WikiaSearch::RELEVANCY_FUNCTION_ID ); //@todo do we need this?
		$this->setVal( 'namespaces',			$searchConfig->getNamespaces() );
		$this->setVal( 'hub',					$searchConfig->getHub() );
		$this->setVal( 'hasArticleMatch',		$searchConfig->hasArticleMatch() );
		$this->setVal( 'isMonobook',			($this->wg->User->getSkin() instanceof SkinMonobook) );
		$this->setVal( 'isCorporateWiki',		$this->isCorporateWiki() );
	}

	/**
	 * Called by a view script to generate the advanced tab link in search.
	 */
	public function advancedTabLink() {
	    $term = $this->getVal('term');
	    $namespaces = $this->getVal('namespaces');
	    $label = $this->getVal('label');
	    $tooltip = $this->getVal('tooltip');
	    $params = $this->getVal('params');
	    $redirs = $this->getVal('redirs');

	    $opt = $params;
	    foreach( $namespaces as $n ) {
	        $opt['ns' . $n] = 1;
	    }

	    $opt['redirs'] = !empty($redirs) ? 1 : 0;
	    $stParams = array_merge( array( 'search' => $term ), $opt );

	    $title = F::build('SpecialPage', array( 'WikiaSearch' ), 'getTitleFor');

	    $this->setVal( 'class',     str_replace( ' ', '-', strtolower( $label ) ) );
	    $this->setVal( 'href',		$title->getLocalURL( $stParams ) );
	    $this->setVal( 'title',		$tooltip );
	    $this->setVal( 'label',		$label );
	    $this->setVal( 'tooltip',	$tooltip );
	}

	/**
	 * Service-level actions -- no view templates, just JSON responses.
	 *---------------------------------------------------------------------------------*/

	/**
	 * Used during indexing to retrieve data for a single page in JSON format.
	 */
	public function getPage() {
	    $pageId = $this->getVal( 'id' );

	    if( !empty( $pageId ) ) {
	        $page = $this->wikiaSearchIndexer->getPage( $pageId );

	        $this->response->setData( $page );
	    }

	    // force output format as there's no template file (BugId:18831)
	    $this->getResponse()->setFormat( 'json' );
	}

	/**
	 * Used during indexing to retrieve multiple pages in JSON format.
	 */
	public function getPages() {
	    $this->wg->AllowMemcacheWrites = false;
	    $ids = $this->getVal('ids');
	    if ( !empty( $ids ) ) {
	        $this->response->setData( $this->wikiaSearchIndexer->getPages( explode( '|', $ids ) ) );
	    }
	    $this->getResponse()->setFormat('json');
	}

	/**
	 * Delivers a JSON response for video searches
	 */
	public function videoSearch() {
	    $searchConfig = new Wikia\Search\Config();
	    $searchConfig
	    	->setCityId			( $this->wg->cityId )
	    	->setQuery			( $this->getVal('q') )
	    	->setNamespaces		( array(NS_FILE) )
	    	->setVideoSearch	( true )
	    ;
	    
	    $dcParams = array(
					'config' => $searchConfig,
					);
		$container = new Wikia\Search\QueryService\DependencyContainer( $dcParams );
		$this->wikiaSearch = Wikia\Search\QueryService\Factory::getInstance()->get( $container ); 

	    $this->wikiaSearch->search( $searchConfig );
	    $this->getResponse()->setFormat( 'json' );
	    $this->getResponse()->setData( ( $searchConfig->getResults() ) ? $searchConfig->getResults()->toNestedArray() : array() );

	}



	/**
	 * Controller Helper Methods
	 *----------------------------------------------------------------------------------*/

	/**
	 * Called in index action.
	 * Based on an article match and various settings, generates tracking events and routes user to appropriate page.
	 * @see    WikiaSearchControllerTest::testArticleMatchTracking
	 * @param  Wikia\Search\Config $searchConfig
	 * @return boolean true (if not routed to search match page)
	 */
	protected function handleArticleMatchTracking( Wikia\Search\Config $searchConfig, Track $track ) {
		$articleMatch	=	$searchConfig->getArticleMatch();

		if ( (! empty($articleMatch) ) && $this->getVal('fulltext', '0') === '0') {
		    $article = $articleMatch->getArticle();

		    $title = $article->getTitle();

		    $this->wf->RunHooks( 'SpecialSearchIsgomatch', array( &$title, $searchConfig->getOriginalQuery() ) );

		    $track->event( 'search_start_gomatch', array( 'sterm' => $searchConfig->getOriginalQuery(), 'rver' => 0 ) );
		    $this->response->redirect( $title->getFullURL() );
		}
		elseif(! empty( $articleMatch ) ) {
		    $track->event( 'search_start_match', array( 'sterm' => $searchConfig->getOriginalQuery(), 'rver' => 0 ) );
		} else {
		    $title = F::build( 'Title', array( $searchConfig->getOriginalQuery() ), 'newFromText');
		    if ( $title !== null ) {
		        $this->wf->RunHooks( 'SpecialSearchNogomatch', array( &$title ) );
		    }
		}

		return true;
	}

	/**
	 * Called in index action. Sets the SearchConfigs namespaces based on MW-core NS request style.
	 * @see    WikiSearchControllerTest::testSetNamespacesFromRequest
	 * @param  Wikia\Search\Config $searchConfig
	 * @return boolean true
	 */
	protected function setNamespacesFromRequest( Wikia\Search\Config $searchConfig, User $user ) {
		$searchEngine = F::build( 'SearchEngine' );
		$searchableNamespaces = $searchEngine->searchableNamespaces();
		$namespaces = array();
		foreach( $searchableNamespaces as $i => $name ) {
		    if ( $this->getVal( 'ns'.$i, false ) ) {
		        $namespaces[] = $i;
		    }
		}
		if ( empty($namespaces) ) {
		    if ( $user->getOption( 'searchAllNamespaces' ) ) {
			    $namespaces = array_keys($searchableNamespaces);
		    } else {
		    	$profiles = $searchConfig->getSearchProfiles();
		    	// this is mostly needed for unit testing
		    	$defaultProfile = !empty( $this->wg->DefaultSearchProfile ) ? $this->wg->DefaultSearchProfile : 'default';
		    	$namespaces = $profiles[$defaultProfile]['namespaces'];
		    }

		}
		$searchConfig->setNamespaces( $namespaces );

		return true;
	}

	/**
	 * Called in index action to manipulate the view based on the user's skin
	 * @see    WikiaSearchControllerTest::testSkinSettings
	 * @param  SkinTemplate $skin
	 * @return boolean true
	 */
	protected function handleSkinSettings( $skin ) {

		if ( $skin instanceof SkinMonoBook ) {
		    $this->response->addAsset ('extensions/wikia/Search/monobook/monobook.scss' );
		}
		if ( $skin instanceof SkinOasis ) {
		    $this->response->addAsset( 'extensions/wikia/Search/css/WikiaSearch.scss' );
		}
		if ( $skin instanceof SkinWikiaMobile ) {
		    $this->overrideTemplate( 'WikiaMobileIndex' );
		}

		return true;
	}

	/**
	 * Determines whether we are on the corporate wiki
	 * @see WikiaSearchControllerTest::testIsCorporateWiki
	 */
	protected function  isCorporateWiki() {
	    return !empty($this->wg->EnableWikiaHomePageExt);
	}

	/**
	 * Self-requests -- these shouldn't be directly called from the browser
	 */

	/**
	 * This is how we generate the subtemplate for the advanced search box.
	 * @see    WikiaSearchControllerTest::testAdvancedBox
	 * @throws Exception
	 */
	public function advancedBox() {
		$config = $this->getVal('config', false);
		if (! $config instanceof Wikia\Search\Config ) {
			throw new Exception("This should not be called outside of self-request context.");
		}

		$searchEngine = F::build( 'SearchEngine' );

		$searchableNamespaces = $searchEngine->searchableNamespaces();

		wfRunHooks( 'AdvancedBoxSearchableNamespaces', array( &$searchableNamespaces ) );

		$this->setVal( 'namespaces', 			$config->getNamespaces() );
		$this->setVal( 'searchableNamespaces', 	$searchableNamespaces );
		$this->setVal( 'redirs', 				$config->getIncludeRedirects() );
		$this->setVal( 'advanced', 				$config->getAdvanced() );
	}

	/**
	 * This is how we generate the search type tabs in the left-hand rail
	 * @see    WikiaSearchControllerTest::tabs
	 * @throws Exception
	 */
	public function tabs() {
		$config = $this->getVal('config', false);

		if (! $config || (! $config instanceOf Wikia\Search\Config ) ) {
		    throw new Exception("This should not be called outside of self-request context.");
		}

		$filters = $config->getFilterQueries();
		$rank = $config->getRank();
		$is_video_wiki = $this->wg->CityId == WikiaSearch::VIDEO_WIKI_ID;

		$form = array(
				'by_category' =>        $this->getVal('by_category', false),
				'cat_videogames' =>     isset( $filters['cat_videogames'] ),
				'cat_entertainment' =>  isset( $filters['cat_entertainment'] ),
				'cat_lifestyle' =>      isset( $filters['cat_lifestyle'] ),
				'is_hd' =>              isset( $filters['is_hd'] ),
				'is_image' =>           isset( $filters['is_image'] ),
				'is_video' =>           isset( $filters['is_video'] ),
				'sort_default' =>       $rank == 'default',
				'sort_longest' =>       $rank == 'longest',
				'sort_newest' =>        $rank == 'newest',
				'no_filter' =>          !( isset( $filters['is_image'] ) || isset( $filters['is_video'] ) ),
			);

		// Set video wiki to display only videos by default
		if( $is_video_wiki && $form['no_filter'] == 1 ) {
			$form['is_video'] = 1;
			$form['no_filter'] = 0;
		}

		$this->setVal( 'bareterm', 			$config->getQuery( Wikia\Search\Config::QUERY_RAW ) );
		$this->setVal( 'searchProfiles', 	$config->getSearchProfiles() );
		$this->setVal( 'redirs', 			$config->getIncludeRedirects() );
		$this->setVal( 'activeTab', 		$config->getActiveTab() );
		$this->setVal( 'form',				$form );
		$this->setVal( 'is_video_wiki',		$is_video_wiki );
	}

	/**
	 * This handles pagination via a template script.
	 * @see    WikiaSearchControllerTest::testPagination
	 * @throws Exception
	 * @return boolean|null (false if we don't want pagination, fully routed to view via sendSelfRequest if we do want pagination)
	 */
	public function pagination() {
		$config = $this->getVal('config', false);
		if (! $config || (! $config instanceOf Wikia\Search\Config ) ) {
			throw new Exception("This should not be called outside of self-request context.");
		}

		if (! $config->getResultsFound() ) {
			return false;
		}

		$page = $config->getPage();

		$windowFirstPage = ( ( $page - self::PAGES_PER_WINDOW ) > 0 )
						? ( $page - self::PAGES_PER_WINDOW )
						: 1;

		$windowLastPage = ( ( ( $page + self::PAGES_PER_WINDOW ) < $config->getNumPages() )
						? ( $page + self::PAGES_PER_WINDOW )
						: $config->getNumPages() ) ;

		$this->setVal( 'query', 			$config->getQuery( Wikia\Search\Config::QUERY_RAW ) );
		$this->setVal( 'pagesNum', 			$config->getNumPages() );
		$this->setVal( 'currentPage', 		$page );
		$this->setVal( 'windowFirstPage', 	$windowFirstPage );
		$this->setVal( 'windowLastPage', 	$windowLastPage );
		$this->setVal( 'pageTitle', 		$this->wg->Title );
		$this->setVal( 'crossWikia', 		$config->getIsInterWiki() );
		$this->setVal( 'resultsCount', 		$config->getResultsFound() );
		$this->setVal( 'skipCache', 		$config->getSkipCache() );
		$this->setVal( 'debug', 			$config->getDebug() );
		$this->setVal( 'namespaces', 		$config->getNamespaces() );
		$this->setVal( 'advanced', 			$config->getAdvanced() );
		$this->setVal( 'redirs', 			$config->getIncludeRedirects() );
		$this->setVal( 'limit', 			$config->getLimit() );
		$this->setVal( 'filters',			$config->getPublicFilterKeys() );
		$this->setVal( 'rank', 				$config->getRank() );
		$this->setVal( 'by_category', 		$this->getVal('by_category', false) );

	}

	/**
	 * Controller-level Hooks
	 */

	/**
	 * WikiaMobile hook to add assets so they are minified and concatenated
	 * @see    WikiaSearchControllerTest::testOnWikiaMobileAssetsPackages
	 * @param  array $jsHeadPackages
	 * @param  array $jsBodyPackages
	 * @param  array $scssPackages
	 * @return boolean
	 */
	public function onWikiaMobileAssetsPackages( &$jsHeadPackages, &$jsBodyPackages, &$scssPackages){
		if( F::app()->wg->Title->isSpecial('Search') ) {
			$jsBodyPackages[] = 'wikiasearch_js_wikiamobile';
			$scssPackages[] = 'wikiasearch_scss_wikiamobile';
		}
		return true;
	}
}
