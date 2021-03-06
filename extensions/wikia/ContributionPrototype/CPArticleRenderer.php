<?php

namespace ContributionPrototype;

use Http;
use MWHttpRequest;
use OutputPage;
use Title;
use Wikia;
use Wikia\Logger\Loggable;
use Wikia\Service\Gateway\UrlProvider;

class CPArticleRenderer {
	
	use Loggable;

	const SERVICE_NAME = "structdata";

	/** @var string */
	private $publicHost;
	
	/** @var int */
	private $wikiId;
	
	/** @var string */
	private $dbName;

	/** @var UrlProvider */
	private $urlProvider;

	/** @var bool */
	private $premiumHeaderEnabled;

	/**
	 * CPArticleRenderer constructor.
	 * @param string $publicHost
	 * @param int $wikiId
	 * @param string $dbName
	 * @param UrlProvider $urlProvider
	 * @param bool $premiumHeaderEnabled
	 */
	public function __construct($publicHost, $wikiId, $dbName, $urlProvider, $premiumHeaderEnabled) {
		$this->publicHost = $publicHost;
		$this->wikiId = $wikiId;
		$this->dbName = $dbName;
		$this->urlProvider = $urlProvider;
		$this->premiumHeaderEnabled = $premiumHeaderEnabled;
	}

	/**
	 * @param Title $title
	 * @param OutputPage $output
	 */
	public function render(Title $title, OutputPage $output, $action='view') {
		$output->setPageTitle($title->getPrefixedText());
		$content = $this->getArticleContent($title->getPartialURL(), $action);
		
		if ($content === false) {
			// TODO: what do we want to show here?
			return;
		}

		$output->addHTML($content);
		$this->addStyles($output);
		$this->addScripts($output);
	}

	private function addStyles(OutputPage $output) {
		$output->addStyle("{$this->publicHost}/public/assets/styles/main.css");

		// this ends up using $wgOut, but we need it for the assets manager integration, no point in duplicating :(
		Wikia::addAssetsToOutput('contribution_prototype_scss');
	}

	private function addScripts(OutputPage $output) {
		$output->addHTML("<script src=\"{$this->publicHost}/public/assets/vendor.dll.js\"></script>");
		$output->addHTML("<script src=\"{$this->publicHost}/public/assets/app.js\"></script>");
		$output->addHTML('<link href="https://opensource.keycdn.com/fontawesome/4.7.0/font-awesome.min.css" rel="stylesheet">');

		// This is not the intended use of renderSvg but it conveniently does what we want because
		// the sprite is stored alongside the individual SVGs. The alternative would be to provide
		// a "correct" mechanism for loading the sprite via the DesignSystem directly (lazy?). Note
		// that this in-lines the whole thing into the page.
		$output->addHTML(\DesignSystemHelper::renderSvg('sprite'));
	}

	private function getArticleContent($title, $action) {
//		$internalHost = $this->urlProvider->getUrl(self::SERVICE_NAME);
		$internalHost = $this->publicHost;
		$path = "wiki/{$title}";

		if ($action != 'view') {
			$path .= "/${action}";
		}

		/** @var MWHttpRequest $response */
		$response = Http::request(
				'GET',
				"{$internalHost}/{$path}",
				[
					'userAgent' => $_SERVER['HTTP_USER_AGENT'],
					'noProxy' => true,
					'returnInstance' => true,
					'followRedirects'=> true,
					'headers' => [
						'X-Wikia-Community' => $this->dbName,
						'X-Wikia-PremiumHeader' => $this->premiumHeaderEnabled
					]
				]
		);

		if ($response->getStatus() >= 500) {
			// Http::request logs when http status > 399
			return false;
		}

		return $response->getContent();
	}
}
