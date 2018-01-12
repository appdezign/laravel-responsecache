<?php

namespace Spatie\ResponseCache;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Spatie\ResponseCache\CacheProfiles\CacheProfile;

class ResponseCache
{
    /** @var ResponseCache */
    protected $cache;

    /** @var RequestHasher */
    protected $hasher;

    /** @var CacheProfile */
    protected $cacheProfile;

    public function __construct(ResponseCacheRepository $cache, RequestHasher $hasher, CacheProfile $cacheProfile)
    {
        $this->cache = $cache;
        $this->hasher = $hasher;
        $this->cacheProfile = $cacheProfile;
    }

    public function enabled(Request $request): bool
    {
        return $this->cacheProfile->enabled($request);
    }

    public function shouldCache(Request $request, Response $response): bool
    {
        if ($request->attributes->has('responsecache.doNotCache')) {
            return false;
        }

        if (! $this->cacheProfile->shouldCacheRequest($request)) {
            return false;
        }

        return $this->cacheProfile->shouldCacheResponse($response);
    }

    public function cacheResponse(Request $request, Response $response, $lifetimeInMinutes = null): Response
    {
        if (config('responsecache.add_cache_time_header')) {
            $response = $this->addCachedHeader($response);
        }

        $this->cache->put(
            $this->hasher->getHashFor($request),
            $response,
            ($lifetimeInMinutes) ? intval($lifetimeInMinutes) : $this->cacheProfile->cacheRequestUntil($request)
        );

        return $response;
    }

    public function hasBeenCached(Request $request): bool
    {
        return config('responsecache.enabled')
            ? $this->cache->has($this->hasher->getHashFor($request))
            : false;
    }

    public function getCachedResponseFor(Request $request): Response
	{

		$response = $this->cache->get($this->hasher->getHashFor($request));
		
		$cachedContent = $response->getContent();
		$newContent = $this->updateCsrfToken($cachedContent);
		$response->setContent($newContent);

		return $response;
	}

	public function updateCsrfToken($responseContent)
	{

		$findToken   = 'name="_token"';
		$posToken = strpos($responseContent, $findToken);

		if ($posToken !== false) {

			$offsetToken = $posToken + strlen($findToken);

			$findValue = 'value="';
			$posValue = strpos($responseContent, $findValue, $offsetToken);

			if ($posValue !== false) {
				$offsetValue = $posValue + strlen($findValue);

				$findQuotes = '"';
				$posQuotes = strpos($responseContent, $findQuotes, $offsetValue);

				if ($posQuotes !== false) {

					$offsetQuotes = $posQuotes + strlen($findQuotes);
					$oldCsrf = substr($responseContent, $offsetValue, $offsetQuotes - $offsetValue);
					$newCsrf = Session::token();
					$newContent = str_replace($oldCsrf, $newCsrf, $responseContent);

				} else {

					return $responseContent;

				}

			} else {

				return $responseContent;

			}

		} else {

			return $responseContent;
			
		}

		return $newContent;

	}

    public function flush()
    {
        $this->cache->flush();
    }

    protected function addCachedHeader(Response $response): Response
    {
        $clonedResponse = clone $response;

        $clonedResponse->headers->set('laravel-responsecache', 'cached on '.date('Y-m-d H:i:s'));

        return $clonedResponse;
    }
}
