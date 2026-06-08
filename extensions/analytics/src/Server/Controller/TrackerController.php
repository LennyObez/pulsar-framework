<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function file_get_contents;
use function hash;
use function is_file;

/**
 * Serves the compiled tracker JavaScript with aggressive caching.
 *
 * Reads the tracker script from the compiled frontend dist directory rather
 * than embedding it as a PHP string constant, allowing proper build tooling,
 * versioning, and cache-busting via ETag.
 */
#[Internal(reason: 'Serves tracker JS; public endpoint')]
final readonly class TrackerController
{
    /**
     * Fallback inline tracker used when the compiled dist file is not available.
     */
    private const string TRACKER_FALLBACK = <<<'JS'
        !function(){"use strict";try{var d=document.currentScript,s=d.getAttribute("data-site"),a=d.getAttribute("data-api");if(!s||!a||"1"===navigator.doNotTrack)return;var u=location,e=function(t,n){var r=JSON.stringify(Object.assign({site:s,url:u.href},t));try{navigator.sendBeacon(a,r)}catch(e){fetch(a,{method:"POST",body:r,keepalive:!0}).catch(function(){})}"function"==typeof n&&n()};e({type:"pageview",referrer:document.referrer,screen_width:screen.width});window.plsr={event:function(n,o,v){e({type:"event",event_name:n,event_props:o||null,revenue_value:v||null})},ext:{}};var x=d.getAttribute("data-extensions");if(x){var b=a.replace(/\/[^\/]*$/,"");x.split(",").forEach(function(n){var t=document.createElement("script");t.async=!0;t.src=b+"/ext/"+n.trim()+".js";d.parentNode.insertBefore(t,d.nextSibling)})}}catch(e){}}();
        JS;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    private const string DIST_PATH = __DIR__ . '/../../frontend/dist/plsr.js';

    public function __construct() {}

    public function script(ServerRequestInterface $request): Response
    {
        $script = $this->loadScript();
        $etag = '"' . hash('xxh3', $script) . '"';

        // Respond 304 if client has matching ETag
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return new Response(
                statusCode: 304,
                headers: [
                    'ETag' => $etag,
                    'Cache-Control' => 'public, max-age=86400',
                ],
                body: '',
            );
        }

        return new Response(
            headers: [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=86400',
                'ETag' => $etag,
                'X-Content-Type-Options' => 'nosniff',
            ],
            body: $script,
        );
    }

    private function loadScript(): string
    {
        if (is_file(self::DIST_PATH)) {
            $content = file_get_contents(self::DIST_PATH);

            if ($content !== false && $content !== '') {
                return $content;
            }
        }

        return self::TRACKER_FALLBACK;
    }
}
