<?php

class GoogleAnalyticsHooks {
	private static $normalCats = array();

	/**
	 * We don't want to log hidden categories
	 */
	static function onOutputPageMakeCategoryLinks( OutputPage &$out, $categories, &$links ) {
		self::$normalCats = array_keys( $categories, 'normal' );

		return true;
	}

	static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addHeadItem( 'GoogleAnalyticsIntegration', self::addGoogleAnalytics( $out ) );

		return true;
	}

	static function addGoogleAnalytics( OutputPage &$out ) {
		global $wgGoogleAnalyticsAccount,
		       $wgGoogleAnalyticsSegmentByGroup, $wgGoogleAnalyticsTrackExtLinks,
		       $wgGoogleAnalyticsDomainName, $wgGoogleAnalyticsCookiePath,
		       $wgGoogleAnalyticsEnahncedLinkAttribution, $wgGoogleAnalyticsPageGrouping,
		       $wgGoogleAnalyticsDemographics;


		if ( is_null( $wgGoogleAnalyticsAccount ) ) {
			$msg = self::messageToComment( 'googleanalytics-error-not-configured' );

			return $msg;
		}

		if ( $out->getUser()->isAllowed( 'noanalytics' ) ) {
			return self::messageToComment( 'googleanalytics-disabled-for-user' );
		}

		if ( self::isIgnoredPage( $out->getTitle() ) ) {
			return self::messageToComment( 'googleanalytics-disabled-for-page' );
		}

		/* Else: we load the script */
		/* Starts with regular GA.js queue initializing, but adds
		 * custom code to make sure '_setAccount' is always first (using 'unshift').
		 * This allows other extensions to shove things in the queue
		 * without knowing anything about it, by doing something like (don't forget ResourceLoader encapsulation!):
		 * var _gaq = _gaq || []; _gaq.push(cmd);
		 */
		$script = <<<JS
<script>
	var _gaq = _gaq || [];
	var cmd = ['_setAccount', '{$wgGoogleAnalyticsAccount}'];
	if (!_gaq.unshift){
		_gaq.push(cmd);
	} else {
	 _gaq.unshift(cmd);
	}
JS;

		if ( !empty( $wgGoogleAnalyticsDomainName ) ) {
			$script .= "
	_gaq.push(['_setDomainName', '{$wgGoogleAnalyticsDomainName}']);";
		}

		if ( !empty( $wgGoogleAnalyticsCookiePath ) ) {
			$script .= "
	_gaq.push(['_setCookiePath', '{$wgGoogleAnalyticsCookiePath}']);";
		}
		if ( isset( $wgGoogleAnalyticsSegmentByGroup ) && $wgGoogleAnalyticsSegmentByGroup === true ) {
			$script .= "
	window.RLQ.push( function () {
		_gaq.push(['_setCustomVar',
			1,								// first slot
			'User Groups',					// custom variable name
			mw.config.get( 'wgUserGroups' ).toString(),	// custom variable filtered in GA
			2						// custom variable scope - session-level
		]);
	});";
		}

		if ( isset( $wgGoogleAnalyticsPageGrouping ) && $wgGoogleAnalyticsPageGrouping === true ) {
			$title = $out->getTitle();
			$ns = $title->getNamespace();
			if ( isset( $ns ) && in_array(
							$ns, array( NS_CATEGORY, NS_FILE, NS_SPECIAL, NS_MEDIAWIKI )
					)
			) {
				$script .= PHP_EOL."/* Namespace excluded from page grouping */".PHP_EOL;
			} else {
				$normalCats = self::$normalCats;
				if ( count( $normalCats ) > 1 ) {
					$normalCats[ 0 ] = Title::makeTitleSafe(
							NS_CATEGORY, $normalCats[ 0 ]
					)->getText();
					$normalCats[ 1 ] = Title::makeTitleSafe(
							NS_CATEGORY, $normalCats[ 1 ]
					)->getText();
					$grouping        = $normalCats[ 1 ] . '/' . $normalCats[ 0 ];
					$script .= "
	  _gaq.push(['_setPageGroup', '1', '{$grouping}']);
	  _gaq.push(['_setPageGroup', '2', '{$normalCats[1]}']);
	  _gaq.push(['_setPageGroup', '3', '{$normalCats[0]}']);
	";
				};
			};
		};

		$script .= "
	_gaq.push(['_trackPageview']);";

		if ( isset( $wgGoogleAnalyticsDemographics ) && $wgGoogleAnalyticsDemographics === true ) {
			$gaSource = "('https:' == document.location.protocol ? 'https://' : 'http://') + 'stats.g.doubleclick.net/dc.js'";
		} else {
			$gaSource = "('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js'";
		}
		$script .= "
	(function() {
	var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
	ga.src = {$gaSource};
	var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
	})();";

		if ( isset( $wgGoogleAnalyticsEnahncedLinkAttribution ) &&
		     $wgGoogleAnalyticsEnahncedLinkAttribution === true
		) {
			$script .= "
	var pluginUrl = '//www.google-analytics.com/plugins/ga/inpage_linkid.js';
	_gaq.push(['_require', 'inpage_linkid', pluginUrl]);";
		}

		if ( isset( $wgGoogleAnalyticsTrackExtLinks ) && $wgGoogleAnalyticsTrackExtLinks === true ) {
			$script .= <<<JS
	function trackEvent(category, action, label, value, noninteraction) {
		try {
		  _gaq.push(['_trackEvent', category , action, label, value, noninteraction ]);
		} catch(err) { }
	}

	window.RLQ.push( function () {
		$( 'body' ).on( 'click', 'a.external, a.extiw, .interlanguage-link > a', function( e ) {
			var url = $( this ).attr( 'href' );
			var host = e.currentTarget.host.replace( ':80', '' );
			var category = '';
			if( $(this).hasClass( 'external' ) ) { category = 'Outbound Links'; }
			else if( $(this).hasClass( 'extiw' ) ) { category = 'Outbound Interwiki'; }
			else { category = 'Language Links'; }

			trackEvent( category, host, url, undefined, true );
		});
	});
JS;
		};

		// And finally...
		$script .= PHP_EOL . "</script>" . PHP_EOL;

		return $script;
	}

	public static function onSkinAfterBottomScripts( Skin $skin, &$text = '' ) {
		global $wgGoogleAnalyticsOtherCode;

		if ( $wgGoogleAnalyticsOtherCode === null
			 || $skin->getUser()->isAllowed( 'noanalytics' )
		     || self::isIgnoredPage( $skin->getTitle() )
		) {
			return true;
		}

		$text .= $wgGoogleAnalyticsOtherCode . PHP_EOL;

		return true;
	}

	static function isIgnoredPage( Title $title ) {
		global $wgGoogleAnalyticsIgnoreNsIDs,
		       $wgGoogleAnalyticsIgnorePages,
		       $wgGoogleAnalyticsIgnoreSpecials;


		return count( array_filter( $wgGoogleAnalyticsIgnoreSpecials, function ( $v ) use ( $title ) {
					return $title->isSpecial( $v );
				} ) ) > 0
				|| in_array( $title->getNamespace(), $wgGoogleAnalyticsIgnoreNsIDs, true )
				|| in_array( $title->getPrefixedText(), $wgGoogleAnalyticsIgnorePages, true );
	}

	protected function messageToComment( $messageName = '' ) {
		if( empty( $messageName ) ) {
			throw( new Exception( 'missing a message name!') );
		}

		return PHP_EOL . '<!-- ' . wfMessage( $messageName )->text() . ' -->' . PHP_EOL;

	}

}
